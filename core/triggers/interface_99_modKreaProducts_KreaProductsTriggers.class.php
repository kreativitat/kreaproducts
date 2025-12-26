<?php
/* Copyright (C) 2023   Laurent Destailleur
 * Copyright (C) 2025   Marcelo
 *
 * GNU GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsNutritionalCalculator.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/productDismantle.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';

/**
 * Triggers for KreaProducts
 */
class InterfaceKreaProductsTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family      = 'Kreativität Works';
		$this->description = 'KreaProducts triggers.';
		$this->version     = self::VERSIONS['dev'];
		$this->picto       = 'kreaproducts@kreaproducts';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $db;
		if (!isModEnabled('kreaproducts')) {
			return 0;
		}

		switch ($action) {
			case 'PRODUCT_PRICE_MODIFY':
				if (!empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {
					ProductHierarchy::updateProductAttributes($object->id, $user);
				}
				return 1;

			case 'PRODUCT_MODIFY':
			case 'PRODUCT_SUBPRODUCT_UPDATE':
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				return 1;

			case 'STOCK_MOVEMENT':
				// handleStockMovement() itself returns 1 or 0
				return $this->handleStockMovement($object, $db, $conf, $user);

			case 'INVENTORY_RECORDED':
			case 'INVENTORY_MODIFY':
				// run our post-save rename hook
				$this->renameInventoryHeaderRef($object, $db);
				return 1;
			case 'INVENTORY_CREATE':
				return $this->prefillInventoryLinesAtCreate($object, $db, $user);

			default:
				return 0;
		}
	}

	protected function prefillInventoryLinesAtCreate($inventory, $db, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($inventory->id)) {
			return 0;
		}

		$sqlCheck = 'SELECT COUNT(*) as cnt FROM ' . MAIN_DB_PREFIX . 'inventorydet WHERE fk_inventory=' . (int) $inventory->id;
		$resCheck = $db->query($sqlCheck);
		if (! $resCheck) {
			dol_syslog(__METHOD__ . " Error checking inventory lines: " . $db->lasterror(), LOG_ERR);
			return -1;
		}
		$check = $db->fetch_object($resCheck);
		if ($check && (int) $check->cnt > 0) {
			return 0;
		}

		$inventoryAnchor = !empty($inventory->date_inventory) ? $inventory->date_inventory : null;
		if (!empty($inventoryAnchor) && !is_numeric($inventoryAnchor)) {
			$inventoryAnchor = dol_stringtotime($inventoryAnchor);
		}

		$warehouseIds = array();
		if (!empty($inventory->fk_warehouse)) {
			$warehouseIds[] = (int) $inventory->fk_warehouse;
			if (getDolGlobalInt('INVENTORY_INCLUDE_SUB_WAREHOUSE')) {
				$TChildWarehouses = array();
				$inventory->getChildWarehouse($inventory->fk_warehouse, $TChildWarehouses);
				if (!empty($TChildWarehouses)) {
					foreach ($TChildWarehouses as $childId) {
						$warehouseIds[] = (int) $childId;
					}
				}
			}
		}
		$warehouseIds = array_values(array_unique($warehouseIds));

		$movedByKey = array();
		if (!empty($inventoryAnchor)) {
			$sqlmove = "SELECT fk_product, fk_entrepot, COALESCE(batch, '') as batch, SUM(value) as moved";
			$sqlmove .= " FROM " . MAIN_DB_PREFIX . "stock_mouvement";
			$sqlmove .= " WHERE datem > '" . $db->escape($db->idate($inventoryAnchor)) . "'";
			if (!empty($inventory->fk_product)) {
				$sqlmove .= " AND fk_product = " . (int) $inventory->fk_product;
			}
			if (!empty($warehouseIds)) {
				$sqlmove .= " AND fk_entrepot IN (" . $db->sanitize(implode(',', $warehouseIds)) . ")";
			}
			$sqlmove .= " GROUP BY fk_product, fk_entrepot, COALESCE(batch, '')";
			$resmove = $db->query($sqlmove);
			if ($resmove) {
				while ($mov = $db->fetch_object($resmove)) {
					$moveKey = $mov->fk_product.'|'.$mov->fk_entrepot.'|'.(string) $mov->batch;
					$movedByKey[$moveKey] = (float) $mov->moved;
				}
			} else {
				dol_syslog(__METHOD__ . " Error loading stock movements: " . $db->lasterror(), LOG_ERR);
			}
		}

		$sql = "SELECT ps.rowid, ps.fk_entrepot as fk_warehouse, ps.fk_product, ps.reel,";
		if (isModEnabled('productbatch')) {
			$sql .= " COALESCE(pb.batch, '') as batch, pb.qty as qty,";
		} else {
			$sql .= " '' as batch, 0 as qty,";
		}
		$sql .= " p.ref, p.tobatch";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_stock as ps";
		if (isModEnabled('productbatch')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_batch as pb ON pb.fk_product_stock = ps.rowid";
		}
		$sql .= ", " . MAIN_DB_PREFIX . "product as p, " . MAIN_DB_PREFIX . "entrepot as e";
		$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
		$sql .= " AND ps.fk_product = p.rowid AND ps.fk_entrepot = e.rowid";
		if (!getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
			$sql .= " AND p.fk_product_type = 0";
		}
		if (!empty($inventory->fk_product)) {
			$sql .= " AND ps.fk_product = " . (int) $inventory->fk_product;
		}
		if (!empty($warehouseIds)) {
			$sql .= " AND ps.fk_entrepot IN (" . $db->sanitize(implode(',', $warehouseIds)) . ")";
		}
		if (!empty($inventory->categories_product)) {
			$sql .= " AND EXISTS (";
			$sql .= " SELECT cp.fk_product";
			$sql .= " FROM " . MAIN_DB_PREFIX . "categorie_product AS cp";
			$sql .= " WHERE cp.fk_product = ps.fk_product";
			$sql .= " AND cp.fk_categorie IN (" . $db->sanitize($inventory->categories_product) . ")";
			$sql .= ")";
		}
		if (getDolGlobalInt('PRODUIT_SOUSPRODUITS')) {
			$sql .= " AND NOT EXISTS (";
			$sql .= " SELECT pa.rowid";
			$sql .= " FROM " . MAIN_DB_PREFIX . "product_association as pa";
			$sql .= " WHERE pa.fk_product_pere = ps.fk_product";
			$sql .= ")";
		}
		$sql .= " ORDER BY p.rowid";

		$resql = $db->query($sql);
		if (! $resql) {
			dol_syslog(__METHOD__ . " Error prefill inventory lines: " . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$error = 0;
		$inventoryline = new InventoryLine($db);
		while ($obj = $db->fetch_object($resql)) {
			$inventoryline->fk_inventory = $inventory->id;
			$inventoryline->fk_warehouse = $obj->fk_warehouse;
			$inventoryline->fk_product = $obj->fk_product;
			$inventoryline->batch = $obj->batch;
			$inventoryline->datec = dol_now();

			if (isModEnabled('productbatch')) {
				if ($obj->batch && empty($obj->tobatch)) {
					$error++;
					dol_syslog(__METHOD__ . " Product ID=" . $obj->ref . " has batch stock but is not batch-managed", LOG_ERR);
					break;
				}
				$currentstock = ($obj->batch ? $obj->qty : $obj->reel);
			} else {
				$currentstock = $obj->reel;
			}
			if (!empty($inventoryAnchor)) {
				$moveKey = $obj->fk_product.'|'.$obj->fk_warehouse.'|'.(string) $obj->batch;
				$moved = $movedByKey[$moveKey] ?? 0.0;
				$inventoryline->qty_stock = (float) $currentstock - (float) $moved;
			} else {
				$inventoryline->qty_stock = $currentstock;
			}

			if ($inventoryline->create($user) <= 0) {
				$error++;
				dol_syslog(__METHOD__ . " Error creating inventory line: " . $inventoryline->error, LOG_ERR);
				break;
			}
		}

		if ($error) {
			return -1;
		}
		return 1;
	}


	protected function handleStockMovement($move, $db, $conf, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA)) {
			return 0;
		}

		// First: adjust timestamps on *this* move
		if ($move->origintype === 'invoice_supplier') {
			$this->shiftSupplierInvoiceMoveToNoon($move, $db);
		}
		if ($move->origintype === 'inventory') {
			$this->alignInventoryMoveTimestamp($move, $db);
		}

		// Then: full routines
		if ($move->origintype === 'inventory') {
			// Re‐compute stock levels after this inventory move
			$this->recalculateAfterInventory($move, $db);
		}
		if ($move->origintype === 'invoice_supplier') {
			// Re‐compute stock levels after this supplier‐invoice move
			$this->recalculateAfterSupplierInvoice($move, $db);

			// Before dismantling, refresh cost prices of BOM children (including nested)
			$this->updateDismantleChildrenBeforeTrigger($move, $db, $user);

			// Then run any BOM dismantle logic
			$this->dismantleIfNeeded($move, $db);
		}

		return 1;
	}

	protected function shiftSupplierInvoiceMoveToNoon($move, $db)
	{
		global $conf;
		dol_syslog(__METHOD__, LOG_DEBUG);

		// Read configured time (HH:MM or HH:MM:SS), fallback to 10:00:00
		$time = trim($conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME ?? '10:00');
		if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
			$time = '10:00';
		}
		if (strlen($time) === 5) {
			$time .= ':00';
		}

		$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid=' . (int)$move->origin_id;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog("Error querying supplier invoice date: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			dol_syslog("No supplier invoice found for id=" . (int)$move->origin_id, LOG_ERR);
			return;
		}

		$new = substr($row->datef, 0, 10) . ' ' . $time;
		if ($new !== $move->datem) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement
                       SET datem=\'' . $db->escape($new) . '\'
                     WHERE rowid=' . (int)$move->id;
			if (!$db->query($upd)) {
				dol_syslog("Error shifting supplier move to noon: " . $db->lasterror(), LOG_ERR);
			}
			$move->datem = $new;
		}
	}

	protected function alignInventoryMoveTimestamp($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$sql = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid=' . (int)$move->origin_id;
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog("Error querying inventory date: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$row = $db->fetch_object($res);
		if (!$row) {
			dol_syslog("No inventory header for id=" . (int)$move->origin_id, LOG_ERR);
			return;
		}

		if ($row->date_inventory !== $move->datem) {
			$upd = 'UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement
                       SET datem=\'' . $db->escape($row->date_inventory) . '\'
                     WHERE rowid=' . (int)$move->id;
			if (!$db->query($upd)) {
				dol_syslog("Error aligning inventory move timestamp: " . $db->lasterror(), LOG_ERR);
			}
			$move->datem = $row->date_inventory;
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
                WHERE b.rowid = " . (int) $bomId . " AND b.bomtype = 1";

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

	protected function recalculateAfterInventory($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		// 1) Anchor: date_inventory
		$sqlInv = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid=' . (int)$move->origin_id;
		$resInv = $db->query($sqlInv);
		if (!$resInv) {
			dol_syslog("Error fetching inventory header: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$anchRow = $db->fetch_object($resInv);
		if (!$anchRow) {
			dol_syslog("Inventory header missing → abort", LOG_ERR);
			return;
		}
		$anchor = $anchRow->date_inventory;

		// 2) Detect warehouse
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

		// 3) Shift **all** supplier‐invoice moves *after* the anchor to noon
		$dayStart = substr($anchor, 0, 10) . ' 00:00:00';
		$sqlShift = '
            UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement
               SET datem = CONCAT(DATE(datem), \' 12:00:00\')
             WHERE origintype=\'invoice_supplier\'
               AND fk_product=' . (int)$move->product_id . '
               AND fk_entrepot=' . $warehouse . '
               AND datem>\'' . $db->escape($dayStart) . '\'';
		if (!$db->query($sqlShift)) {
			dol_syslog("Error shifting post-inventory supplier moves: " . $db->lasterror(), LOG_ERR);
		}

		// 4) Delete *other* inventory moves same day (keep the one we're processing)
		$sqlDel = '
            DELETE FROM ' . MAIN_DB_PREFIX . 'stock_mouvement
             WHERE origintype=\'inventory\'
               AND fk_product=' . (int)$move->product_id . '
               AND fk_entrepot=' . $warehouse . '
               AND datem>=\'' . $db->escape($dayStart) . '\'
               AND rowid<>' . (int)$move->id;
		if (!$db->query($sqlDel)) {
			dol_syslog("Error deleting other-day inventory moves: " . $db->lasterror(), LOG_ERR);
		}

		// 5) Snapshot from inventorydet
		$sqlSnap = '
            SELECT qty_stock, batch
              FROM ' . MAIN_DB_PREFIX . 'inventorydet
             WHERE fk_inventory=' . (int)$move->origin_id . '
               AND fk_product=' . (int)$move->product_id . '
               AND fk_warehouse=' . $warehouse . ' LIMIT 1';
		$resSnap = $db->query($sqlSnap);
		if (!$resSnap) {
			dol_syslog("Error fetching inventory snapshot: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$snap = $db->fetch_object($resSnap);
		if (!$snap) {
			dol_syslog("Inventory snapshot missing → abort", LOG_ERR);
			return;
		}
		$snapshot = (float)$snap->qty_stock;
		$batch    = trim($snap->batch ?? '');

		// 6) Get current reel
		$sqlCur = '
            SELECT reel
              FROM ' . MAIN_DB_PREFIX . 'product_stock
             WHERE fk_product=' . (int)$move->product_id . '
               AND fk_entrepot=' . $warehouse;
		$resCur = $db->query($sqlCur);
		$current = 0.0;
		if ($resCur) {
			$c = $db->fetch_object($resCur);
			$current = $c ? (float)$c->reel : 0.0;
		}

		// 7) Sum qty moved since anchor (ignore inventory)
		$batchCond = $batch !== ''
			? "AND batch='" . $db->escape($batch) . "'"
			: "AND (batch='' OR batch IS NULL)";
		$sqlMoved = '
            SELECT COALESCE(SUM(value),0) AS moved
              FROM ' . MAIN_DB_PREFIX . 'stock_mouvement
             WHERE fk_product=' . (int)$move->product_id . '
               AND fk_entrepot=' . $warehouse . '
               AND datem>\'' . $db->escape($anchor) . '\'
               AND origintype<>\'inventory\' ' . $batchCond;
		$resMoved = $db->query($sqlMoved);
		$moved    = 0.0;
		if ($resMoved) {
			$mv = $db->fetch_object($resMoved);
			$moved = $mv ? (float)$mv->moved : 0.0;
		}

		// 8) Compute new reel
		$past = $current - $moved;
		$new  = $current + $moved;
		dol_syslog("Product_stock variable past: " . $past);
		dol_syslog("Product_stock variable current: " . $current);
		dol_syslog("Product_stock variable moved: " . $moved);
		dol_syslog("Product_stock variable snapshot: " . $snapshot);
		dol_syslog("Product_stock variable new: " . $new);

		// 9) Persist
		$sqlUpStock = '
            UPDATE ' . MAIN_DB_PREFIX . 'product_stock
               SET reel=' . $db->escape($new) . '
             WHERE fk_product=' . (int)$move->product_id . '
               AND fk_entrepot=' . $warehouse;
		if (!$db->query($sqlUpStock)) {
			dol_syslog("Error updating product_stock: " . $db->lasterror(), LOG_ERR);
		}

		if ($batch !== '') {
			$sqlUpBatch = '
                UPDATE ' . MAIN_DB_PREFIX . 'product_batch pb
                JOIN ' . MAIN_DB_PREFIX . 'product_stock ps
                  ON ps.rowid=pb.fk_product_stock
                   SET pb.qty=' . $db->escape($past) . '
                 WHERE ps.fk_product=' . (int)$move->product_id . '
                   AND ps.fk_entrepot=' . $warehouse . '
                   AND pb.batch=\'' . $db->escape($batch) . '\'';
			if (!$db->query($sqlUpBatch)) {
				dol_syslog("Error updating product_batch: " . $db->lasterror(), LOG_ERR);
			}
		}

		dol_syslog("Inventory recalc OK (prod={$move->product_id}, wh={$warehouse}, snapshot={$snapshot}, moved={$moved}, new={$new})", LOG_INFO);
	}

	protected function recalculateAfterSupplierInvoice($move, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		// 1) Anchor timestamp
		$anchor = $move->datem; // already shifted to noon

		// 2) Determine warehouse
		$warehouse = 0;
		if (!empty($move->fk_entrepot)) {
			$warehouse = (int)$move->fk_entrepot;
		} elseif (!empty($move->fk_warehouse)) {
			$warehouse = (int)$move->fk_warehouse;
		}
		if ($warehouse <= 0) {
			dol_syslog("Cannot determine warehouse for supplier recalc", LOG_ERR);
			return;
		}

		// 3) Determine batch (if any)
		$batch = trim($move->batch ?? '');
		$batchCondBefore = $batch !== ''
			? "AND batch='" . $db->escape($batch) . "'"
			: "AND (batch='' OR batch IS NULL)";
		$batchCondAfter  = $batchCondBefore;

		// 4) Compute snapshot: sum of all movements BEFORE anchor
		$sqlSnap = "
        SELECT COALESCE(SUM(value),0) AS snapshot
          FROM " . MAIN_DB_PREFIX . "stock_mouvement
         WHERE fk_product=" . (int)$move->product_id . "
           AND fk_entrepot=" . $warehouse . "
           AND datem<'" . $db->escape($anchor) . "'
           AND origintype<>'inventory'
           $batchCondBefore";
		$resSnap = $db->query($sqlSnap);
		$snapshot = 0.0;
		if ($resSnap) {
			$r = $db->fetch_object($resSnap);
			$snapshot = (float)$r->snapshot;
		}

		// 5) Compute moved: sum of all movements ON OR AFTER anchor
		$sqlMoved = "
        SELECT COALESCE(SUM(value),0) AS moved
          FROM " . MAIN_DB_PREFIX . "stock_mouvement
         WHERE fk_product=" . (int)$move->product_id . "
           AND fk_entrepot=" . $warehouse . "
           AND datem>='" . $db->escape($anchor) . "'
           AND origintype<>'inventory'
           $batchCondAfter";
		$resMoved = $db->query($sqlMoved);
		$moved = 0.0;
		if ($resMoved) {
			$r = $db->fetch_object($resMoved);
			$moved = (float)$r->moved;
		}

		// 6) Compute new reel
		$newReel = $snapshot + $moved;
		dol_syslog("Supplier recalc for prod={$move->product_id}, wh={$warehouse}, "
			. "snapshot={$snapshot}, moved={$moved}, newReel={$newReel}", LOG_INFO);

		// 7) Persist to product_stock
		$sqlUp = "
        UPDATE " . MAIN_DB_PREFIX . "product_stock
           SET reel=" . $db->escape($newReel) . "
         WHERE fk_product=" . (int)$move->product_id . "
           AND fk_entrepot=" . $warehouse;
		if (!$db->query($sqlUp)) {
			dol_syslog("Error updating product_stock for supplier recalc: " . $db->lasterror(), LOG_ERR);
		}

		// 8) If batch-managed, update product_batch too
		if ($batch !== '') {
			$past = $snapshot; // for the batch, its “past” is the snapshot
			$sqlUpBatch = "
            UPDATE " . MAIN_DB_PREFIX . "product_batch pb
            JOIN " . MAIN_DB_PREFIX . "product_stock ps
              ON ps.rowid=pb.fk_product_stock
             SET pb.qty=" . $db->escape($past) . "
           WHERE ps.fk_product=" . (int)$move->product_id . "
             AND ps.fk_entrepot=" . $warehouse . "
             AND pb.batch='" . $db->escape($batch) . "'";
			if (!$db->query($sqlUpBatch)) {
				dol_syslog("Error updating product_batch for supplier recalc: " . $db->lasterror(), LOG_ERR);
			}
		}
	}

	protected function renameInventoryHeaderRef($inventory, $db)
	{
		global $user;
		dol_syslog(__METHOD__, LOG_DEBUG);

		// 1) Grab existing ref & date
		$oldRef = trim($inventory->ref);
		$date   = $inventory->date_inventory;
		if (empty($oldRef) || empty($date)) {
			dol_syslog(__METHOD__ . " nothing to do (empty ref or date)", LOG_DEBUG);
			return;
		}

		// 2) Normalize into a DateTime object (handle both timestamps and DATETIME strings)
		if (preg_match('/^\d+$/', (string)$date)) {
			// Pure integer: treat as Unix timestamp
			$dt = new DateTime();
			$dt->setTimestamp((int)$date);
		} else {
			// DATETIME string e.g. '2025-05-08 19:00:00'
			try {
				$dt = new DateTime($date);
			} catch (Exception $e) {
				dol_syslog(__METHOD__ . " failed to parse date_inventory='{$date}': " . $e->getMessage(), LOG_ERR);
				return;
			}
		}
		$prefix = $dt->format('Ymd');

		// 3) Decide suffix based on oldRef content
		$up = strtoupper($oldRef);
		if (strpos($up, 'PAT')  !== false) $suffix = 'PATTIES';
		elseif (strpos($up, 'PAD')  !== false) $suffix = 'PADARIA';
		elseif (
			strpos($up, 'CHA')  !== false
			|| strpos($up, 'LACT') !== false
		) $suffix = 'CHARCUTERIA_E_LACTICINIOS';
		elseif (strpos($up, 'DIVE') !== false) $suffix = 'DIVERSOS';
		elseif (strpos($up, 'CERV') !== false) $suffix = 'CERVEJAS';
		elseif (strpos($up, 'REFR') !== false) $suffix = 'REFRIGERANTES';
		else {
			dol_syslog(__METHOD__ . " no matching suffix for '{$oldRef}'", LOG_DEBUG);
			return;
		}

		// 4) Build newRef and ensure uniqueness within the same entity
		$baseRef = $prefix . '_' . $suffix;
		$entity = isset($inventory->entity) ? (int) $inventory->entity : (int) $GLOBALS['conf']->entity;
		$sql = 'SELECT ref FROM ' . MAIN_DB_PREFIX . 'inventory'
			. " WHERE entity = " . $entity
			. " AND rowid <> " . (int) $inventory->id
			. " AND ref LIKE '" . $db->escape($baseRef) . "%'";
		$resql = $db->query($sql);
		$hasAny = false;
		$maxVersion = 1;
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$hasAny = true;
				if ($obj->ref === $baseRef) {
					$maxVersion = max($maxVersion, 1);
				} elseif (preg_match('/^' . preg_quote($baseRef, '/') . '_V(\d+)$/', $obj->ref, $m)) {
					$maxVersion = max($maxVersion, (int) $m[1]);
				}
			}
		} else {
			dol_syslog(__METHOD__ . " Error checking existing refs: " . $db->lasterror(), LOG_ERR);
			return;
		}

		$newRef = $hasAny ? ($baseRef . '_V' . ($maxVersion + 1)) : $baseRef;
		if ($newRef === $oldRef) {
			dol_syslog(__METHOD__ . " newRef '{$newRef}' is identical to oldRef, skipping", LOG_DEBUG);
			return;
		}

		// 5) Persist directly to DB
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'inventory'
			. ' SET ref = \'' . $db->escape($newRef) . '\''
			. ' WHERE rowid = ' . (int)$inventory->id;
		if (! $db->query($sql)) {
			dol_syslog(__METHOD__ . " Error renaming inventory #{$inventory->id}: " . $db->lasterror(), LOG_ERR);
			return;
		}
		dol_syslog(__METHOD__ . " Renamed inventory #{$inventory->id} '{$oldRef}' → '{$newRef}'", LOG_INFO);
	}

	protected function dismantleIfNeeded($move, $db)
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
			dol_stringtotime($move->datem)
		);
	}
}
