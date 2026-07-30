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
	/**
	 * @var int Guard used while this trigger performs controlled cost/sell updates.
	 */
	private static $costAndSellSyncSuspended = 0;

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
				if (self::$costAndSellSyncSuspended <= 0 && $this->hasCostPriceChanged($object)) {
					if ($this->syncCostPriceIfEnabled((int) $object->id, $user, $conf) < 0) {
						return -1;
					}
					$this->syncSellPriceFromCostIfEnabled((int) $object->id, $user, $conf);
				}
				return 1;

			case 'PRODUCT_MODIFY':
				if (self::$costAndSellSyncSuspended <= 0 && $this->hasCostPriceChanged($object)) {
					if ($this->syncCostPriceIfEnabled((int) $object->id, $user, $conf) < 0) {
						return -1;
					}
					$this->syncSellPriceFromCostIfEnabled((int) $object->id, $user, $conf);
				}
				$this->syncAliasToDolizsynchShortDescription($object, $conf);
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				return 1;

			case 'PRODUCT_SUBPRODUCT_ADD':
			case 'PRODUCT_SUBPRODUCT_DELETE':
				if ($this->syncCostPriceIfEnabled((int) $object->id, $user, $conf) < 0) {
					return -1;
				}
				return 1;

			case 'PRODUCT_SUBPRODUCT_UPDATE':
				if ($this->syncCostPriceIfEnabled((int) $object->id, $user, $conf) < 0) {
					return -1;
				}
				if (($object->array_options['options_kreap_calc_nut'] ?? 0) == 1) {
					KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
				}
				return 1;

			case 'STOCK_MOVEMENT':
				if (!empty($object->context['kreaproducts_inventory_ledger'])) {
					return 0;
				}
				$stockService = new KreaProductsStockMovementService();
				return $stockService->handleStockMovement($object, $db, $conf, $user);

			case 'INVENTORY_RECORDED':
			case 'INVENTORY_MODIFY':
				if (!empty($object->context['kreaproducts_mobile_inventory'])) {
					return 1;
				}
				// run our post-save rename hook
				$inventoryService = new KreaProductsInventoryService();
				$inventoryService->renameInventoryHeaderRef($object, $db);
				return 1;
			case 'INVENTORY_CREATE':
				if (!empty($object->context['kreaproducts_mobile_inventory'])) {
					return 1;
				}
				$inventoryService = new KreaProductsInventoryService();
				return $inventoryService->prefillInventoryLinesAtCreate($object, $db, $user);

			case 'BILL_SUPPLIER_VALIDATE':
				if (empty($conf->global->KREAPRODUCTS_AUTO_SYNC_SUPPLIER_PRICE_FROM_PURCHASE)) {
					return 1;
				}
				try {
					$supplierPriceSync = new KreaProductsSupplierPriceSyncService();
					$supplierPriceSync->syncFromValidatedSupplierInvoice($object, $db, $user, $conf);
					$this->syncCostAndSellPriceFromValidatedSupplierInvoice($object, $user, $conf);
					return 1;
				} catch (Throwable $exception) {
					$this->error = 'Supplier invoice synchronization failed: ' . $exception->getMessage();
					dol_syslog(__METHOD__ . ' invoice=' . (int) $object->id . ' ' . $this->error, LOG_ERR);
					return -1;
				}

			default:
				return 0;
		}
	}

	private function syncCostPriceIfEnabled(int $productId, User $user, Conf $conf): int
	{
		static $inProgress = false;

		if ($productId <= 0 || $inProgress || empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {
			return 1;
		}

		$inProgress = true;
		try {
			$result = ProductHierarchy::updateProductAttributes($productId, $user);
			if ($result < 0 || !empty(ProductUpdater::getLastErrors())) {
				$this->error = 'Product cost cascade failed: '.implode('; ', ProductUpdater::getLastErrors());
				dol_syslog(__METHOD__.' product='.(int) $productId.' '.$this->error, LOG_ERR);
				return -1;
			}
		} finally {
			$inProgress = false;
		}

		return 1;
	}

	private function syncSellPriceFromCostIfEnabled(int $productId, User $user, Conf $conf, bool $failOnError = false): void
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
			if ($failOnError) {
				throw new RuntimeException('Unable to load product ' . $productId . ' for selling-price synchronization');
			}
			return;
		}
		if (!$this->shouldSyncSellPriceForProduct($product, $failOnError)) {
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
				$message = 'Unable to update the selling price for product ' . $productId . ': ' . ($product->error ?: $this->db->lasterror());
				if ($failOnError) {
					throw new RuntimeException($message);
				}
				dol_syslog(__METHOD__ . ' ' . $message, LOG_WARNING);
			}
		} finally {
			$inProgress = false;
		}
	}

	/**
	 * Synchronize product cost (and dependent sell price) from validated supplier invoice lines.
	 *
	 * This is intentionally independent from supplier price-card rows because purchase invoices
	 * may be entered without `ref_supplier` and without pre-existing `product_fournisseur_price`.
	 */
	private function syncCostAndSellPriceFromValidatedSupplierInvoice($invoice, User $user, Conf $conf): void
	{
		static $inProgress = false;

		if ($inProgress || !$this->isSupplierInvoiceEligibleForCostSync($invoice, $conf)) {
			return;
		}

		$invoiceLines = $this->extractSupplierInvoiceLines($invoice);
		if (empty($invoiceLines)) {
			return;
		}

		$productTotals = $this->aggregateSupplierInvoiceCostsByProduct($invoiceLines);
		if (empty($productTotals)) {
			return;
		}

		$inProgress = true;
		self::$costAndSellSyncSuspended++;
		try {
			$changedProductIds = array();

			foreach ($productTotals as $productId => $totals) {
				$productId = (int) $productId;
				$totalQty = (float) ($totals['qty'] ?? 0);
				$totalAmount = (float) ($totals['amount'] ?? 0);
				if ($productId <= 0 || $totalQty <= 0 || $totalAmount < 0) {
					continue;
				}

				$newUnitCost = (float) price2num($totalAmount / $totalQty, 'MU');
				if ($newUnitCost < 0) {
					continue;
				}

				$product = new Product($this->db);
				if ($product->fetch($productId) <= 0) {
					throw new RuntimeException('Unable to load product ' . $productId . ' from supplier invoice ' . (int) $invoice->id);
				}
				if (!$this->isProductInCurrentEntityScope($product, $conf)) {
					throw new RuntimeException('Product ' . $productId . ' is outside the active product entity scope');
				}
				if (!$this->shouldSyncCostPriceForProduct($product, true)) {
					continue;
				}

				$currentCost = $this->normalizeCostValue($product->cost_price ?? null);
				if ($currentCost !== null && abs($currentCost - $newUnitCost) < 0.0001) {
					continue;
				}

				ProductUpdater::prepareProductCostUpdate($product);
				$product->cost_price = $newUnitCost;
				$resUpdate = $product->update($product->id, $user);
				if ($resUpdate <= 0) {
					throw new RuntimeException(
						'Unable to update the cost for product ' . $productId
						. ' from supplier invoice ' . (int) $invoice->id
						. ': ' . ($product->error ?: $this->db->lasterror())
					);
				}

				$changedProductIds[$productId] = $productId;
			}

			if (!empty($changedProductIds) && !empty($conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE)) {
				$cascadeResults = ProductUpdater::batchUpdateCostPrices(array_values($changedProductIds), true);
				$cascadeErrors = ProductUpdater::getLastErrors();
				if (!empty($cascadeErrors)) {
					throw new RuntimeException('Cost cascade failed: ' . implode('; ', $cascadeErrors));
				}
				foreach ($cascadeResults as $cascadeProductId => $cascadeResult) {
					if (!empty($cascadeResult['updated'])) {
						$changedProductIds[(int) $cascadeProductId] = (int) $cascadeProductId;
					}
				}
			}

			if (!empty($changedProductIds) && !empty($conf->global->KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST)) {
				foreach (array_values($changedProductIds) as $changedProductId) {
					$this->syncSellPriceFromCostIfEnabled((int) $changedProductId, $user, $conf, true);
				}
			}
		} finally {
			self::$costAndSellSyncSuspended--;
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
	 * Check if supplier invoice can drive cost synchronization.
	 */
	private function isSupplierInvoiceEligibleForCostSync($invoice, Conf $conf): bool
	{
		if (!is_object($invoice) || empty($invoice->id)) {
			return false;
		}

		if (!empty($invoice->type) && (int) $invoice->type === 2) {
			// Never update cost from supplier credit notes.
			return false;
		}

		if (!empty($invoice->entity) && (int) $invoice->entity !== (int) $conf->entity) {
			return false;
		}

		return true;
	}

	/**
	 * Extract supplier invoice lines from in-memory object or by fetching them.
	 *
	 * @param object $invoice
	 * @return array<int, object>
	 */
	private function extractSupplierInvoiceLines($invoice): array
	{
		if (empty($invoice->lines) && method_exists($invoice, 'fetch_lines')) {
			$invoice->fetch_lines();
		}
		if (empty($invoice->lines) || !is_array($invoice->lines)) {
			return array();
		}

		return $invoice->lines;
	}

	/**
	 * Aggregate effective purchase unit costs by product using weighted totals from invoice lines.
	 *
	 * @param array<int, object> $invoiceLines
	 * @return array<int, array{qty: float, amount: float}>
	 */
	private function aggregateSupplierInvoiceCostsByProduct(array $invoiceLines): array
	{
		$productTotals = array();

		foreach ($invoiceLines as $line) {
			if (!is_object($line)) {
				continue;
			}

			$productId = (int) ($line->fk_product ?? 0);
			if ($productId <= 0) {
				continue;
			}

			$qty = (float) price2num($line->qty ?? 0, 'MS');
			if ($qty <= 0) {
				continue;
			}

			$unitCost = $this->extractEffectiveLineUnitCost($line, $qty);
			if ($unitCost === null || $unitCost < 0) {
				continue;
			}

			if (!isset($productTotals[$productId])) {
				$productTotals[$productId] = array('qty' => 0.0, 'amount' => 0.0);
			}

			$productTotals[$productId]['qty'] += $qty;
			$productTotals[$productId]['amount'] += (float) price2num($unitCost * $qty, 'MU');
		}

		return $productTotals;
	}

	/**
	 * Resolve an effective HT unit cost for one supplier invoice line.
	 *
	 * Priority:
	 * 1. total_ht / qty (captures line discounts),
	 * 2. subprice,
	 * 3. pu_ht.
	 */
	private function extractEffectiveLineUnitCost($line, float $qty): ?float
	{
		if ($qty <= 0) {
			return null;
		}

		$totalHt = $this->normalizeCostValue($line->total_ht ?? null);
		if ($totalHt !== null) {
			return (float) price2num($totalHt / $qty, 'MU');
		}

		$subprice = $this->normalizeCostValue($line->subprice ?? null);
		if ($subprice !== null) {
			return $subprice;
		}

		$puHt = $this->normalizeCostValue($line->pu_ht ?? null);
		if ($puHt !== null) {
			return $puHt;
		}

		return null;
	}

	/**
	 * Ensure updates are limited to products reachable from current entity scope.
	 */
	private function isProductInCurrentEntityScope(Product $product, Conf $conf): bool
	{
		if (!isset($product->entity) || $product->entity === '') {
			return false;
		}

		$currentEntity = (int) $conf->entity;
		$productEntity = (int) $product->entity;
		if ($productEntity === $currentEntity) {
			return true;
		}

		$entityList = getEntity('product');
		if ($entityList === '') {
			return false;
		}

		$entities = array_map('intval', array_filter(array_map('trim', explode(',', $entityList)), 'strlen'));
		return in_array($productEntity, $entities, true);
	}

	/**
	 * Check per-product flag for cost sync from purchase flows.
	 */
	private function shouldSyncCostPriceForProduct(Product $product, bool $failOnError = false): bool
	{
		if (empty($product->id)) {
			return false;
		}

		$extrafields = new ExtraFields($this->db);
		$result = $product->fetch_optionals((int) $product->id, $extrafields);
		if ($result < 0) {
			if ($failOnError) {
				throw new RuntimeException('Unable to load cost synchronization settings for product ' . (int) $product->id);
			}
			return false;
		}

		if (!empty($product->array_options['options_kreap_updatebuyprice'])) {
			return true;
		}

		// Legacy field kept for backward compatibility.
		return !empty($product->array_options['options_kreap_syncprice']);
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

	private function shouldSyncSellPriceForProduct(Product $product, bool $failOnError = false): bool
	{
		if (empty($product->id)) {
			return false;
		}

		$extrafields = new ExtraFields($this->db);
		$result = $product->fetch_optionals((int) $product->id, $extrafields);
		if ($result < 0) {
			if ($failOnError) {
				throw new RuntimeException('Unable to load selling-price synchronization settings for product ' . (int) $product->id);
			}
			return false;
		}

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
