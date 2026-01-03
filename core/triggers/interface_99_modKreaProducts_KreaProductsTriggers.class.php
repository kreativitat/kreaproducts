<?php
/* Copyright (C) 2023   Laurent Destailleur
 * Copyright (C) 2025   Marcelo
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 *
 * GNU GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsNutritionalCalculator.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsInventoryService.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsStockMovementService.class.php';

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
				$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
				return 1;

			case 'PRODUCT_MODIFY':
				if ($this->hasCostPriceChanged($object)) {
					$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
				}
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				return 1;

			case 'PRODUCT_SUBPRODUCT_ADD':
			case 'PRODUCT_SUBPRODUCT_DELETE':
				$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
				return 1;

			case 'PRODUCT_SUBPRODUCT_UPDATE':
				$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				return 1;

			case 'STOCK_MOVEMENT':
				// handleStockMovement() itself returns 1 or 0
				$stockService = new KreaProductsStockMovementService();
				return $stockService->handleStockMovement($object, $db, $conf, $user);

			case 'INVENTORY_RECORDED':
			case 'INVENTORY_MODIFY':
				// run our post-save rename hook
				$inventoryService = new KreaProductsInventoryService();
				$inventoryService->renameInventoryHeaderRef($object, $db);
				return 1;
			case 'INVENTORY_CREATE':
				$inventoryService = new KreaProductsInventoryService();
				return $inventoryService->prefillInventoryLinesAtCreate($object, $db, $user);

			default:
				return 0;
		}
	}

	private function syncCostPriceIfEnabled(int $productId, User $user, Conf $conf): void
	{
		static $inProgress = false;

		if ($productId <= 0 || $inProgress || empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {
			return;
		}

		$inProgress = true;
		try {
			ProductHierarchy::updateProductAttributes($productId, $user);
		} finally {
			$inProgress = false;
		}
	}

	private function hasCostPriceChanged($object): bool
	{
		if (!is_object($object)) {
			return false;
		}

		if (!isset($object->oldcopy) || !is_object($object->oldcopy)) {
			return true;
		}

		$oldCost = $object->oldcopy->cost_price ?? null;
		$newCost = $object->cost_price ?? null;

		if ($oldCost === null && $newCost === null) {
			return false;
		}
		if ($oldCost === null || $newCost === null) {
			return true;
		}

		return abs((float) $oldCost - (float) $newCost) > 0.0001;
	}

}
