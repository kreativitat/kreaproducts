<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

dol_include_once('/kreaproducts/class/ProductUpdater.class.php');
dol_include_once('/kreaproducts/class/productdismantlecontroller.class.php');
require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';

/**
 * KreaProductsStockMovementService
 *
 * Full-featured version with robust stock rules:
 * - Supports inventory moves (including backdated inventories).
 * - Supports supplier invoice moves (including backdated purchases).
 * - Keeps later inventories consistent by adjusting the next inventory movement when needed.
 * - Handles history-truncated systems safely (old stock_mouvement deleted) by refusing to rebuild
 *   reel from sums when no valid inventory anchor exists.
 *
 * IMPORTANT: A "valid inventory anchor" requires BOTH:
 * - inventory.status = RECORDED
 * - and an existing stock_mouvement row with origintype='inventory' for the same inventory (fk_origin)
 *   and same product + warehouse (+ batch).
 * This prevents phantom anchors when inventory rows exist but their movements were deleted.
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
			if ($move->origintype === 'invoice_supplier') {
				$this->shiftSupplierInvoiceMoveToConfiguredTime($move, $db, $conf);
			}
			if ($move->origintype === 'inventory') {
				$this->alignInventoryMoveToDateInventory($move, $db);
			}
		}

		// Then: stock routines
		if ($move->origintype === 'inventory' && $applyMovementDates) {
			$this->recalculateAfterInventory($move, $db);
		}

		if ($move->origintype === 'invoice_supplier') {
			if ($applyMovementDates) {
				$this->recalculateAfterSupplierInvoice($move, $db);
			}

			// Dismantle routines are independent from the stock rebuild logic
			if ($skipDismantle) {
				dol_syslog(__METHOD__ . ' skip dismantle for movement id=' . (int) $move->id . ' label=' . (string) $move->label, LOG_DEBUG);
			} else {
				$this->updateDismantleChildrenBeforeTrigger($move, $db, $user);
				$this->dismantleIfNeeded($move, $db, $user);
			}
		}

		return 1;
	}

	protected function isDismantleMovement($move)
	{
		if (empty($move->label)) {
			return false;
		}

		$label = (string) $move->label;
		return (strpos($label, 'Consume for MO') !== false || strpos($label, 'Produce for MO') !== false);
	}

	protected function shiftSupplierInvoiceMoveToConfiguredTime($move, $db, $conf)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($move->origin_id)) {
			return;
		}

		$time = trim($conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME ?? '10:00');
		if (!preg_match('/^[0-9]{2}:[0-9]{2}(:[0-9]{2})?$/', $time)) {
			$time = '10:00';
		}
		if (strlen($time) === 5) {
			$time .= ':00';
		}

		$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid=' . (int) $move->origin_id;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error querying supplier invoice date: ' . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row || empty($row->datef)) {
			return;
		}

		$invoiceDate = $row->datef;
		if (is_numeric($invoiceDate)) {
			$invoiceDate = $db->idate($invoiceDate);
		}
		if (empty($invoiceDate)) {
			return;
		}

		$new = $invoiceDate;
		if (strlen($invoiceDate) <= 10) {
			$new = $invoiceDate . ' ' . $time;
		} else {
			$timePart = substr($invoiceDate, 11, 8);
			if ($timePart === '00:00:00') {
				$new = substr($invoiceDate, 0, 10) . ' ' . $time;
			}
		}

		$current = $move->datem;
		if (is_numeric($current)) {
			$current = $db->idate($current);
		}

		if (!empty($move->id) && $new !== $current) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . "stock_mouvement SET datem='" . $db->escape($new) . "' WHERE rowid=" . (int) $move->id;
			$db->query($upd);
		}

		$move->datem = $new;
	}

	protected function alignInventoryMoveToDateInventory($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$inventoryId = (int) $move->origin_id;
		if ($inventoryId <= 0 || empty($move->id)) {
			return;
		}

		$sql = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid = ' . $inventoryId;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error querying inventory date: ' . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row || empty($row->date_inventory)) {
			return;
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
			$db->query($upd);
			$move->datem = $dateInventory;
		}
	}

	protected function updateDismantleChildrenBeforeTrigger($move, $db, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$dismantle = new ProductDismantleController($db);

		if (!$dismantle->productInDismantleCategory($move->product_id)) {
			return;
		}

		$bomId = $dismantle->findBom($move->product_id);
		if (!$bomId) {
			return;
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
			return;
		}

		$children = array();
		while ($obj = $db->fetch_object($res)) {
			if (!empty($obj->child)) {
				$children[(int) $obj->child] = true;
			}
		}
		$db->free($res);

		foreach (array_keys($children) as $childId) {
			ProductUpdater::updateProductCostPrice((int) $childId, true);
		}
	}

	protected function dismantleIfNeeded($move, $db, $user = null)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$d = new ProductDismantleController($db);
		if (!$d->productInDismantleCategory($move->product_id)) {
			return;
		}
		$bom = $d->findBom($move->product_id);
		if (!$bom) {
			return;
		}

		$d->produceAndConsume(
			$bom,
			$move->qty,
			$move->price,
			$move->label,
			$move->origin_id,
			$move->origintype,
			dol_stringtotime($move->datem),
			$user
		);
	}

	protected function recalculateAfterInventory($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$warehouse = $this->resolveWarehouseFromMoveOrInventory($move, $db);
		if ($warehouse <= 0) {
			dol_syslog(__METHOD__ . ' Cannot determine warehouse for inventory recalc', LOG_ERR);
			return;
		}

		// If this inventory is inserted before an existing later inventory, adjust the next inventory movement.
		if ($this->adjustForBackdatedInventory($move, $db, $warehouse)) {
			return;
		}

		// Normal inventory: rebuild from this anchor only if this inventory has an inventory movement row.
		$this->recalculateStockFromInventoryAnchor($db, (int) $move->origin_id, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''));
	}

	protected function recalculateAfterSupplierInvoice($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$moveDate = $move->datem;
		if (empty($moveDate)) {
			return;
		}
		if (is_numeric($moveDate)) {
			$moveDate = $db->idate($moveDate);
		}

		$warehouse = $this->resolveWarehouseFromMove($move, $db);
		if ($warehouse <= 0) {
			dol_syslog(__METHOD__ . ' Cannot determine warehouse for supplier recalc', LOG_ERR);
			return;
		}

		if ($this->applySupplierInvoiceCase3($move, $db, $warehouse, $moveDate)) {
			return;
		}

		$this->applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate);
	}

	protected function applySupplierInvoiceCase3($move, $db, $warehouse, $moveDate)
	{
		$batch = (string) ($move->batch ?? '');
		$nextAnchor = $this->getNextInventoryAnchorAfter($db, (int) $move->product_id, (int) $warehouse, $batch, $moveDate);
		if (!$nextAnchor['valid']) {
			return true;
		}
		if (!$nextAnchor['found']) {
			return false;
		}

		return $this->adjustNextInventoryMovementForBackdatedNonInventoryMove(
			$db,
			(int) $move->id,
			(int) $move->product_id,
			(int) $warehouse,
			$batch,
			(int) $nextAnchor['fk_movement']
		);
	}

	protected function applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate)
	{
		$anchorBefore = $this->getLatestInventoryAnchorBefore($db, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''), $moveDate);
		if (!$anchorBefore['valid']) {
			return;
		}
		if (!$anchorBefore['found']) {
			// History truncated: baseline unknown -> do nothing, let Dolibarr keep reel.
			return;
		}

		$this->recalculateStockFromLatestInventoryAnchor($db, (int) $move->product_id, (int) $warehouse, (string) ($move->batch ?? ''), $anchorBefore);
	}

	protected function adjustNextInventoryMovementForBackdatedNonInventoryMove($db, $moveId, $productId, $warehouse, $batch, $nextInventoryMoveId)
	{
		$moveId = (int) $moveId;
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$nextInventoryMoveId = (int) $nextInventoryMoveId;
		$batch = trim((string) $batch);

		if ($moveId <= 0 || $productId <= 0 || $warehouse <= 0 || $nextInventoryMoveId <= 0) {
			return true;
		}

		$res = $db->query('SELECT value, batch FROM ' . MAIN_DB_PREFIX . 'stock_mouvement WHERE rowid = ' . $moveId);
		if (!$res) {
			dol_syslog(__METHOD__ . ' Error fetching movement value: ' . $db->lasterror(), LOG_ERR);
			return true;
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			return true;
		}

		$val = (float) $row->value;
		if ($batch === '' && isset($row->batch)) {
			$batch = (string) $row->batch;
		}

		if ($val != 0.0) {
			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement SET value = value - ' . price2num($val, 'MT') . ' WHERE rowid = ' . $nextInventoryMoveId);
		}

		// Truth-set only if a valid anchor exists (function returns early if none).
		$this->recalculateStockFromLatestInventoryAnchor($db, $productId, $warehouse, $batch);

		return true;
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
			return true;
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
			return true;
		}
		if (!$nextInvMove['found']) {
			return false;
		}

		// counted qty: prefer qty_view, fallback to qty_stock if needed
		$condDet = $this->batchCondDet($db, $batch);
		$sqlLine = 'SELECT qty_view, qty_stock, batch FROM ' . MAIN_DB_PREFIX . 'inventorydet id'
			. ' WHERE id.fk_inventory = ' . $inventoryId
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
			. ' LIMIT 1';
		$resLine = $db->query($sqlLine);
		if (!$resLine) {
			dol_syslog(__METHOD__ . ' Error fetching inventory line: ' . $db->lasterror(), LOG_ERR);
			return true;
		}
		$line = $db->fetch_object($resLine);
		if (!$line) {
			return true;
		}

		if ($batch === '' && !empty($line->batch)) {
			$batch = trim((string) $line->batch);
		}

		$hasView = ($line->qty_view !== null && $line->qty_view !== '');
		$hasStock = ($line->qty_stock !== null && $line->qty_stock !== '');
		if (!$hasView && !$hasStock) {
			return true;
		}
		$counted = $hasView ? (float) $line->qty_view : (float) $line->qty_stock;

		$resMove = $db->query('SELECT value FROM ' . MAIN_DB_PREFIX . 'stock_mouvement WHERE rowid = ' . $moveId);
		if (!$resMove) {
			return true;
		}
		$rowMove = $db->fetch_object($resMove);
		$Vold = $rowMove ? (float) $rowMove->value : 0.0;

		// previous valid anchor (may be none)
		$prev = $this->getLatestInventoryAnchorBeforeExcluding($db, $productId, $warehouse, $batch, $invDate, $inventoryId);
		if (!$prev['valid']) {
			return true;
		}
		$prevDate = $prev['found'] ? $prev['date_inventory'] : '';
		if (is_numeric($prevDate)) {
			$prevDate = $db->idate($prevDate);
		}
		$prevQty = $prev['found'] ? $this->resolveInventoryAnchorQty($prev) : 0.0;

		// expected = prevQty + sum non-inventory moves in (prevDate, invDate]
		$condMove = $this->batchCondMoveValue($db, $batch);
		$sqlMoved = 'SELECT COALESCE(SUM(value),0) AS moved FROM ' . MAIN_DB_PREFIX . 'stock_mouvement'
			. ' WHERE fk_product = ' . $productId
			. ' AND fk_entrepot = ' . $warehouse
			. " AND origintype <> 'inventory'"
			. $condMove;
		if (!empty($prevDate)) {
			$sqlMoved .= " AND datem > '" . $db->escape($prevDate) . "'";
		}
		$sqlMoved .= " AND datem <= '" . $db->escape($invDate) . "'";
		$resMoved = $db->query($sqlMoved);
		if (!$resMoved) {
			return true;
		}
		$movedRow = $db->fetch_object($resMoved);
		$moved = $movedRow ? (float) $movedRow->moved : 0.0;

		$expected = $prevQty + $moved;
		$Vnew = $counted - $expected;

		if ($Vnew !== $Vold) {
			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement SET value = ' . price2num($Vnew, 'MT') . ' WHERE rowid = ' . $moveId);
		}

		$delta = $Vnew - $Vold;
		if ($delta != 0.0) {
			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement SET value = value - ' . price2num($delta, 'MT') . ' WHERE rowid = ' . (int) $nextInvMove['rowid']);
		}

		$this->recalculateStockFromLatestInventoryAnchor($db, $productId, $warehouse, $batch);
		return true;
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
		if (array_key_exists('qty_view', $anchor) && $anchor['qty_view'] !== null && $anchor['qty_view'] !== '') {
			return (float) $anchor['qty_view'];
		}
		if (array_key_exists('qty_stock', $anchor) && $anchor['qty_stock'] !== null && $anchor['qty_stock'] !== '') {
			return (float) $anchor['qty_stock'];
		}
		return 0.0;
	}

	protected function batchCondDet($db, $batch)
	{
		$batch = trim((string) $batch);
		return ($batch !== '')
			? " AND id.batch='" . $db->escape($batch) . "'"
			: " AND (id.batch='' OR id.batch IS NULL)";
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

		$sql = 'SELECT i.date_inventory, id.qty_view, id.qty_stock, id.batch'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
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

		$sql = 'SELECT i.date_inventory, id.qty_view, id.qty_stock, id.batch'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. " AND i.date_inventory <= '" . $db->escape($beforeDate) . "'"
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
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
			'batch' => $row->batch,
		);
	}

	protected function getLatestInventoryAnchorBeforeExcluding($db, $productId, $warehouse, $batch, $beforeDate, $excludeInventoryId)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$excludeInventoryId = (int) $excludeInventoryId;
		if ($productId <= 0 || $warehouse <= 0 || empty($beforeDate)) {
			return array('found' => false, 'valid' => true);
		}
		if (is_numeric($beforeDate)) {
			$beforeDate = $db->idate($beforeDate);
		}

		$batch = trim((string) $batch);
		$condDet = ($batch !== '') ? " AND id.batch='" . $db->escape($batch) . "'" : " AND (id.batch='' OR id.batch IS NULL)";
		$condSm = $this->batchCondSm($db, $batch);

		$sql = 'SELECT i.date_inventory, id.qty_view, id.qty_stock, id.batch'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. ' AND i.rowid <> ' . $excludeInventoryId
			. " AND i.date_inventory < '" . $db->escape($beforeDate) . "'"
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
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

		$sql = 'SELECT i.rowid AS inv_id, i.date_inventory, id.qty_view, id.qty_stock, id.batch, sm.rowid AS fk_movement'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory i'
			. ' JOIN ' . MAIN_DB_PREFIX . 'inventorydet id ON id.fk_inventory = i.rowid'
			. ' JOIN ' . MAIN_DB_PREFIX . "stock_mouvement sm ON sm.origintype='inventory' AND sm.fk_origin=i.rowid AND sm.fk_product=id.fk_product AND sm.fk_entrepot=id.fk_warehouse" . $condSm
			. ' WHERE i.status = ' . ((int) Inventory::STATUS_RECORDED)
			. ' AND i.entity IN (' . getEntity('inventory') . ')'
			. " AND i.date_inventory > '" . $db->escape($afterDate) . "'"
			. ' AND id.fk_product = ' . $productId
			. ' AND id.fk_warehouse = ' . $warehouse
			. $condDet
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
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
			'qty_stock' => $row->qty_stock,
			'batch' => $row->batch,
			'fk_movement' => (int) $row->fk_movement,
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
			return;
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
			. " AND origintype <> 'inventory'"
			. $condMove
			. " AND datem > '" . $db->escape($anchorDate) . "'";

		$resMoved = $db->query($sqlMoved);
		if (!$resMoved) {
			return;
		}
		$row = $db->fetch_object($resMoved);
		$moved = $row ? (float) $row->moved : 0.0;

		$this->setWarehouseReel($db, $productId, $warehouse, $batch, $anchorQty + $moved);
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
			return;
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
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row || $row->qty_view === null || $row->qty_view === '') {
			return;
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
			. " AND origintype <> 'inventory'"
			. $condMove
			. " AND datem > '" . $db->escape($anchorDate) . "'";

		$resMoved = $db->query($sqlMoved);
		if (!$resMoved) {
			return;
		}
		$movedRow = $db->fetch_object($resMoved);
		$moved = $movedRow ? (float) $movedRow->moved : 0.0;

		$this->setWarehouseReel($db, $productId, $warehouse, $batch, $anchorQty + $moved);
	}

	protected function setWarehouseReel($db, $productId, $warehouse, $batch, $reel)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		$batch = trim((string) $batch);
		$reel = (float) $reel;

		if ($productId <= 0 || $warehouse <= 0) {
			return;
		}

		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlUpBatch = 'UPDATE ' . MAIN_DB_PREFIX . 'product_batch pb'
				. ' JOIN ' . MAIN_DB_PREFIX . 'product_stock ps ON ps.rowid = pb.fk_product_stock'
				. ' SET pb.qty = ' . price2num($reel, 'MT')
				. ' WHERE ps.fk_product = ' . $productId
				. ' AND ps.fk_entrepot = ' . $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			$db->query($sqlUpBatch);

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

			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product_stock SET reel = ' . price2num($total, 'MT') . ' WHERE fk_product = ' . $productId . ' AND fk_entrepot = ' . $warehouse);
		} else {
			$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product_stock SET reel = ' . price2num($reel, 'MT') . ' WHERE fk_product = ' . $productId . ' AND fk_entrepot = ' . $warehouse);
		}

		$db->query('UPDATE ' . MAIN_DB_PREFIX . 'product SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM ' . MAIN_DB_PREFIX . 'product_stock ps WHERE ps.fk_product = ' . $productId . ') WHERE rowid = ' . $productId);
	}
}
