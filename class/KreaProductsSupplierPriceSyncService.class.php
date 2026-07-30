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

		$updated = 0;
		foreach ($invoiceLines as $line) {
			if (!$this->isLineEligible($line)) {
				continue;
			}

			$productId = (int) $line->fk_product;
			$supplierRef = $this->extractSupplierRef($line);
			$lineQty = (float) price2num($line->qty, 'MS');
			$lineUnitPrice = (float) price2num($line->subprice, 'MU');

			$pfpId = $this->findSupplierPriceRowId($db, (int) $conf->entity, $productId, $supplierId, $supplierRef, $lineQty);
			if ($pfpId <= 0) {
				continue;
			}

			$ret = $this->updateSupplierPriceRow($db, $conf, $user, $supplier, $pfpId, $supplierRef, $lineUnitPrice);
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

		$resql = $db->query($sql);
		if (!$resql) {
			throw new RuntimeException('Unable to locate the supplier price row: ' . $db->lasterror());
		}

		$obj = $db->fetch_object($resql);
		return ($obj ? (int) $obj->rowid : 0);
	}

	/**
	 * @param DoliDB $db
	 * @param Conf $conf
	 * @param User $user
	 * @param Fournisseur $supplier
	 * @param int $pfpId
	 * @param string $supplierRef
	 * @param float $lineUnitPrice
	 * @return int
	 */
	private function updateSupplierPriceRow($db, $conf, $user, $supplier, $pfpId, $supplierRef, $lineUnitPrice)
	{
		$productFourn = new ProductFournisseur($db);
		$resultFetch = $productFourn->fetch_product_fournisseur_price($pfpId, 1);
		if ($resultFetch <= 0) {
			throw new RuntimeException('Unable to load supplier price row ' . $pfpId);
		}

		$rowQty = (float) price2num($productFourn->fourn_qty, 'MS');
		if ($rowQty <= 0) {
			throw new RuntimeException('Supplier price row ' . $pfpId . ' has an invalid quantity');
		}

		$newBuyPrice = (float) price2num($lineUnitPrice * $rowQty, 'MU');
		$newVat = (float) price2num($productFourn->fourn_tva_tx, 'MU');
		$newCharges = (float) price2num($productFourn->fourn_charges, 'MU');
		$newRemisePercent = (float) price2num($productFourn->fourn_remise_percent, 'MU');
		$newRemise = (float) price2num($productFourn->fourn_remise, 'MU');
		$newNpr = empty($productFourn->fourn_tva_npr) ? 0 : 1;
		$newDeliveryTime = ($productFourn->delivery_time_days === '' || $productFourn->delivery_time_days === null) ? '' : (int) $productFourn->delivery_time_days;
		$newSupplierReputation = empty($productFourn->supplier_reputation) ? '' : (string) $productFourn->supplier_reputation;
		$newDefaultVatCode = empty($productFourn->default_vat_code) ? '' : (string) $productFourn->default_vat_code;
		$newDescription = empty($productFourn->desc_supplier) ? '' : (string) $productFourn->desc_supplier;
		$newBarcode = (isset($productFourn->supplier_barcode) && $productFourn->supplier_barcode !== null) ? (string) $productFourn->supplier_barcode : '';
		$newBarcodeType = (isset($productFourn->supplier_fk_barcode_type) && $productFourn->supplier_fk_barcode_type !== null) ? (int) $productFourn->supplier_fk_barcode_type : 0;

		$multicurrencyTx = (float) price2num($productFourn->fourn_multicurrency_tx, 'MU');
		if ($multicurrencyTx <= 0) {
			$multicurrencyTx = 1;
		}
		$multicurrencyCode = !empty($productFourn->fourn_multicurrency_code) ? (string) $productFourn->fourn_multicurrency_code : (string) ($conf->currency ?? '');
		$multicurrencyBuyPrice = (float) price2num($newBuyPrice * $multicurrencyTx, 'MU');

		// Preserve existing packaging on update_buyprice().
		$productFourn->product_fourn_packaging = (float) price2num($productFourn->packaging, 'MS');

		$resultUpdate = $productFourn->update_buyprice(
			$rowQty,
			$newBuyPrice,
			$user,
			'HT',
			$supplier,
			(int) $productFourn->fk_availability,
			$supplierRef,
			$newVat,
			$newCharges,
			$newRemisePercent,
			$newRemise,
			$newNpr,
			$newDeliveryTime,
			$newSupplierReputation,
			array(),
			$newDefaultVatCode,
			$multicurrencyBuyPrice,
			'HT',
			$multicurrencyTx,
			$multicurrencyCode,
			$newDescription,
			$newBarcode,
			$newBarcodeType
		);

		if ($resultUpdate <= 0) {
			throw new RuntimeException(
				'Unable to update supplier price row ' . $pfpId
				. ' for product ' . (int) $productFourn->id
				. ': ' . ($productFourn->error ?: $db->lasterror())
			);
		}

		return 1;
	}
}
