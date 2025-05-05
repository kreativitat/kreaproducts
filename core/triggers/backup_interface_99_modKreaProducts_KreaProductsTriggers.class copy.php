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
include_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/productDismantle.class.php';

/**
 *  Class of triggers for KreaProducts module
 */
class InterfaceKreaProductsTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		// Call parent constructor
		parent::__construct($db);

		// Basic trigger info
		$this->family      = "Kreativität Works";
		$this->description = "KreaProducts triggers.";
		$this->version     = self::VERSIONS['dev'];
		$this->picto       = 'kreaproducts@kreaproducts';
	}

	/**
	 * Required by DolibarrTriggers (abstract) => runTrigger(...)
	 *
	 * @param string       $action Action code (e.g. PRODUCT_MODIFY)
	 * @param CommonObject $object Current object (e.g. Product)
	 * @param User         $user   Current user
	 * @param Translate    $langs  Translation object
	 * @param Conf         $conf   Global conf object
	 * @return int                <0 if error, 0 if no triggered, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{

		global $db;
		// ------------------------------------------------------------------
		// If the KreaProducts module is not enabled, do nothing
		if (! isModEnabled('kreaproducts')) {
			return 0;
		}

		// We only handle two actions in the switch
		switch ($action) {
			// PRODUCT modification triggers
			//case 'PRODUCT_MODIFY': // Desligado porque torna o sistema lento
			case 'PRODUCT_PRICE_MODIFY':
				if (!empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {

					dol_syslog(
						"Trigger kreaproducts for action '" . $action . "' on object ID=" . $object->id,
						LOG_INFO
					);

					ProductHierarchy::updateProductAttributes($object->id, $user);
				} else {
					dol_syslog(
						"Trigger kreaproducts for action '" . $action . "' on object ID=" . $object->id . " is disabled",
						LOG_DEBUG
					);
					return 0;
				}

			case 'PRODUCT_MODIFY':
			case 'PRODUCT_SUBPRODUCT_UPDATE':


				if ($object->array_options['options_kreap_calc_nut'] != 1) {
					dol_syslog("KreaProducts trigger skipped for product #" . $object->id . " because options_kreap_calc_nut != 1", LOG_DEBUG);
					return 0;
				}

				$result = KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);

				if ($result > 0) {
					dol_syslog("Nutritional totals saved for product #" . $object->id, LOG_INFO);
					return 1;
				} else {
					dol_syslog("Error saving nutritional totals for product #" . $object->id, LOG_ERR);
					return 0;
				}

				return 1;

			case 'STOCK_MOVEMENT':
				// Only handle supplier invoice and inventory origins
				if (empty($conf->global->KREAPRODUCTS_STOCK_MOVEMENT_DATA)) break;

				$dateToApply = null;

				/* -------- Supplier invoice origin -------- */
				if ($object->origin_type === 'invoice_supplier') {
					$sql = 'SELECT datef FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid = ' . (int)$object->origin_id;
					$res = $db->query($sql);
					if ($res && ($row = $db->fetch_object($res))) {
						$dateToApply = $row->datef; // MySQL datetime
					}
				}

				/* -------- Inventory origin -------- */
				if ($object->origin_type === 'inventory') {
					$sql = 'SELECT date_inventory FROM ' . MAIN_DB_PREFIX . 'inventory WHERE rowid = ' . (int)$object->origin_id;
					$res = $db->query($sql);
					if ($res && ($row = $db->fetch_object($res))) {
						$dateToApply = $row->date_validation; // MySQL datetime
					}
				}

				/* -------- Apply date -------- */
				if ($dateToApply) {
					$dateSql = $db->escape(dol_print_date($dateToApply, 'dayhourlog'));
					$sqlUpd  = 'UPDATE ' . MAIN_DB_PREFIX . 'stock_mouvement'
						. ' SET datem = \'' . $dateSql . '\''
						. ' WHERE rowid = ' . (int)$object->id;
					$db->query($sqlUpd);
				}

				/* -------- Dismantle logic (only for supplier invoice moves) -------- */
				if ($object->origin_type === 'invoice_supplier') {
					$dismantle = new ProductDismantleController($db);
					if ($dismantle->productInDismantleCategory($object->product_id)) {
						$bomId = $dismantle->findBom($object->product_id);
						if ($bomId) {
							$ts = dol_stringtotime($dateToApply ?: '');
							$dismantle->produceAndConsume($bomId, $object->qty, $object->price, $object->label, $object->origin_id, $object->origin_type, $ts);
						}
					}
				}
				break;

			default:
				// We do nothing for other events
				dol_syslog(
					"Trigger kreaproducts for action '" . $action . "' did not match. ID=" . $object->id,
					LOG_DEBUG
				);
				return 0;
		}
	}
}
