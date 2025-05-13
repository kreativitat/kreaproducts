<?php
/* Copyright (C) 2023    Laurent Destailleur
 * Copyright (C) 2025    Marcelo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation...
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsNutritionalCalculator.class.php';
include_once  DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/productDismantle.class.php';

/**
 *  Class of triggers for KreaProducts module
 */
class InterfaceKreaProductsTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family      = "Kreativität Works";
		$this->description = "KreaProducts triggers.";
		$this->version     = self::VERSIONS['dev'];
		$this->picto       = 'kreaproducts@kreaproducts';
	}

	/**
	 * runTrigger
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $db;

		dol_syslog(">> runTrigger received action='$action' id=" . $object->id, LOG_DEBUG);

		if (! isModEnabled('kreaproducts')) {
			dol_syslog("Module kreaproducts disabled, skipping trigger", LOG_DEBUG);
			return 0;
		}

		switch ($action) {

			case 'PRODUCT_PRICE_MODIFY':
				dol_syslog("Handling PRODUCT_PRICE_MODIFY for product #{$object->id}", LOG_DEBUG);
				if (!empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {
					dol_syslog("Auto-sync enabled, calling ProductHierarchy::updateProductAttributes()", LOG_INFO);
					ProductHierarchy::updateProductAttributes($object->id, $user);
				} else {
					dol_syslog("Auto-sync disabled (KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE=0)", LOG_DEBUG);
					return 0;
				}
				break;

			case 'PRODUCT_MODIFY':
			case 'PRODUCT_SUBPRODUCT_UPDATE':
				dol_syslog("Handling $action for product #{$object->id}", LOG_DEBUG);
				$opt = $object->array_options['options_kreap_calc_nut'] ?? 'unset';
				dol_syslog("options_kreap_calc_nut = $opt", LOG_DEBUG);

				if ($opt != 1) {
					dol_syslog("Skipping nutritional calc because option!=1", LOG_DEBUG);
					return 0;
				}

				$result = KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				if ($result > 0) {
					dol_syslog("Nutritional totals successfully saved for product #{$object->id}", LOG_INFO);
					return 1;
				} else {
					dol_syslog("Error saving nutritional totals (result=$result) for product #{$object->id}", LOG_ERR);
					return 0;
				}
				break;

			case 'STOCK_MOVEMENT':
				dol_syslog("Handling STOCK_MOVEMENT #{$object->id}, origin_type={$object->origin_type}", LOG_DEBUG);

				if (empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA)) {
					dol_syslog("Stock movement data disabled, skipping", LOG_DEBUG);
				}

				$dateToApply = null;

				// Supplier invoice origin
				if ($object->origin_type === 'invoice_supplier') {
					$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid=' . (int)$object->origin_id;
					dol_syslog("Querying supplier invoice date: $sql", LOG_DEBUG);
					$res = $db->query($sql);
					if ($res && ($row = $db->fetch_object($res))) {
						$dateToApply = $row->datef;
						dol_syslog("Supplier invoice datef=$dateToApply", LOG_DEBUG);
					}
				}

				// Inventory origin
				if ($object->origin_type === 'inventory') {
					$sql = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid=' . (int)$object->origin_id;
					dol_syslog("Querying inventory date: $sql", LOG_DEBUG);
					$res = $db->query($sql);
					if ($res && ($row = $db->fetch_object($res))) {
						$dateToApply = $row->date_inventory;
						dol_syslog("Inventory date_inventory=$dateToApply", LOG_DEBUG);
					}
				}

				// Update the movement timestamp (datem)
				if ($dateToApply) {
					$ts = dol_stringtotime($dateToApply);
					if ($ts === false) {
						dol_syslog("Failed to parse dateToApply='$dateToApply'", LOG_ERR);
					} else {
						$dateSql = date('Y-m-d H:i:s', $ts);
						dol_syslog("Updating stock_mouvement datem to '$dateSql' for movement #{$object->id}", LOG_DEBUG);
						$sqlUpd = 'UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement'
							. ' SET datem=\'' . $db->escape($dateSql) . '\''
							. ' WHERE rowid=' . (int)$object->id;
						if (!$db->query($sqlUpd)) {
							dol_syslog("Error updating datem: " . $db->lasterror, LOG_ERR);
						}
					}
				}

				// Recalculate “live” stock after an inventory
				if ($object->origin_type === 'inventory' && ! empty($dateToApply)) {


					// 1) Fetch snapshot from inventory line
					$sqlInv = 'SELECT qty_stock, fk_warehouse, fk_product, batch'
						. ' FROM ' . MAIN_DB_PREFIX . 'inventorydet'
						. ' WHERE fk_inventory=' . (int)$object->origin_id
						. ' AND fk_product='   . (int)$object->product_id
						. ' AND fk_warehouse=' . (int)$object->warehouse_id
						. (isset($object->batch) && $object->batch !== ''
							? " AND batch='" . $db->escape($object->batch) . "'"
							: " AND batch=''");
					dol_syslog("Inventory snapshot SQL: $sqlInv", LOG_DEBUG);
					$resInv = $db->query($sqlInv);
					if ($resInv && ($inv = $db->fetch_object($resInv))) {
						$snapshot    = (float)$inv->qty_stock;
						$warehouseId = (int)$inv->fk_warehouse;
						$productId   = (int)$inv->fk_product;
						$batchVal    = $db->escape($inv->batch);
						dol_syslog("Snapshot qty_stock=$snapshot warehouse=$warehouseId product=$productId batch='$batchVal'", LOG_DEBUG);

						// 2) Sum all non-inventory movements since that date
						$sqlSum = 'SELECT COALESCE(SUM(value),0) as moved'
							. ' FROM ' . MAIN_DB_PREFIX . 'stock_mouvement'
							. ' WHERE fk_product = '   . $productId
							. ' AND fk_entrepot = '   . $warehouseId
							. ' AND datem >= "'      . $db->idate($dateToApply) . '"'
							. ' AND origintype != "inventory"'
							. ($batchVal
								? ' AND batch="' . $batchVal . '"'
								: ' AND (batch="" OR batch IS NULL)');
						dol_syslog("Summing non-inventory movements: $sqlSum", LOG_DEBUG);
						$resSum = $db->query($sqlSum);
						if ($resSum && ($rowSum = $db->fetch_object($resSum))) {
							$moved = (float)$rowSum->moved;
						} else {
							dol_syslog("Sum query failed: " . $db->lasterror, LOG_WARNING);
							$moved = 0;
						}

						// 3) Compute and store new “live” quantity
						$newQty = $snapshot + $moved;
						dol_syslog("Computed live stock = snapshot($snapshot) + moved($moved) = $newQty", LOG_INFO);

						// 4) Update product_stock
						$sqlUpdPS = 'UPDATE ' . MAIN_DB_PREFIX . 'product_stock'
							. ' SET reel=' . $db->escape($newQty)
							. ' WHERE fk_product=' . $productId
							. ' AND fk_entrepot=' . $warehouseId;
						dol_syslog("Updating product_stock: $sqlUpdPS", LOG_DEBUG);
						$db->query($sqlUpdPS);

						// 5) Update product_batch if applicable
						if ($batchVal) {
							$sqlUpdPb = 'UPDATE ' . MAIN_DB_PREFIX . 'product_batch'
								. ' SET qty=' . $db->escape($newQty)
								. ' WHERE fk_product_stock IN ('
								. '   SELECT rowid FROM ' . MAIN_DB_PREFIX . 'product_stock'
								. '   WHERE fk_product=' . $productId
								. '   AND fk_entrepot=' . $warehouseId
								. ') AND batch="' . $batchVal . '"';
							dol_syslog("Updating product_batch: $sqlUpdPb", LOG_DEBUG);
							$db->query($sqlUpdPb);
						}
					}
				}

				// Dismantle logic for supplier-invoice movements
				if ($object->origin_type === 'invoice_supplier') {
					dol_syslog("Checking dismantle logic for supplier invoice move", LOG_DEBUG);
					$dismantle = new ProductDismantleController($db);
					if ($dismantle->productInDismantleCategory($object->product_id)) {
						$bomId = $dismantle->findBom($object->product_id);
						dol_syslog("Found BOM #$bomId for product #{$object->product_id}", LOG_DEBUG);
						if ($bomId) {
							$ts = dol_stringtotime($dateToApply ?: '');
							dol_syslog("Producing and consuming BOM at ts=$ts qty={$object->qty}", LOG_DEBUG);
							$dismantle->produceAndConsume(
								$bomId,
								$object->qty,
								$object->price,
								$object->label,
								$object->origin_id,
								$object->origin_type,
								$ts
							);
						}
					}
				}
				break;

			default:
				dol_syslog("Trigger got unmatched action '$action' for ID=" . $object->id, LOG_DEBUG);
				return 0;
		}

		dol_syslog("Trigger for action '$action' completed OK for ID=" . $object->id, LOG_INFO);
		return 1;
	}
}
