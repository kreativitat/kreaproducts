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
 */

dol_include_once('/kreaproducts/class/ProductUpdater.class.php');
dol_include_once('/kreaproducts/class/productdismantlecontroller.class.php');
require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';
require_once __DIR__.'/KreaProductsInventoryLedgerCalculator.class.php';
require_once __DIR__.'/KreaProductsInventoryMovementService.class.php';
require_once __DIR__.'/KreaProductsBusinessDayService.class.php';

/**
 * KreaProductsStockMovementService
 *
 * Full-featured version with robust stock rules:
 * - Supports inventory moves (including backdated inventories).
 * - Supports customer and supplier invoice moves (including delayed posting).
 * - Keeps later inventories consistent by adjusting the next inventory movement when needed.
 * - Handles history-truncated systems safely (old stock_mouvement deleted) by refusing to rebuild
 *   reel from sums when no valid inventory anchor exists.
 *
 * A valid anchor requires a recorded inventory plus either an active KreaProducts adjustment
 * audit row or a matching legacy inventory movement for the product, warehouse, and batch.
 */
class KreaProductsStockMovementService
{
	public function handleStockMovement($move, $db, $conf, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$applyMovementDates = !empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA);
		$skipDismantle = $this->isDismantleMovement($move);

		// First: align timestamps (optional)
		if ($applyMovementDates) {
			if ($move->origintype === 'facture') {
				if (!$this->shiftCustomerInvoiceMoveToInvoiceDateTime($move, $db, $conf)) {
					return -1;
				}
			}
			if ($move->origintype === 'invoice_supplier') {
				if (!$this->shiftSupplierInvoiceMoveToConfiguredTime($move, $db, $conf)) {
					return -1;
				}
			}
			if ($move->origintype === 'inventory') {
				if (!$this->alignInventoryMoveToDateInventory($move, $db, $conf)) {
					return -1;
				}
			}
		}

		// Then: stock routines
		if ($move->origintype === 'inventory' && $applyMovementDates) {
			if (!$this->recalculateAfterInventory($move, $db)) {
				return -1;
			}
		}

		if ($move->origintype === 'invoice_supplier') {
			if ($applyMovementDates) {
				if (!$this->recalculateAfterOperationalMovement($move, $db, $conf, $user)) {
					return -1;
				}
			}

			// Dismantle routines are independent from the stock rebuild logic
			if ($skipDismantle) {
				dol_syslog(__METHOD__ . ' skip dismantle for movement id=' . (int) $move->id . ' label=' . (string) $move->label, LOG_DEBUG);
			} else {
				if (!$this->updateDismantleChildrenBeforeTrigger($move, $db, $user)) {
					return -1;
				}
				if ($this->dismantleIfNeeded($move, $db, $user) < 0) {
					return -1;
				}
			}
		}

		if ($move->origintype === 'facture' && $applyMovementDates) {
			if (!$this->recalculateAfterOperationalMovement($move, $db, $conf, $user)) {
				return -1;
			}
		}

		// Auto-dismantle generated MO movements can be backdated too; keep reel recalculation consistent.
		if ($move->origintype === 'mo' && $skipDismantle && $applyMovementDates) {
			if (!$this->recalculateAfterOperationalMovement($move, $db, $conf, $user)) {
				return -1;
			}
		}

		return 0;
	}

	/**
	 * Align a customer movement with the authoritative invoice datetime.
	 *
	 * @param MouvementStock $move Customer invoice stock movement
	 * @param DoliDB         $db   Database handler
	 * @param Conf           $conf Dolibarr configuration
	 * @return bool
	 */
	protected function shiftCustomerInvoiceMoveToInvoiceDateTime($move, $db, $conf)
	{
		if (empty($move->origin_id)) {
			return true;
		}

		$sql = 'SELECT f.datef, f.datec';
		if (isModEnabled('dolizsynch')) {
			$sql .= ', (SELECT zs.datahora_zs FROM '.MAIN_DB_PREFIX.'dolizsynch_zsinvoicesynch zs';
			$sql .= ' WHERE zs.fk_facture=f.rowid AND zs.entity=f.entity AND zs.datahora_zs IS NOT NULL';
			$sql .= ' ORDER BY zs.rowid DESC LIMIT 1) AS source_datetime';
		} else {
			$sql .= ', NULL AS source_datetime';
		}
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture f';
		$sql .= ' WHERE f.rowid='.(int) $move->origin_id;
		$sql .= ' AND f.entity='.(int) $conf->entity;
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' Error querying customer invoice date: '.$db->lasterror(), LOG_ERR);
			return false;
		}
		$row = $db->fetch_object($resql);
		$db->free($resql);
		if (!$row || empty($row->datef)) {
			return false;
		}

		try {
			$invoiceDateTime = !empty($row->source_datetime) ? $row->source_datetime : $row->datec;
			if (empty($invoiceDateTime)) {
				$current = is_numeric($move->datem) ? $db->idate($move->datem) : (string) $move->datem;
				$invoiceDateTime = substr((string) $row->datef, 0, 10).' '.substr($current, 11, 8);
			}
			$invoiceTimestamp = $this->resolveCustomerInvoiceDateTimeTimestamp($invoiceDateTime, $conf);
			$futureToleranceMinutes = min(1440, max(0, getDolGlobalInt('KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES', 30)));
			if ($invoiceTimestamp > dol_now() + ($futureToleranceMinutes * 60)) {
				dol_syslog(__METHOD__.' Refusing future customer invoice datetime '.(string) $invoiceDateTime, LOG_ERR);
				return false;
			}
			$new = $db->idate($invoiceTimestamp);
		} catch (InvalidArgumentException $exception) {
			dol_syslog(__METHOD__.' '.$exception->getMessage(), LOG_ERR);
			return false;
		}

		$current = $move->datem;
		if (is_numeric($current)) {
			$current = $db->idate($current);
		}
		if (!empty($move->id) && $new !== $current) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX."stock_mouvement SET datem='".$db->escape($new)."'";
			$sql .= ' WHERE rowid='.(int) $move->id;
			if (!$db->query($sql)) {
				dol_syslog(__METHOD__.' Error updating customer movement date: '.$db->lasterror(), LOG_ERR);
				return false;
			}
		}

		$move->datem = $new;
		return true;
	}

	/**
	 * Normalize customer movements relevant to a physical inventory reconstruction.
	 *
	 * Retimed historical movements are passed through the same append-only rebase logic
	 * as newly generated movements. Source quantities and invoice dates remain immutable.
	 *
	 * @param DoliDB $db             Database handler
	 * @param Conf   $conf           Dolibarr configuration
	 * @param User   $user           Movement author
	 * @param int    $productId      Product ID
	 * @param int    $warehouseId    Warehouse ID
	 * @param string $batch          Lot or serial
	 * @param int    $valueTimestamp Inventory value timestamp
	 * @return bool
	 */
	public function normalizeCustomerInvoiceMovementsForReconstruction($db, $conf, $user, $productId, $warehouseId, $batch, $valueTimestamp)
	{
		$timezone = $this->getBusinessTimezone($conf);
		$valueDate = (new DateTimeImmutable('@'.((int) $valueTimestamp)))->setTimezone($timezone);
		$minimumInvoiceDate = $valueDate->format('Y-m-d');

		$sql = 'SELECT sm.rowid, sm.fk_origin, sm.fk_product, sm.fk_entrepot, sm.batch, sm.datem, sm.value, f.datef';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement sm';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture f ON f.rowid=sm.fk_origin AND f.entity='.(int) $conf->entity;
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid=sm.fk_product AND p.entity IN ('.getEntity('product').')';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot e ON e.rowid=sm.fk_entrepot AND e.entity IN ('.getEntity('stock').')';
		$sql .= " WHERE sm.origintype='facture'";
		$sql .= ' AND sm.fk_product='.(int) $productId;
		$sql .= ' AND sm.fk_entrepot='.(int) $warehouseId;
		$sql .= " AND (f.datef >= '".$db->escape($minimumInvoiceDate)."'";
		$sql .= " OR sm.datem > '".$db->escape($db->idate((int) $valueTimestamp))."')";
		$sql .= $this->batchCondMoveValue($db, (string) $batch);
		$sql .= ' ORDER BY sm.rowid ASC';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' Error loading customer movements: '.$db->lasterror(), LOG_ERR);
			return false;
		}
		$rows = array();
		while ($row = $db->fetch_object($resql)) {
			$rows[] = $row;
		}
		$db->free($resql);

		foreach ($rows as $row) {
			$move = new stdClass();
			$move->id = (int) $row->rowid;
			$move->origin_id = (int) $row->fk_origin;
			$move->origintype = 'facture';
			$move->product_id = (int) $row->fk_product;
			$move->fk_entrepot = (int) $row->fk_entrepot;
			$move->batch = (string) $row->batch;
			$move->datem = (string) $row->datem;
			if (!$this->shiftCustomerInvoiceMoveToInvoiceDateTime($move, $db, $conf)) {
				return false;
			}
			if (!$this->recalculateAfterOperationalMovement($move, $db, $conf, $user)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize supplier movements relevant to a physical inventory reconstruction.
	 *
	 * Existing late-posted movements are reconciled through the same append-only rebase
	 * path as newly triggered movements. Source quantities and supplier invoice dates remain immutable.
	 *
	 * @param DoliDB $db             Database handler
	 * @param Conf   $conf           Dolibarr configuration
	 * @param User   $user           Movement author
	 * @param int    $productId      Product ID
	 * @param int    $warehouseId    Warehouse ID
	 * @param string $batch          Lot or serial
	 * @param int    $valueTimestamp Inventory value timestamp
	 * @return bool
	 */
	public function normalizeSupplierInvoiceMovementsForReconstruction($db, $conf, $user, $productId, $warehouseId, $batch, $valueTimestamp)
	{
		$valueDateSql = $db->idate((int) $valueTimestamp);
		$valueCalendarDate = substr($valueDateSql, 0, 10);
		$sql = 'SELECT sm.rowid, sm.fk_origin, sm.fk_product, sm.fk_entrepot, sm.batch, sm.datem, sm.value, ff.datef';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement sm';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture_fourn ff ON ff.rowid=sm.fk_origin AND ff.entity='.(int) $conf->entity;
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid=sm.fk_product AND p.entity IN ('.getEntity('product').')';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot e ON e.rowid=sm.fk_entrepot AND e.entity IN ('.getEntity('stock').')';
		$sql .= " WHERE sm.origintype='invoice_supplier'";
		$sql .= ' AND sm.fk_product='.(int) $productId;
		$sql .= ' AND sm.fk_entrepot='.(int) $warehouseId;
		$sql .= " AND (ff.datef >= '".$db->escape($valueCalendarDate)."'";
		$sql .= " OR sm.datem > '".$db->escape($valueDateSql)."')";
		$sql .= $this->batchCondMoveValue($db, (string) $batch);
		$sql .= ' ORDER BY sm.rowid ASC';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' Error loading supplier movements: '.$db->lasterror(), LOG_ERR);
			return false;
		}
		$rows = array();
		while ($row = $db->fetch_object($resql)) {
			$rows[] = $row;
		}
		$db->free($resql);

		foreach ($rows as $row) {
			$move = new stdClass();
			$move->id = (int) $row->rowid;
			$move->origin_id = (int) $row->fk_origin;
			$move->origintype = 'invoice_supplier';
			$move->product_id = (int) $row->fk_product;
			$move->fk_entrepot = (int) $row->fk_entrepot;
			$move->batch = (string) $row->batch;
			$move->datem = (string) $row->datem;
			if (!$this->shiftSupplierInvoiceMoveToConfiguredTime($move, $db, $conf)) {
				return false;
			}
			if (!$this->recalculateAfterOperationalMovement($move, $db, $conf, $user)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $invoiceDateTime Customer invoice datetime
	 * @param Conf   $conf        Dolibarr configuration
	 * @return int
	 */
	protected function resolveCustomerInvoiceDateTimeTimestamp($invoiceDateTime, $conf)
	{
		$businessDay = new KreaProductsBusinessDayService();
		return $businessDay->resolveInvoiceDateTimeTimestamp((string) $invoiceDateTime, $this->getBusinessTimezone($conf));
	}

	/**
	 * @param Conf $conf Dolibarr configuration
	 * @return DateTimeZone
	 */
	protected function getBusinessTimezone($conf)
	{
		$timezoneName = trim((string) ($conf->global->KREAPRODUCTS_BUSINESS_TIMEZONE ?? date_default_timezone_get()));
		try {
			return new DateTimeZone($timezoneName);
		} catch (Exception $exception) {
			throw new InvalidArgumentException('Invalid business timezone configuration.');
		}
	}

	protected function isDismantleMovement($move)
	{
		if (empty($move->label)) {
			return false;
		}

		$label = strtolower((string) $move->label);
		return (strpos($label, 'consume for mo') !== false || strpos($label, 'produce for mo') !== false);
	}

	protected function shiftSupplierInvoiceMoveToConfiguredTime($move, $db, $conf)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($move->origin_id)) {
			return true;
		}

		$time = trim($conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME ?? '10:00');
		if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $time)) {
			dol_syslog(__METHOD__.' Invalid KREAPRODUCTS_SUPPLIER_MOVE_TIME value.', LOG_ERR);
			return false;
		}
		if (strlen($time) === 5) {
			$time .= ':00';
		}

		$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn';
		$sql .= ' WHERE rowid=' . (int) $move->origin_id;
		$sql .= ' AND entity=' . (int) $conf->entity;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error querying supplier invoice date: ' . $db->lasterror(), LOG_ERR);
			return false;
		}
		$row = $db->fetch_object($res);
		if (!$row || empty($row->datef)) {
			return false;
		}

		$invoiceDate = $row->datef;
		if (is_numeric($invoiceDate)) {
			$invoiceDate = $db->idate($invoiceDate);
		}
		$invoiceDate = substr((string) $invoiceDate, 0, 10);
		if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $invoiceDate)) {
			return false;
		}

		$new = $invoiceDate.' '.$time;

		$current = $move->datem;
		if (is_numeric($current)) {
			$current = $db->idate($current);
		}

		if (!empty($move->id) && $new !== $current) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . "stock_mouvement SET datem='" . $db->escape($new) . "' WHERE rowid=" . (int) $move->id;
			if (!$db->query($upd)) {
				dol_syslog(__METHOD__.' Error updating supplier movement date: '.$db->lasterror(), LOG_ERR);
				return false;
			}
		}

		$move->datem = $new;
		return true;
	}

	protected function alignInventoryMoveToDateInventory($move, $db, $conf)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$inventoryId = (int) $move->origin_id;
		if ($inventoryId <= 0 || empty($move->id)) {
			return true;
		}

		$sql = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory';
		$sql .= ' WHERE rowid = ' . $inventoryId;
		$sql .= ' AND entity = '.(int) $conf->entity;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error querying inventory date: ' . $db->lasterror(), LOG_ERR);
			return false;
		}
		$row = $db->fetch_object($res);
		if (!$row || empty($row->date_inventory)) {
			return false;
		}

		$dateInventory = $row->date_inventory;
		if (is_numeric($dateInventory)) {
			$dateInventory = $db->idate($dateInventory);
		}

		$current = $move->datem;
		if (is_numeric($current)) {
			$current = $db->idate($current);
		}

		if ($current !== $dateInventory) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . "stock_mouvement SET datem='" . $db->escape($dateInventory) . "' WHERE rowid=" . (int) $move->id;
			if (!$db->query($upd)) {
				dol_syslog(__METHOD__.' Error updating inventory movement date: '.$db->lasterror(), LOG_ERR);
				return false;
			}
			$move->datem = $dateInventory;
		}
		return true;
	}

	protected function updateDismantleChildrenBeforeTrigger($move, $db, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$dismantle = new ProductDismantleController($db);

		$isDismantleProduct = $dismantle->productInDismantleCategory($move->product_id);
		if ($isDismantleProduct === null) {
			return false;
		}
		if (!$isDismantleProduct) {
			return true;
		}

		$bomId = $dismantle->findBom($move->product_id);
		if ($bomId === null) {
			return false;
		}
		if (!$bomId) {
			return true;
		}

		$sql = 'SELECT DISTINCT COALESCE(bl.fk_product, cb.fk_product) AS child'
			. ' FROM ' . MAIN_DB_PREFIX . 'bom_bom b'
			. ' JOIN ' . MAIN_DB_PREFIX . 'bom_bomline bl ON bl.fk_bom = b.rowid'
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . 'bom_bom cb ON cb.rowid = bl.fk_bom_child'
			. ' WHERE b.rowid = ' . (int) $bomId
			. ' AND b.entity IN (0,' . getEntity('bom') . ')'
			. ' AND (cb.rowid IS NULL OR cb.entity IN (0,' . getEntity('bom') . '))';

		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error loading dismantle BOM children: ' . $db->lasterror(), LOG_ERR);
			return false;
		}

		$children = array();
		while ($obj = $db->fetch_object($res)) {
			if (!empty($obj->child)) {
				$children[(int) $obj->child] = true;
			}
		}
		$db->free($res);

		if (!empty($children)) {
			try {
				ProductUpdater::batchUpdateCostPrices(array_keys($children), true);
				$cascadeErrors = ProductUpdater::getLastErrors();
				if (!empty($cascadeErrors)) {
					dol_syslog(__METHOD__.' cost cascade failed: '.implode('; ', $cascadeErrors), LOG_ERR);
					return false;
				}
			} catch (Throwable $exception) {
				dol_syslog(__METHOD__.' '.$exception->getMessage(), LOG_ERR);
				return false;
			}
		}

		return true;
	}

	protected function dismantleIfNeeded($move, $db, $user = null)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$d = new ProductDismantleController($db);
		$isDismantleProduct = $d->productInDismantleCategory($move->product_id);
		if ($isDismantleProduct === null) {
			return -1;
		}
		if (!$isDismantleProduct) {
			return 1;
		}
		$bom = $d->findBom($move->product_id);
		if ($bom === null) {
			return -1;
		}
		if (!$bom) {
			return 1;
		}

		return (int) $d->produceAndConsume(
			$bom,
			$move->qty,
			$move->price,
			$move->label,
			$move->origin_id,
			$move->origintype,
			$db->jdate($move->datem),
			$user,
			(int) $move->id
		);
	}

	protected function recalculateAfterInventory($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$warehouse = $this->resolveWarehouseFromMoveOrInventory($move, $db);
		if ($warehouse <= 0) {
			dol_syslog(__METHOD__ . ' Cannot determine warehouse for inventory recalc', LOG_ERR);
			return false;
		}

		// If this inventory is inserted before an existing later inventory, adjust the next inventory movement.
		$backdatedResult = $this->adjustForBackdatedInventory($move, $db, $warehouse);
		if ($backdatedResult === null) {
			return false;
		}
		if ($backdatedResult) {
			return true;
		}

		// Normal inventory: rebuild from this anchor only if this inventory has an inventory movement row.
		return $this->recalculateStockFromInventoryAnchor($db, (int) $move->origin_id, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''));
	}

	protected function recalculateAfterOperationalMovement($move, $db, $conf, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$moveDate = $move->datem;
		if (empty($moveDate)) {
			return true;
		}
		if (is_numeric($moveDate)) {
			$moveDate = $db->idate($moveDate);
		}

		$warehouse = $this->resolveWarehouseFromMove($move, $db);
		if ($warehouse <= 0) {
			dol_syslog(__METHOD__ . ' Cannot determine warehouse for operational movement reconciliation', LOG_ERR);
			return false;
		}

		$case3 = $this->applySupplierInvoiceCase3($move, $db, $conf, $user, $warehouse, $moveDate);
		if ($case3 !== null) {
			return $case3;
		}

		return $this->applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate);
	}

	protected function applySupplierInvoiceCase3($move, $db, $conf, $user, $warehouse, $moveDate)
	{
		$batch = (string) ($move->batch ?? '');
		$nextAnchor = $this->getNextInventoryAnchorAfter($db, (int) $move->product_id, (int) $warehouse, $batch, $moveDate);
		if (!$nextAnchor['valid']) {
			return false;
		}
		if (!$nextAnchor['found']) {
			return null;
		}
		return $this->createInventoryRebaseMovement($move, $db, $conf, $user, $warehouse, $nextAnchor);
	}

	/**
	 * Compensate a late operational movement before an immutable mobile inventory anchor.
	 *
	 * @return bool
	 */
	protected function createInventoryRebaseMovement($move, $db, $conf, $user, $warehouse, array $anchor)
	{
		$moveId = (int) $move->id;
		$inventoryId = (int) $anchor['inv_id'];
		$inventoryCode = 'KPS-REBASE-'.$moveId;
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'stock_mouvement';
		$sql .= " WHERE origintype='".$db->escape(KreaProductsInventoryLedgerCalculator::REBASE_ORIGIN)."'";
		$sql .= ' AND fk_origin='.$inventoryId;
		$sql .= " AND inventorycode='".$db->escape($inventoryCode)."' LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		if ($db->fetch_object($resql)) {
			return true;
		}

		$sql = 'SELECT value FROM '.MAIN_DB_PREFIX.'stock_mouvement WHERE rowid='.$moveId;
		$resql = $db->query($sql);
		$row = $resql ? $db->fetch_object($resql) : false;
		if (!$row) {
			return false;
		}
		$quantity = (float) price2num(-((float) $row->value), 'MS');
		if ($quantity == 0.0) {
			return true;
		}

		$movement = new MouvementStock($db);
		$movement->setOrigin(KreaProductsInventoryLedgerCalculator::REBASE_ORIGIN, $inventoryId);
		$movement->context['kreaproducts_inventory_ledger'] = 1;
		$movementService = new KreaProductsInventoryMovementService($db, $conf);
		$result = $movementService->create(
			$movement,
			$user,
			(int) $move->product_id,
			(int) $warehouse,
			$quantity,
			0,
			'Inventory rebase '.$anchor['ref'],
			$inventoryCode,
			$anchor['date_inventory'],
			(string) ($move->batch ?? '')
		);
		if ($result <= 0) {
			dol_syslog(__METHOD__.' '.$movementService->error, LOG_ERR);
			return false;
		}

		return true;
	}

	protected function applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate)
	{
		$anchorBefore = $this->getLatestInventoryAnchorBefore($db, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''), $moveDate);
		if (!$anchorBefore['valid']) {
			return false;
		}
		if (!$anchorBefore['found']) {
			// History truncated: baseline unknown -> do nothing, let Dolibarr keep reel.
			return true;
		}

		return $this->recalculateStockFromLatestInventoryAnchor($db, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''), $anchorBefore);
	}

	protected function adjustForBackdatedInventory($move, $db, $warehouse)
	{
		$productId = (int) $move->product_id;
		$inventoryId = (int) $move->origin_id;
		$moveId = !empty($move->id) ? (int) $move->id : 0;
		$warehouse = (int) $warehouse;
		$batch = trim((string) ($move->batch ?? ''));

		if ($productId <= 0 || $inventoryId <= 0 || $warehouse <= 0 || $moveId <= 0) {
			return false;
		}

		// inventory date
		$resInv = $db->query('SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid = ' . $inventoryId);
		if (!$resInv) {
			dol_syslog(__METHOD__ . ' Error fetching inventory date: ' . $db->lasterror(), LOG_ERR);
			return null;
		}
		$rowInv = $db->fetch_object($resInv);
		if (!$rowInv || empty($rowInv->date_inventory)) {
			return false;
		}

		$invDate = $rowInv->date_inventory;
		if (is_numeric($invDate)) {
			$invDate = $db->idate($invDate);
		}

		$nextInvMove = $this->getNextInventoryMovementAfter($db, $productId, $warehouse, $batch, $invDate);
		if (!$nextInvMove['valid']) {
			return null;
		}
		if (!$nextInvMove['found']) {
			return false;
		}

		// Inventory movements are correction markers, not operational quantities.
		// Keep both inventory movements immutable and rebuild current stock from the later anchor.
		return $this->recalculateStockFromLatestInventoryAnchor($db, $productId, $warehouse, $batch) ? true : null;
	}

	protected function resolveWarehouseFromMove($move, $db)
	{
		$warehouse = 0;
		if (!empty($move->fk_entrepot)) {
			$warehouse = (int) $move->fk_entrepot;
		} elseif (!empty($move->warehouse_id)) {
			$warehouse = (int) $move->warehouse_id;
		} elseif (!empty($move->entrepot_id)) {
			$warehouse = (int) $move->entrepot_id;
		} elseif (!empty($move->fk_warehouse)) {
			$warehouse = (int) $move->fk_warehouse;
		}

		if ($warehouse <= 0 && !empty($move->id)) {
			$res = $db->query('SELECT fk_entrepot FROM ' . MAIN_DB_PREFIX . 'stock_mouvement WHERE rowid = ' . (int) $move->id);
			if ($res) {
				$row = $db->fetch_object($res);
				if ($row) {
					$warehouse = (int) $row->fk_entrepot;
				}
			}
		}

		return (int) $warehouse;
	}

	protected function resolveWarehouseFromMoveOrInventory($move, $db)
	{
		$warehouse = $this->resolveWarehouseFromMove($move, $db);
		if ($warehouse > 0) {
			return $warehouse;
		}

		if (!empty($move->origin_id) && !empty($move->product_id)) {
			$res = $db->query('SELECT fk_warehouse FROM ' . MAIN_DB_PREFIX . 'inventorydet WHERE fk_inventory = ' . (int) $move->origin_id . ' AND fk_product = ' . (int) $move->product_id . ' LIMIT 1');
			if ($res) {
				$row = $db->fetch_object($res);
				if ($row) {
					return (int) $row->fk_warehouse;
				}
			}
		}

		return 0;
	}

	protected function resolveInventoryAnchorQty($anchor)
	{
		if (!is_array($anchor) || empty($anchor['found'])) {
			return 0.0;
		}
		if (array_key_exists('corrected_counted_qty', $anchor) && $anchor['corrected_counted_qty'] !== null && $anchor['corrected_counted_qty'] !== '') {
			return (float) $anchor['corrected_counted_qty'];
		}
		if (array_key_exists('qty_view', $anchor) && $anchor['qty_view'] !== null && $anchor['qty_view'] !== '') {
			return (float) $anchor['qty_view'];
		}
		if (array_key_exists('qty_stock', $anchor) && $anchor['qty_stock'] !== null && $anchor['qty_stock'] !== '') {
			return (float) $anchor['qty_stock'];
		}
		return 0.0;
	}

	protected function batchCondMoveValue($db, $batch)
	{
		$batch = trim((string) $batch);
		return ($batch !== '')
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";
	}

	protected function batchCondSm($db, $batch)
	{
		$batch = trim((string) $batch);
		return ($batch !== '')
			? " AND sm.batch='" . $db->escape($batch) . "'"
			: " AND (sm.batch='' OR sm.batch IS NULL)";
	}

	/**
	 * Exclude inventory ledger corrections from operational movement sums.
	 *
	 * @param DoliDB $db     Database handler
	 * @param string $column Qualified or unqualified origin column
	 * @return string
	 */
	protected function operationalOriginCondition($db, $column = 'origintype')
	{
		$origins = array();
		foreach (KreaProductsInventoryLedgerCalculator::excludedMovementOrigins() as $origin) {
			$origins[] = "'".$db->escape($origin)."'";
		}
		return ' AND ('.$column.' IS NULL OR '.$column.' NOT IN ('.implode(', ', $origins).'))';
	}

	protected function getLatestInventoryAnchor($db, $productId, $warehouse, $batch)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0) {
			return array('found' => false, 'valid' => true);
		}

		$batch = trim((string) $batch);
		$condDet = ($batch !== '') ? " AND id.batch='" . $db->escape($batch) . "'" : " AND (id.batch='' OR id.batch IS NULL)";
		$condSm = $this->batchCondSm($db, $batch);

		$sql = 'SELECT i.date_inventory, id.qty_view, id.qty_stock, id.batch,'
			. ' (SELECT c.corrected_counted_qty FROM ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_correction c'
			. ' WHERE c.entity=i.entity AND c.fk_inventorydet=id.rowid AND c.status=1 ORDER BY c.rowid DESC LIMIT 1) AS corrected_counted_qty'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_adjustment a ON a.entity=i.entity AND a.fk_inventorydet=id.rowid AND a.status=1'
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
			. ' AND (sm.rowid IS NOT NULL OR a.rowid IS NOT NULL)'
			. ' ORDER BY i.date_inventory DESC, i.rowid DESC LIMIT 1';

		$res = $db->query($sql);
		if (!$res) {
			return array('found' => false, 'valid' => false);
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			return array('found' => false, 'valid' => true);
		}

		return array(
			'found' => true,
			'valid' => true,
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
			'qty_stock' => $row->qty_stock,
			'corrected_counted_qty' => $row->corrected_counted_qty,
			'batch' => $row->batch,
		);
	}

	protected function getLatestInventoryAnchorBefore($db, $productId, $warehouse, $batch, $beforeDate)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0 || empty($beforeDate)) {
			return array('found' => false, 'valid' => true);
		}
		if (is_numeric($beforeDate)) {
			$beforeDate = $db->idate($beforeDate);
		}

		$batch = trim((string) $batch);
		$condDet = ($batch !== '') ? " AND id.batch='" . $db->escape($batch) . "'" : " AND (id.batch='' OR id.batch IS NULL)";
		$condSm = $this->batchCondSm($db, $batch);

		$sql = 'SELECT i.date_inventory, id.qty_view, id.qty_stock, id.batch,'
			. ' (SELECT c.corrected_counted_qty FROM ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_correction c'
			. ' WHERE c.entity=i.entity AND c.fk_inventorydet=id.rowid AND c.status=1 ORDER BY c.rowid DESC LIMIT 1) AS corrected_counted_qty'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_adjustment a ON a.entity=i.entity AND a.fk_inventorydet=id.rowid AND a.status=1'
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. " AND i.date_inventory <= '" . $db->escape($beforeDate) . "'"
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
			. ' AND (sm.rowid IS NOT NULL OR a.rowid IS NOT NULL)'
			. ' ORDER BY i.date_inventory DESC, i.rowid DESC LIMIT 1';

		$res = $db->query($sql);
		if (!$res) {
			return array('found' => false, 'valid' => false);
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			return array('found' => false, 'valid' => true);
		}

		return array(
			'found' => true,
			'valid' => true,
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
			'qty_stock' => $row->qty_stock,
			'corrected_counted_qty' => $row->corrected_counted_qty,
			'batch' => $row->batch,
		);
	}

	protected function getNextInventoryMovementAfter($db, $productId, $warehouse, $batch, $afterDate)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0 || empty($afterDate)) {
			return array('found' => false, 'valid' => true);
		}
		if (is_numeric($afterDate)) {
			$afterDate = $db->idate($afterDate);
		}

		$batch = trim((string) $batch);
		$cond = ($batch !== '') ? " AND batch='" . $db->escape($batch) . "'" : " AND (batch='' OR batch IS NULL)";

		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "stock_mouvement WHERE origintype='inventory' AND fk_product=" . $productId . ' AND fk_entrepot=' . $warehouse . $cond
			. " AND datem > '" . $db->escape($afterDate) . "' ORDER BY datem ASC, rowid ASC LIMIT 1";

		$res = $db->query($sql);
		if (!$res) {
			return array('found' => false, 'valid' => false);
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			return array('found' => false, 'valid' => true);
		}

		return array('found' => true, 'valid' => true, 'rowid' => (int) $row->rowid);
	}

	protected function getNextInventoryAnchorAfter($db, $productId, $warehouse, $batch, $afterDate)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0 || empty($afterDate)) {
			return array('found' => false, 'valid' => true);
		}
		if (is_numeric($afterDate)) {
			$afterDate = $db->idate($afterDate);
		}

		$batch = trim((string) $batch);
		$condDet = ($batch !== '') ? " AND id.batch='" . $db->escape($batch) . "'" : " AND (id.batch='' OR id.batch IS NULL)";
		$condSm = $this->batchCondSm($db, $batch);

		$sql = 'SELECT i.rowid AS inv_id, i.ref, i.date_inventory, id.qty_view, id.qty_stock, id.batch,'
			. ' (SELECT c.corrected_counted_qty FROM ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_correction c'
			. ' WHERE c.entity=i.entity AND c.fk_inventorydet=id.rowid AND c.status=1 ORDER BY c.rowid DESC LIMIT 1) AS corrected_counted_qty,'
			. ' sm.rowid AS fk_movement, a.rowid AS fk_adjustment'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' LEFT JOIN ' . MAIN_DB_PREFIX . 'kreaproducts_inventory_adjustment a ON a.entity=i.entity AND a.fk_inventorydet=id.rowid AND a.status=1'
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. " AND i.date_inventory > '" . $db->escape($afterDate) . "'"
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
			. ' AND (sm.rowid IS NOT NULL OR a.rowid IS NOT NULL)'
			. ' ORDER BY i.date_inventory ASC, i.rowid ASC LIMIT 1';

		$res = $db->query($sql);
		if (!$res) {
			return array('found' => false, 'valid' => false);
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			return array('found' => false, 'valid' => true);
		}

		return array(
			'found' => true,
			'valid' => true,
			'inv_id' => (int) $row->inv_id,
			'ref' => (string) $row->ref,
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
			'qty_stock' => $row->qty_stock,
			'corrected_counted_qty' => $row->corrected_counted_qty,
			'batch' => $row->batch,
			'fk_movement' => (int) $row->fk_movement,
			'ledger_managed' => !empty($row->fk_adjustment),
		);
	}

	protected function recalculateStockFromLatestInventoryAnchor($db, $productId, $warehouse, $batch, $anchorOverride = null)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$batch = trim((string) $batch);

		$anchor = ($anchorOverride !== null) ? $anchorOverride : $this->getLatestInventoryAnchor($db, $productId, $warehouse, $batch);
		if (!$anchor['valid'] || !$anchor['found']) {
			// No valid anchor -> baseline unknown -> do nothing
			return $anchor['valid'];
		}

		if ($batch === '' && !empty($anchor['batch'])) {
			$batch = trim((string) $anchor['batch']);
		}

		$anchorQty = $this->resolveInventoryAnchorQty($anchor);
		$anchorDate = $anchor['date_inventory'];
		if (is_numeric($anchorDate)) {
			$anchorDate = $db->idate($anchorDate);
		}

		$condMove = $this->batchCondMoveValue($db, $batch);
		$sqlMoved = 'SELECT COALESCE(SUM(value),0) AS moved FROM ' . MAIN_DB_PREFIX . 'stock_mouvement'
			. ' WHERE fk_product = ' . $productId
			. ' AND fk_entrepot = ' . $warehouse
			. $this->operationalOriginCondition($db)
			. $condMove
			. " AND datem > '" . $db->escape($anchorDate) . "'";

		$resMoved = $db->query($sqlMoved);
		if (!$resMoved) {
			return false;
		}
		$row = $db->fetch_object($resMoved);
		$moved = $row ? (float) $row->moved : 0.0;

		return $this->setWarehouseReel($db, $productId, $warehouse, $batch, $anchorQty + $moved);
	}

	protected function recalculateStockFromInventoryAnchor($db, $inventoryId, $productId, $warehouse, $batch)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$inventoryId = (int) $inventoryId;
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$batch = trim((string) $batch);

		// This inventory is a valid anchor only if its inventory stock movement exists.
		$cond = ($batch !== '') ? " AND batch='" . $db->escape($batch) . "'" : " AND (batch='' OR batch IS NULL)";
		$resChk = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . "stock_mouvement WHERE origintype='inventory' AND fk_origin=" . $inventoryId . ' AND fk_product=' . $productId . ' AND fk_entrepot=' . $warehouse . $cond . ' LIMIT 1');
		if (!$resChk || !$db->fetch_object($resChk)) {
			return (bool) $resChk;
		}

		$condDet = ($batch !== '') ? " AND id.batch='" . $db->escape($batch) . "'" : " AND (id.batch='' OR id.batch IS NULL)";
		$sql = 'SELECT i.date_inventory, id.qty_view, id.batch FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' WHERE i.rowid = ' . $inventoryId
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
			. ' LIMIT 1';

		$res = $db->query($sql);
		if (!$res) {
			return false;
		}
		$row = $db->fetch_object($res);
		if (!$row || $row->qty_view === null || $row->qty_view === '') {
			return true;
		}

		$anchorDate = $row->date_inventory;
		if (is_numeric($anchorDate)) {
			$anchorDate = $db->idate($anchorDate);
		}
		if ($batch === '' && !empty($row->batch)) {
			$batch = trim((string) $row->batch);
		}
		$anchorQty = (float) $row->qty_view;

		$condMove = $this->batchCondMoveValue($db, $batch);
		$sqlMoved = 'SELECT COALESCE(SUM(value),0) AS moved FROM ' . MAIN_DB_PREFIX . 'stock_mouvement'
			. ' WHERE fk_product = ' . $productId
			. ' AND fk_entrepot = ' . $warehouse
			. $this->operationalOriginCondition($db)
			. $condMove
			. " AND datem > '" . $db->escape($anchorDate) . "'";

		$resMoved = $db->query($sqlMoved);
		if (!$resMoved) {
			return false;
		}
		$movedRow = $db->fetch_object($resMoved);
		$moved = $movedRow ? (float) $movedRow->moved : 0.0;

		return $this->setWarehouseReel($db, $productId, $warehouse, $batch, $anchorQty + $moved);
	}

	protected function setWarehouseReel($db, $productId, $warehouse, $batch, $reel)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$batch = trim((string) $batch);
		$reel = (float) $reel;

		if ($productId <= 0 || $warehouse <= 0) {
			return false;
		}

		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlUpBatch = 'UPDATE ' . MAIN_DB_PREFIX . 'product_batch pb'
				. ' JOIN ' . MAIN_DB_PREFIX . 'product_stock ps ON ps.rowid = pb.fk_product_stock'
				. ' SET pb.qty = ' . price2num($reel, 'MT')
				. ' WHERE ps.fk_product = ' . $productId
				. ' AND ps.fk_entrepot = ' . $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			if (!$db->query($sqlUpBatch)) {
				return false;
			}

			$sqlTotal = 'SELECT COALESCE(SUM(pb.qty),0) AS total'
				. ' FROM ' . MAIN_DB_PREFIX . 'product_batch pb'
				. ' JOIN ' . MAIN_DB_PREFIX . 'product_stock ps ON ps.rowid = pb.fk_product_stock'
				. ' WHERE ps.fk_product = ' . $productId
				. ' AND ps.fk_entrepot = ' . $warehouse;
			$resTotal = $db->query($sqlTotal);
			$total = $reel;
			if ($resTotal) {
				$t = $db->fetch_object($resTotal);
				$total = $t ? (float) $t->total : $reel;
			}

			if (!$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product_stock SET reel = ' . price2num($total, 'MT') . ' WHERE fk_product = ' . $productId . ' AND fk_entrepot = ' . $warehouse)) {
				return false;
			}
		} else {
			if (!$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product_stock SET reel = ' . price2num($reel, 'MT') . ' WHERE fk_product = ' . $productId . ' AND fk_entrepot = ' . $warehouse)) {
				return false;
			}
		}

		return (bool) $db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM ' . MAIN_DB_PREFIX . 'product_stock ps WHERE ps.fk_product = ' . $productId . ') WHERE rowid = ' . $productId);
	}
}
