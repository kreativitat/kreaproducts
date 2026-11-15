<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.product.class.php';

/**
 * Synchronize supplier product prices from validated supplier invoices.
 *
 * This service updates existing rows in `product_fournisseur_price` only when
 * a matching tuple exists for:
 * - current entity
 * - supplier
 * - product
 * - supplier product code (`ref_fourn`)
 *
 * Writes always use the verified bound native price ID (never the unbound
 * `update_buyprice` DELETE/INSERT fallback), lock the supplier before its price
 * rows, pass the saved ancillary fields from the locked row and read the row
 * back. Foreign-currency and dynamic-expression rows are left untouched.
 */
class KreaProductsSupplierPriceSyncService
{
	/**
	 * @param object $invoice Supplier invoice object
	 * @param DoliDB $db Database handler
	 * @param User $user Current user
	 * @param Conf $conf Global conf
	 * @return int Number of updated supplier price rows
	 */
	public function syncFromValidatedSupplierInvoice($invoice, $db, $user, $conf)
	{
		if (!$this->isInvoiceEligible($invoice, $conf)) {
			return 0;
		}

		$supplierId = $this->resolveSupplierId($invoice);
		if ($supplierId <= 0) {
			throw new RuntimeException('Validated supplier invoice has no supplier identifier');
		}

		$invoiceLines = $this->extractInvoiceLines($invoice);
		if (empty($invoiceLines)) {
			return 0;
		}

		$supplier = new Fournisseur($db);
		if ($supplier->fetch($supplierId) <= 0) {
			throw new RuntimeException('Unable to load supplier ' . $supplierId . ' for invoice ' . (int) $invoice->id);
		}

		$eligibleLines = array();
		foreach ($invoiceLines as $line) {
			if ($this->isLineEligible($line)) {
				$eligibleLines[] = $line;
			}
		}
		if (empty($eligibleLines)) {
			return 0;
		}

		// Supplier lock always precedes supplier-price row locks.
		$this->lockSupplier($db, $supplierId);

		$updated = 0;
		foreach ($eligibleLines as $line) {
			$productId = (int) $line->fk_product;
			$supplierRef = $this->extractSupplierRef($line);
			$lineQty = (float) price2num($line->qty, 'MS');
			$lineUnitPrice = (float) price2num($line->subprice, 'MU');

			$pfpId = $this->findSupplierPriceRowId($db, (int) $conf->entity, $productId, $supplierId, $supplierRef, $lineQty);
			if ($pfpId <= 0) {
				continue;
			}

			$ret = $this->updateSupplierPriceRow($db, $conf, $user, $supplier, $pfpId, $supplierRef, $lineUnitPrice, $productId, $supplierId);
			if ($ret > 0) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * @param object $invoice
	 * @param Conf $conf
	 * @return bool
	 */
	private function isInvoiceEligible($invoice, $conf)
	{
		if (!is_object($invoice) || empty($invoice->id)) {
			return false;
		}

		$invoiceEntity = isset($invoice->entity) ? (int) $invoice->entity : 0;
		if ($invoiceEntity > 0 && $invoiceEntity !== (int) $conf->entity) {
			return false;
		}

		// Ignore supplier credit notes to avoid rewriting buy prices from return flows.
		if (isset($invoice->type) && (int) $invoice->type === 2) {
			return false;
		}

		return true;
	}

	/**
	 * @param object $invoice
	 * @return int
	 */
	private function resolveSupplierId($invoice)
	{
		if (!empty($invoice->socid)) {
			return (int) $invoice->socid;
		}
		if (!empty($invoice->fk_soc)) {
			return (int) $invoice->fk_soc;
		}
		return 0;
	}

	/**
	 * @param object $invoice
	 * @return array<int, object>
	 */
	private function extractInvoiceLines($invoice)
	{
		if (empty($invoice->lines) && method_exists($invoice, 'fetch_lines')) {
			$result = $invoice->fetch_lines();
			if ($result < 0) {
				throw new RuntimeException('Unable to load supplier invoice lines for invoice ' . (int) $invoice->id);
			}
		}
		if (empty($invoice->lines) || !is_array($invoice->lines)) {
			return array();
		}

		return $invoice->lines;
	}

	/**
	 * @param object $line
	 * @return bool
	 */
	private function isLineEligible($line)
	{
		if (!is_object($line)) {
			return false;
		}
		if (empty($line->fk_product) || (int) $line->fk_product <= 0) {
			return false;
		}

		$supplierRef = $this->extractSupplierRef($line);
		if ($supplierRef === '') {
			return false;
		}

		$qty = (float) price2num($line->qty, 'MS');
		if ($qty <= 0) {
			return false;
		}

		$lineUnitPrice = (float) price2num($line->subprice, 'MU');
		return $lineUnitPrice >= 0;
	}

	/**
	 * @param object $line
	 * @return string
	 */
	private function extractSupplierRef($line)
	{
		$ref = '';
		if (isset($line->ref_supplier)) {
			$ref = (string) $line->ref_supplier;
		} elseif (isset($line->ref)) {
			$ref = (string) $line->ref;
		}

		return trim($ref);
	}

	/**
	 * @param DoliDB $db
	 * @param int $entity
	 * @param int $productId
	 * @param int $supplierId
	 * @param string $supplierRef
	 * @param float $lineQty
	 * @return int
	 */
	private function findSupplierPriceRowId($db, $entity, $productId, $supplierId, $supplierRef, $lineQty)
	{
		if ($entity <= 0 || $productId <= 0 || $supplierId <= 0 || $supplierRef === '') {
			return 0;
		}

		$sql = "SELECT pfp.rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp";
		$sql .= " WHERE pfp.entity = " . ((int) $entity);
		$sql .= " AND pfp.fk_product = " . ((int) $productId);
		$sql .= " AND pfp.fk_soc = " . ((int) $supplierId);
		$sql .= " AND pfp.ref_fourn = '" . $db->escape($supplierRef) . "'";
		$sql .= " ORDER BY CASE WHEN ABS(pfp.quantity - " . ((float) price2num($lineQty, 'MS')) . ") < 0.0000001 THEN 0 ELSE 1 END, pfp.datec DESC, pfp.rowid DESC";
		$sql .= " LIMIT 1";
		$sql .= " FOR UPDATE";

		$resql = $db->query($sql);
		if (!$resql) {
			throw new RuntimeException('Unable to locate the supplier price row: ' . $db->lasterror());
		}

		$obj = $db->fetch_object($resql);
		return ($obj ? (int) $obj->rowid : 0);
	}

	/**
	 * Saved columns that the native bound update must preserve.
	 */
	const ROW_COLUMNS = 'rowid, entity, fk_product, fk_soc, ref_fourn, desc_fourn, fk_availability, price, quantity, unitprice, charges, remise_percent, remise, default_vat_code, tva_tx, localtax1_tx, localtax1_type, localtax2_tx, localtax2_type, info_bits, fk_supplier_price_expression, delivery_time_days, supplier_reputation, packaging, fk_multicurrency, multicurrency_code, multicurrency_tx, multicurrency_unitprice, multicurrency_price, barcode, fk_barcode_type, import_key, status';

	/**
	 * @param DoliDB $db
	 * @param int $supplierId
	 * @return void
	 */
	private function lockSupplier($db, $supplierId)
	{
		$resql = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe WHERE rowid = ' . ((int) $supplierId) . ' FOR UPDATE');
		if (!$resql || !$db->fetch_object($resql)) {
			throw new RuntimeException('Unable to lock supplier ' . (int) $supplierId . ': ' . $db->lasterror());
		}
	}

	/**
	 * @param DoliDB $db
	 * @param int $pfpId
	 * @param bool $lock Current (locking) read instead of a snapshot read
	 * @return object|null
	 */
	private function readSupplierPriceRow($db, $pfpId, $lock)
	{
		$sql = 'SELECT ' . self::ROW_COLUMNS . ' FROM ' . MAIN_DB_PREFIX . 'product_fournisseur_price WHERE rowid = ' . ((int) $pfpId);
		if ($lock) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $db->query($sql);
		if (!$resql) {
			throw new RuntimeException('Unable to read supplier price row ' . (int) $pfpId . ': ' . $db->lasterror());
		}
		$row = $db->fetch_object($resql);
		return $row ? $row : null;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private function isBlank($value)
	{
		return $value === null || $value === '' || (is_numeric($value) && (float) $value == 0.0);
	}

	/**
	 * Native-equivalent comparison: NULL, '' and 0 are the same saved value.
	 *
	 * @param mixed $before
	 * @param mixed $after
	 * @return bool
	 */
	private function sameSavedValue($before, $after)
	{
		if ($this->isBlank($before) && $this->isBlank($after)) {
			return true;
		}
		if (is_numeric($before) && is_numeric($after)) {
			return abs((float) $before - (float) $after) < 0.000001;
		}
		return (string) $before === (string) $after;
	}

	/**
	 * @param DoliDB $db
	 * @param Conf $conf
	 * @param User $user
	 * @param Fournisseur $supplier
	 * @param int $pfpId
	 * @param string $supplierRef
	 * @param float $lineUnitPrice
	 * @param int $productId Product of the invoice line
	 * @param int $supplierId Supplier of the invoice
	 * @return int 1 when updated, 0 when the row is intentionally left untouched
	 */
	private function updateSupplierPriceRow($db, $conf, $user, $supplier, $pfpId, $supplierRef, $lineUnitPrice, $productId = 0, $supplierId = 0)
	{
		$pfpId = (int) $pfpId;

		// Current locked values: an earlier snapshot of the transaction may be stale.
		$raw = $this->readSupplierPriceRow($db, $pfpId, true);
		if (!$raw) {
			throw new RuntimeException('Unable to load supplier price row ' . $pfpId);
		}
		if (
			($productId > 0 && (int) $raw->fk_product !== (int) $productId)
			|| ($supplierId > 0 && (int) $raw->fk_soc !== (int) $supplierId)
			|| (int) $raw->entity !== (int) $conf->entity
		) {
			throw new RuntimeException('Supplier price row ' . $pfpId . ' changed identity while it was being locked');
		}

		// Dynamic prices and foreign currencies need their dedicated native workflow.
		if (!empty($raw->fk_supplier_price_expression)) {
			dol_syslog(__METHOD__ . ' supplier price row ' . $pfpId . ' skipped: price is controlled by a dynamic expression', LOG_WARNING);
			return 0;
		}
		$companyCurrency = (string) ($conf->currency ?? '');
		$rowCurrency = trim((string) ($raw->multicurrency_code ?? ''));
		$rowRate = ($raw->multicurrency_tx === null || $raw->multicurrency_tx === '') ? 1.0 : (float) $raw->multicurrency_tx;
		if (($rowCurrency !== '' && strcasecmp($rowCurrency, $companyCurrency) !== 0) || abs($rowRate - 1.0) > 0.00000001) {
			dol_syslog(__METHOD__ . ' supplier price row ' . $pfpId . ' skipped: foreign-currency price (' . $rowCurrency . ' @ ' . $rowRate . ')', LOG_WARNING);
			return 0;
		}

		$productFourn = new ProductFournisseur($db);
		$resultFetch = $productFourn->fetch_product_fournisseur_price($pfpId, 1);
		if ($resultFetch <= 0 || (int) $productFourn->id !== (int) $raw->fk_product) {
			throw new RuntimeException('Unable to load supplier price row ' . $pfpId);
		}

		$rowQty = (float) price2num($raw->quantity, 'MS');
		if ($rowQty <= 0) {
			throw new RuntimeException('Supplier price row ' . $pfpId . ' has an invalid quantity');
		}

		$newBuyPrice = (float) price2num($lineUnitPrice * $rowQty, 'MU');
		$newDeliveryTime = ($raw->delivery_time_days === '' || $raw->delivery_time_days === null) ? '' : (int) $raw->delivery_time_days;
		$newSupplierReputation = (empty($raw->supplier_reputation) || $raw->supplier_reputation == -1) ? '' : (string) $raw->supplier_reputation;

		if (isModEnabled('multicurrency')) {
			// Same-currency row: native derives the price from the currency price at rate 1.
			$multicurrencyCode = ($rowCurrency !== '' ? (string) $raw->multicurrency_code : $companyCurrency);
			$multicurrencyBuyPrice = $newBuyPrice;
		} else {
			// Keep the saved currency metadata untouched.
			$multicurrencyCode = $raw->multicurrency_code;
			$multicurrencyBuyPrice = $this->isBlank($raw->multicurrency_price) ? $raw->multicurrency_price : $newBuyPrice;
		}

		// Binding the verified ID prevents update_buyprice's fallback DELETE.
		$productFourn->product_fourn_price_id = $pfpId;
		// Preserve existing packaging on update_buyprice().
		$productFourn->product_fourn_packaging = (float) price2num($raw->packaging, 'MS');

		$resultUpdate = $productFourn->update_buyprice(
			$rowQty,
			$newBuyPrice,
			$user,
			'HT',
			$supplier,
			(int) $raw->fk_availability,
			(string) $raw->ref_fourn,
			(float) $raw->tva_tx,
			(float) $raw->charges,
			(float) $raw->remise_percent,
			(float) $raw->remise,
			(int) $raw->info_bits,
			$newDeliveryTime,
			$newSupplierReputation,
			array((string) $raw->localtax1_type, (float) $raw->localtax1_tx, (string) $raw->localtax2_type, (float) $raw->localtax2_tx),
			empty($raw->default_vat_code) ? '' : (string) $raw->default_vat_code,
			$multicurrencyBuyPrice,
			'HT',
			1,
			$multicurrencyCode,
			(string) $raw->desc_fourn,
			empty($raw->barcode) ? '' : (string) $raw->barcode,
			empty($raw->fk_barcode_type) ? 0 : (int) $raw->fk_barcode_type
		);

		if ($resultUpdate <= 0 || (int) $productFourn->product_fourn_price_id !== $pfpId) {
			throw new RuntimeException(
				'Unable to update supplier price row ' . $pfpId
				. ' for product ' . (int) $raw->fk_product
				. ': ' . ($productFourn->error ?: $db->lasterror())
			);
		}

		$this->assertRowPreserved($db, $conf, $pfpId, $raw, $newBuyPrice);

		return 1;
	}

	/**
	 * Read the row back: identity and ancillary fields must be intact.
	 *
	 * @param DoliDB $db
	 * @param Conf $conf
	 * @param int $pfpId
	 * @param object $raw Locked row before the native update
	 * @param float $newBuyPrice
	 * @return void
	 */
	private function assertRowPreserved($db, $conf, $pfpId, $raw, $newBuyPrice)
	{
		$saved = $this->readSupplierPriceRow($db, $pfpId, false);
		if (!$saved) {
			throw new RuntimeException('Supplier price row ' . $pfpId . ' disappeared during its update');
		}
		if (abs((float) $saved->price - (float) $newBuyPrice) > 0.000001) {
			throw new RuntimeException('Supplier price row ' . $pfpId . ' was not saved with the invoice price');
		}

		$fields = array(
			'entity', 'fk_product', 'fk_soc', 'ref_fourn', 'quantity', 'desc_fourn', 'fk_availability', 'charges',
			'remise_percent', 'remise', 'default_vat_code', 'tva_tx', 'localtax1_tx', 'localtax1_type', 'localtax2_tx',
			'localtax2_type', 'info_bits', 'fk_supplier_price_expression', 'delivery_time_days', 'supplier_reputation',
			'barcode', 'fk_barcode_type', 'import_key', 'status',
		);
		// Native fills an empty packaging with the minimum quantity when supplier packaging is enabled.
		if (!$this->isBlank($raw->packaging) || !getDolGlobalString('PRODUCT_USE_SUPPLIER_PACKAGING')) {
			$fields[] = 'packaging';
		}
		foreach ($fields as $field) {
			$before = $raw->$field ?? null;
			$after = $saved->$field ?? null;
			if ($field === 'supplier_reputation' && $before == -1) {
				$before = null;
			}
			if (!$this->sameSavedValue($before, $after)) {
				throw new RuntimeException('Supplier price row ' . $pfpId . ' did not preserve its saved ' . $field);
			}
		}
	}
}
