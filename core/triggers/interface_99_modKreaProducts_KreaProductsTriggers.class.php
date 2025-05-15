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
				break;

			case 'PRODUCT_MODIFY':
			case 'PRODUCT_SUBPRODUCT_UPDATE':
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				break;

			case 'STOCK_MOVEMENT':
				return $this->handleStockMovement($object, $db, $conf);

			default:
				return 0;
		}

		return 1;
	}

	protected function handleStockMovement($move, $db, $conf)
	{
		if (empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA)) {
			return 0;
		}

		// First: adjust timestamps on *this* move
		if ($move->origintype === 'invoice_supplier') {
			$this->shiftSupplierInvoiceMoveToNoon($move, $db);
		}
		if ($move->origintype === 'inventory') {
			// And normalize the inventory ref
			$this->renameInventoryRefFromMove($move, $db);

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

			// Then run any BOM dismantle logic
			$this->dismantleIfNeeded($move, $db);
		}

		return 1;
	}

	protected function shiftSupplierInvoiceMoveToNoon($move, $db)
	{
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

		$new = substr($row->datef, 0, 10) . ' 10:00:00';
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

	protected function recalculateAfterInventory($move, $db)
	{
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

	protected function renameInventoryRefFromMove($move, $db)
	{
		// 1) Fetch the inventory header
		$sqlInv = 'SELECT rowid, ref, date_inventory'
			. ' FROM ' . MAIN_DB_PREFIX . 'inventory'
			. ' WHERE rowid=' . (int)$move->origin_id;
		$resInv = $db->query($sqlInv);
		if (!$resInv) {
			dol_syslog("Error fetching inventory header for renaming: " . $db->lasterror(), LOG_ERR);
			return;
		}
		$inv = $db->fetch_object($resInv);
		if (!$inv) {
			dol_syslog("No inventory found for id=" . (int)$move->origin_id, LOG_ERR);
			return;
		}

		$oldRef = trim($inv->ref);
		$date   = $inv->date_inventory;
		if (empty($oldRef) || empty($date)) {
			// nothing to do
			return;
		}

		// 2) Compute YYYYMMDD prefix
		$dt = new DateTime($date);
		$prefix = $dt->format('YmdHi');

		// 3) Determine suffix from oldRef
		$upper = strtoupper($oldRef);
		if (strpos($upper, 'PAT')  !== false)        $suffix = 'PATTIES';
		elseif (strpos($upper, 'PAD')  !== false)    $suffix = 'PADARIA';
		elseif (
			strpos($upper, 'CHA')  !== false
			|| strpos($upper, 'LACT') !== false
		)    $suffix = 'CHARCUTERIA_E_LACTICINIOS';
		elseif (strpos($upper, 'DIVE') !== false)    $suffix = 'DIVERSOS';
		elseif (strpos($upper, 'CERV') !== false)    $suffix = 'CERVEJAS';
		elseif (strpos($upper, 'REFR') !== false)    $suffix = 'REFRIGERANTES';
		else {
			// no match → leave original
			return;
		}

		$newRef = $prefix . '_' . $suffix;
		if ($newRef === $oldRef) {
			// already correct
			return;
		}

		dol_syslog("Rename inventory ref, oldRef: " . $oldRef);
		dol_syslog("Rename inventory ref, date: " . $date);
		dol_syslog("Rename inventory ref, prefix: " . $prefix);
		dol_syslog("Rename inventory ref, suffix: " . $suffix);
		dol_syslog("Rename inventory ref, newRef: " . $newRef);

		// 4) Persist the change
		$sqlUp = 'UPDATE ' . MAIN_DB_PREFIX . 'inventory'
			. ' SET ref = \'' . $db->escape($newRef) . '\''
			. ' WHERE rowid = ' . (int)$inv->rowid;
		if (!$db->query($sqlUp)) {
			dol_syslog("Error renaming inventory ref #{$inv->rowid}: " . $db->lasterror(), LOG_ERR);
		} else {
			dol_syslog("Renamed inventory ref #{$inv->rowid} from '{$oldRef}' to '{$newRef}'", LOG_INFO);
		}
	}


	protected function dismantleIfNeeded($move, $db)
	{
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