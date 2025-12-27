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

		$inventoryAnchorDate = '';
		if (!empty($inventoryAnchor)) {
			$inventoryAnchorDate = $db->idate($inventoryAnchor);
		}
		$movedCache = array();

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
			if (!empty($inventoryAnchorDate)) {
				$batchKey = (string) $obj->batch;
				$cacheKey = $obj->fk_product . '|' . $obj->fk_warehouse . '|' . $batchKey;
				if (!array_key_exists($cacheKey, $movedCache)) {
					$batchCond = $batchKey !== ''
						? " AND batch='" . $db->escape($batchKey) . "'"
						: " AND (batch='' OR batch IS NULL)";
					$sqlMoved = "SELECT COALESCE(SUM(value),0) as moved";
					$sqlMoved .= " FROM " . MAIN_DB_PREFIX . "stock_mouvement";
					$sqlMoved .= " WHERE fk_product=" . (int) $obj->fk_product;
					$sqlMoved .= " AND fk_entrepot=" . (int) $obj->fk_warehouse;
					$sqlMoved .= " AND datem > '" . $db->escape($inventoryAnchorDate) . "'";
					$sqlMoved .= " AND origintype <> 'inventory'";
					$sqlMoved .= $batchCond;

					$resMoved = $db->query($sqlMoved);
					$moved = 0.0;
					if ($resMoved) {
						$mv = $db->fetch_object($resMoved);
						$moved = $mv ? (float) $mv->moved : 0.0;
					} else {
						dol_syslog(__METHOD__ . " Error loading stock movements: " . $db->lasterror(), LOG_ERR);
					}
					$movedCache[$cacheKey] = $moved;
				}

				$inventoryline->qty_stock = (float) $currentstock - (float) $movedCache[$cacheKey];
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

		if ((int) $inventory->status === Inventory::STATUS_DRAFT) {
			$result = $inventory->setStatut(Inventory::STATUS_VALIDATED, null, '', 'INVENTORY_VALIDATED');
			if ($result < 0) {
				dol_syslog(__METHOD__ . " Error setting inventory to started: " . $inventory->error, LOG_ERR);
				return -1;
			}
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
			$this->alignInventoryMoveToDateInventory($move, $db);
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

		if (empty($move->datem)) {
			return;
		}
		$timePart = substr($move->datem, 11, 8);
		if ($timePart !== '00:00:00') {
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
			$sqlMove = "SELECT fk_entrepot, fk_product, batch FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . (int) $move->id;
			$resMove = $db->query($sqlMove);
			if ($resMove) {
				$rowMove = $db->fetch_object($resMove);
				if ($rowMove) {
					$warehouse = (int) $rowMove->fk_entrepot;
					if (empty($move->product_id)) {
						$move->product_id = (int) $rowMove->fk_product;
					}
					if (empty($move->batch) && isset($rowMove->batch)) {
						$move->batch = $rowMove->batch;
					}
				}
			}
		}
		if ($warehouse <= 0 || empty($move->product_id)) {
			return;
		}

		$anchor = $this->getLatestInventoryAnchor($db, (int) $move->product_id, $warehouse, $move->batch ?? '');
		if (!$anchor['found'] || !$anchor['valid'] || empty($anchor['date_inventory'])) {
			return;
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

		$invoiceDay = substr($row->datef, 0, 10);
		$anchorDay = substr($anchor['date_inventory'], 0, 10);
		if ($invoiceDay !== $anchorDay) {
			return;
		}

		$new = $invoiceDay . ' ' . $time;
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
		if ($warehouse <= 0 && !empty($move->id)) {
			$sqlMove = "SELECT fk_entrepot, fk_product, value, batch FROM " . MAIN_DB_PREFIX . "stock_mouvement WHERE rowid = " . (int) $move->id;
			$resMove = $db->query($sqlMove);
			if ($resMove) {
				$rowMove = $db->fetch_object($resMove);
				if ($rowMove) {
					$warehouse = (int) $rowMove->fk_entrepot;
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

		// Always recompute from the latest recorded inventory anchor.
		$this->recalculateStockFromLatestInventoryAnchor($db, (int) $move->product_id, $warehouse, $move->batch ?? '');
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

}
