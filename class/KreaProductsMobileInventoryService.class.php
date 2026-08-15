<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once __DIR__.'/KreaProductsBusinessDayService.class.php';
require_once __DIR__.'/KreaProductsInventoryLedgerCalculator.class.php';
require_once __DIR__.'/KreaProductsInventoryMovementService.class.php';
require_once __DIR__.'/KreaProductsStockMovementService.class.php';

/**
 * Exception returned by the KreaProductsStock application API.
 */
class KreaProductsStockApiException extends Exception
{
	/** @var int */
	public $httpCode;

	/**
	 * @param string $message Error message
	 * @param int    $httpCode HTTP status code
	 */
	public function __construct($message, $httpCode = 400)
	{
		parent::__construct($message);
		$this->httpCode = (int) $httpCode;
	}
}

/**
 * Entity-safe inventory lifecycle for the KreaProductsStock mobile application.
 */
class KreaProductsMobileInventoryService
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var Translate */
	private $langs;

	/** @var Conf */
	private $conf;

	/** @var bool */
	private $schedulerMode = false;

	/**
	 * @param DoliDB    $db    Database handler
	 * @param User      $user  Current user
	 * @param Translate $langs Translation handler
	 * @param Conf      $conf  Dolibarr configuration
	 */
	public function __construct($db, $user, $langs, $conf)
	{
		$this->db = $db;
		$this->user = $user;
		$this->langs = $langs;
		$this->conf = $conf;
	}

	/**
	 * List configured inventory templates and their active inventory.
	 *
	 * @return array<string,mixed>
	 */
	public function listTemplates()
	{
		$this->requireReadAccess();

		$rootCategoryId = $this->getRootCategoryId();
		$warehouses = $this->listWarehouses();
		$defaultWarehouseId = $this->resolveDefaultWarehouseId($warehouses);
		$currentValueTimestamp = $this->resolveInventoryValueTimestamp(dol_now());
		$blockingOpenInventory = $this->findAnyOpenManagedInventory();

		$sql = 'SELECT c.rowid, c.label, COUNT(DISTINCT p.rowid) as product_count';
		$sql .= ' FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'categorie_product as cp ON cp.fk_categorie = c.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'product as p ON p.rowid = cp.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' AND p.fk_product_type = 0';
		if ($this->mustExcludeKitParents()) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->prefix().'product_association as pa';
			$sql .= ' WHERE pa.fk_product_pere = p.rowid AND pa.incdec = 1)';
		}
		$sql .= ' WHERE c.fk_parent = '.((int) $rootCategoryId);
		$sql .= ' AND c.type = 0';
		$sql .= ' AND c.entity IN ('.getEntity('category').')';
		$sql .= ' GROUP BY c.rowid, c.label';
		$sql .= ' ORDER BY c.label ASC, c.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$templates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$categoryId = (int) $obj->rowid;
			$openInventory = $this->findOpenInventory($categoryId, $defaultWarehouseId);
			if (empty($openInventory['id'])) {
				$openInventory = $this->findBusinessDayInventory($categoryId, $defaultWarehouseId, $currentValueTimestamp);
			}
			$templates[] = array(
				'id' => $categoryId,
				'label' => $this->cleanTemplateLabel((string) $obj->label),
				'full_label' => (string) $obj->label,
				'product_count' => (int) $obj->product_count,
				'open_inventory' => $openInventory,
			);
		}
		$this->db->free($resql);

		return array(
			'root_category_id' => $rootCategoryId,
			'default_warehouse_id' => $defaultWarehouseId,
			'history_enabled' => $this->isHistoryEnabled() ? 1 : 0,
			'blocking_open_inventory' => $blockingOpenInventory,
			'warehouses' => $warehouses,
			'templates' => $templates,
		);
	}

	/**
	 * Create and validate an inventory from a direct child category.
	 *
	 * @param int $categoryId  Template category ID
	 * @param int $warehouseId Warehouse ID
	 * @return array<string,mixed>
	 */
	public function startInventory($categoryId, $warehouseId = 0)
	{
		$this->requireCountAccess();
		$this->requireInventoryValueDatingEnabled();

		$category = $this->fetchTemplateCategory((int) $categoryId);
		$warehouse = $this->fetchWarehouse((int) $warehouseId);
		$valueTimestamp = $this->resolveInventoryValueTimestamp(dol_now());
		$this->beginStockTransaction();
		try {
			$this->lockInventoryStartScope((int) $category->rowid, (int) $warehouse->rowid);
			$openInventory = $this->findOpenInventory((int) $category->rowid, (int) $warehouse->rowid);
			if (!empty($openInventory['id'])) {
				$this->commitStockTransaction();
				return $this->getInventory((int) $openInventory['id']);
			}
			$dayInventory = $this->findBusinessDayInventory((int) $category->rowid, (int) $warehouse->rowid, $valueTimestamp);
			if (!empty($dayInventory['id'])) {
				$this->commitStockTransaction();
				return $this->getInventory((int) $dayInventory['id']);
			}
			$blockingOpenInventory = $this->findAnyOpenManagedInventory();
			if (!empty($blockingOpenInventory['id'])) {
				throw new KreaProductsStockApiException(
					$this->langs->trans('KREAPRODUCTS_INVENTORY_OPEN_BLOCKED', (string) $blockingOpenInventory['ref']),
					409
				);
			}
			$overlappingInventory = $this->findOverlappingBusinessDayInventory((int) $category->rowid, (int) $warehouse->rowid, $valueTimestamp);
			if (!empty($overlappingInventory['id'])) {
				throw new KreaProductsStockApiException(
					$this->langs->trans('KREAPRODUCTS_ERROR_OVERLAPPING_INVENTORY', (string) $overlappingInventory['ref']),
					409
				);
			}

			$inventory = new Inventory($this->db);
			$inventory->context['kreaproducts_mobile_inventory'] = 1;
			$inventory->entity = (int) $this->conf->entity;
			$inventory->title = $this->cleanTemplateLabel((string) $category->label);
			$inventory->ref = $this->buildTemporaryInventoryReference((int) $category->rowid);
			$inventory->import_key = 'KPS';
			$inventory->fk_warehouse = (int) $warehouse->rowid;
			$inventory->categories_product = (string) ((int) $category->rowid);
			$inventory->date_inventory = $valueTimestamp;

			$result = $inventory->create($this->user);
			if ($result <= 0) {
				throw new KreaProductsStockApiException($this->getObjectError($inventory, $this->langs->trans('KREAPRODUCTS_ERROR_CREATE_INVENTORY')), 500);
			}
			$inventory->ref = $this->buildProvisionalInventoryReference((int) $inventory->id);
			$this->persistInventoryReference((int) $inventory->id, $inventory->ref);
			$result = $inventory->validate($this->user);
			if ($result <= 0) {
				throw new KreaProductsStockApiException($this->getObjectError($inventory, $this->langs->trans('KREAPRODUCTS_ERROR_START_INVENTORY')), 500);
			}

			$this->removeNonOperationalKitParentLines((int) $inventory->id);
			$this->addMissingZeroStockProducts($inventory, (int) $category->rowid, (int) $warehouse->rowid);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}
		$this->commitStockTransaction();

		dol_syslog(__METHOD__.' inventory='.$inventory->id.' category='.$category->rowid.' warehouse='.$warehouse->rowid.' entity='.$this->conf->entity, LOG_INFO);

		return $this->getInventory((int) $inventory->id);
	}

	/**
	 * Fetch one inventory and its count lines.
	 *
	 * @param int $inventoryId Inventory ID
	 * @return array<string,mixed>
	 */
	public function getInventory($inventoryId)
	{
		$this->requireReadAccess();
		$canViewInventoryAnalysis = $this->canViewInventoryAnalysis();
		$inventory = $this->fetchInventoryRecord((int) $inventoryId);
		$this->normalizeInitiatedTechnicalReference($inventory);

		$sql = 'SELECT id.rowid, id.fk_product, id.fk_warehouse, id.batch, id.qty_stock, id.qty_view,';
		$sql .= ' p.ref, p.label, p.barcode, p.tobatch';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = id.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventory->rowid);
		$sql .= ' ORDER BY p.ref ASC, id.batch ASC, id.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$lines = array();
		$counted = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$isCounted = !is_null($obj->qty_view);
			if ($isCounted) {
				$counted++;
			}
			$line = array(
				'id' => (int) $obj->rowid,
				'product_id' => (int) $obj->fk_product,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'barcode' => (string) $obj->barcode,
				'batch' => (string) $obj->batch,
				'batch_managed' => (int) $obj->tobatch,
				'counted' => $isCounted ? 1 : 0,
				'quantity' => $isCounted ? (float) $obj->qty_view : null,
			);
			if ($canViewInventoryAnalysis) {
				$line['expected_quantity'] = (float) $obj->qty_stock;
			}
			$lines[] = $line;
		}
		$this->db->free($resql);

		$total = count($lines);
		$templateCategoryId = !empty($inventory->template_category_id) ? (int) $inventory->template_category_id : $this->extractSingleTemplateCategoryId((string) $inventory->categories_product);
		$category = $this->fetchCategoryById($templateCategoryId);
		$isOpen = (int) $inventory->status === Inventory::STATUS_VALIDATED;
		$isKreaProductsStockInventory = $this->isKreaProductsStockReference((string) $inventory->ref, (string) $inventory->import_key);
		$isCurrentBusinessDay = $isKreaProductsStockInventory && $this->isInventoryInCurrentCountingWindow($inventory);
		$openInventoryCanBeCounted = $isOpen;
		$canCorrect = $isKreaProductsStockInventory
			&& (int) $inventory->status === Inventory::STATUS_RECORDED
			&& $isCurrentBusinessDay
			&& $this->canCount()
			&& !$this->hasReversedAdjustments((int) $inventory->rowid);
		$canCount = $isKreaProductsStockInventory && $this->canCount() && ($openInventoryCanBeCounted || $canCorrect);
		$canClose = $isKreaProductsStockInventory
			&& $isOpen
			&& (int) $this->db->jdate($inventory->date_inventory) <= dol_now()
			&& $this->canClose();
		$canDelete = $isKreaProductsStockInventory && $isOpen && $this->canCount();
		$canReverse = $isKreaProductsStockInventory
			&& (int) $inventory->status === Inventory::STATUS_RECORDED
			&& !$isCurrentBusinessDay
			&& $this->canClose()
			&& $this->hasActiveAdjustments((int) $inventory->rowid);

		return array(
			'id' => (int) $inventory->rowid,
			'ref' => (string) $inventory->ref,
			'title' => (string) $inventory->title,
			'status' => (int) $inventory->status,
			'category_id' => $templateCategoryId,
			'category_label' => $category ? $this->cleanTemplateLabel((string) $category->label) : (string) $inventory->title,
			'warehouse_id' => (int) $inventory->fk_warehouse,
			'warehouse_ref' => (string) $inventory->warehouse_ref,
			'date_creation' => $this->db->jdate($inventory->date_creation),
			'date_inventory' => $this->db->jdate($inventory->date_inventory),
			'max_value_date' => $this->resolveInventoryValueTimestamp(dol_now()),
			'counted_lines' => $counted,
			'total_lines' => $total,
			'complete' => ($total > 0 && $counted === $total) ? 1 : 0,
			'editable' => $canCount ? 1 : 0,
			'can_count' => $canCount ? 1 : 0,
			'can_edit_value_date' => ($isKreaProductsStockInventory && $isOpen && $this->canCount()) ? 1 : 0,
			'can_close' => $canClose ? 1 : 0,
			'can_delete' => $canDelete ? 1 : 0,
			'can_reverse' => $canReverse ? 1 : 0,
			'correction_mode' => $canCorrect ? 1 : 0,
			'managed' => $isKreaProductsStockInventory ? 1 : 0,
			'can_view_analysis' => $canViewInventoryAnalysis ? 1 : 0,
			'lines' => $lines,
		);
	}

	/**
	 * Return operational intake and consumption statistics for an inventory.
	 *
	 * Positive movements are intake and the absolute value of negative movements
	 * is consumption. Inventory corrections and reversals are intentionally
	 * excluded because they are stock anchors, not operational flows.
	 *
	 * @param int $inventoryId Inventory ID
	 * @param int $days        Number of calendar days, including today
	 * @return array<string,mixed>
	 */
	public function getInventoryStatistics($inventoryId, $days = 15)
	{
		$this->requireReadAccess();
		if (!$this->canViewInventoryAnalysis()) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}
		$inventory = $this->fetchInventoryRecord((int) $inventoryId);
		$days = max(1, min(31, (int) $days));
		$timezone = $this->getOperationTimezone();
		$today = new DateTimeImmutable('today', $timezone);
		$periodStart = $today->modify('-'.($days - 1).' days');
		$periodEndExclusive = $today->modify('+1 day');

		$daily = array();
		for ($day = $periodStart; $day < $periodEndExclusive; $day = $day->modify('+1 day')) {
			$key = $day->format('Y-m-d');
			$daily[$key] = array(
				'date' => $key,
				'label' => $day->format('d/m'),
				'consumption' => 0.0,
				'intake' => 0.0,
			);
		}

		$sql = 'SELECT DISTINCT id.fk_product, p.ref, p.label';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = id.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventory->rowid);
		$sql .= ' ORDER BY p.ref ASC, id.fk_product ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$products = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$products[(int) $obj->fk_product] = array(
				'product_id' => (int) $obj->fk_product,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'consumption' => 0.0,
				'intake' => 0.0,
				'net' => 0.0,
				'daily' => $daily,
			);
		}
		$this->db->free($resql);

		if (!empty($products)) {
			$excludedOrigins = array();
			foreach (KreaProductsInventoryLedgerCalculator::excludedMovementOrigins() as $excludedOrigin) {
				$excludedOrigins[] = "'".$this->db->escape($excludedOrigin)."'";
			}
			$sql = 'SELECT sm.fk_product, sm.value, sm.datem';
			$sql .= ' FROM '.$this->db->prefix().'stock_mouvement as sm';
			$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = sm.fk_product';
			$sql .= ' AND p.entity IN ('.getEntity('product').')';
			$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = sm.fk_entrepot';
			$sql .= ' AND e.entity IN ('.getEntity('stock').')';
			$sql .= ' WHERE sm.fk_entrepot = '.((int) $inventory->fk_warehouse);
			$sql .= ' AND EXISTS (SELECT 1 FROM '.$this->db->prefix().'inventorydet as inventory_product';
			$sql .= ' WHERE inventory_product.fk_inventory = '.((int) $inventory->rowid);
			$sql .= ' AND inventory_product.fk_product = sm.fk_product)';
			$sql .= ' AND (sm.origintype IS NULL OR sm.origintype NOT IN ('.implode(', ', $excludedOrigins).'))';
			$sql .= " AND sm.datem >= '".$this->db->escape($this->db->idate($periodStart->getTimestamp()))."'";
			$sql .= " AND sm.datem < '".$this->db->escape($this->db->idate($periodEndExclusive->getTimestamp()))."'";
			$sql .= ' ORDER BY sm.datem ASC, sm.rowid ASC';

			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$productId = (int) $obj->fk_product;
				if (!isset($products[$productId])) {
					continue;
				}
				$movementDate = (new DateTimeImmutable('@'.$this->db->jdate($obj->datem)))->setTimezone($timezone);
				$dayKey = $movementDate->format('Y-m-d');
				if (!isset($products[$productId]['daily'][$dayKey])) {
					continue;
				}
				$value = (float) $obj->value;
				if ($value < 0) {
					$quantity = abs($value);
					$products[$productId]['daily'][$dayKey]['consumption'] += $quantity;
					$products[$productId]['consumption'] += $quantity;
				} elseif ($value > 0) {
					$products[$productId]['daily'][$dayKey]['intake'] += $value;
					$products[$productId]['intake'] += $value;
				}
			}
			$this->db->free($resql);
		}

		foreach ($products as &$product) {
			$product['net'] = $product['intake'] - $product['consumption'];
			$product['daily'] = array_values($product['daily']);
		}
		unset($product);

		return array(
			'period_start' => $periodStart->format('Y-m-d'),
			'period_end' => $today->format('Y-m-d'),
			'days' => $days,
			'warehouse_id' => (int) $inventory->fk_warehouse,
			'products' => array_values($products),
		);
	}

	/**
	 * Return current Dolibarr virtual stock grouped by inventory category.
	 *
	 * @return array<string,mixed>
	 */
	public function getInventoryStockOverview()
	{
		if (empty($this->user->id)
			|| !$this->user->hasRight('stock', 'lire')
			|| !$this->canViewInventoryAnalysis()
		) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$rootCategoryId = $this->getRootCategoryId();
		$categoryMap = $this->listTemplateCategoryMap();
		$categories = array();
		foreach ($categoryMap as $categoryId => $categoryLabel) {
			$categories[(int) $categoryId] = array(
				'id' => (int) $categoryId,
				'label' => (string) $categoryLabel,
				'products' => array(),
			);
		}
		if (empty($categories)) {
			return array(
				'root_category_id' => $rootCategoryId,
				'categories' => array(),
			);
		}

		$sql = 'SELECT DISTINCT c.rowid as category_id, p.rowid as product_id, p.ref, p.label';
		$sql .= ' FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' INNER JOIN '.$this->db->prefix().'categorie_product as cp ON cp.fk_categorie = c.rowid';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = cp.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' AND p.fk_product_type = 0';
		if ($this->mustExcludeKitParents()) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->prefix().'product_association as pa';
			$sql .= ' WHERE pa.fk_product_pere = p.rowid AND pa.incdec = 1)';
		}
		$sql .= ' WHERE c.fk_parent = '.((int) $rootCategoryId);
		$sql .= ' AND c.type = 0';
		$sql .= ' AND c.entity IN ('.getEntity('category').')';
		$sql .= ' ORDER BY c.label ASC, p.ref ASC, p.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$virtualStockByProduct = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$categoryId = (int) $obj->category_id;
			$productId = (int) $obj->product_id;
			if (!isset($categories[$categoryId])) {
				continue;
			}
			if (!array_key_exists($productId, $virtualStockByProduct)) {
				$product = new Product($this->db);
				if ($product->fetch($productId) <= 0 || $product->load_stock('nobatch') < 0) {
					$this->db->free($resql);
					throw new KreaProductsStockApiException($product->error ?: $this->langs->trans('KREAPRODUCTS_ERROR_LOAD_VIRTUAL_STOCK'), 500);
				}
				$virtualStockByProduct[$productId] = (float) $product->stock_theorique;
			}
			$categories[$categoryId]['products'][] = array(
				'product_id' => $productId,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'virtual_stock' => $virtualStockByProduct[$productId],
			);
		}
		$this->db->free($resql);

		return array(
			'root_category_id' => $rootCategoryId,
			'categories' => array_values($categories),
		);
	}

	/**
	 * List KreaProductsStock inventories for mobile access.
	 *
	 * @return array<string,mixed>
	 */
	public function listInventories()
	{
		$this->requireReadAccess();

		$templateCategories = $this->listTemplateCategoryMap();
		if (empty($templateCategories)) {
			return array(
				'history_enabled' => 1,
				'inventories' => array(),
			);
		}

		$categoryConditions = array();
		foreach (array_keys($templateCategories) as $categoryId) {
			$categoryConditions[] = $this->buildCategoryContainsSqlCondition('i.categories_product', (int) $categoryId);
		}
		$listLimit = $this->getInventoryListLimit();

		$sql = 'SELECT i.rowid, i.ref, i.title, i.status, i.categories_product, i.fk_warehouse, i.date_creation, i.date_inventory,';
		$sql .= ' e.ref as warehouse_ref,';
		$sql .= ' COUNT(id.rowid) as total_lines,';
		$sql .= ' SUM(CASE WHEN id.qty_view IS NOT NULL THEN 1 ELSE 0 END) as counted_lines';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = i.fk_warehouse';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory = i.rowid';
		$sql .= ' WHERE i.entity = '.((int) $this->conf->entity);
		$sql .= ' AND (i.status = '.((int) Inventory::STATUS_RECORDED);
		$sql .= ' OR (i.status = '.((int) Inventory::STATUS_VALIDATED)." AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')))";
		$sql .= ' AND ('.implode(' OR ', $categoryConditions).')';
		$sql .= ' GROUP BY i.rowid, i.ref, i.title, i.status, i.categories_product, i.fk_warehouse, i.date_creation, i.date_inventory, e.ref';
		$sql .= ' ORDER BY i.date_creation DESC, i.rowid DESC';
		$sql .= $this->db->plimit($listLimit, 0);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$inventories = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$categoryId = $this->extractSingleTemplateCategoryId((string) $obj->categories_product);
			if ($categoryId <= 0 || !isset($templateCategories[$categoryId])) {
				continue;
			}
			$inventories[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'title' => (string) $obj->title,
				'status' => (int) $obj->status,
				'category_id' => $categoryId,
				'category_label' => isset($templateCategories[$categoryId]) ? $templateCategories[$categoryId] : (string) $obj->title,
				'warehouse_id' => (int) $obj->fk_warehouse,
				'warehouse_ref' => (string) $obj->warehouse_ref,
				'date_creation' => $this->db->jdate($obj->date_creation),
				'date_inventory' => $this->db->jdate($obj->date_inventory),
				'counted_lines' => (int) $obj->counted_lines,
				'total_lines' => (int) $obj->total_lines,
			);
		}
		$this->db->free($resql);

		return array(
			'history_enabled' => 1,
			'inventories' => $inventories,
		);
	}

	/**
	 * Backward-compatible alias for older frontend builds.
	 *
	 * @return array<string,mixed>
	 */
	public function listCompletedInventories()
	{
		return $this->listInventories();
	}

	/**
	 * Delete an initiated KreaProductsStock inventory before any stock movements are generated.
	 *
	 * @param int $inventoryId Inventory ID
	 * @return array<string,int>
	 */
	public function deleteInventory($inventoryId)
	{
		$this->requireCountAccess();
		$record = $this->fetchInventoryRecord((int) $inventoryId);
		if (!$this->isKreaProductsStockReference((string) $record->ref, (string) $record->import_key)) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_MANAGED'), 404);
		}
		if ((int) $record->status !== Inventory::STATUS_VALIDATED) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_DELETE_INITIATED_ONLY'), 409);
		}

		$inventory = new Inventory($this->db);
		$inventory->context['kreaproducts_mobile_inventory'] = 1;
		$result = $inventory->fetch((int) $inventoryId);
		if ($result <= 0) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_FOUND'), 404);
		}

		$this->beginStockTransaction();
		$error = 0;
		$errorMessage = '';

		$sql = 'SELECT i.rowid, i.ref, i.status, i.categories_product';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' WHERE i.rowid = '.((int) $inventoryId);
		$sql .= ' AND i.entity = '.((int) $this->conf->entity);
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$lockedInventory = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$lockedInventory) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_ENTITY_NOT_FOUND'), 404);
		}
		if ((int) $lockedInventory->status !== Inventory::STATUS_VALIDATED) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_STATUS_CHANGED_BEFORE_DELETE'), 409);
		}
		if (!$this->isTemplateCategoryValue((string) $lockedInventory->categories_product)) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_MANAGED'), 404);
		}

		$sql = 'SELECT id.rowid, id.qty_view';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventoryId);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$this->db->free($resql);

		$result = $inventory->setDraft($this->user);
		if ($result <= 0) {
			$error++;
			$errorMessage = $this->getObjectError($inventory, $this->langs->trans('KREAPRODUCTS_ERROR_DRAFT_BEFORE_DELETE'));
		}

		if (!$error) {
			$result = $inventory->delete($this->user);
			if ($result <= 0) {
				$error++;
				$errorMessage = $this->getObjectError($inventory, $this->langs->trans('KREAPRODUCTS_ERROR_DELETE_INVENTORY'));
			}
		}

		if ($error) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($errorMessage, 500);
		}

		$this->commitStockTransaction();
		dol_syslog(__METHOD__.' inventory='.$inventoryId.' user='.$this->user->id, LOG_NOTICE);

		return array(
			'deleted' => 1,
			'inventory_id' => (int) $inventoryId,
		);
	}

	/**
	 * Persist absolute physical quantities for inventory lines.
	 *
	 * @param int                         $inventoryId Inventory ID
	 * @param array<int,array<string,mixed>> $counts     Count rows
	 * @param string                      $calendarDate Optional editable value date in YYYY-MM-DD format
	 * @return array<string,mixed>
	 */
	public function saveCounts($inventoryId, array $counts, $calendarDate = '')
	{
		$this->requireCountAccess();
		$this->requireInventoryValueDatingEnabled();
		$inventory = $this->fetchInventoryRecord((int) $inventoryId);
		if (!$this->isKreaProductsStockReference((string) $inventory->ref, (string) $inventory->import_key)) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_MANAGED'), 404);
		}
		if ((int) $inventory->status === Inventory::STATUS_RECORDED) {
			if (!$this->isCurrentBusinessDayInventory($inventory)) {
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_CURRENT_DAY_CORRECTION_ONLY'), 409);
			}
			if ($this->hasReversedAdjustments((int) $inventory->rowid)) {
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_REVERSED_NOT_CORRECTABLE'), 409);
			}
			return $this->correctRecordedCounts($inventory, $counts);
		}
		if ((int) $inventory->status !== Inventory::STATUS_VALIDATED) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_COUNT_OPEN_ONLY'), 409);
		}
		$calendarDate = trim((string) $calendarDate);
		$hasExplicitValueDate = $calendarDate !== '';
		$valueDateSql = '';
		if ($hasExplicitValueDate) {
			try {
				$editableValueTimestamp = $this->resolveEditableInventoryValueTimestamp($calendarDate);
				if ($editableValueTimestamp > $this->resolveInventoryValueTimestamp(dol_now())) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_VALUE_DATE_AFTER_WINDOW'), 400);
				}
				$valueDateSql = $this->db->idate($editableValueTimestamp);
			} catch (InvalidArgumentException $exception) {
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_VALUE_DATE_INVALID'), 400);
			}
		}
		if (empty($counts) && !$hasExplicitValueDate) {
			return $this->getInventory((int) $inventoryId);
		}

		$this->beginStockTransaction();
		$error = 0;
		$errorMessage = '';
		try {
			$this->lockInventoryStartScope((int) $inventory->template_category_id, (int) $inventory->fk_warehouse);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}

		$sql = 'SELECT i.rowid, i.ref, i.status, i.date_inventory';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' WHERE i.rowid = '.((int) $inventoryId);
		$sql .= ' AND i.entity = '.((int) $this->conf->entity);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		$lockedInventory = $resql ? $this->db->fetch_object($resql) : false;
		if ($resql) {
			$this->db->free($resql);
		}
		if (!$lockedInventory || (int) $lockedInventory->status !== Inventory::STATUS_VALIDATED) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_STATUS_CHANGED_BEFORE_SAVE'), 409);
		}

		$sql = 'SELECT id.rowid, id.qty_view';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventoryId);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$allowedLineIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$allowedLineIds[(int) $obj->rowid] = true;
		}
		$this->db->free($resql);
		foreach ($counts as $count) {
			$lineId = isset($count['line_id']) ? (int) $count['line_id'] : 0;
			$hasQuantity = array_key_exists('quantity', $count);
			$rawQuantity = $hasQuantity ? $count['quantity'] : null;
			if ($lineId <= 0 || empty($allowedLineIds[$lineId])) {
				$error++;
				$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_LINE_ENTITY');
				break;
			}
			if (!$hasQuantity) {
				$error++;
				$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_COUNT_NUMERIC_OR_BLANK');
				break;
			}
			try {
				$quantity = KreaProductsInventoryLedgerCalculator::normalizePhysicalCount($rawQuantity);
			} catch (InvalidArgumentException $exception) {
				$error++;
				$errorMessage = $this->translateCountValidationError($exception);
				break;
			}

			$line = new InventoryLine($this->db);
			$result = $line->fetch($lineId);
			if ($result <= 0 || (int) $line->fk_inventory !== (int) $inventoryId) {
				$error++;
				$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_LINE_LOAD');
				break;
			}
			$line->qty_view = $quantity;
			$result = $line->update($this->user);
			if ($result <= 0) {
				$error++;
				$errorMessage = $this->getObjectError($line, $this->langs->trans('KREAPRODUCTS_ERROR_SAVE_COUNT'));
				break;
			}
		}

		if (!$error && $valueDateSql === '') {
			$lockedValueDate = is_numeric($lockedInventory->date_inventory)
				? $this->db->idate((int) $lockedInventory->date_inventory)
				: (string) $lockedInventory->date_inventory;
			try {
				$valueDateSql = $this->db->idate(
					$this->resolveEditableInventoryValueTimestamp(substr($lockedValueDate, 0, 10))
				);
			} catch (InvalidArgumentException $exception) {
				$error++;
				$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_VALUE_DATE_INVALID');
			}
		}

		if (!$error) {
			$sql = 'UPDATE '.$this->db->prefix().'inventory';
			$sql .= ' SET fk_user_modif = '.((int) $this->user->id);
			if ($valueDateSql !== '' && !$hasExplicitValueDate) {
				try {
					$assignedInventory = $this->findBusinessDayInventory(
						(int) $inventory->template_category_id,
						(int) $inventory->fk_warehouse,
						$this->db->jdate($valueDateSql),
						(int) $inventoryId
					);
					if (!empty($assignedInventory['id'])) {
						throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_SCOPE_EXISTS'), 409);
					}
					$overlappingInventory = $this->findOverlappingBusinessDayInventory(
						(int) $inventory->template_category_id,
						(int) $inventory->fk_warehouse,
						$this->db->jdate($valueDateSql),
						(int) $inventoryId
					);
					if (!empty($overlappingInventory['id'])) {
						throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_TEMPLATE_ALREADY_ANCHORED'), 409);
					}
				} catch (Throwable $exception) {
					$this->db->rollback();
					throw $exception;
				}
			}
			if ($valueDateSql !== '') {
				$sql .= ", date_inventory = '".$this->db->escape($valueDateSql)."'";
			}
			$sql .= ' WHERE rowid = '.((int) $inventoryId);
			$sql .= ' AND entity = '.((int) $this->conf->entity);
			if (!$this->db->query($sql)) {
				$error++;
				$errorMessage = $this->db->lasterror();
			}
		}

		if ($error) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($errorMessage, 500);
		}

		$this->commitStockTransaction();
		dol_syslog(__METHOD__.' inventory='.$inventoryId.' count_rows='.count($counts).' user='.$this->user->id, LOG_INFO);

		return $this->getInventory((int) $inventoryId);
	}

	/**
	 * Append corrections to the current business-day inventory without replacing history.
	 *
	 * Blank submitted values are ignored. Numeric values change the physical anchor through
	 * a new correction movement and an immutable audit row.
	 *
	 * @param object                         $record Inventory database record
	 * @param array<int,array<string,mixed>> $counts Submitted count rows
	 * @return array<string,mixed>
	 */
	private function correctRecordedCounts($record, array $counts)
	{
		if (empty($counts)) {
			return $this->getInventory((int) $record->rowid);
		}

		$this->beginStockTransaction();
		$sql = 'SELECT i.rowid, i.status, i.date_inventory FROM '.$this->db->prefix().'inventory i';
		$sql .= ' WHERE i.rowid='.((int) $record->rowid);
		$sql .= ' AND i.entity='.((int) $this->conf->entity).' FOR UPDATE';
		$resql = $this->db->query($sql);
		$lockedInventory = $resql ? $this->db->fetch_object($resql) : false;
		if ($resql) {
			$this->db->free($resql);
		}
		if (!$lockedInventory || (int) $lockedInventory->status !== Inventory::STATUS_RECORDED) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_STATUS_CHANGED_BEFORE_CORRECTION'), 409);
		}
		try {
			$isAvailableForCorrection = $this->isCurrentBusinessDayInventory($lockedInventory)
				&& !$this->hasReversedAdjustments((int) $record->rowid);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}
		if (!$isAvailableForCorrection) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_CORRECTION_UNAVAILABLE'), 409);
		}

		$sql = 'SELECT id.rowid, id.fk_product, id.fk_warehouse, id.batch, id.qty_stock, id.qty_view';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet id';
		$sql .= ' WHERE id.fk_inventory='.((int) $record->rowid).' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$lines = array();
		while ($line = $this->db->fetch_object($resql)) {
			$lines[(int) $line->rowid] = $line;
		}
		$this->db->free($resql);

		$valueTimestamp = (int) $this->db->jdate($lockedInventory->date_inventory);
		$valueDateSql = $this->db->idate($valueTimestamp);
		$movementService = new KreaProductsInventoryMovementService($this->db, $this->conf, $this->langs);
		$stockService = new KreaProductsStockMovementService();
		$now = dol_now();

		foreach ($counts as $count) {
			$lineId = isset($count['line_id']) ? (int) $count['line_id'] : 0;
			if ($lineId <= 0 || !isset($lines[$lineId]) || !array_key_exists('quantity', $count)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_LINE_CORRECTION_UNAVAILABLE'), 409);
			}
			try {
				$correctedQuantity = KreaProductsInventoryLedgerCalculator::normalizePhysicalCount($count['quantity']);
			} catch (InvalidArgumentException $exception) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->translateCountValidationError($exception), 400);
			}
			if ($correctedQuantity === null) {
				continue;
			}

			$line = $lines[$lineId];
			$previousQuantity = is_null($line->qty_view) ? null : (float) $line->qty_view;
			if ($previousQuantity !== null && (float) price2num($correctedQuantity - $previousQuantity, 'MS') == 0.0) {
				continue;
			}
			try {
				if ($this->hasLaterActiveInventoryAnchor(
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueDateSql,
					(int) $record->rowid
				)) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_LATER_PRODUCT_ANCHOR'), 409);
				}

				if (!$stockService->normalizeSupplierInvoiceMovementsForReconstruction(
					$this->db,
					$this->conf,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueTimestamp
				)) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_NORMALIZE_SUPPLIER_MOVEMENTS'), 500);
				}
				if (!$stockService->normalizeCustomerInvoiceMovementsForReconstruction(
					$this->db,
					$this->conf,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueTimestamp
				)) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_NORMALIZE_CUSTOMER_MOVEMENTS'), 500);
				}

				$expectedQuantity = (float) $line->qty_stock;
				if ($previousQuantity === null) {
					$currentQuantity = $this->getCurrentStockQuantity((int) $line->fk_product, (int) $line->fk_warehouse, (string) $line->batch, true);
					$postValueQuantity = $this->getMovementQuantityAfterValueDate((int) $line->fk_product, (int) $line->fk_warehouse, (string) $line->batch, $valueDateSql);
					$expectedQuantity = KreaProductsInventoryLedgerCalculator::expectedQuantityAtValueDate($currentQuantity, $postValueQuantity);
					$adjustmentQuantity = KreaProductsInventoryLedgerCalculator::adjustmentQuantity($correctedQuantity, $expectedQuantity);
				} else {
					$currentQuantity = 0.0;
					$postValueQuantity = 0.0;
					$adjustmentQuantity = (float) price2num($correctedQuantity - $previousQuantity, 'MS');
				}
			} catch (Throwable $exception) {
				$this->db->rollback();
				throw $exception;
			}

			$movementId = 0;
			if ($adjustmentQuantity != 0.0) {
				$allowLegacyKitParent = $previousQuantity !== null
					&& $this->isAuditedLegacyKitParentLine((int) $record->rowid, $line);
				$movement = new MouvementStock($this->db);
				$origin = $previousQuantity === null
					? KreaProductsInventoryLedgerCalculator::INVENTORY_ORIGIN
					: KreaProductsInventoryLedgerCalculator::COUNT_CORRECTION_ORIGIN;
				$movement->setOrigin($origin, (int) $record->rowid);
				$movement->context['kreaproducts_inventory_ledger'] = 1;
				$movementId = $movementService->create(
					$movement,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					$adjustmentQuantity,
					0,
					$previousQuantity === null
						? $this->langs->trans('KREAPRODUCTS_MOVEMENT_LATE_COUNT', (string) $record->ref)
						: $this->langs->trans('KREAPRODUCTS_MOVEMENT_COUNT_CORRECTION', (string) $record->ref),
					'KPS-CORR-'.((int) $record->rowid).'-'.$lineId.'-'.$now,
					$valueTimestamp,
					(string) $line->batch,
					$allowLegacyKitParent
				);
				if ($movementId <= 0) {
					$this->db->rollback();
					throw new KreaProductsStockApiException($movementService->error ?: $this->langs->trans('KREAPRODUCTS_ERROR_CREATE_CORRECTION_MOVEMENT'), 500);
				}
			}

			if ($previousQuantity === null) {
				$sql = 'INSERT INTO '.$this->db->prefix().'kreaproducts_inventory_adjustment (';
				$sql .= 'entity, fk_inventory, fk_inventorydet, fk_product, fk_warehouse, batch, value_datetime,';
				$sql .= ' live_qty_before, post_value_qty, expected_qty, counted_qty, adjustment_qty, fk_movement,';
				$sql .= ' status, fk_user_creat, date_creation) VALUES (';
				$sql .= ((int) $this->conf->entity).', '.((int) $record->rowid).', '.$lineId.', '.((int) $line->fk_product).', ';
				$sql .= ((int) $line->fk_warehouse).", '".$this->db->escape((string) $line->batch)."', '".$this->db->escape($valueDateSql)."', ";
				$sql .= ((float) $currentQuantity).', '.((float) $postValueQuantity).', '.((float) $expectedQuantity).', ';
				$sql .= ((float) $correctedQuantity).', '.((float) $adjustmentQuantity).', '.($movementId > 0 ? (int) $movementId : 'NULL').', ';
				$sql .= '1, '.((int) $this->user->id).", '".$this->db->idate($now)."')";
			} else {
				$sql = 'INSERT INTO '.$this->db->prefix().'kreaproducts_inventory_correction (';
				$sql .= 'entity, fk_inventory, fk_inventorydet, fk_product, fk_warehouse, batch, value_datetime,';
				$sql .= ' previous_counted_qty, corrected_counted_qty, adjustment_qty, fk_movement, status, fk_user_creat, date_creation) VALUES (';
				$sql .= ((int) $this->conf->entity).', '.((int) $record->rowid).', '.$lineId.', '.((int) $line->fk_product).', ';
				$sql .= ((int) $line->fk_warehouse).", '".$this->db->escape((string) $line->batch)."', '".$this->db->escape($valueDateSql)."', ";
				$sql .= ((float) $previousQuantity).', '.((float) $correctedQuantity).', '.((float) $adjustmentQuantity).', ';
				$sql .= ($movementId > 0 ? (int) $movementId : 'NULL').', 1, '.((int) $this->user->id).", '".$this->db->idate($now)."')";
			}
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}

			$sql = 'UPDATE '.$this->db->prefix().'inventorydet SET qty_view='.((float) $correctedQuantity);
			if ($previousQuantity === null) {
				$sql .= ', qty_stock='.((float) $expectedQuantity).', fk_movement='.($movementId > 0 ? (int) $movementId : 'NULL');
			}
			$sql .= ' WHERE rowid='.$lineId.' AND fk_inventory='.((int) $record->rowid);
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
		}

		$sql = 'UPDATE '.$this->db->prefix().'inventory SET fk_user_modif='.((int) $this->user->id);
		$sql .= ' WHERE rowid='.((int) $record->rowid).' AND entity='.((int) $this->conf->entity);
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$this->commitStockTransaction();
		dol_syslog(__METHOD__.' inventory='.(int) $record->rowid.' count_rows='.count($counts).' user='.$this->user->id, LOG_NOTICE);
		return $this->getInventory((int) $record->rowid);
	}

	/**
	 * Close every initiated managed inventory whose automatic closure time has passed.
	 *
	 * @param int $now Current timestamp, or zero for now
	 * @return array<string,mixed>
	 */
	public function closeDueInventories($now = 0)
	{
		$this->requireCloseAccess();
		$this->requireInventoryValueDatingEnabled();
		$now = (int) $now > 0 ? (int) $now : dol_now();
		$businessDayService = new KreaProductsBusinessDayService();
		$timezone = $this->getOperationTimezone();
		$entryCutoff = getDolGlobalString('KREAPRODUCTS_INVENTORY_ENTRY_CUTOFF_TIME', '20:00');

		$sql = 'SELECT i.rowid, i.date_inventory';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = i.fk_warehouse';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE i.entity = '.((int) $this->conf->entity);
		$sql .= ' AND i.status = '.((int) Inventory::STATUS_VALIDATED);
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' ORDER BY i.date_inventory ASC, i.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$dueInventoryIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$valueTimestamp = (int) $this->db->jdate($obj->date_inventory);
			if ($valueTimestamp <= 0 || $valueTimestamp > $now) {
				continue;
			}
			$autoCloseTimestamp = $businessDayService->resolveInventoryAutoCloseTimestamp(
				$valueTimestamp,
				$timezone,
				$entryCutoff,
				15
			);
			if ($now >= $autoCloseTimestamp) {
				$dueInventoryIds[] = (int) $obj->rowid;
			}
		}
		$this->db->free($resql);

		$closedInventoryIds = array();
		$errors = array();
		foreach ($dueInventoryIds as $inventoryId) {
			try {
				$this->closeInventory((int) $inventoryId, true);
				$closedInventoryIds[] = (int) $inventoryId;
			} catch (Throwable $exception) {
				$errors[] = $this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_PREFIX', $inventoryId, $exception->getMessage());
				dol_syslog(__METHOD__.' inventory='.$inventoryId.' error='.$exception->getMessage(), LOG_ERR);
			}
		}

		return array(
			'due' => count($dueInventoryIds),
			'closed' => count($closedInventoryIds),
			'closed_inventory_ids' => $closedInventoryIds,
			'errors' => $errors,
		);
	}

	/**
	 * Close due inventories from a Dolibarr scheduled job.
	 *
	 * Cron execution users do not necessarily inherit module UI permissions.
	 * This narrow entry point accepts only an authenticated Dolibarr administrator
	 * and keeps normal web/mobile permission checks unchanged.
	 *
	 * @param int $now Current timestamp, or zero for now
	 * @return array<string,mixed>
	 */
	public function closeDueInventoriesAsScheduler($now = 0)
	{
		if (empty($this->user->id) || empty($this->user->admin)) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		$this->schedulerMode = true;
		try {
			return $this->closeDueInventories($now);
		} finally {
			$this->schedulerMode = false;
		}
	}

	/**
	 * Generate correction movements and record the inventory.
	 *
	 * @param int  $inventoryId    Inventory ID
	 * @param bool $allowIncomplete Allow closure with uncounted lines
	 * @return array<string,mixed>
	 */
	public function closeInventory($inventoryId, $allowIncomplete = false)
	{
		$this->requireCloseAccess();
		$this->requireInventoryValueDatingEnabled();
		$record = $this->fetchInventoryRecord((int) $inventoryId);
		if ((int) $record->status === Inventory::STATUS_RECORDED) {
			return $this->getInventory((int) $inventoryId);
		}
		if (!$this->isKreaProductsStockReference((string) $record->ref, (string) $record->import_key)) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_MANAGED'), 404);
		}
		if ((int) $record->status !== Inventory::STATUS_VALIDATED) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_CLOSE_OPEN_ONLY'), 409);
		}
		$inventory = new Inventory($this->db);
		$result = $inventory->fetch((int) $inventoryId);
		if ($result <= 0) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_FOUND'), 404);
		}
		$closedInventory = $this->getInventory((int) $inventoryId);
		$inventory->context['kreaproducts_mobile_inventory'] = 1;
		$this->beginStockTransaction();
		$error = 0;
		$errorMessage = '';
		try {
			$this->lockInventoryStartScope((int) $record->template_category_id, (int) $record->fk_warehouse);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}

		$sql = 'SELECT i.rowid, i.status';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' WHERE i.rowid = '.((int) $inventoryId);
		$sql .= ' AND i.entity = '.((int) $this->conf->entity);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		$lockedInventory = $resql ? $this->db->fetch_object($resql) : false;
		if (!$lockedInventory || (int) $lockedInventory->status !== Inventory::STATUS_VALIDATED) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_STATUS_CHANGED_BEFORE_CLOSE'), 409);
		}
		if (strpos((string) $record->ref, 'KS-') === 0) {
			$businessDayService = new KreaProductsBusinessDayService();
			$legacyEntryTimestamp = (int) $this->db->jdate($record->date_inventory);
			$inventory->date_inventory = $businessDayService->resolveInventoryValueTimestamp(
				$legacyEntryTimestamp > 0 ? $legacyEntryTimestamp : dol_now(),
				$this->getOperationTimezone(),
				getDolGlobalString('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30'),
				getDolGlobalString('KREAPRODUCTS_INVENTORY_ENTRY_CUTOFF_TIME', '20:00')
			);
			$sql = 'UPDATE '.$this->db->prefix().'inventory';
			$sql .= " SET date_inventory = '".$this->db->idate($inventory->date_inventory)."'";
			$sql .= ' WHERE rowid = '.((int) $inventoryId).' AND entity = '.((int) $this->conf->entity);
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
		}

		$sql = 'SELECT id.rowid, id.fk_product, id.fk_warehouse, id.batch, id.qty_stock, id.qty_view';
		$sql .= ' FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventoryId);
		$sql .= ' ORDER BY id.rowid ASC';
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$lines = array();
		$totalLineCount = 0;
		$uncountedLineCount = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$totalLineCount++;
			if (is_null($obj->qty_view)) {
				$uncountedLineCount++;
				continue;
			}
			$lines[] = $obj;
		}
		$this->db->free($resql);
		if ($totalLineCount <= 0) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NO_LINES'), 409);
		}
		if ($uncountedLineCount > 0 && !$allowIncomplete) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_INVENTORY_UNCOUNTED_CONFIRMATION_REQUIRED'), 409);
		}
		$valueTimestamp = !empty($inventory->date_inventory) ? (int) $inventory->date_inventory : (int) $this->db->jdate($record->date_inventory);
		if ($valueTimestamp > dol_now()) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_INVENTORY_FUTURE_CLOSE_BLOCKED'), 409);
		}
		$valueDateSql = $this->db->idate($valueTimestamp);
		$existingRecordedInventory = $this->findRecordedInventoryOnCalendarDate(
			(int) $record->template_category_id,
			(int) $record->fk_warehouse,
			$valueTimestamp,
			(int) $inventoryId,
			true
		);
		if (!empty($existingRecordedInventory['id'])) {
			$this->db->rollback();
			$displayDate = dol_print_date($valueTimestamp, 'day');
			throw new KreaProductsStockApiException(
				$this->langs->trans('KREAPRODUCTS_INVENTORY_RECORDED_DATE_EXISTS', (string) $existingRecordedInventory['ref'], $displayDate),
				409
			);
		}

		$movement = new MouvementStock($this->db);
		$movement->setOrigin($inventory->element, (int) $inventory->id);
		$movement->context['kreaproducts_inventory_ledger'] = 1;
		$inventoryMovementService = new KreaProductsInventoryMovementService($this->db, $this->conf, $this->langs);
		$now = dol_now();
		$stockMovementService = new KreaProductsStockMovementService();
		$finalReference = $this->resolveAvailableInventoryReference(
			$this->buildInventoryReference((string) $record->title, $valueTimestamp),
			(int) $inventoryId
		);
		$this->persistInventoryReference((int) $inventoryId, $finalReference);
		$inventory->ref = $finalReference;
		$record->ref = $finalReference;
		$closedInventory['ref'] = $finalReference;
		$inventoryCode = 'INV-'.$inventory->ref;

		foreach ($lines as $line) {
			if ($this->mustExcludeKitParents() && $this->isKitParent((int) $line->fk_product)) {
				$error++;
				$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_UNSUPPORTED_KIT_PARENT');
				break;
			}
			try {
				if ($this->hasLaterActiveInventoryAnchor(
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueDateSql,
					(int) $inventoryId
				)) {
					$error++;
					$errorMessage = $this->langs->trans('KREAPRODUCTS_ERROR_ACTIVE_PRODUCT_ANCHOR');
					break;
				}
				if (!$stockMovementService->normalizeSupplierInvoiceMovementsForReconstruction(
					$this->db,
					$this->conf,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueTimestamp
				)) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_NORMALIZE_SUPPLIER_MOVEMENTS'), 500);
				}
				if (!$stockMovementService->normalizeCustomerInvoiceMovementsForReconstruction(
					$this->db,
					$this->conf,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueTimestamp
				)) {
					throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_NORMALIZE_CUSTOMER_MOVEMENTS'), 500);
				}
				$currentQuantity = $this->getCurrentStockQuantity((int) $line->fk_product, (int) $line->fk_warehouse, (string) $line->batch, true);
				$postValueQuantity = $this->getMovementQuantityAfterValueDate(
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					(string) $line->batch,
					$valueDateSql
				);
			} catch (Throwable $exception) {
				$this->db->rollback();
				throw $exception;
			}
			$expectedQuantity = KreaProductsInventoryLedgerCalculator::expectedQuantityAtValueDate($currentQuantity, $postValueQuantity);
			$movementQuantity = KreaProductsInventoryLedgerCalculator::adjustmentQuantity((float) $line->qty_view, $expectedQuantity);
			$movementId = 0;

			if ($movementQuantity != 0.0) {
				$movementId = $inventoryMovementService->create(
					$movement,
					$this->user,
					(int) $line->fk_product,
					(int) $line->fk_warehouse,
					$movementQuantity,
					0,
					$this->langs->trans('LabelOfInventoryMovemement', $inventory->ref),
					$inventoryCode,
					$valueTimestamp,
					(string) $line->batch
				);
				if ($movementId <= 0) {
					$error++;
					$errorMessage = $inventoryMovementService->error ?: $this->getObjectError($movement, $this->langs->trans('KREAPRODUCTS_ERROR_CREATE_STOCK_MOVEMENT'));
					break;
				}
			}

			$sql = 'UPDATE '.$this->db->prefix().'inventorydet';
			$sql .= ' SET qty_stock = '.((float) $expectedQuantity);
			$sql .= ', fk_movement = '.($movementId > 0 ? (int) $movementId : 'NULL');
			$sql .= ' WHERE rowid = '.((int) $line->rowid);
			$sql .= ' AND fk_inventory = '.((int) $inventoryId);
			if (!$this->db->query($sql)) {
				$error++;
				$errorMessage = $this->db->lasterror();
				break;
			}

			$sql = 'INSERT INTO '.$this->db->prefix().'kreaproducts_inventory_adjustment (';
			$sql .= 'entity, fk_inventory, fk_inventorydet, fk_product, fk_warehouse, batch, value_datetime,';
			$sql .= ' live_qty_before, post_value_qty, expected_qty, counted_qty, adjustment_qty, fk_movement,';
			$sql .= ' status, fk_user_creat, date_creation) VALUES (';
			$sql .= ((int) $this->conf->entity).', '.((int) $inventoryId).', '.((int) $line->rowid).', ';
			$sql .= ((int) $line->fk_product).', '.((int) $line->fk_warehouse).", '".$this->db->escape((string) $line->batch)."', ";
			$sql .= "'".$this->db->escape($valueDateSql)."', ".((float) $currentQuantity).', '.((float) $postValueQuantity).', ';
			$sql .= ((float) $expectedQuantity).', '.((float) $line->qty_view).', '.((float) $movementQuantity).', ';
			$sql .= ($movementId > 0 ? (int) $movementId : 'NULL').', 1, '.((int) $this->user->id).", '".$this->db->idate($now)."')";
			if (!$this->db->query($sql)) {
				$error++;
				$errorMessage = $this->db->lasterror();
				break;
			}
		}

		if (!$error) {
			$result = $inventory->setRecorded($this->user);
			if ($result <= 0) {
				$error++;
				$errorMessage = $this->getObjectError($inventory, $this->langs->trans('KREAPRODUCTS_ERROR_CLOSE_INVENTORY'));
			}
		}

		if ($error) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($errorMessage, 500);
		}

		$this->commitStockTransaction();
		$closedInventory = $this->refreshClosedInventoryMetadata($closedInventory);
		dol_syslog(__METHOD__.' inventory='.$inventoryId.' lines='.count($lines).' user='.$this->user->id, LOG_NOTICE);

		$closedInventory['status'] = (int) Inventory::STATUS_RECORDED;
		$closedRecord = new stdClass();
		$closedRecord->date_inventory = $valueDateSql;
		$canCorrectNow = $this->canCount() && $this->isCurrentBusinessDayInventory($closedRecord);
		$closedInventory['editable'] = $canCorrectNow ? 1 : 0;
		$closedInventory['can_count'] = $canCorrectNow ? 1 : 0;
		$closedInventory['can_close'] = 0;
		$closedInventory['can_delete'] = 0;
		$closedInventory['can_reverse'] = 0;
		$closedInventory['correction_mode'] = $canCorrectNow ? 1 : 0;
		$closedInventory['skipped_lines'] = $uncountedLineCount;
		$closedInventory['email_notification'] = $this->sendInventoryEmailIfConfigured($closedInventory);

		return $closedInventory;
	}

	/**
	 * Reverse a closed inventory through new opposite stock movements.
	 *
	 * @param int $inventoryId Inventory ID
	 * @return array<string,mixed>
	 */
	public function reverseInventory($inventoryId)
	{
		$this->requireCloseAccess();
		$this->beginStockTransaction();
		$sql = 'SELECT i.rowid FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' WHERE i.rowid='.((int) $inventoryId);
		$sql .= ' AND i.entity='.((int) $this->conf->entity).' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$lockedInventory = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$lockedInventory) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_NOT_FOUND'), 404);
		}

		try {
			$record = $this->fetchInventoryRecord((int) $inventoryId);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}
		if (!$this->isKreaProductsStockReference((string) $record->ref, (string) $record->import_key)
			|| (int) $record->status !== Inventory::STATUS_RECORDED
		) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_REVERSE_RECORDED_ONLY'), 409);
		}
		try {
			$isCurrentBusinessDay = $this->isCurrentBusinessDayInventory($record);
		} catch (Throwable $exception) {
			$this->db->rollback();
			throw $exception;
		}
		if ($isCurrentBusinessDay) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_CURRENT_DAY_MUST_CORRECT'), 409);
		}

		$sql = 'SELECT a.rowid, a.fk_product, a.fk_warehouse, a.batch, a.adjustment_qty, a.fk_movement,';
		$sql .= ' sm.value as current_movement_qty';
		$sql .= ' FROM '.$this->db->prefix().'kreaproducts_inventory_adjustment as a';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'stock_mouvement as sm ON sm.rowid = a.fk_movement';
		$sql .= ' WHERE a.entity = '.((int) $this->conf->entity);
		$sql .= ' AND a.fk_inventory = '.((int) $inventoryId);
		$sql .= ' AND a.status = 1';
		$sql .= ' ORDER BY a.rowid ASC FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);
		if (empty($rows)) {
			$this->db->rollback();
			$result = $this->getInventory((int) $inventoryId);
			$result['reversed'] = 1;
			return $result;
		}

		$checkedScopes = array();
		foreach ($rows as $row) {
			$scopeKey = ((int) $row->fk_product).'|'.((int) $row->fk_warehouse).'|'.((string) $row->batch);
			if (isset($checkedScopes[$scopeKey])) {
				continue;
			}
			$checkedScopes[$scopeKey] = true;
			if ($this->hasLaterActiveInventoryAnchor(
				(int) $row->fk_product,
				(int) $row->fk_warehouse,
				(string) $row->batch,
				(string) $record->date_inventory,
				(int) $inventoryId,
				true
			)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_LATER_PRODUCT_ANCHOR'), 409);
			}
		}

		$movement = new MouvementStock($this->db);
		$movement->setOrigin(KreaProductsInventoryLedgerCalculator::REVERSAL_ORIGIN, (int) $inventoryId);
		$movement->context['kreaproducts_inventory_ledger'] = 1;
		$inventoryMovementService = new KreaProductsInventoryMovementService($this->db, $this->conf, $this->langs);
		$now = dol_now();

		foreach ($rows as $row) {
			if (!empty($row->fk_movement) && is_null($row->current_movement_qty)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_REVERSAL_MOVEMENT_MISSING'), 409);
			}
			if (empty($row->fk_movement) && (float) $row->adjustment_qty != 0.0) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_REVERSAL_AUDIT_INCONSISTENT'), 409);
			}
			$activeAdjustment = !empty($row->fk_movement) ? (float) $row->current_movement_qty : (float) $row->adjustment_qty;
			$reverseQuantity = (float) price2num(-$activeAdjustment, 'MS');
			$reverseMovementId = 0;
			if ($reverseQuantity != 0.0) {
				$reverseMovementId = $inventoryMovementService->create(
					$movement,
					$this->user,
					(int) $row->fk_product,
					(int) $row->fk_warehouse,
					$reverseQuantity,
					0,
					$this->langs->trans('KREAPRODUCTS_MOVEMENT_INVENTORY_REVERSAL', (string) $record->ref),
					'REV-'.$record->ref,
					$now,
					(string) $row->batch,
					true
				);
				if ($reverseMovementId <= 0) {
					$this->db->rollback();
					throw new KreaProductsStockApiException($inventoryMovementService->error ?: $this->getObjectError($movement, $this->langs->trans('KREAPRODUCTS_ERROR_REVERSE_STOCK_MOVEMENT')), 500);
				}
			}

			$sql = 'UPDATE '.$this->db->prefix().'kreaproducts_inventory_adjustment';
			$sql .= ' SET status = 2, fk_reverse_movement = '.($reverseMovementId > 0 ? (int) $reverseMovementId : 'NULL');
			$sql .= ', fk_user_reverse = '.((int) $this->user->id);
			$sql .= ", date_reversal = '".$this->db->idate($now)."'";
			$sql .= ' WHERE rowid = '.((int) $row->rowid);
			$sql .= ' AND entity = '.((int) $this->conf->entity).' AND status = 1';
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
		}

		$sql = 'SELECT c.rowid, c.fk_product, c.fk_warehouse, c.batch, c.adjustment_qty, c.fk_movement,';
		$sql .= ' sm.value as current_movement_qty';
		$sql .= ' FROM '.$this->db->prefix().'kreaproducts_inventory_correction c';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'stock_mouvement sm ON sm.rowid=c.fk_movement';
		$sql .= ' WHERE c.entity='.((int) $this->conf->entity);
		$sql .= ' AND c.fk_inventory='.((int) $inventoryId).' AND c.status=1';
		$sql .= ' ORDER BY c.rowid ASC FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$corrections = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$corrections[] = $obj;
		}
		$this->db->free($resql);

		foreach ($corrections as $correction) {
			if (!empty($correction->fk_movement) && is_null($correction->current_movement_qty)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_COUNT_REVERSAL_MOVEMENT_MISSING'), 409);
			}
			$activeQuantity = !empty($correction->fk_movement) ? (float) $correction->current_movement_qty : (float) $correction->adjustment_qty;
			$reverseQuantity = (float) price2num(-$activeQuantity, 'MS');
			$reverseMovementId = 0;
			if ($reverseQuantity != 0.0) {
				$movement->setOrigin(KreaProductsInventoryLedgerCalculator::COUNT_CORRECTION_REVERSAL_ORIGIN, (int) $inventoryId);
				$reverseMovementId = $inventoryMovementService->create(
					$movement,
					$this->user,
					(int) $correction->fk_product,
					(int) $correction->fk_warehouse,
					$reverseQuantity,
					0,
					$this->langs->trans('KREAPRODUCTS_MOVEMENT_COUNT_CORRECTION_REVERSAL', (string) $record->ref),
					'KPS-CORR-REV-'.((int) $correction->rowid),
					$now,
					(string) $correction->batch,
					true
				);
				if ($reverseMovementId <= 0) {
					$this->db->rollback();
					throw new KreaProductsStockApiException($inventoryMovementService->error ?: $this->langs->trans('KREAPRODUCTS_ERROR_REVERSE_COUNT_CORRECTION'), 500);
				}
			}

			$sql = 'UPDATE '.$this->db->prefix().'kreaproducts_inventory_correction';
			$sql .= ' SET status=2, fk_reverse_movement='.($reverseMovementId > 0 ? (int) $reverseMovementId : 'NULL');
			$sql .= ', fk_user_reverse='.((int) $this->user->id);
			$sql .= ", date_reversal='".$this->db->idate($now)."'";
			$sql .= ' WHERE rowid='.((int) $correction->rowid);
			$sql .= ' AND entity='.((int) $this->conf->entity).' AND status=1';
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
		}

		$sql = 'SELECT sm.rowid, sm.fk_product, sm.fk_entrepot, sm.batch, sm.value';
		$sql .= ' FROM '.$this->db->prefix().'stock_mouvement as sm';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'stock_mouvement as rev';
		$sql .= " ON rev.origintype = '".$this->db->escape(KreaProductsInventoryLedgerCalculator::REBASE_REVERSAL_ORIGIN)."'";
		$sql .= ' AND rev.fk_origin = sm.fk_origin';
		$sql .= " AND rev.inventorycode = CONCAT('KPS-REBASE-REV-', sm.rowid)";
		$sql .= " WHERE sm.origintype = '".$this->db->escape(KreaProductsInventoryLedgerCalculator::REBASE_ORIGIN)."'";
		$sql .= ' AND sm.fk_origin = '.((int) $inventoryId);
		$sql .= ' AND rev.rowid IS NULL';
		$sql .= ' ORDER BY sm.rowid ASC FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$rebaseMovements = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rebaseMovements[] = $obj;
		}
		$this->db->free($resql);

		foreach ($rebaseMovements as $rebaseMovement) {
			$reverseQuantity = (float) price2num(-((float) $rebaseMovement->value), 'MS');
			if ($reverseQuantity == 0.0) {
				continue;
			}
			$movement->setOrigin(KreaProductsInventoryLedgerCalculator::REBASE_REVERSAL_ORIGIN, (int) $inventoryId);
			$reverseMovementId = $inventoryMovementService->create(
				$movement,
				$this->user,
				(int) $rebaseMovement->fk_product,
				(int) $rebaseMovement->fk_entrepot,
				$reverseQuantity,
				0,
				$this->langs->trans('KREAPRODUCTS_MOVEMENT_REBASE_REVERSAL', (string) $record->ref),
				'KPS-REBASE-REV-'.((int) $rebaseMovement->rowid),
				$now,
				(string) $rebaseMovement->batch,
				true
			);
			if ($reverseMovementId <= 0) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($inventoryMovementService->error ?: $this->langs->trans('KREAPRODUCTS_ERROR_REVERSE_REBASE_MOVEMENT'), 500);
			}
		}

		$this->commitStockTransaction();
		dol_syslog(__METHOD__.' inventory='.$inventoryId.' user='.$this->user->id, LOG_NOTICE);
		$result = $this->getInventory((int) $inventoryId);
		$result['reversed'] = 1;
		return $result;
	}

	/**
	 * Start a stock transaction and fail before the first mutation when unavailable.
	 *
	 * @return void
	 */
	private function beginStockTransaction()
	{
		if ($this->db->begin()) {
			return;
		}

		dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		throw new KreaProductsStockApiException($this->langs->trans('KreaProductsStockUnexpectedError'), 500);
	}

	/**
	 * Commit a stock transaction and never report success after commit failure.
	 *
	 * @return void
	 */
	private function commitStockTransaction()
	{
		if ($this->db->commit()) {
			return;
		}

		dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		$this->db->rollback();
		throw new KreaProductsStockApiException($this->langs->trans('KreaProductsStockUnexpectedError'), 500);
	}

	/**
	 * @return void
	 */
	public function requireReadAccess()
	{
		if (empty($this->user->id)
			|| !$this->user->hasRight('kreaproducts', 'stockmobile', 'read')
			|| !$this->user->hasRight('stock', 'lire')
		) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return bool
	 */
	public function canCount()
	{
		return !empty($this->user->id)
			&& $this->user->hasRight('kreaproducts', 'stockmobile', 'read')
			&& $this->user->hasRight('kreaproducts', 'stockinventory', 'write')
			&& $this->user->hasRight('stock', 'lire');
	}

	/**
	 * Check whether the user may see expected stock, deviations, and statistics.
	 *
	 * @return bool
	 */
	public function canViewInventoryAnalysis()
	{
		return !empty($this->user->id)
			&& $this->user->hasRight('kreaproducts', 'inventory', 'expected');
	}

	/**
	 * @return bool
	 */
	public function canClose()
	{
		if (!$this->canCount() || !$this->user->hasRight('kreaproducts', 'stockinventory', 'close')) {
			return false;
		}
		if (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS')) {
			return $this->user->hasRight('stock', 'inventory_advance', 'write');
		}
		return $this->user->hasRight('stock', 'mouvement', 'creer');
	}

	/**
	 * @return bool
	 */
	public function isHistoryEnabled()
	{
		return true;
	}

	/**
	 * @return int
	 */
	private function getInventoryListLimit()
	{
		$limit = getDolGlobalInt('KREAPRODUCTS_STOCK_INVENTORY_LIST_LIMIT', 30);
		if ($limit < 1) {
			return 30;
		}
		if ($limit > 200) {
			return 200;
		}
		return $limit;
	}

	/**
	 * @return bool
	 */
	public function isInventoryEmailEnabled()
	{
		return (bool) getDolGlobalInt('KREAPRODUCTS_STOCK_INVENTORY_EMAIL_ENABLED');
	}

	/**
	 * @return void
	 */
	private function requireCountAccess()
	{
		if (!$this->canCount()) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return void
	 */
	private function requireCloseAccess()
	{
		if ($this->schedulerMode && !empty($this->user->id) && !empty($this->user->admin)) {
			return;
		}
		if (!$this->canClose()) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}
	}

	/**
	 * @return int
	 */
	private function getRootCategoryId()
	{
		$rootCategoryId = getDolGlobalInt('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT');
		if ($rootCategoryId <= 0) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_ROOT_CATEGORY_NOT_CONFIGURED'), 500);
		}
		$category = $this->fetchCategoryById($rootCategoryId);
		if (!$category) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_ROOT_CATEGORY_ENTITY'), 500);
		}
		return $rootCategoryId;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function listWarehouses()
	{
		$sql = 'SELECT e.rowid, e.ref, e.description';
		$sql .= ' FROM '.$this->db->prefix().'entrepot as e';
		$sql .= ' WHERE e.entity IN ('.getEntity('stock').')';
		$sql .= ' AND e.statut = 1';
		$sql .= ' ORDER BY e.ref ASC, e.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$warehouses = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$warehouses[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'description' => (string) $obj->description,
			);
		}
		$this->db->free($resql);
		return $warehouses;
	}

	/**
	 * @param array<int,array<string,mixed>> $warehouses Warehouses
	 * @return int
	 */
	private function resolveDefaultWarehouseId(array $warehouses)
	{
		$configuredId = getDolGlobalInt('KREAPRODUCTS_STOCK_DEFAULT_WAREHOUSE_ID');
		foreach ($warehouses as $warehouse) {
			if ($configuredId > 0 && (int) $warehouse['id'] === $configuredId) {
				return $configuredId;
			}
		}
		if (count($warehouses) === 1) {
			return (int) $warehouses[0]['id'];
		}
		return 0;
	}

	/**
	 * @param int $categoryId Category ID
	 * @return object
	 */
	private function fetchTemplateCategory($categoryId)
	{
		$rootCategoryId = $this->getRootCategoryId();
		$sql = 'SELECT c.rowid, c.label, c.fk_parent';
		$sql .= ' FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' WHERE c.rowid = '.((int) $categoryId);
		$sql .= ' AND c.fk_parent = '.((int) $rootCategoryId);
		$sql .= ' AND c.type = 0';
		$sql .= ' AND c.entity IN ('.getEntity('category').')';
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : false;
		if (!$obj) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_TEMPLATE_ENTITY'), 404);
		}
		return $obj;
	}

	/**
	 * @return array<int,string>
	 */
	private function listTemplateCategoryMap()
	{
		$rootCategoryId = $this->getRootCategoryId();
		$sql = 'SELECT c.rowid, c.label';
		$sql .= ' FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' WHERE c.fk_parent = '.((int) $rootCategoryId);
		$sql .= ' AND c.type = 0';
		$sql .= ' AND c.entity IN ('.getEntity('category').')';
		$sql .= ' ORDER BY c.label ASC, c.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$categories = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$categories[(int) $obj->rowid] = $this->cleanTemplateLabel((string) $obj->label);
		}
		$this->db->free($resql);
		return $categories;
	}

	/**
	 * @param int $categoryId Category ID
	 * @return object|false
	 */
	private function fetchCategoryById($categoryId)
	{
		$sql = 'SELECT c.rowid, c.label, c.fk_parent';
		$sql .= ' FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' WHERE c.rowid = '.((int) $categoryId);
		$sql .= ' AND c.type = 0';
		$sql .= ' AND c.entity IN ('.getEntity('category').')';
		$resql = $this->db->query($sql);
		return $resql ? $this->db->fetch_object($resql) : false;
	}

	/**
	 * @param int $warehouseId Warehouse ID or zero for configured default
	 * @return object
	 */
	private function fetchWarehouse($warehouseId)
	{
		$warehouses = $this->listWarehouses();
		if ($warehouseId <= 0) {
			$warehouseId = $this->resolveDefaultWarehouseId($warehouses);
		}
		foreach ($warehouses as $warehouse) {
			if ((int) $warehouse['id'] === (int) $warehouseId) {
				return (object) array(
					'rowid' => (int) $warehouse['id'],
					'ref' => (string) $warehouse['ref'],
				);
			}
		}
		throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_WAREHOUSE_ENTITY'), 404);
	}

	/**
	 * @param int $inventoryId Inventory ID
	 * @return object
	 */
	private function fetchInventoryRecord($inventoryId)
	{
		$sql = 'SELECT i.rowid, i.ref, i.title, i.status, i.categories_product, i.fk_warehouse, i.date_creation, i.date_inventory, i.import_key,';
		$sql .= ' e.ref as warehouse_ref';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = i.fk_warehouse';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE i.rowid = '.((int) $inventoryId);
		$sql .= ' AND i.entity = '.((int) $this->conf->entity);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : false;
		$templateCategoryId = $obj ? $this->extractSingleTemplateCategoryId((string) $obj->categories_product) : 0;
		if (!$obj || $templateCategoryId <= 0 || !in_array((int) $obj->status, array(Inventory::STATUS_VALIDATED, Inventory::STATUS_RECORDED), true)) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_ENTITY_NOT_FOUND'), 404);
		}
		$obj->template_category_id = $templateCategoryId;
		return $obj;
	}

	/**
	 * @param string $categoryValue Inventory categories_product value
	 * @return bool
	 */
	private function isTemplateCategoryValue($categoryValue)
	{
		return $this->extractSingleTemplateCategoryId($categoryValue) > 0;
	}

	/**
	 * @param string $ref       Inventory reference
	 * @param string $importKey Dolibarr import key used as the new hidden ownership marker
	 * @return bool
	 */
	private function isKreaProductsStockReference($ref, $importKey = '')
	{
		return (string) $importKey === 'KPS'
			|| preg_match('/^(?:KPS|KS)-\d+-\d+-\d{14}-[A-F0-9]{4}$/', (string) $ref) === 1;
	}

	/**
	 * @param string $categoryValue Inventory categories_product value
	 * @return int
	 */
	private function extractSingleTemplateCategoryId($categoryValue)
	{
		$categoryValue = trim((string) $categoryValue);
		if ($categoryValue === '') {
			return 0;
		}

		$tokens = array();
		foreach (explode(',', $categoryValue) as $token) {
			$token = trim($token);
			if ($token === '') {
				continue;
			}
			if (!ctype_digit($token)) {
				return 0;
			}
			$tokens[] = (int) $token;
		}
		$tokens = array_values(array_unique($tokens));
		if (count($tokens) !== 1) {
			return 0;
		}

		try {
			$this->fetchTemplateCategory((int) $tokens[0]);
		} catch (KreaProductsStockApiException $exception) {
			return 0;
		}
		return (int) $tokens[0];
	}

	/**
	 * @param string $field      SQL field name
	 * @param int    $categoryId Category ID
	 * @return string
	 */
	private function buildCategoryContainsSqlCondition($field, $categoryId)
	{
		$value = $this->db->escape((string) ((int) $categoryId));
		return "(".$field." = '".$value."'"
			." OR ".$field." LIKE '".$value.",%'"
			." OR ".$field." LIKE '%,".$value.",%'"
			." OR ".$field." LIKE '%,".$value."')";
	}

	/**
	 * @param int $categoryId  Category ID
	 * @param int $warehouseId Warehouse ID
	 * @return array<string,mixed>|null
	 */
	private function findOpenInventory($categoryId, $warehouseId)
	{
		if ($warehouseId <= 0) {
			return null;
		}
		$sql = 'SELECT i.rowid, i.ref, i.title,';
		$sql .= ' i.categories_product,';
		$sql .= ' COUNT(id.rowid) as total_lines,';
		$sql .= ' SUM(CASE WHEN id.qty_view IS NOT NULL THEN 1 ELSE 0 END) as counted_lines';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory = i.rowid';
		$sql .= ' WHERE i.entity = '.((int) $this->conf->entity);
		$sql .= ' AND i.status = '.((int) Inventory::STATUS_VALIDATED);
		$sql .= ' AND i.fk_warehouse = '.((int) $warehouseId);
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' AND '.$this->buildCategoryContainsSqlCondition('i.categories_product', (int) $categoryId);
		$sql .= ' GROUP BY i.rowid, i.ref, i.title, i.categories_product';
		$sql .= ' ORDER BY i.rowid DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$obj = false;
		while ($candidate = $this->db->fetch_object($resql)) {
			if ($this->extractSingleTemplateCategoryId((string) $candidate->categories_product) === (int) $categoryId) {
				$obj = $candidate;
				break;
			}
		}
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array(
			'id' => (int) $obj->rowid,
			'ref' => (string) $obj->ref,
			'title' => (string) $obj->title,
			'total_lines' => (int) $obj->total_lines,
			'counted_lines' => (int) $obj->counted_lines,
		);
	}

	/**
	 * Find the oldest initiated managed inventory in the active entity.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findAnyOpenManagedInventory()
	{
		$sql = 'SELECT i.rowid, i.ref, i.title, i.categories_product, i.fk_warehouse, i.date_inventory,';
		$sql .= ' COUNT(id.rowid) as total_lines,';
		$sql .= ' SUM(CASE WHEN id.qty_view IS NOT NULL THEN 1 ELSE 0 END) as counted_lines';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = i.fk_warehouse';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory = i.rowid';
		$sql .= ' WHERE i.entity = '.((int) $this->conf->entity);
		$sql .= ' AND i.status = '.((int) Inventory::STATUS_VALIDATED);
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' GROUP BY i.rowid, i.ref, i.title, i.categories_product, i.fk_warehouse, i.date_inventory';
		$sql .= ' ORDER BY i.date_inventory ASC, i.rowid ASC';
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}

		return array(
			'id' => (int) $obj->rowid,
			'ref' => (string) $obj->ref,
			'title' => (string) $obj->title,
			'category_id' => $this->extractSingleTemplateCategoryId((string) $obj->categories_product),
			'warehouse_id' => (int) $obj->fk_warehouse,
			'date_inventory' => $this->db->jdate($obj->date_inventory),
			'total_lines' => (int) $obj->total_lines,
			'counted_lines' => (int) $obj->counted_lines,
		);
	}

	/**
	 * Find the single inventory assigned to a template, warehouse, and business day.
	 *
	 * @param int $categoryId    Template category ID
	 * @param int $warehouseId   Warehouse ID
	 * @param int $valueTimestamp Normalized inventory value timestamp
	 * @return array<string,mixed>|null
	 */
	private function findBusinessDayInventory($categoryId, $warehouseId, $valueTimestamp, $excludeInventoryId = 0)
	{
		if ($categoryId <= 0 || $warehouseId <= 0 || $valueTimestamp <= 0) {
			return null;
		}
		$valueDateSql = $this->db->idate((int) $valueTimestamp);
		$sql = 'SELECT i.rowid, i.ref, i.title, i.status, i.categories_product,';
		$sql .= ' COUNT(id.rowid) as total_lines,';
		$sql .= ' SUM(CASE WHEN id.qty_view IS NOT NULL THEN 1 ELSE 0 END) as counted_lines';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory=i.rowid';
		$sql .= ' WHERE i.entity='.((int) $this->conf->entity);
		$sql .= ' AND i.fk_warehouse='.((int) $warehouseId);
		if ($excludeInventoryId > 0) {
			$sql .= ' AND i.rowid<>'.((int) $excludeInventoryId);
		}
		$sql .= " AND i.date_inventory='".$this->db->escape($valueDateSql)."'";
		$sql .= ' AND i.status IN ('.((int) Inventory::STATUS_VALIDATED).', '.((int) Inventory::STATUS_RECORDED).')';
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' AND '.$this->buildCategoryContainsSqlCondition('i.categories_product', (int) $categoryId);
		$sql .= ' GROUP BY i.rowid, i.ref, i.title, i.status, i.categories_product';
		$sql .= ' ORDER BY i.rowid DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$obj = false;
		while ($candidate = $this->db->fetch_object($resql)) {
			if ($this->extractSingleTemplateCategoryId((string) $candidate->categories_product) === (int) $categoryId) {
				$obj = $candidate;
				break;
			}
		}
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}

		return array(
			'id' => (int) $obj->rowid,
			'ref' => (string) $obj->ref,
			'title' => (string) $obj->title,
			'status' => (int) $obj->status,
			'total_lines' => (int) $obj->total_lines,
			'counted_lines' => (int) $obj->counted_lines,
		);
	}

	/**
	 * Find a recorded inventory for the same category, warehouse, and calendar value date.
	 * This intentionally includes ordinary Dolibarr inventories so a managed close cannot replace them.
	 *
	 * @param int  $categoryId        Template category ID
	 * @param int  $warehouseId       Warehouse ID
	 * @param int  $valueTimestamp    Selected inventory value timestamp
	 * @param int  $excludeInventoryId Inventory ID to exclude
	 * @param bool $lock              Lock matching rows during closure
	 * @return array<string,mixed>|null
	 */
	private function findRecordedInventoryOnCalendarDate($categoryId, $warehouseId, $valueTimestamp, $excludeInventoryId = 0, $lock = false)
	{
		$timezone = $this->getOperationTimezone();
		$valueDate = (new DateTimeImmutable('@'.((int) $valueTimestamp)))->setTimezone($timezone);
		$calendarDate = $valueDate->format('Y-m-d');
		$nextCalendarDate = $valueDate->modify('+1 day')->format('Y-m-d');
		$businessDayService = new KreaProductsBusinessDayService();
		$dayStart = $this->db->idate($businessDayService->resolveDateTimestamp($calendarDate, $timezone, '00:00'));
		$dayEnd = $this->db->idate($businessDayService->resolveDateTimestamp($nextCalendarDate, $timezone, '00:00'));

		$sql = 'SELECT i.rowid, i.ref, i.categories_product FROM '.$this->db->prefix().'inventory i';
		$sql .= ' WHERE i.entity='.((int) $this->conf->entity);
		$sql .= ' AND i.status='.((int) Inventory::STATUS_RECORDED);
		$sql .= ' AND i.fk_warehouse='.((int) $warehouseId);
		if ($excludeInventoryId > 0) {
			$sql .= ' AND i.rowid<>'.((int) $excludeInventoryId);
		}
		$sql .= " AND i.date_inventory>='".$this->db->escape($dayStart)."'";
		$sql .= " AND i.date_inventory<'".$this->db->escape($dayEnd)."'";
		$sql .= ' AND '.$this->buildCategoryContainsSqlCondition('i.categories_product', (int) $categoryId);
		$sql .= ' ORDER BY i.rowid DESC';
		if ($lock) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$match = null;
		while ($obj = $this->db->fetch_object($resql)) {
			if ($this->extractSingleTemplateCategoryId((string) $obj->categories_product) === (int) $categoryId) {
				$match = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref);
				break;
			}
		}
		$this->db->free($resql);
		return $match;
	}

	/**
	 * Prevent two same-day templates from anchoring the same product stock.
	 *
	 * @param int $categoryId    Template category ID
	 * @param int $warehouseId   Warehouse ID
	 * @param int $valueTimestamp Normalized inventory value timestamp
	 * @return array<string,mixed>|null
	 */
	private function findOverlappingBusinessDayInventory($categoryId, $warehouseId, $valueTimestamp, $excludeInventoryId = 0)
	{
		$valueDateSql = $this->db->idate((int) $valueTimestamp);
		$sql = 'SELECT DISTINCT i.rowid, i.ref';
		$sql .= ' FROM '.$this->db->prefix().'inventory i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'inventorydet id ON id.fk_inventory=i.rowid';
		$sql .= ' INNER JOIN '.$this->db->prefix().'categorie_product cp ON cp.fk_product=id.fk_product';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product p ON p.rowid=id.fk_product AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE i.entity='.((int) $this->conf->entity);
		$sql .= ' AND i.fk_warehouse='.((int) $warehouseId);
		if ($excludeInventoryId > 0) {
			$sql .= ' AND i.rowid<>'.((int) $excludeInventoryId);
		}
		$sql .= ' AND id.fk_warehouse='.((int) $warehouseId);
		$sql .= " AND i.date_inventory='".$this->db->escape($valueDateSql)."'";
		$sql .= ' AND i.status IN ('.((int) Inventory::STATUS_VALIDATED).', '.((int) Inventory::STATUS_RECORDED).')';
		$sql .= " AND (i.import_key = 'KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' AND cp.fk_categorie='.((int) $categoryId);
		$sql .= ' AND p.fk_product_type=0 AND p.stockable_product>0';
		if ($this->mustExcludeKitParents()) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->prefix().'product_association pa';
			$sql .= ' WHERE pa.fk_product_pere=p.rowid AND pa.incdec=1)';
		}
		$sql .= ' ORDER BY i.rowid DESC';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref) : null;
	}

	/**
	 * Add category products that have no product_stock row yet.
	 *
	 * @param Inventory $inventory   Inventory
	 * @param int       $categoryId  Category ID
	 * @param int       $warehouseId Warehouse ID
	 * @return void
	 */
	private function addMissingZeroStockProducts(Inventory $inventory, $categoryId, $warehouseId)
	{
		$sql = 'SELECT p.rowid';
		$sql .= ' FROM '.$this->db->prefix().'categorie_product as cp';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = cp.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory = '.((int) $inventory->id);
		$sql .= ' AND id.fk_warehouse = '.((int) $warehouseId);
		$sql .= ' AND id.fk_product = p.rowid';
		$sql .= " AND COALESCE(id.batch, '') = ''";
		$sql .= ' WHERE cp.fk_categorie = '.((int) $categoryId);
		$sql .= ' AND p.fk_product_type = 0';
		$sql .= ' AND p.stockable_product > 0';
		$sql .= ' AND COALESCE(p.tobatch, 0) = 0';
		if ($this->mustExcludeKitParents()) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->prefix().'product_association as pa';
			$sql .= ' WHERE pa.fk_product_pere = p.rowid AND pa.incdec = 1)';
		}
		$sql .= ' AND id.rowid IS NULL';
		$sql .= ' ORDER BY p.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$this->beginStockTransaction();
		$error = 0;
		$errorMessage = '';
		while ($obj = $this->db->fetch_object($resql)) {
			$line = new InventoryLine($this->db);
			$line->fk_inventory = (int) $inventory->id;
			$line->fk_warehouse = (int) $warehouseId;
			$line->fk_product = (int) $obj->rowid;
			$line->batch = '';
			$line->qty_stock = $this->getCurrentStockQuantity((int) $obj->rowid, (int) $warehouseId, '');
			$line->datec = dol_now();
			$result = $line->create($this->user);
			if ($result <= 0) {
				$error++;
				$errorMessage = $this->getObjectError($line, $this->langs->trans('KREAPRODUCTS_ERROR_ADD_ZERO_STOCK_PRODUCT'));
				break;
			}
		}
		$this->db->free($resql);

		if ($error) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($errorMessage, 500);
		}
		$this->commitStockTransaction();
	}

	/**
	 * Serialize inventory creation for one template and warehouse.
	 *
	 * @param int $categoryId  Template category
	 * @param int $warehouseId Warehouse
	 * @return void
	 */
	private function lockInventoryStartScope($categoryId, $warehouseId)
	{
		$rootCategoryId = $this->getRootCategoryId();
		$sql = 'SELECT c.rowid FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' WHERE c.rowid = '.((int) $rootCategoryId);
		$sql .= ' AND c.entity IN ('.getEntity('category').') FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			throw new KreaProductsStockApiException($resql ? $this->langs->trans('KREAPRODUCTS_ERROR_ROOT_CATEGORY_UNAVAILABLE') : $this->db->lasterror(), 500);
		}
		$this->db->free($resql);

		$sql = 'SELECT c.rowid FROM '.$this->db->prefix().'categorie as c';
		$sql .= ' WHERE c.rowid = '.((int) $categoryId);
		$sql .= ' AND c.entity IN ('.getEntity('category').') FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			throw new KreaProductsStockApiException($resql ? $this->langs->trans('KREAPRODUCTS_ERROR_TEMPLATE_UNAVAILABLE') : $this->db->lasterror(), 500);
		}
		$this->db->free($resql);

		$sql = 'SELECT e.rowid FROM '.$this->db->prefix().'entrepot as e';
		$sql .= ' WHERE e.rowid = '.((int) $warehouseId);
		$sql .= ' AND e.entity IN ('.getEntity('stock').') FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			throw new KreaProductsStockApiException($resql ? $this->langs->trans('KREAPRODUCTS_ERROR_WAREHOUSE_UNAVAILABLE') : $this->db->lasterror(), 500);
		}
		$this->db->free($resql);
	}

	/**
	 * Remove kit-parent lines when normal Dolibarr movements do not maintain parent stock.
	 *
	 * @param int $inventoryId Inventory ID
	 * @return void
	 */
	private function removeNonOperationalKitParentLines($inventoryId)
	{
		if (!$this->mustExcludeKitParents()) {
			return;
		}
		$sql = 'SELECT DISTINCT id.rowid FROM '.$this->db->prefix().'inventorydet as id';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product_association as pa';
		$sql .= ' ON pa.fk_product_pere = id.fk_product AND pa.incdec = 1';
		$sql .= ' WHERE id.fk_inventory = '.((int) $inventoryId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$lineIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lineIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		foreach ($lineIds as $lineId) {
			$line = new InventoryLine($this->db);
			if ($line->fetch($lineId) <= 0 || (int) $line->fk_inventory !== (int) $inventoryId || $line->delete($this->user) <= 0) {
				throw new KreaProductsStockApiException($this->getObjectError($line, $this->langs->trans('KREAPRODUCTS_ERROR_REMOVE_KIT_PARENT_LINE')), 500);
			}
		}
	}

	/**
	 * @return bool
	 */
	private function mustExcludeKitParents()
	{
		return (bool) getDolGlobalInt('PRODUIT_SOUSPRODUITS')
			&& !getDolGlobalInt('PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE');
	}

	/**
	 * @param int $productId Product ID
	 * @return bool
	 */
	private function isKitParent($productId)
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'product_association';
		$sql .= ' WHERE fk_product_pere = '.((int) $productId).' AND incdec = 1';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$isKitParent = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		return $isKitParent;
	}

	/**
	 * @param int $entryTimestamp Entry timestamp
	 * @return int
	 */
	private function resolveInventoryValueTimestamp($entryTimestamp)
	{
		$businessDayService = new KreaProductsBusinessDayService();
		return $businessDayService->resolveInventoryValueTimestamp(
			(int) $entryTimestamp,
			$this->getOperationTimezone(),
			getDolGlobalString('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30'),
			getDolGlobalString('KREAPRODUCTS_INVENTORY_ENTRY_CUTOFF_TIME', '20:00')
		);
	}

	/**
	 * Resolve an explicitly selected calendar date to the configured inventory anchor.
	 *
	 * @param string $calendarDate Calendar date in YYYY-MM-DD format
	 * @return int
	 * @throws InvalidArgumentException
	 */
	private function resolveEditableInventoryValueTimestamp($calendarDate)
	{
		$businessDayService = new KreaProductsBusinessDayService();
		return $businessDayService->resolveDateTimestamp(
			(string) $calendarDate,
			$this->getOperationTimezone(),
			getDolGlobalString('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30')
		);
	}

	/**
	 * Refuse physical inventories when invoice movement ordering is disabled.
	 *
	 * @return void
	 */
	private function requireInventoryValueDatingEnabled()
	{
		if (!getDolGlobalInt('KREAPRODUCTS_STOCK_MOVEMENT_DATA')) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_VALUE_DATING_DISABLED'), 409);
		}
	}

	/**
	 * @param int    $productId   Product ID
	 * @param int    $warehouseId Warehouse ID
	 * @param string $batch       Lot or serial
	 * @param bool   $lock        Lock the current stock row for a critical write
	 * @return float
	 */
	private function getCurrentStockQuantity($productId, $warehouseId, $batch, $lock = false)
	{
		$sql = 'SELECT ps.rowid, ps.reel as qty';
		$sql .= ' FROM '.$this->db->prefix().'product_stock as ps';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = ps.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = ps.fk_entrepot';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE ps.fk_product = '.((int) $productId);
		$sql .= ' AND ps.fk_entrepot = '.((int) $warehouseId);
		if ($lock) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$stockRow = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$stockRow || $batch === '') {
			return $stockRow ? (float) $stockRow->qty : 0.0;
		}

		$sql = 'SELECT pb.qty FROM '.$this->db->prefix().'product_batch as pb';
		$sql .= ' WHERE pb.fk_product_stock = '.((int) $stockRow->rowid);
		$sql .= " AND pb.batch = '".$this->db->escape($batch)."'";
		if ($lock) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$batchRow = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $batchRow ? (float) $batchRow->qty : 0.0;
	}

	/**
	 * @return DateTimeZone
	 */
	private function getOperationTimezone()
	{
		$timezoneName = getDolGlobalString('KREAPRODUCTS_BUSINESS_TIMEZONE', date_default_timezone_get());
		try {
			return new DateTimeZone($timezoneName);
		} catch (Exception $exception) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVALID_TIMEZONE'), 500);
		}
	}

	/**
	 * Sum movements that happened after the inventory value date but before closure.
	 *
	 * @param int    $productId     Product ID
	 * @param int    $warehouseId   Warehouse ID
	 * @param string $batch         Lot or serial
	 * @param string $valueDateSql  Inventory value date
	 * @return float
	 */
	private function getMovementQuantityAfterValueDate($productId, $warehouseId, $batch, $valueDateSql)
	{
		$sql = 'SELECT COALESCE(SUM(sm.value), 0) as moved';
		$sql .= ' FROM '.$this->db->prefix().'stock_mouvement as sm';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product as p ON p.rowid = sm.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = sm.fk_entrepot';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE sm.fk_product = '.((int) $productId);
		$sql .= ' AND sm.fk_entrepot = '.((int) $warehouseId);
		$excludedOrigins = array();
		foreach (KreaProductsInventoryLedgerCalculator::excludedMovementOrigins() as $excludedOrigin) {
			$excludedOrigins[] = "'".$this->db->escape($excludedOrigin)."'";
		}
		$sql .= ' AND (sm.origintype IS NULL OR sm.origintype NOT IN ('.implode(', ', $excludedOrigins).'))';
		$sql .= " AND sm.datem > '".$this->db->escape((string) $valueDateSql)."'";
		if ((string) $batch !== '') {
			$sql .= " AND sm.batch = '".$this->db->escape((string) $batch)."'";
		} else {
			$sql .= " AND (sm.batch = '' OR sm.batch IS NULL)";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (float) $obj->moved : 0.0;
	}

	/**
	 * Allow a correction on a historical kit-parent line only when its original
	 * parent-only inventory movement is still backed by an active audit row.
	 *
	 * @param int    $inventoryId Inventory ID
	 * @param object $line        Inventory line
	 * @return bool
	 */
	private function isAuditedLegacyKitParentLine($inventoryId, $line)
	{
		$sql = 'SELECT a.rowid FROM '.$this->db->prefix().'kreaproducts_inventory_adjustment a';
		$sql .= ' INNER JOIN '.$this->db->prefix().'stock_mouvement sm ON sm.rowid=a.fk_movement';
		$sql .= ' WHERE a.entity='.((int) $this->conf->entity);
		$sql .= ' AND a.fk_inventory='.((int) $inventoryId);
		$sql .= ' AND a.fk_inventorydet='.((int) $line->rowid);
		$sql .= ' AND a.fk_product='.((int) $line->fk_product);
		$sql .= ' AND a.fk_warehouse='.((int) $line->fk_warehouse);
		$sql .= ' AND a.status=1';
		$sql .= " AND sm.origintype='inventory'";
		$sql .= ' AND sm.fk_origin='.((int) $inventoryId);
		$sql .= ' AND sm.fk_product='.((int) $line->fk_product);
		$sql .= ' AND sm.fk_entrepot='.((int) $line->fk_warehouse);
		if ((string) $line->batch !== '') {
			$sql .= " AND a.batch='".$this->db->escape((string) $line->batch)."'";
			$sql .= " AND sm.batch='".$this->db->escape((string) $line->batch)."'";
		} else {
			$sql .= " AND (a.batch='' OR a.batch IS NULL)";
			$sql .= " AND (sm.batch='' OR sm.batch IS NULL)";
		}
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$isAudited = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		return $isAudited;
	}

	/**
	 * @param int $inventoryId Inventory ID
	 * @return bool
	 */
	private function hasActiveAdjustments($inventoryId)
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'kreaproducts_inventory_adjustment';
		$sql .= ' WHERE entity = '.((int) $this->conf->entity);
		$sql .= ' AND fk_inventory = '.((int) $inventoryId).' AND status = 1';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$hasAdjustments = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		return $hasAdjustments;
	}

	/**
	 * @param int $inventoryId Inventory ID
	 * @return bool
	 */
	private function hasReversedAdjustments($inventoryId)
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'kreaproducts_inventory_adjustment';
		$sql .= ' WHERE entity='.((int) $this->conf->entity);
		$sql .= ' AND fk_inventory='.((int) $inventoryId).' AND status=2';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$hasReversed = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		return $hasReversed;
	}

	/**
	 * @param object $inventory Inventory database record
	 * @return bool
	 */
	private function isCurrentBusinessDayInventory($inventory)
	{
		if (empty($inventory->date_inventory)) {
			return false;
		}
		$currentValueDate = $this->db->idate($this->resolveInventoryValueTimestamp(dol_now()));
		$inventoryValueDate = $inventory->date_inventory;
		if (is_numeric($inventoryValueDate)) {
			$inventoryValueDate = $this->db->idate((int) $inventoryValueDate);
		}

		return (string) $inventoryValueDate === (string) $currentValueDate;
	}

	/**
	 * Check whether an inventory belongs to the currently writable counting window.
	 *
	 * Legacy KS references stored an entry timestamp instead of the normalized anchor,
	 * so their effective value date must be resolved before comparison.
	 *
	 * @param object $inventory Inventory database record
	 * @return bool
	 */
	private function isInventoryInCurrentCountingWindow($inventory)
	{
		if (strpos((string) ($inventory->ref ?? ''), 'KS-') !== 0) {
			return $this->isCurrentBusinessDayInventory($inventory);
		}

		$entryTimestamp = (int) $this->db->jdate($inventory->date_inventory);
		if ($entryTimestamp <= 0) {
			return false;
		}
		$effectiveDate = $this->db->idate($this->resolveInventoryValueTimestamp($entryTimestamp));
		$currentDate = $this->db->idate($this->resolveInventoryValueTimestamp(dol_now()));
		return $effectiveDate === $currentDate;
	}

	/**
	 * @param int    $productId       Product ID
	 * @param int    $warehouseId     Warehouse ID
	 * @param string $batch           Lot or serial
	 * @param string $valueDateSql    Inventory value date
	 * @param int    $excludeInventory Inventory to exclude
	 * @param bool   $lock             Lock the matching later anchor when found
	 * @return bool
	 */
	private function hasLaterActiveInventoryAnchor($productId, $warehouseId, $batch, $valueDateSql, $excludeInventory, $lock = false)
	{
		$sql = 'SELECT i.rowid FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'inventorydet as id ON id.fk_inventory = i.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'kreaproducts_inventory_adjustment as a';
		$sql .= ' ON a.entity = i.entity AND a.fk_inventorydet = id.rowid';
		$sql .= ' WHERE i.entity = '.((int) $this->conf->entity);
		$sql .= ' AND i.status = '.((int) Inventory::STATUS_RECORDED);
		$sql .= ' AND i.rowid <> '.((int) $excludeInventory);
		$sql .= ' AND id.fk_product = '.((int) $productId);
		$sql .= ' AND id.fk_warehouse = '.((int) $warehouseId);
		$sql .= " AND COALESCE(id.batch, '') = '".$this->db->escape((string) $batch)."'";
		$sql .= ' AND id.qty_view IS NOT NULL';
		$sql .= " AND i.date_inventory >= '".$this->db->escape((string) $valueDateSql)."'";
		$sql .= ' AND (a.rowid IS NULL OR a.status = 1)';
		$sql .= $this->db->plimit(1, 0);
		if ($lock) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
		$hasLaterAnchor = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		return $hasLaterAnchor;
	}

	/**
	 * Send the completed inventory report if automatic email is enabled.
	 *
	 * @param array<string,mixed> $inventory Closed inventory detail
	 * @return array<string,mixed>
	 */
	private function sendInventoryEmailIfConfigured(array $inventory)
	{
		if (!$this->isInventoryEmailEnabled()) {
			return array(
				'enabled' => 0,
				'sent' => 0,
			);
		}

		$recipient = trim(getDolGlobalString('KREAPRODUCTS_STOCK_INVENTORY_EMAIL_TO'));
		if ($recipient === '' || !isValidEmail($recipient)) {
			$error = $this->langs->trans('KREAPRODUCTS_ERROR_EMAIL_RECIPIENT');
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' '.$error, LOG_WARNING);
			return array(
				'enabled' => 1,
				'sent' => 0,
				'recipient' => $recipient,
				'error' => $error,
			);
		}

		$from = $this->resolveMailFromAddress();
		if ($from === '') {
			$error = $this->langs->trans('KREAPRODUCTS_ERROR_EMAIL_SENDER');
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' '.$error, LOG_WARNING);
			return array(
				'enabled' => 1,
				'sent' => 0,
				'recipient' => $recipient,
				'error' => $error,
			);
		}

		$subject = 'KreaProductsStock - Inventário concluído '.$this->formatInventoryEmailReference((string) $inventory['ref']);
		$message = $this->buildInventoryEmailHtml($inventory);
		$attachment = $this->createInventoryEmailAgentJsonAttachment($inventory);
		if (empty($attachment['path'])) {
			$error = $this->langs->trans('KREAPRODUCTS_ERROR_EMAIL_ATTACHMENT');
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' '.$error, LOG_WARNING);
			return array(
				'enabled' => 1,
				'sent' => 0,
				'recipient' => $recipient,
				'error' => $error,
			);
		}
		$trackId = 'kreaproducts-inventory-'.((int) $inventory['id']);
		$mailfile = new CMailFile($subject, $recipient, $from, $message, array($attachment['path']), array('application/json'), array($attachment['filename']), '', '', 0, 1, '', '', $trackId);
		$sent = $mailfile->sendfile();
		@unlink($attachment['path']);
		if (!$sent) {
			$error = $this->getObjectError($mailfile, $this->langs->trans('KREAPRODUCTS_ERROR_EMAIL_SEND'));
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' recipient='.$recipient.' error='.$error, LOG_WARNING);
			return array(
				'enabled' => 1,
				'sent' => 0,
				'recipient' => $recipient,
				'error' => $error,
			);
		}

		dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' recipient='.$recipient, LOG_INFO);
		return array(
			'enabled' => 1,
			'sent' => 1,
			'recipient' => $recipient,
			'attachment' => $attachment['filename'],
		);
	}

	/**
	 * @return string
	 */
	private function resolveMailFromAddress()
	{
		$senderEmail = trim(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
		$senderLabel = trim(getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'KreaProductsStock'));
		if ($senderEmail === '' || !isValidEmail($senderEmail)) {
			$senderEmail = trim(getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'));
		}
		if ($senderEmail === '' || !isValidEmail($senderEmail)) {
			$senderEmail = trim((string) $this->user->email);
			$senderLabel = trim((string) dolGetFirstLastname($this->user->firstname, $this->user->lastname));
		}
		if ($senderEmail === '' || !isValidEmail($senderEmail)) {
			return '';
		}
		if ($senderLabel === '') {
			$senderLabel = 'KreaProductsStock';
		}
		return dol_string_nospecial($senderLabel, ' ', array(',')).' <'.$senderEmail.'>';
	}

	/**
	 * @param array<string,mixed> $inventory Inventory detail
	 * @return string
	 */
	private function buildInventoryEmailHtml(array $inventory)
	{
		$inventoryUrl = $this->buildInventoryCardUrl((int) $inventory['id']);
		$displayRef = $this->formatInventoryEmailReference((string) $inventory['ref']);
		$linesHtml = '';
		foreach ((array) $inventory['lines'] as $line) {
			$batch = empty($line['batch']) ? '-' : (string) $line['batch'];
			$linesHtml .= '<tr>';
			$linesHtml .= '<td>'.dol_escape_htmltag((string) $line['ref']).'</td>';
			$linesHtml .= '<td>'.dol_escape_htmltag((string) $line['label']).'</td>';
			$linesHtml .= '<td>'.dol_escape_htmltag($batch).'</td>';
			$linesHtml .= '<td style="text-align:right">'.dol_escape_htmltag($this->formatInventoryEmailQuantity($line['quantity'])).'</td>';
			$linesHtml .= '</tr>';
		}

		$inventoryDate = !empty($inventory['date_inventory'])
			? dol_print_date((int) $inventory['date_inventory'], 'dayhour')
			: dol_print_date((int) $inventory['date_creation'], 'dayhour');

		$html = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#17242a">';
		$html .= '<h2 style="margin:0 0 12px">Inventário concluído</h2>';
		$html .= '<p style="margin:0 0 16px">O KreaProductsStock concluiu automaticamente este inventário e gerou os movimentos de stock no Dolibarr.</p>';
		$html .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:16px">';
		$html .= '<tr><th align="left">Referência</th><td><a href="'.dol_escape_htmltag($inventoryUrl).'">'.dol_escape_htmltag($displayRef).'</a></td></tr>';
		$html .= '<tr><th align="left">Categoria</th><td>'.dol_escape_htmltag((string) $inventory['category_label']).'</td></tr>';
		$html .= '<tr><th align="left">Armazém</th><td>'.dol_escape_htmltag((string) $inventory['warehouse_ref']).'</td></tr>';
		$html .= '<tr><th align="left">Data</th><td>'.dol_escape_htmltag($inventoryDate).'</td></tr>';
		$html .= '<tr><th align="left">Linhas</th><td>'.((int) $inventory['counted_lines']).' de '.((int) $inventory['total_lines']).'</td></tr>';
		$html .= '</table>';
		$html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#d8e1dd;width:100%;max-width:900px">';
		$html .= '<thead><tr style="background:#eef3f0"><th align="left">Referência</th><th align="left">Produto</th><th align="left">Lote</th><th align="right">Quantidade</th></tr></thead>';
		$html .= '<tbody>'.$linesHtml.'</tbody>';
		$html .= '</table>';
		$html .= '<p style="margin-top:16px;color:#607178;font-size:12px">O JSON para agente segue em anexo.</p>';
		$html .= '<p style="margin-top:16px;color:#607178;font-size:12px">Email enviado automaticamente pelo KreaProductsStock.</p>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * @param array<string,mixed> $inventory Inventory detail
	 * @return array{path:string,filename:string}|array<string,string>
	 */
	private function createInventoryEmailAgentJsonAttachment(array $inventory)
	{
		$json = $this->buildInventoryEmailAgentJson($inventory);
		$tmpPath = tempnam(sys_get_temp_dir(), 'kreaproducts_inventory_');
		if ($tmpPath === false) {
			return array();
		}
		$result = file_put_contents($tmpPath, $json);
		if ($result === false) {
			@unlink($tmpPath);
			return array();
		}

		return array(
			'path' => $tmpPath,
			'filename' => $this->buildInventoryEmailAgentJsonFilename($inventory),
		);
	}

	/**
	 * Refresh only safe metadata after stock closure, without reopening the full mobile inventory path.
	 *
	 * @param array<string,mixed> $inventory Closed inventory snapshot
	 * @return array<string,mixed>
	 */
	private function refreshClosedInventoryMetadata(array $inventory)
	{
		$sql = 'SELECT i.ref, i.title, i.status, i.date_creation, i.date_inventory, e.ref as warehouse_ref';
		$sql .= ' FROM '.$this->db->prefix().'inventory as i';
		$sql .= ' INNER JOIN '.$this->db->prefix().'entrepot as e ON e.rowid = i.fk_warehouse';
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE i.rowid = '.((int) $inventory['id']);
		$sql .= ' AND i.entity = '.((int) $this->conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' error='.$this->db->lasterror(), LOG_WARNING);
			return $inventory;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			dol_syslog(__METHOD__.' inventory='.(int) $inventory['id'].' metadata row not found after closure', LOG_WARNING);
			return $inventory;
		}

		$inventory['ref'] = (string) $obj->ref;
		$inventory['title'] = (string) $obj->title;
		$inventory['status'] = (int) $obj->status;
		$inventory['date_creation'] = $this->db->jdate($obj->date_creation);
		$inventory['date_inventory'] = $this->db->jdate($obj->date_inventory);
		$inventory['warehouse_ref'] = (string) $obj->warehouse_ref;

		return $inventory;
	}

	/**
	 * @param string $ref Inventory reference
	 * @return string
	 */
	private function formatInventoryEmailReference($ref)
	{
		$ref = preg_replace('/\s*\([^)]*\)/', '', (string) $ref);
		return strtoupper(trim((string) $ref));
	}

	/**
	 * @param int $inventoryId Inventory ID
	 * @return string
	 */
	private function buildInventoryCardUrl($inventoryId)
	{
		$kreaProductsCard = DOL_DOCUMENT_ROOT.'/custom/kreaproducts/inventory.php';
		$path = file_exists($kreaProductsCard) || isModEnabled('kreaproducts')
			? '/custom/kreaproducts/inventory.php'
			: '/product/inventory/inventory.php';

		return dol_buildpath($path, 1).'?id='.((int) $inventoryId);
	}

	/**
	 * @param array<string,mixed> $inventory Inventory detail
	 * @return string
	 */
	private function buildInventoryEmailAgentJson(array $inventory)
	{
		$json = json_encode($this->buildInventoryEmailAgentPayload($inventory), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return is_string($json) ? $json : '{}';
	}

	/**
	 * @param array<string,mixed> $inventory Inventory detail
	 * @return string
	 */
	private function buildInventoryEmailAgentJsonFilename(array $inventory)
	{
		$displayRef = $this->formatInventoryEmailReference((string) $inventory['ref']);
		$safeRef = preg_replace('/[^A-Z0-9_.-]+/', '_', $displayRef);
		$safeRef = trim((string) $safeRef, '._-');
		if ($safeRef === '') {
			$safeRef = 'inventory_'.((int) $inventory['id']);
		}

		return 'kreaproducts_completed_inventory_'.$safeRef.'.json';
	}

	/**
	 * @param array<string,mixed> $inventory Inventory detail
	 * @return array<string,mixed>
	 */
	private function buildInventoryEmailAgentPayload(array $inventory)
	{
		$lines = array();
		foreach ((array) $inventory['lines'] as $line) {
			$lines[] = array(
				'line_id' => (int) $line['id'],
				'product_id' => (int) $line['product_id'],
				'product_ref' => (string) $line['ref'],
				'product_label' => (string) $line['label'],
				'barcode' => (string) $line['barcode'],
				'batch' => (string) $line['batch'],
				'quantity' => is_null($line['quantity']) ? null : (float) $line['quantity'],
			);
		}

		return array(
			'schema' => 'kreaproducts.completed_inventory.v1',
			'event' => 'inventory.completed',
			'generated_at' => date('c', dol_now()),
			'module' => 'kreaproducts',
			'entity_id' => (int) $this->conf->entity,
			'inventory' => array(
				'id' => (int) $inventory['id'],
				'ref' => (string) $inventory['ref'],
				'display_ref' => $this->formatInventoryEmailReference((string) $inventory['ref']),
				'url' => $this->buildInventoryCardUrl((int) $inventory['id']),
				'status' => 'recorded',
				'category_id' => (int) $inventory['category_id'],
				'category_label' => (string) $inventory['category_label'],
				'warehouse_id' => (int) $inventory['warehouse_id'],
				'warehouse_ref' => (string) $inventory['warehouse_ref'],
				'date_creation' => $this->formatInventoryAgentDate($inventory['date_creation']),
				'date_inventory' => $this->formatInventoryAgentDate($inventory['date_inventory']),
				'counted_lines' => (int) $inventory['counted_lines'],
				'total_lines' => (int) $inventory['total_lines'],
				'lines' => $lines,
			),
			'closed_by' => array(
				'id' => (int) $this->user->id,
				'name' => trim((string) dolGetFirstLastname($this->user->firstname, $this->user->lastname)),
				'email' => (string) $this->user->email,
			),
		);
	}

	/**
	 * @param mixed $timestamp Dolibarr timestamp
	 * @return string|null
	 */
	private function formatInventoryAgentDate($timestamp)
	{
		$timestamp = (int) $timestamp;
		return $timestamp > 0 ? date('c', $timestamp) : null;
	}

	/**
	 * @param mixed $quantity Quantity
	 * @return string
	 */
	private function formatInventoryEmailQuantity($quantity)
	{
		if ($quantity === null || $quantity === '') {
			return '-';
		}
		$number = (float) $quantity;
		return rtrim(rtrim(number_format($number, 6, ',', ''), '0'), ',');
	}

	/**
	 * Build an insertion-only unique reference that is replaced inside the creation transaction.
	 *
	 * @param int $categoryId Category ID
	 * @return string
	 */
	private function buildTemporaryInventoryReference($categoryId)
	{
		return 'KPS-TMP-'.((int) $this->conf->entity).'-'.((int) $categoryId).'-'.strtoupper(bin2hex(random_bytes(8)));
	}

	/**
	 * Convert a pre-3.1 initiated KPS technical reference to its visible provisional reference.
	 * Recorded inventories are immutable and are never renamed here.
	 *
	 * @param object $inventory Inventory database record
	 * @return void
	 */
	private function normalizeInitiatedTechnicalReference($inventory)
	{
		if ((int) $inventory->status !== Inventory::STATUS_VALIDATED
			|| preg_match('/^KPS-\d+-\d+-\d{14}-[A-F0-9]{4}$/', (string) $inventory->ref) !== 1
		) {
			return;
		}

		$provisionalReference = $this->buildProvisionalInventoryReference((int) $inventory->rowid);
		$this->beginStockTransaction();
		$sql = 'SELECT rowid, ref, status FROM '.$this->db->prefix().'inventory';
		$sql .= ' WHERE rowid='.((int) $inventory->rowid);
		$sql .= ' AND entity='.((int) $this->conf->entity);
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		$lockedInventory = $resql ? $this->db->fetch_object($resql) : false;
		if ($resql) {
			$this->db->free($resql);
		}
		if (!$lockedInventory) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVENTORY_ENTITY_NOT_FOUND'), 404);
		}
		if ((int) $lockedInventory->status === Inventory::STATUS_VALIDATED
			&& preg_match('/^KPS-\d+-\d+-\d{14}-[A-F0-9]{4}$/', (string) $lockedInventory->ref) === 1
		) {
			$sql = 'UPDATE '.$this->db->prefix().'inventory';
			$sql .= " SET ref='".$this->db->escape($provisionalReference)."', import_key='KPS'";
			$sql .= ' WHERE rowid='.((int) $inventory->rowid);
			$sql .= ' AND entity='.((int) $this->conf->entity);
			if (!$this->db->query($sql)) {
				$this->db->rollback();
				throw new KreaProductsStockApiException($this->db->lasterror(), 500);
			}
			$inventory->ref = $provisionalReference;
			$inventory->import_key = 'KPS';
		}
		$this->commitStockTransaction();
	}

	/**
	 * Build the visible Dolibarr-style reference used while an inventory is initiated.
	 *
	 * @param int $inventoryId Inventory ID
	 * @return string
	 */
	private function buildProvisionalInventoryReference($inventoryId)
	{
		return '(PROV'.str_pad((string) ((int) $inventoryId), 6, '0', STR_PAD_LEFT).')';
	}

	/**
	 * Resolve the final unique reference without changing the normal business format.
	 *
	 * @param string $baseReference Base YYYYMMDD_CATEGORY reference
	 * @param int    $inventoryId   Inventory ID excluded from collision checks
	 * @return string
	 */
	private function resolveAvailableInventoryReference($baseReference, $inventoryId)
	{
		$sql = 'SELECT ref FROM '.$this->db->prefix().'inventory';
		$sql .= ' WHERE entity='.((int) $this->conf->entity);
		$sql .= ' AND rowid<>'.((int) $inventoryId);
		$sql .= " AND (ref='".$this->db->escape((string) $baseReference)."'";
		$sql .= " OR ref LIKE '".$this->db->escape($this->db->escapeforlike((string) $baseReference.'_V'))."%')";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}

		$hasCollision = false;
		$highestVersion = 1;
		while ($obj = $this->db->fetch_object($resql)) {
			$hasCollision = true;
			if (preg_match('/^'.preg_quote((string) $baseReference, '/').'_V(\d+)$/', (string) $obj->ref, $matches)) {
				$highestVersion = max($highestVersion, (int) $matches[1]);
			}
		}
		$this->db->free($resql);

		return $hasCollision ? (string) $baseReference.'_V'.($highestVersion + 1) : (string) $baseReference;
	}

	/**
	 * Persist an inventory reference inside the caller's transaction.
	 *
	 * @param int    $inventoryId Inventory ID
	 * @param string $reference   New reference
	 * @return void
	 */
	private function persistInventoryReference($inventoryId, $reference)
	{
		$sql = 'UPDATE '.$this->db->prefix().'inventory';
		$sql .= " SET ref='".$this->db->escape((string) $reference)."'";
		$sql .= ' WHERE rowid='.((int) $inventoryId);
		$sql .= ' AND entity='.((int) $this->conf->entity);
		if (!$this->db->query($sql)) {
			throw new KreaProductsStockApiException($this->db->lasterror(), 500);
		}
	}

	/**
	 * @param string $categoryLabel Clean inventory category label
	 * @param int    $valueTimestamp Normalized inventory value timestamp
	 * @return string
	 */
	private function buildInventoryReference($categoryLabel, $valueTimestamp)
	{
		$referenceLabel = strtoupper(dol_string_unaccent($this->cleanTemplateLabel((string) $categoryLabel)));
		$referenceLabel = preg_replace('/[^A-Z0-9]+/', '_', $referenceLabel);
		$referenceLabel = trim((string) preg_replace('/_+/', '_', (string) $referenceLabel), '_');
		$referenceLabel = rtrim(dol_substr($referenceLabel !== '' ? $referenceLabel : 'INVENTORY', 0, 55), '_');
		return dol_print_date((int) $valueTimestamp, '%Y%m%d', 'tzuserrel').'_'.$referenceLabel;
	}

	/**
	 * @param string $label Category label
	 * @return string
	 */
	private function cleanTemplateLabel($label)
	{
		$clean = preg_replace('/^\s*\(IV\)\s*/i', '', trim((string) $label));
		return is_string($clean) && $clean !== '' ? $clean : trim((string) $label);
	}

	/**
	 * @param object $object   Dolibarr object
	 * @param string $fallback Fallback message
	 * @return string
	 */
	private function getObjectError($object, $fallback)
	{
		if (!empty($object->error)) {
			return (string) $object->error;
		}
		if (!empty($object->errors) && is_array($object->errors)) {
			return implode(', ', $object->errors);
		}
		return (string) $fallback;
	}

	/**
	 * Translate inventory-count validation errors returned by the framework-neutral calculator.
	 *
	 * @param InvalidArgumentException $exception Validation error
	 * @return string
	 */
	private function translateCountValidationError(InvalidArgumentException $exception)
	{
		if ($exception->getMessage() === 'Count quantity cannot be negative.') {
			return $this->langs->trans('KREAPRODUCTS_ERROR_COUNT_NEGATIVE');
		}
		return $this->langs->trans('KREAPRODUCTS_ERROR_COUNT_NUMERIC_OR_BLANK');
	}
}
