<?php
/* Copyright (C) 2023       Laurent Destailleur
 * Copyright (C) 2024-2026  Kreativität Works  <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
dol_include_once('/kreaproducts/class/ProductUpdater.class.php');
dol_include_once('/kreaproducts/class/KreaProductsNutritionalCalculator.class.php');
dol_include_once('/kreaproducts/class/KreaProductsInventoryService.class.php');
dol_include_once('/kreaproducts/class/KreaProductsStockMovementService.class.php');
dol_include_once('/kreaproducts/class/KreaProductsSupplierPriceSyncService.class.php');

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
				if ($this->hasCostPriceChanged($object)) {
					$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
					$this->syncSellPriceFromCostIfEnabled((int) $object->id, $user, $conf);
				}
				return 1;

			case 'PRODUCT_MODIFY':
				if ($this->hasCostPriceChanged($object)) {
					$this->syncCostPriceIfEnabled((int) $object->id, $user, $conf);
					$this->syncSellPriceFromCostIfEnabled((int) $object->id, $user, $conf);
				}
				$this->syncAliasToDolizsynchShortDescription($object, $conf);
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

			case 'BILL_SUPPLIER_VALIDATE':
				if (empty($conf->global->KREAPRODUCTS_AUTO_SYNC_SUPPLIER_PRICE_FROM_PURCHASE)) {
					return 1;
				}
				$supplierPriceSync = new KreaProductsSupplierPriceSyncService();
				$supplierPriceSync->syncFromValidatedSupplierInvoice($object, $db, $user, $conf);
				return 1;

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

	private function syncSellPriceFromCostIfEnabled(int $productId, User $user, Conf $conf): void
	{
		static $inProgress = false;

		if (
			$productId <= 0
			|| $inProgress
			|| empty($conf->global->KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST)
		) {
			return;
		}

		$product = new Product($this->db);
		if ($product->fetch($productId) <= 0) {
			return;
		}
		if (!$this->shouldSyncSellPriceForProduct($product)) {
			return;
		}
		$syncPercent = $this->getSellPriceSyncPercentForProduct($product);
		if ($syncPercent === null || $syncPercent <= -100) {
			return;
		}

		$costPrice = $this->normalizeCostValue($product->cost_price ?? null);
		if ($costPrice === null || $costPrice < 0) {
			return;
		}

		$hasMultiprices = !empty($conf->global->PRODUIT_MULTIPRICES);
		$priceLevel = ($hasMultiprices ? 1 : 0);

		$baseType = 'HT';
		$vatTx = (float) price2num($product->tva_tx, 'MU');
		$currentMinPrice = (float) price2num($product->price_min, 'MU');
		$currentPrice = (float) price2num($product->price, 'MU');

		if ($hasMultiprices) {
			$baseType = strtoupper((string) ($product->multiprices_base_type[$priceLevel] ?? 'HT'));
			$vatTx = (float) price2num(
				$product->multiprices_tva_tx[$priceLevel] ?? $product->tva_tx,
				'MU'
			);
			if ($baseType === 'TTC') {
				$currentPrice = (float) price2num($product->multiprices_ttc[$priceLevel] ?? 0, 'MU');
				$currentMinPrice = (float) price2num($product->multiprices_min_ttc[$priceLevel] ?? 0, 'MU');
			} else {
				$currentPrice = (float) price2num($product->multiprices[$priceLevel] ?? 0, 'MU');
				$currentMinPrice = (float) price2num($product->multiprices_min[$priceLevel] ?? 0, 'MU');
			}
		} else {
			$baseType = strtoupper((string) (!empty($product->price_base_type) ? $product->price_base_type : 'HT'));
			if ($baseType === 'TTC') {
				$currentPrice = (float) price2num($product->price_ttc, 'MU');
				$currentMinPrice = (float) price2num($product->price_min_ttc, 'MU');
			} else {
				$currentMinPrice = (float) price2num($product->price_min, 'MU');
			}
		}

		if ($baseType !== 'TTC') {
			$baseType = 'HT';
		}

		$targetPriceHt = (float) price2num($costPrice * (1 + ($syncPercent / 100)), 'MU');
		if ($targetPriceHt < 0) {
			return;
		}

		$targetPrice = $targetPriceHt;
		if ($baseType === 'TTC') {
			$targetPrice = (float) price2num($targetPriceHt * (1 + ($vatTx / 100)), 'MU');
		}

		if (abs($currentPrice - $targetPrice) < 0.0001) {
			return;
		}

		$inProgress = true;
		try {
			$resUpdate = $product->updatePrice($targetPrice, $baseType, $user, $vatTx, $currentMinPrice, $priceLevel);
			if ($resUpdate <= 0) {
				dol_syslog(
					__METHOD__ . ' failed for product=' . $productId . ' error=' . ($product->error ?: $this->db->lasterror()),
					LOG_WARNING
				);
			}
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
			// Strict mode: without a previous snapshot we cannot prove a cost change.
			return false;
		}

		$oldCost = $this->normalizeCostValue($object->oldcopy->cost_price ?? null);
		$newCost = $this->normalizeCostValue($object->cost_price ?? null);

		if ($oldCost === null && $newCost === null) {
			return false;
		}
		if ($oldCost === null || $newCost === null) {
			return true;
		}

		return abs((float) $oldCost - (float) $newCost) > 0.0001;
	}

	/**
	 * Normalize cost values to float or null.
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizeCostValue($value): ?float
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value)) {
			$value = trim($value);
			if ($value === '') {
				return null;
			}
		}

		if (!is_numeric($value)) {
			return null;
		}

		return (float) $value;
	}

	private function normalizePercentageValue($value): ?float
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value)) {
			$value = trim($value);
			if ($value === '') {
				return null;
			}
		}

		if (!is_numeric($value)) {
			return null;
		}

		return (float) price2num($value, 'MU');
	}

	private function shouldSyncSellPriceForProduct(Product $product): bool
	{
		if (empty($product->id)) {
			return false;
		}

		$extrafields = new ExtraFields($this->db);
		$product->fetch_optionals((int) $product->id, $extrafields);

		return !empty($product->array_options['options_kreap_updatesellprice']);
	}

	private function getSellPriceSyncPercentForProduct(Product $product): ?float
	{
		if (empty($product->array_options) || !is_array($product->array_options)) {
			return null;
		}

		return $this->normalizePercentageValue($product->array_options['options_kreap_updatesellpricepct'] ?? null);
	}

	/**
	 * Keep DoliZSynch short description aligned with product alias.
	 *
	 * Sync runs only when `options_kreap_alias` is part of current product update payload.
	 */
	private function syncAliasToDolizsynchShortDescription($object, Conf $conf): void
	{
		$productId = (is_object($object) && !empty($object->id)) ? (int) $object->id : 0;
		if ($productId <= 0) {
			return;
		}

		$newAlias = $this->extractKreapAlias($object);
		if ($newAlias === null) {
			// Alias is not part of this PRODUCT_MODIFY flow.
			return;
		}

		$oldAlias = null;
		if (isset($object->oldcopy) && is_object($object->oldcopy)) {
			$oldAlias = $this->extractKreapAlias($object->oldcopy);
			if ($oldAlias !== null && $oldAlias === $newAlias) {
				return;
			}
		}

		$tableName = MAIN_DB_PREFIX . 'dolizsynch_zsproduct';
		if (!$this->tableExists($tableName) || !$this->tableColumnExists($tableName, 'descricaocurta')) {
			return;
		}

		$hasEntityColumn = $this->tableColumnExists($tableName, 'entity');
		$entity = isset($conf->entity) ? (int) $conf->entity : 0;
		$escapedAlias = "'" . $this->db->escape($newAlias) . "'";

		$sql = "UPDATE " . $tableName;
		$sql .= " SET descricaocurta = " . $escapedAlias;
		$sql .= " WHERE fk_product = " . $productId;
		if ($hasEntityColumn) {
			$sql .= " AND entity IN (0," . $entity . ")";
		}
		$sql .= " AND (descricaocurta IS NULL OR descricaocurta <> " . $escapedAlias . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(
				__METHOD__ . ' failed for product=' . $productId . ' alias update: ' . $this->db->lasterror(),
				LOG_WARNING
			);
		}
	}

	/**
	 * Extract `options_kreap_alias` from an object array_options payload.
	 *
	 * @param object $object Product-like object with array_options
	 * @return string|null Null when alias is not present in payload
	 */
	private function extractKreapAlias($object): ?string
	{
		if (!is_object($object) || !isset($object->array_options) || !is_array($object->array_options)) {
			return null;
		}
		if (!array_key_exists('options_kreap_alias', $object->array_options)) {
			return null;
		}

		$value = $object->array_options['options_kreap_alias'];
		return ($value === null ? '' : (string) $value);
	}

	/**
	 * Check if table exists (cached per request).
	 */
	private function tableExists(string $tableName): bool
	{
		static $cache = array();

		$tableName = trim($tableName);
		if ($tableName === '') {
			return false;
		}
		if (array_key_exists($tableName, $cache)) {
			return (bool) $cache[$tableName];
		}

		$exists = false;
		$res = $this->db->DDLDescTable($tableName);
		if ($res) {
			$exists = true;
			$this->db->free($res);
		}

		$cache[$tableName] = $exists;
		return $exists;
	}

	/**
	 * Check if table column exists (cached per request).
	 */
	private function tableColumnExists(string $tableName, string $columnName): bool
	{
		static $cache = array();

		$tableName = trim($tableName);
		$columnName = trim($columnName);
		if ($tableName === '' || $columnName === '') {
			return false;
		}

		$key = $tableName . '|' . $columnName;
		if (array_key_exists($key, $cache)) {
			return (bool) $cache[$key];
		}

		$exists = false;
		$res = $this->db->DDLDescTable($tableName, $columnName);
		if ($res) {
			$exists = ($this->db->num_rows($res) > 0);
			$this->db->free($res);
		}

		$cache[$key] = $exists;
		return $exists;
	}

}
