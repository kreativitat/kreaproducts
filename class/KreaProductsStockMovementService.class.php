<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/productdismantlecontroller.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';

class KreaProductsStockMovementService
{
	public function handleStockMovement($move, $db, $conf, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$applyMovementDates = !empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA);
		$skipDismantle = $this->isDismantleMovement($move);

		// First: adjust timestamps on this move (optional)
		if ($applyMovementDates) {
			if ($move->origintype === 'invoice_supplier') {
				$this->shiftSupplierInvoiceMoveToNoon($move, $db);
			}
			if ($move->origintype === 'inventory') {
				$this->alignInventoryMoveToDateInventory($move, $db);
			}
		}

		// Then: full routines
		if ($move->origintype === 'inventory' && $applyMovementDates) {
			// Recompute stock levels after this inventory move
			$this->recalculateAfterInventory($move, $db);
		}
		if ($move->origintype === 'invoice_supplier') {
			if ($applyMovementDates) {
				// Recompute stock levels after this supplier-invoice move
				$this->recalculateAfterSupplierInvoice($move, $db);
			}

			if ($skipDismantle) {
				dol_syslog(__METHOD__ . " skip dismantle for movement id=" . (int) $move->id . " label=" . $move->label, LOG_DEBUG);
			} else {
				// Before dismantling, refresh cost prices of BOM children (including nested)
				$this->updateDismantleChildrenBeforeTrigger($move, $db, $user);

				// Then run any BOM dismantle logic
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

	protected function shiftSupplierInvoiceMoveToNoon($move, $db)
	{
		global $conf;
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($move->origin_id)) {
			return;
		}

		// Read configured time (HH:MM or HH:MM:SS), fallback to 10:00:00
		$time = trim($conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME ?? '10:00');
		if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
			$time = '10:00';
		}
		if (strlen($time) === 5) {
			$time .= ':00';
		}

		$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid=' . (int) $move->origin_id;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog("Error querying supplier invoice date: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row || empty($row->datef)) {
			dol_syslog("No supplier invoice date found for id=" . (int) $move->origin_id, LOG_ERR);
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

		if ($new !== $current && !empty($move->id)) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement'
				. ' SET datem=\'' . $db->escape($new) . '\''
				. ' WHERE rowid=' . (int) $move->id;
			if (!$db->query($upd)) {
				dol_syslog("Error aligning supplier move date: " . $db->lasterror(), LOG_ERR);
			}
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

		$sql = "SELECT date_inventory FROM " . MAIN_DB_PREFIX . "inventory WHERE rowid = " . $inventoryId;
		$res = $db->query($sql);
		if (! $res) {
			dol_syslog("Error querying inventory date: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (! $row || empty($row->date_inventory)) {
			return;
		}

		$dateInventory = $row->date_inventory;
		if (is_numeric($dateInventory)) {
			$dateInventory = $db->idate($dateInventory);
		}

		if ($move->datem !== $dateInventory) {
			$upd = "UPDATE " . MAIN_DB_PREFIX . "stock_mouvement"
				. " SET datem = '" . $db->escape($dateInventory) . "'"
				. " WHERE rowid = " . (int) $move->id;
			if (! $db->query($upd)) {
				dol_syslog("Error aligning inventory movement date: " . $db->lasterror(), LOG_ERR);
			}
			$move->datem = $dateInventory;
		}
	}

	/**
	 * Before running dismantle, refresh children cost trees for dismantle BOM products
	 */
	protected function updateDismantleChildrenBeforeTrigger($move, $db, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$dismantle = new ProductDismantleController($db);

		// Only proceed for products that are part of the dismantle category and have a dismantle BOM
		if (!$dismantle->productInDismantleCategory($move->product_id)) {
			return;
		}

		$bomId = $dismantle->findBom($move->product_id);
		if (!$bomId) {
			return;
		}

        $sql = "SELECT DISTINCT COALESCE(bl.fk_product, cb.fk_product) AS child
                FROM " . MAIN_DB_PREFIX . "bom_bom b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON bl.fk_bom = b.rowid
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                WHERE b.rowid = " . (int) $bomId . "
                AND b.entity IN (0," . getEntity('bom') . ")
                AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))";

		$res = $db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__ . " Error loading dismantle BOM children: " . $db->lasterror(), LOG_ERR);
			return;
		}

		$children = [];
		while ($obj = $db->fetch_object($res)) {
			if (!empty($obj->child)) {
				$children[(int)$obj->child] = true;
			}
		}
		$db->free($res);

		if (empty($children)) {
			return;
		}

		// Update each child (and its nested tree) using the existing ProductUpdater logic
		foreach (array_keys($children) as $childId) {
			ProductUpdater::updateProductCostPrice((int)$childId, true);
		}
	}

	protected function dismantleIfNeeded($move, $db, $user = null)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$d    = new ProductDismantleController($db);
		if (!$d->productInDismantleCategory($move->product_id)) {
			return;
		}
		if (!($bom = $d->findBom($move->product_id))) {
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

		// Always anchor to the latest recorded inventory for this product/warehouse/batch.
		$warehouse = 0;
		if (!empty($move->fk_entrepot)) {
			$warehouse = (int)$move->fk_entrepot;
		} elseif (!empty($move->fk_warehouse)) {
			$warehouse = (int)$move->fk_warehouse;
		} else {
			$sqlWh = 'SELECT fk_warehouse FROM ' . MAIN_DB_PREFIX . 'inventorydet
                        WHERE fk_inventory=' . (int)$move->origin_id . '
                          AND fk_product=' . (int)$move->product_id . ' LIMIT 1';
			$resWh = $db->query($sqlWh);
			if ($resWh) {
				$w = $db->fetch_object($resWh);
				if ($w) {
					$warehouse = (int)$w->fk_warehouse;
				}
			}
		}
		if ($warehouse <= 0) {
			dol_syslog("Cannot determine warehouse for inventory recalc", LOG_ERR);
			return;
		}
		// If this inventory is inserted before an existing one, adjust the next inventory movement only.
		if ($this->adjustNextInventoryMovementForInsertedInventory($move, $db, $warehouse)) {
			return;
		}
		$this->recalculateStockFromInventoryAnchor($db, (int) $move->origin_id, (int) $move->product_id, $warehouse, $move->batch ?? '');
	}

	protected function recalculateAfterSupplierInvoice($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($move->datem)) {
			dol_syslog("Missing supplier move timestamp for recalc", LOG_ERR);
			return;
		}
		$moveDate = $move->datem;
		if (is_numeric($moveDate)) {
			$moveDate = $db->idate($moveDate);
		}

		// 2) Determine warehouse
		$warehouse = 0;
		if (!empty($move->fk_entrepot)) {
			$warehouse = (int)$move->fk_entrepot;
		} elseif (!empty($move->warehouse_id)) {
			$warehouse = (int)$move->warehouse_id;
		} elseif (!empty($move->entrepot_id)) {
			$warehouse = (int)$move->entrepot_id;
		} elseif (!empty($move->fk_warehouse)) {
			$warehouse = (int)$move->fk_warehouse;
		}
		if (!empty($move->id) && ($warehouse <= 0 || empty($move->product_id) || empty($move->batch))) {
			$sqlMove = "SELECT fk_entrepot, fk_product, value, batch FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . (int) $move->id;
			$resMove = $db->query($sqlMove);
			if ($resMove) {
				$rowMove = $db->fetch_object($resMove);
				if ($rowMove) {
					if ($warehouse <= 0) {
						$warehouse = (int) $rowMove->fk_entrepot;
					}
					if (empty($move->product_id)) {
						$move->product_id = (int) $rowMove->fk_product;
					}
					if (empty($move->batch) && isset($rowMove->batch)) {
						$move->batch = $rowMove->batch;
					}
				}
			}
		}
		if ($warehouse <= 0) {
			dol_syslog("Cannot determine warehouse for supplier recalc", LOG_ERR);
			return;
		}

		if ($this->applySupplierInvoiceCase3($move, $db, $warehouse, $moveDate)) {
			return;
		}

		$this->applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate);
	}

	protected function applySupplierInvoiceCase3($move, $db, $warehouse, $moveDate)
	{
		// Case 3: invoice before a later inventory -> adjust next inventory movement only.
		$batch = $move->batch ?? '';
		$nextAnchor = $this->getNextInventoryAnchorAfter($db, (int) $move->product_id, $warehouse, $batch, $moveDate);
		if (! $nextAnchor['valid']) {
			return true;
		}
		if (! $nextAnchor['found']) {
			return false;
		}

		$moveTs = dol_stringtotime($moveDate);
		$nextInvDate = $nextAnchor['date_inventory'] ?? '';
		if (is_numeric($nextInvDate)) {
			$nextInvDate = $db->idate($nextInvDate);
		}
		$nextInvTs = $nextInvDate !== '' ? dol_stringtotime($nextInvDate) : 0;
		if ($moveTs > 0 && $nextInvTs > 0 && $nextInvTs <= $moveTs) {
			return false;
		}

		$nextMoveId = (int) $nextAnchor['fk_movement'];
		if ($nextMoveId > 0 && $moveTs > 0) {
			$sqlNextMoveDate = "SELECT datem, origintype FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . $nextMoveId;
			$resNextMoveDate = $db->query($sqlNextMoveDate);
			if ($resNextMoveDate) {
				$rowNextMoveDate = $db->fetch_object($resNextMoveDate);
				if (! $rowNextMoveDate || $rowNextMoveDate->origintype !== 'inventory') {
					$nextMoveId = 0;
				} elseif (!empty($rowNextMoveDate->datem)) {
					$nextMoveDate = $rowNextMoveDate->datem;
					if (is_numeric($nextMoveDate)) {
						$nextMoveDate = $db->idate($nextMoveDate);
					}
					$nextMoveTs = dol_stringtotime($nextMoveDate);
					if ($nextMoveTs > 0 && $nextMoveTs <= $moveTs) {
						return false;
					}
				} else {
					$nextMoveId = 0;
				}
			}
		}
		if ($nextMoveId <= 0) {
			$batchCond = $batch !== ''
				? " AND batch='" . $db->escape($batch) . "'"
				: " AND (batch='' OR batch IS NULL)";
			$sqlNext = "SELECT rowid, datem FROM " . MAIN_DB_PREFIX . "stock_mouvement"
				. " WHERE origintype='inventory'"
				. " AND fk_origin=" . (int) $nextAnchor['inv_id']
				. " AND fk_product=" . (int) $move->product_id
				. " AND fk_entrepot=" . (int) $warehouse
				. $batchCond
				. " AND datem > '" . $db->escape($moveDate) . "'"
				. " ORDER BY datem ASC LIMIT 1";
			$resNext = $db->query($sqlNext);
			if ($resNext) {
				$rowNext = $db->fetch_object($resNext);
				if ($rowNext && !empty($rowNext->rowid)) {
					$nextMoveId = (int) $rowNext->rowid;
				}
			}
			if ($nextMoveId <= 0) {
				return false;
			}
		}

		return $this->adjustNextInventoryMovementForInsertedSupplierInvoice($move, $db, $warehouse, $nextMoveId);
	}

	protected function applySupplierInvoiceCase4($move, $db, $warehouse, $moveDate)
	{
		// Case 4: invoice after existing inventory -> recalc reel up to invoice date.
		$anchorBefore = $this->getLatestInventoryAnchorBefore($db, (int) $move->product_id, $warehouse, $move->batch ?? '', $moveDate);
		if (! $anchorBefore['valid']) {
			return;
		}

		$this->recalculateStockFromLatestInventoryAnchorUntilDate(
			$db,
			(int) $move->product_id,
			$warehouse,
			$move->batch ?? '',
			$moveDate,
			$anchorBefore
		);
	}

	protected function adjustNextInventoryMovementForInsertedSupplierInvoice($move, $db, $warehouse, $nextMoveIdOverride = 0)
	{
		$productId = (int) $move->product_id;
		$warehouse = (int) $warehouse;
		$moveId = (int) $move->id;
		if ($productId <= 0 || $warehouse <= 0 || $moveId <= 0) {
			return false;
		}

		$moveDate = $move->datem;
		if (empty($moveDate)) {
			return false;
		}
		if (is_numeric($moveDate)) {
			$moveDate = $db->idate($moveDate);
		}

		$batch = trim((string) ($move->batch ?? ''));
		$moveValue = null;
		$sqlMove = "SELECT value, batch FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . $moveId;
		$resMove = $db->query($sqlMove);
		if ($resMove) {
			$rowMove = $db->fetch_object($resMove);
			if ($rowMove) {
				$moveValue = (float) $rowMove->value;
				if ($batch === '' && isset($rowMove->batch)) {
					$batch = $rowMove->batch;
				}
			}
		} else {
			dol_syslog(__METHOD__ . " Error fetching supplier movement value: " . $db->lasterror(), LOG_ERR);
		}
		if ($moveValue === null && isset($move->qty)) {
			$moveValue = (float) $move->qty;
		}
		if ($moveValue === null) {
			dol_syslog(__METHOD__ . " Missing supplier movement value; abort", LOG_ERR);
			return true;
		}

		$nextMoveId = (int) $nextMoveIdOverride;
		if ($nextMoveId <= 0) {
			$nextAnchor = $this->getNextInventoryAnchorAfter($db, $productId, $warehouse, $batch, $moveDate);
			if (! $nextAnchor['found']) {
				return false;
			}
			if (! $nextAnchor['valid']) {
				dol_syslog(__METHOD__ . " Next inventory anchor invalid; abort", LOG_ERR);
				return true;
			}

			$nextMoveId = (int) $nextAnchor['fk_movement'];
			if ($nextMoveId <= 0) {
				$batchCond = $batch !== ''
					? " AND batch='" . $db->escape($batch) . "'"
					: " AND (batch='' OR batch IS NULL)";
				$sqlNext = "SELECT rowid FROM " . MAIN_DB_PREFIX . "stock_mouvement"
					. " WHERE origintype='inventory'"
					. " AND fk_origin=" . (int) $nextAnchor['inv_id']
					. " AND fk_product=" . $productId
					. " AND fk_entrepot=" . $warehouse
					. $batchCond
					. " ORDER BY datem ASC LIMIT 1";
				$resNext = $db->query($sqlNext);
				if ($resNext) {
					$rowNext = $db->fetch_object($resNext);
					if ($rowNext) {
						$nextMoveId = (int) $rowNext->rowid;
					}
				}
			}
		}
		if ($nextMoveId <= 0) {
			dol_syslog(__METHOD__ . " Cannot find next inventory movement to adjust", LOG_ERR);
			return true;
		}

		if ($moveValue != 0.0) {
			$sqlUpdNext = "UPDATE " . MAIN_DB_PREFIX . "stock_mouvement"
				. " SET value = value - " . $db->escape($moveValue)
				. " WHERE rowid = " . $nextMoveId;
			if (! $db->query($sqlUpdNext)) {
				dol_syslog(__METHOD__ . " Error updating next inventory movement: " . $db->lasterror(), LOG_ERR);
			}
			$this->undoInventoryMovementStockImpact($db, $productId, $warehouse, $batch, $moveValue);
		}

		return true;
	}

	protected function getLatestInventoryAnchor($db, $productId, $warehouse, $batch)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0) {
			return array('found' => false, 'valid' => true);
		}

		$batch = trim((string) $batch);
		$batchCond = $batch !== ''
			? " AND id.batch='" . $db->escape($batch) . "'"
			: " AND (id.batch='' OR id.batch IS NULL)";

		$sql = "SELECT i.date_inventory, id.qty_view, id.batch"
			. " FROM " . MAIN_DB_PREFIX . "inventory i"
			. " JOIN " . MAIN_DB_PREFIX . "inventorydet id ON id.fk_inventory = i.rowid"
			. " WHERE i.status = " . ((int) Inventory::STATUS_RECORDED)
			. " AND i.entity IN (" . getEntity('inventory') . ")"
			. " AND id.fk_product = " . $productId
			. " AND id.fk_warehouse = " . $warehouse
			. $batchCond
			. " ORDER BY i.date_inventory DESC"
			. " LIMIT 1";

		$res = $db->query($sql);
		if (! $res) {
			dol_syslog("Error fetching latest inventory anchor: " . $db->lasterror(), LOG_ERR);
			return array('found' => false, 'valid' => false);
		}

		$row = $db->fetch_object($res);
		if (! $row) {
			return array('found' => false, 'valid' => true);
		}
		if ($row->qty_view === null || $row->qty_view === '') {
			dol_syslog("Latest inventory anchor missing qty_view; abort", LOG_ERR);
			return array('found' => true, 'valid' => false);
		}

		return array(
			'found' => true,
			'valid' => true,
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
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
		$batchCond = $batch !== ''
			? " AND id.batch='" . $db->escape($batch) . "'"
			: " AND (id.batch='' OR id.batch IS NULL)";

		$sql = "SELECT i.date_inventory, id.qty_view, id.batch"
			. " FROM " . MAIN_DB_PREFIX . "inventory i"
			. " JOIN " . MAIN_DB_PREFIX . "inventorydet id ON id.fk_inventory = i.rowid"
			. " WHERE i.status = " . ((int) Inventory::STATUS_RECORDED)
			. " AND i.entity IN (" . getEntity('inventory') . ")"
			. " AND i.date_inventory <= '" . $db->escape($beforeDate) . "'"
			. " AND id.fk_product = " . $productId
			. " AND id.fk_warehouse = " . $warehouse
			. $batchCond
			. " ORDER BY i.date_inventory DESC"
			. " LIMIT 1";

		$res = $db->query($sql);
		if (! $res) {
			dol_syslog("Error fetching latest inventory anchor before date: " . $db->lasterror(), LOG_ERR);
			return array('found' => false, 'valid' => false);
		}

		$row = $db->fetch_object($res);
		if (! $row) {
			return array('found' => false, 'valid' => true);
		}
		if ($row->qty_view === null || $row->qty_view === '') {
			dol_syslog("Latest inventory anchor before date missing qty_view; abort", LOG_ERR);
			return array('found' => true, 'valid' => false);
		}

		return array(
			'found' => true,
			'valid' => true,
			'date_inventory' => $row->date_inventory,
			'qty_view' => (float) $row->qty_view,
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
		$batchCond = $batch !== ''
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";

		$sql = "SELECT rowid"
			. " FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE origintype='inventory'"
			. " AND fk_product = " . $productId
			. " AND fk_entrepot = " . $warehouse
			. $batchCond
			. " AND datem > '" . $db->escape($afterDate) . "'"
			. " ORDER BY datem ASC"
			. " LIMIT 1";

		$res = $db->query($sql);
		if (! $res) {
			dol_syslog("Error fetching next inventory movement: " . $db->lasterror(), LOG_ERR);
			return array('found' => false, 'valid' => false);
		}

		$row = $db->fetch_object($res);
		if (! $row) {
			return array('found' => false, 'valid' => true);
		}

		return array(
			'found' => true,
			'valid' => true,
			'rowid' => (int) $row->rowid,
		);
	}

	protected function getNextInventoryAnchorAfter($db, $productId, $warehouse, $batch, $afterDate, $excludeInventoryId = 0)
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
		$batchCond = $batch !== ''
			? " AND id.batch='" . $db->escape($batch) . "'"
			: " AND (id.batch='' OR id.batch IS NULL)";
		$excludeSql = $excludeInventoryId > 0 ? " AND i.rowid <> " . (int) $excludeInventoryId : "";

		$sql = "SELECT i.rowid as inv_id, i.date_inventory, id.qty_view, id.qty_stock, id.batch, id.fk_movement"
			. " FROM " . MAIN_DB_PREFIX . "inventory i"
			. " JOIN " . MAIN_DB_PREFIX . "inventorydet id ON id.fk_inventory = i.rowid"
			. " WHERE i.status = " . ((int) Inventory::STATUS_RECORDED)
			. " AND i.entity IN (" . getEntity('inventory') . ")"
			. " AND i.date_inventory > '" . $db->escape($afterDate) . "'"
			. " AND id.fk_product = " . $productId
			. " AND id.fk_warehouse = " . $warehouse
			. $batchCond
			. $excludeSql
			. " ORDER BY i.date_inventory ASC"
			. " LIMIT 1";

		$res = $db->query($sql);
		if (! $res) {
			dol_syslog("Error fetching next inventory anchor: " . $db->lasterror(), LOG_ERR);
			return array('found' => false, 'valid' => false);
		}

		$row = $db->fetch_object($res);
		if (! $row) {
			return array('found' => false, 'valid' => true);
		}
		if ($row->qty_view === null || $row->qty_view === '') {
			dol_syslog("Next inventory anchor missing qty_view; abort", LOG_ERR);
			return array('found' => true, 'valid' => false);
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

	protected function adjustNextInventoryMovementForInsertedInventory($move, $db, $warehouse)
	{
		$productId = (int) $move->product_id;
		$inventoryId = (int) $move->origin_id;
		$batch = trim((string) ($move->batch ?? ''));
		if ($productId <= 0 || $inventoryId <= 0 || $warehouse <= 0) {
			return false;
		}

		$moveValue = null;
		if (!empty($move->id)) {
			$sqlMove = "SELECT value FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . (int) $move->id;
			$resMove = $db->query($sqlMove);
			if ($resMove) {
				$rowMove = $db->fetch_object($resMove);
				if ($rowMove) {
					$moveValue = (float) $rowMove->value;
				}
			}
		}

		$sqlInv = "SELECT date_inventory FROM " . MAIN_DB_PREFIX . "inventory WHERE rowid = " . $inventoryId;
		$resInv = $db->query($sqlInv);
		if (! $resInv) {
			dol_syslog("Error fetching inventory date: " . $db->lasterror(), LOG_ERR);
			return false;
		}
		$rowInv = $db->fetch_object($resInv);
		if (! $rowInv || empty($rowInv->date_inventory)) {
			return false;
		}
		$invDate = $rowInv->date_inventory;
		if (is_numeric($invDate)) {
			$invDate = $db->idate($invDate);
		}

		$batchCond = $batch !== ''
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";

		$sqlLineCurrent = "SELECT qty_view FROM " . MAIN_DB_PREFIX . "inventorydet"
			. " WHERE fk_inventory = " . $inventoryId
			. " AND fk_product = " . $productId
			. " AND fk_warehouse = " . (int) $warehouse
			. $batchCond
			. " LIMIT 1";
		$resLineCurrent = $db->query($sqlLineCurrent);
		if (! $resLineCurrent) {
			dol_syslog("Error fetching current inventory line: " . $db->lasterror(), LOG_ERR);
			return true;
		}
		$currentLine = $db->fetch_object($resLineCurrent);
		if (! $currentLine || $currentLine->qty_view === null || $currentLine->qty_view === '') {
			return true;
		}
		$currentQtyView = (float) $currentLine->qty_view;

		$sqlNextMove = "SELECT rowid, datem, fk_origin FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE origintype='inventory'"
			. " AND fk_product = " . $productId
			. " AND fk_entrepot = " . (int) $warehouse
			. " AND datem > '" . $db->escape($invDate) . "'"
			. $batchCond
			. " ORDER BY datem ASC LIMIT 1";
		$resNextMove = $db->query($sqlNextMove);
		if (! $resNextMove) {
			dol_syslog("Error fetching next inventory movement: " . $db->lasterror(), LOG_ERR);
			return false;
		}
		$nextMove = $db->fetch_object($resNextMove);
		if (! $nextMove) {
			return false;
		}

		$nextMoveId = (int) $nextMove->rowid;
		$nextInvId = (int) $nextMove->fk_origin;
		$nextDate = $nextMove->datem;
		if (is_numeric($nextDate)) {
			$nextDate = $db->idate($nextDate);
		}

		$sqlLineNext = "SELECT qty_view FROM " . MAIN_DB_PREFIX . "inventorydet"
			. " WHERE fk_inventory = " . $nextInvId
			. " AND fk_product = " . $productId
			. " AND fk_warehouse = " . (int) $warehouse
			. $batchCond
			. " LIMIT 1";
		$resLineNext = $db->query($sqlLineNext);
		if (! $resLineNext) {
			dol_syslog("Error fetching next inventory line: " . $db->lasterror(), LOG_ERR);
			return true;
		}
		$nextLine = $db->fetch_object($resLineNext);
		if (! $nextLine || $nextLine->qty_view === null || $nextLine->qty_view === '') {
			return true;
		}
		$nextQtyView = (float) $nextLine->qty_view;

		$sqlMoved = "SELECT COALESCE(SUM(value),0) AS moved"
			. " FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE fk_product = " . $productId
			. " AND fk_entrepot = " . (int) $warehouse
			. " AND origintype <> 'inventory'"
			. $batchCond
			. " AND datem > '" . $db->escape($invDate) . "'"
			. " AND datem < '" . $db->escape($nextDate) . "'";
		$resMoved = $db->query($sqlMoved);
		if (! $resMoved) {
			dol_syslog("Error summing movements between inventories: " . $db->lasterror(), LOG_ERR);
			return true;
		}
		$movedObj = $db->fetch_object($resMoved);
		$moved = $movedObj ? (float) $movedObj->moved : 0.0;

		// Expected stock at this inventory date, anchored to the next inventory snapshot.
		$expected = $nextQtyView - $moved;
		$delta = $currentQtyView - $expected;

		if ($nextMoveId > 0 && $delta != 0.0) {
			$sqlUpdNext = "UPDATE " . MAIN_DB_PREFIX . "stock_mouvement"
				. " SET value = value - " . $db->escape($delta)
				. " WHERE rowid = " . $nextMoveId;
			$db->query($sqlUpdNext);
		}

		if ($moveValue !== null && $moveValue != 0.0) {
			$this->undoInventoryMovementStockImpact($db, $productId, $warehouse, $batch, $moveValue);
		}

		return true;
	}

	protected function undoInventoryMovementStockImpact($db, $productId, $warehouse, $batch, $moveValue)
	{
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($productId <= 0 || $warehouse <= 0 || $moveValue == 0.0) {
			return;
		}

		$batch = trim((string) $batch);
		$deltaSql = $db->escape($moveValue);

		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlBatch = "UPDATE " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " SET pb.qty = pb.qty - " . $deltaSql
				. " WHERE ps.fk_product = " . $productId
				. " AND ps.fk_entrepot = " . $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			$db->query($sqlBatch);
		}

		$sqlStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
			. " SET reel = reel - " . $deltaSql
			. " WHERE fk_product = " . $productId
			. " AND fk_entrepot = " . $warehouse;
		$db->query($sqlStock);

		$sqlProd = "UPDATE " . MAIN_DB_PREFIX . "product"
			. " SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM " . MAIN_DB_PREFIX . "product_stock ps"
			. " WHERE ps.fk_product = " . $productId . ")"
			. " WHERE rowid = " . $productId;
		$db->query($sqlProd);
	}

	protected function recalculateStockFromLatestInventoryAnchor($db, $productId, $warehouse, $batch)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$anchor = $this->getLatestInventoryAnchor($db, $productId, $warehouse, $batch);
		if (!$anchor['valid']) {
			return;
		}

		$batch = trim((string) $batch);
		if ($batch === '' && !empty($anchor['batch'])) {
			$batch = trim((string) $anchor['batch']);
		}

		// Anchor logic: latest recorded inventory qty_view + movements strictly after it.
		$anchorQty = $anchor['found'] ? (float) $anchor['qty_view'] : 0.0;
		$anchorDate = $anchor['found'] ? $anchor['date_inventory'] : '';

		$batchCond = $batch !== ''
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";

		$sqlMoved = "SELECT COALESCE(SUM(value),0) AS moved"
			. " FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE fk_product=" . (int) $productId
			. " AND fk_entrepot=" . (int) $warehouse
			. " AND origintype <> 'inventory'"
			. $batchCond;
		if (!empty($anchorDate)) {
			$sqlMoved .= " AND datem > '" . $db->escape($anchorDate) . "'";
		}

		$resMoved = $db->query($sqlMoved);
		if (! $resMoved) {
			dol_syslog("Error summing movements for anchor recalc: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$movedObj = $db->fetch_object($resMoved);
		$moved = $movedObj ? (float) $movedObj->moved : 0.0;

		$new = $anchorQty + $moved;
		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlUpBatch = "UPDATE " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " SET pb.qty = " . $db->escape($new)
				. " WHERE ps.fk_product = " . (int) $productId
				. " AND ps.fk_entrepot = " . (int) $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			if (! $db->query($sqlUpBatch)) {
				dol_syslog("Error updating product_batch for anchor recalc: " . $db->lasterror(), LOG_ERR);
			}

			$sqlTotal = "SELECT COALESCE(SUM(pb.qty),0) AS total"
				. " FROM " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " WHERE ps.fk_product = " . (int) $productId
				. " AND ps.fk_entrepot = " . (int) $warehouse;
			$resTotal = $db->query($sqlTotal);
			$total = $new;
			if ($resTotal) {
				$tot = $db->fetch_object($resTotal);
				$total = $tot ? (float) $tot->total : $new;
			}

			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($total)
				. " WHERE fk_product = " . (int) $productId
				. " AND fk_entrepot = " . (int) $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		} else {
			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($new)
				. " WHERE fk_product = " . (int) $productId
				. " AND fk_entrepot = " . (int) $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		}

		$sqlProd = "UPDATE " . MAIN_DB_PREFIX . "product"
			. " SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM " . MAIN_DB_PREFIX . "product_stock ps"
			. " WHERE ps.fk_product = " . (int) $productId . ")"
			. " WHERE rowid = " . (int) $productId;
		if (! $db->query($sqlProd)) {
			dol_syslog("Error updating product stock total after anchor recalc: " . $db->lasterror(), LOG_ERR);
		}
	}

	protected function recalculateStockFromLatestInventoryAnchorUntilDate($db, $productId, $warehouse, $batch, $upToDate, $anchorOverride = null)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($upToDate)) {
			return;
		}
		if (is_numeric($upToDate)) {
			$upToDate = $db->idate($upToDate);
		}

		$anchor = $anchorOverride !== null
			? $anchorOverride
			: $this->getLatestInventoryAnchor($db, $productId, $warehouse, $batch);
		if (!$anchor['valid']) {
			return;
		}

		$batch = trim((string) $batch);
		if ($batch === '' && !empty($anchor['batch'])) {
			$batch = trim((string) $anchor['batch']);
		}

		$anchorQty = $anchor['found'] ? (float) $anchor['qty_view'] : 0.0;
		$anchorDate = $anchor['found'] ? $anchor['date_inventory'] : '';
		if (is_numeric($anchorDate)) {
			$anchorDate = $db->idate($anchorDate);
		}

		$batchCond = $batch !== ''
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";

		$sqlMoved = "SELECT COALESCE(SUM(value),0) AS moved"
			. " FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE fk_product=" . (int) $productId
			. " AND fk_entrepot=" . (int) $warehouse
			. " AND origintype <> 'inventory'"
			. $batchCond;
		if (!empty($anchorDate)) {
			$sqlMoved .= " AND datem > '" . $db->escape($anchorDate) . "'";
		}
		$sqlMoved .= " AND datem <= '" . $db->escape($upToDate) . "'";

		$resMoved = $db->query($sqlMoved);
		if (! $resMoved) {
			dol_syslog("Error summing movements for dated anchor recalc: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$movedObj = $db->fetch_object($resMoved);
		$moved = $movedObj ? (float) $movedObj->moved : 0.0;

		$new = $anchorQty + $moved;
		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlUpBatch = "UPDATE " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " SET pb.qty = " . $db->escape($new)
				. " WHERE ps.fk_product = " . (int) $productId
				. " AND ps.fk_entrepot = " . (int) $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			if (! $db->query($sqlUpBatch)) {
				dol_syslog("Error updating product_batch for dated anchor recalc: " . $db->lasterror(), LOG_ERR);
			}

			$sqlTotal = "SELECT COALESCE(SUM(pb.qty),0) AS total"
				. " FROM " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " WHERE ps.fk_product = " . (int) $productId
				. " AND ps.fk_entrepot = " . (int) $warehouse;
			$resTotal = $db->query($sqlTotal);
			$total = $new;
			if ($resTotal) {
				$tot = $db->fetch_object($resTotal);
				$total = $tot ? (float) $tot->total : $new;
			}

			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($total)
				. " WHERE fk_product = " . (int) $productId
				. " AND fk_entrepot = " . (int) $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for dated anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		} else {
			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($new)
				. " WHERE fk_product = " . (int) $productId
				. " AND fk_entrepot = " . (int) $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for dated anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		}

		$sqlProd = "UPDATE " . MAIN_DB_PREFIX . "product"
			. " SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM " . MAIN_DB_PREFIX . "product_stock ps"
			. " WHERE ps.fk_product = " . (int) $productId . ")"
			. " WHERE rowid = " . (int) $productId;
		if (! $db->query($sqlProd)) {
			dol_syslog("Error updating product stock total after dated anchor recalc: " . $db->lasterror(), LOG_ERR);
		}
	}

	protected function recalculateStockFromInventoryAnchor($db, $inventoryId, $productId, $warehouse, $batch)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$inventoryId = (int) $inventoryId;
		$productId = (int) $productId;
		$warehouse = (int) $warehouse;
		if ($inventoryId <= 0 || $productId <= 0 || $warehouse <= 0) {
			return;
		}

		$batch = trim((string) $batch);
		$batchCond = $batch !== ''
			? " AND id.batch='" . $db->escape($batch) . "'"
			: " AND (id.batch='' OR id.batch IS NULL)";

		$sqlAnchor = "SELECT i.date_inventory, id.qty_view, id.batch"
			. " FROM " . MAIN_DB_PREFIX . "inventory i"
			. " JOIN " . MAIN_DB_PREFIX . "inventorydet id ON id.fk_inventory = i.rowid"
			. " WHERE i.rowid = " . $inventoryId
			. " AND id.fk_product = " . $productId
			. " AND id.fk_warehouse = " . $warehouse
			. $batchCond
			. " LIMIT 1";
		$resAnchor = $db->query($sqlAnchor);
		if (! $resAnchor) {
			dol_syslog("Error fetching inventory anchor: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$anchor = $db->fetch_object($resAnchor);
		if (! $anchor || $anchor->qty_view === null || $anchor->qty_view === '') {
			dol_syslog("Inventory anchor missing qty_view; abort", LOG_ERR);
			return;
		}

		$anchorDate = $anchor->date_inventory;
		if (is_numeric($anchorDate)) {
			$anchorDate = $db->idate($anchorDate);
		}

		if ($batch === '' && !empty($anchor->batch)) {
			$batch = trim((string) $anchor->batch);
			$batchCond = " AND id.batch='" . $db->escape($batch) . "'";
		}

		$qtyView = (float) $anchor->qty_view;

		$batchCondMove = $batch !== ''
			? " AND batch='" . $db->escape($batch) . "'"
			: " AND (batch='' OR batch IS NULL)";

		$sqlMoved = "SELECT COALESCE(SUM(value),0) AS moved"
			. " FROM " . MAIN_DB_PREFIX . "stock_mouvement"
			. " WHERE fk_product=" . $productId
			. " AND fk_entrepot=" . $warehouse
			. " AND origintype <> 'inventory'"
			. $batchCondMove
			. " AND datem > '" . $db->escape($anchorDate) . "'";
		$resMoved = $db->query($sqlMoved);
		if (! $resMoved) {
			dol_syslog("Error summing movements for inventory anchor recalc: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$movedObj = $db->fetch_object($resMoved);
		$moved = $movedObj ? (float) $movedObj->moved : 0.0;

		$new = $qtyView + $moved;
		if ($batch !== '' && isModEnabled('productbatch')) {
			$sqlUpBatch = "UPDATE " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " SET pb.qty = " . $db->escape($new)
				. " WHERE ps.fk_product = " . $productId
				. " AND ps.fk_entrepot = " . $warehouse
				. " AND pb.batch = '" . $db->escape($batch) . "'";
			if (! $db->query($sqlUpBatch)) {
				dol_syslog("Error updating product_batch for inventory anchor recalc: " . $db->lasterror(), LOG_ERR);
			}

			$sqlTotal = "SELECT COALESCE(SUM(pb.qty),0) AS total"
				. " FROM " . MAIN_DB_PREFIX . "product_batch pb"
				. " JOIN " . MAIN_DB_PREFIX . "product_stock ps ON ps.rowid = pb.fk_product_stock"
				. " WHERE ps.fk_product = " . $productId
				. " AND ps.fk_entrepot = " . $warehouse;
			$resTotal = $db->query($sqlTotal);
			$total = $new;
			if ($resTotal) {
				$tot = $db->fetch_object($resTotal);
				$total = $tot ? (float) $tot->total : $new;
			}

			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($total)
				. " WHERE fk_product = " . $productId
				. " AND fk_entrepot = " . $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for inventory anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		} else {
			$sqlUpStock = "UPDATE " . MAIN_DB_PREFIX . "product_stock"
				. " SET reel = " . $db->escape($new)
				. " WHERE fk_product = " . $productId
				. " AND fk_entrepot = " . $warehouse;
			if (! $db->query($sqlUpStock)) {
				dol_syslog("Error updating product_stock for inventory anchor recalc: " . $db->lasterror(), LOG_ERR);
			}
		}

		$sqlProd = "UPDATE " . MAIN_DB_PREFIX . "product"
			. " SET stock = (SELECT COALESCE(SUM(ps.reel),0) FROM " . MAIN_DB_PREFIX . "product_stock ps"
			. " WHERE ps.fk_product = " . $productId . ")"
			. " WHERE rowid = " . $productId;
		if (! $db->query($sqlProd)) {
			dol_syslog("Error updating product stock total after inventory anchor recalc: " . $db->lasterror(), LOG_ERR);
		}
	}
}
