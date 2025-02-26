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


				return 1;

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
