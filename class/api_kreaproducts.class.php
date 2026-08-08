<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Commercial support and integration services are available from Kreativität Works.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/moline.class.php';
if (!class_exists('DolibarrApi')) {
	require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';
}
if (!class_exists('Mos')) {
	require_once DOL_DOCUMENT_ROOT . '/mrp/class/api_mos.class.php';
}
dol_include_once('/kreaproducts/class/KreaProductsLabelService.class.php');

/**
 * API class for KreaProducts touch production workflow.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class KreaProductsApi extends DolibarrApi
{
	/**
	 * Database handler.
	 */
	public $db;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List product categories that contain producible products.
	 *
	 * @return array
	 *
	 * @url GET production/categories
	 */
	public function getProductionCategories()
	{
		$this->assertMrpEnabled();
		$this->assertProductionReadRights();

		$category = new Categorie($this->db);
		$productTypeId = (int) (array_key_exists(Categorie::TYPE_PRODUCT, $category->MAP_ID) ? $category->MAP_ID[Categorie::TYPE_PRODUCT] : -1);
		if ($productTypeId < 0) {
			throw new RestException(500, 'Unable to resolve product category type id');
		}
		$bomEntitySql = $this->entityListToSql($this->getEntityIdList('bom', true));

		$sql = "SELECT c.rowid, c.label, c.fk_parent, c.color, COUNT(DISTINCT p.rowid) AS product_count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "categorie AS c";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product AS cp ON cp.fk_categorie = c.rowid";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = cp.fk_product";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.fk_product = p.rowid";
		$sql .= " WHERE c.type = " . $productTypeId;
		$sql .= " AND c.entity IN (" . getEntity('category') . ")";
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " AND b.entity IN (" . $bomEntitySql . ")";
		$sql .= " AND b.status = " . ((int) BOM::STATUS_VALIDATED);
		$sql .= " GROUP BY c.rowid, c.label, c.fk_parent, c.color";
		$sql .= " ORDER BY c.label ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load production categories', $this->db->lasterror());
		}

		$result = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$result[] = array(
				'id' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'fk_parent' => (int) $obj->fk_parent,
				'color' => (string) $obj->color,
				'product_count' => (int) $obj->product_count,
			);
		}
		$this->db->free($resql);

		return $result;
	}

	/**
	 * List producible products by product category.
	 *
	 * @param int $category_id Product category id
	 * @return array
	 *
	 * @url GET production/categories/{category_id}/products
	 */
	public function getProductionProductsByCategory($category_id)
	{
		$this->assertMrpEnabled();
		$this->assertProductionReadRights();

		$category = new Categorie($this->db);
		$result = $category->fetch((int) $category_id);
		if ($result <= 0) {
			throw new RestException(404, 'Category not found');
		}
		$productTypeId = (int) (!empty($category->MAP_ID[Categorie::TYPE_PRODUCT]) ? $category->MAP_ID[Categorie::TYPE_PRODUCT] : 0);
		$isProductType = (
			((string) $category->type === Categorie::TYPE_PRODUCT)
			|| ((int) $category->type === $productTypeId)
		);
		if (!$isProductType) {
			throw new RestException(400, 'Category is not a product category');
		}
		if (!DolibarrApi::_checkAccessToResource('categorie', $category->id)) {
			throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}
		$bomEntitySql = $this->entityListToSql($this->getEntityIdList('bom', true));

		$sql = "SELECT p.rowid, p.ref, p.label, p.barcode, p.tobatch AS status_batch, p.fk_default_warehouse, p.fk_default_bom,";
		$sql .= " MIN(b.rowid) AS fallback_bom_id, COUNT(DISTINCT b.rowid) AS bom_count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product AS p";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product AS cp ON cp.fk_product = p.rowid";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.fk_product = p.rowid";
		$sql .= " WHERE cp.fk_categorie = " . ((int) $category_id);
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " AND b.entity IN (" . $bomEntitySql . ")";
		$sql .= " AND b.status = " . ((int) BOM::STATUS_VALIDATED);
		$sql .= " GROUP BY p.rowid, p.ref, p.label, p.barcode, p.tobatch, p.fk_default_warehouse, p.fk_default_bom";
		$sql .= " ORDER BY p.label ASC, p.ref ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load products for category', $this->db->lasterror());
		}

		$products = array();
		$productIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$defaultBomId = (int) $obj->fk_default_bom;
			if ($defaultBomId <= 0) {
				$defaultBomId = (int) $obj->fallback_bom_id;
			}

			$products[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'barcode' => (string) $obj->barcode,
				'status_batch' => (int) $obj->status_batch,
				'default_warehouse_id' => (int) $obj->fk_default_warehouse,
				'default_bom_id' => $defaultBomId,
				'bom_count' => (int) $obj->bom_count,
			);
			$productIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		if (!empty($products)) {
			$defaultLayouts = $this->loadProductExtrafieldTextMap($productIds, 'kreap_default_label_layout');
			$aliases = $this->loadProductExtrafieldTextMap($productIds, 'kreap_alias');
			foreach ($products as &$row) {
				$productId = (int) $row['id'];
				$labelStoragePayload = $this->parseProductLabelStoragePayload(!empty($defaultLayouts[$productId]) ? (string) $defaultLayouts[$productId] : '');
				$layout = (!empty($labelStoragePayload['default_label_layout']) ? (string) $labelStoragePayload['default_label_layout'] : '');
				$alias = (!empty($aliases[$productId]) ? (string) $aliases[$productId] : '');
				$row['kreap_alias'] = $alias;
				$row['default_label_layout'] = $layout;
				$row['array_options'] = array(
					'options_kreap_default_label_layout' => $layout,
					'options_kreap_alias' => $alias,
				);
			}
			unset($row);
		}

		return $products;
	}

	/**
	 * Return one product-category subtree with products per category node.
	 *
	 * Use this endpoint when the app controls one root category (for example id 100)
	 * and needs all descendants + associated products to render a touch catalog.
	 *
	 * @param int $category_id Root product category id
	 * @param int $only_producible 1=only products with enabled BOM, 0=all linked products
	 * @return array
	 *
	 * @url GET production/categories/{category_id}/tree
	 */
	public function getProductionCategoryTree($category_id, $only_producible = 0)
	{
		$this->assertProductionReadRights();
		$onlyProducible = ((int) $only_producible > 0 ? 1 : 0);
		$rootId = (int) $category_id;

		$rootCategory = $this->fetchProductCategoryOrFail($rootId);
		$categoriesById = $this->loadProductCategoriesIndexed();
		if (empty($categoriesById[$rootId])) {
			throw new RestException(404, 'Category not found in current entity scope');
		}

		$childrenMap = array();
		foreach ($categoriesById as $id => $cat) {
			$parentId = (int) $cat['fk_parent'];
			if (!isset($childrenMap[$parentId])) {
				$childrenMap[$parentId] = array();
			}
			$childrenMap[$parentId][] = (int) $id;
		}

		foreach ($childrenMap as $parentId => $childIds) {
			usort($childIds, function ($a, $b) use ($categoriesById) {
				$la = mb_strtolower((string) $categoriesById[$a]['label']);
				$lb = mb_strtolower((string) $categoriesById[$b]['label']);
				if ($la === $lb) {
					return ($a <=> $b);
				}
				return strcmp($la, $lb);
			});
			$childrenMap[$parentId] = $childIds;
		}

		$productsByCategory = $this->loadProductsByCategory($onlyProducible);
		$tree = $this->buildCategoryTreeNode($rootId, $categoriesById, $childrenMap, $productsByCategory);

		$stats = array(
			'categories_count' => 0,
			'products_count' => 0,
			'producible_products_count' => 0,
		);
		$this->accumulateCategoryTreeStats($tree, $stats);

		return array(
			'root_category' => $rootCategory,
			'only_producible' => $onlyProducible,
			'tree' => $tree,
			'totals' => $stats,
		);
	}

	/**
	 * List MO production history created by production/run trace.
	 *
	 * Each item represents one produced batch linked to one MO.
	 *
	 * @param int $limit Max items per page
	 * @param int $page  Page offset
	 * @param int $days_back Restrict results to the last N days (0 = no limit)
	 * @return array
	 *
	 * @url GET production/mos/created
	 */
	public function getProductionCreatedMos($limit = 200, $page = 0, $days_back = 30)
	{
		$this->assertMrpEnabled();
		$this->assertProductionReadRights();

		$this->assertProductionTraceSchemaReady();

		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = 200;
		}
		if ($limit > 1000) {
			$limit = 1000;
		}
		$page = max(0, (int) $page);
		$daysBack = (int) $days_back;
		if ($daysBack < 0) {
			$daysBack = 0;
		}
		if ($daysBack > 3650) {
			$daysBack = 3650;
		}
		$offset = $limit * $page;

		$mrpEntitySql = $this->entityListToSql($this->getEntityIdList('mrp', true));
		$bomEntitySql = $this->entityListToSql($this->getEntityIdList('bom', true));
		$productEntitySql = $this->entityListToSql($this->getEntityIdList('product', true));
		$traceTable = MAIN_DB_PREFIX . 'kreaproducts_mo_batch';

		$whereSql = " WHERE t.entity IN (" . $mrpEntitySql . ")";
		$whereSql .= " AND mo.entity IN (" . $mrpEntitySql . ")";
		$whereSql .= " AND mo.fk_bom > 0";
		$whereSql .= " AND (b.rowid IS NULL OR b.entity IN (" . $bomEntitySql . "))";
		$whereSql .= " AND (p.rowid IS NULL OR p.entity IN (" . $productEntitySql . "))";
		if ($daysBack > 0) {
			$thresholdTs = dol_now() - ($daysBack * 86400);
			$whereSql .= " AND t.date_creation >= '" . $this->db->idate($thresholdTs) . "'";
		}

		$sqlCount = "SELECT COUNT(t.rowid) AS total_count";
		$sqlCount .= " FROM " . $traceTable . " AS t";
		$sqlCount .= " INNER JOIN " . MAIN_DB_PREFIX . "mrp_mo AS mo ON mo.rowid = t.fk_mo";
		$sqlCount .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.rowid = mo.fk_bom";
		$sqlCount .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = mo.fk_product";
		$sqlCount .= $whereSql;
		$resCount = $this->db->query($sqlCount);
		if (!$resCount) {
			$this->failInternalRequest('Unable to count created MO history', $this->db->lasterror());
		}
		$objCount = $this->db->fetch_object($resCount);
		$this->db->free($resCount);
		$totalCount = (!empty($objCount->total_count) ? (int) $objCount->total_count : 0);

		$sql = "SELECT t.rowid AS trace_id, t.date_creation, t.inventorycode, t.production_qty,";
		$sql .= " mo.rowid AS mo_id, mo.ref AS mo_ref, mo.label AS mo_label, mo.fk_bom AS bom_id, mo.fk_product AS product_id,";
		$sql .= " b.ref AS bom_ref, b.label AS bom_label,";
		$sql .= " p.ref AS product_ref, p.label AS product_label";
		$sql .= " FROM " . $traceTable . " AS t";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "mrp_mo AS mo ON mo.rowid = t.fk_mo";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.rowid = mo.fk_bom";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = mo.fk_product";
		$sql .= $whereSql;
		$sql .= " ORDER BY t.date_creation DESC, t.rowid DESC";
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load created MO history', $this->db->lasterror());
		}

		$items = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$createdAt = '';
			if (!empty($obj->date_creation)) {
				$createdAtTs = $this->db->jdate($obj->date_creation);
				if ($createdAtTs > 0) {
					$createdAt = dol_print_date($createdAtTs, '%Y-%m-%dT%H:%M:%SZ', 'gmt');
				}
			}

			$recordRef = trim((string) $obj->mo_ref);
			if ($recordRef === '') {
				$recordRef = trim((string) $obj->bom_ref);
			}
			$recordLabel = trim((string) $obj->product_label);
			if ($recordLabel === '') {
				$recordLabel = trim((string) $obj->mo_label);
			}
			if ($recordLabel === '') {
				$recordLabel = trim((string) $obj->bom_label);
			}

			$items[] = array(
				'id' => (int) $obj->trace_id,
				'date' => (string) $createdAt,
				'ref' => (string) $recordRef,
				'label' => (string) $recordLabel,
				'batch' => (string) $obj->inventorycode,
				'quantity' => (float) price2num($obj->production_qty, 'MS'),
				'bom_id' => (int) $obj->bom_id,
				'bom_ref' => (string) $obj->bom_ref,
				'bom_label' => (string) $obj->bom_label,
				'product_id' => (int) $obj->product_id,
				'product_ref' => (string) $obj->product_ref,
				'product_label' => (string) $obj->product_label,
				'mo_id' => (int) $obj->mo_id,
				'mo_ref' => (string) $obj->mo_ref,
				'mo_label' => (string) $obj->mo_label,
			);
		}
		$this->db->free($resql);

		$totalPages = ($totalCount > 0 ? (int) ceil($totalCount / $limit) : 0);

		return array(
			'page' => $page,
			'limit' => $limit,
			'days_back' => $daysBack,
			'count' => count($items),
			'total_count' => $totalCount,
			'total_pages' => $totalPages,
			'items' => $items,
		);
	}

	/**
	 * Return one immutable created MO trace payload.
	 *
	 * This endpoint exposes the produced snapshot (header + traced component lines)
	 * and does not allow updates.
	 *
	 * @param int $id Trace row id
	 * @return array
	 *
	 * @url GET production/mos/created/{id}
	 */
	public function getProductionCreatedMo($id)
	{
		$this->assertMrpEnabled();
		$this->assertProductionReadRights();

		$this->assertProductionTraceSchemaReady();

		$traceId = (int) $id;
		if ($traceId <= 0) {
			throw new RestException(400, 'Invalid created MO id');
		}

		$mrpEntitySql = $this->entityListToSql($this->getEntityIdList('mrp', true));
		$bomEntitySql = $this->entityListToSql($this->getEntityIdList('bom', true));
		$productEntitySql = $this->entityListToSql($this->getEntityIdList('product', true));
		$traceTable = MAIN_DB_PREFIX . 'kreaproducts_mo_batch';
		$componentTable = MAIN_DB_PREFIX . 'kreaproducts_mo_component_batch';

		$sqlHeader = "SELECT t.rowid AS trace_id, t.date_creation, t.inventorycode, t.production_qty,";
		$sqlHeader .= " mo.rowid AS mo_id, mo.ref AS mo_ref, mo.label AS mo_label, mo.fk_bom AS bom_id, mo.fk_product AS product_id,";
		$sqlHeader .= " b.ref AS bom_ref, b.label AS bom_label,";
		$sqlHeader .= " p.ref AS product_ref, p.label AS product_label";
		$sqlHeader .= " FROM " . $traceTable . " AS t";
		$sqlHeader .= " INNER JOIN " . MAIN_DB_PREFIX . "mrp_mo AS mo ON mo.rowid = t.fk_mo";
		$sqlHeader .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.rowid = mo.fk_bom";
		$sqlHeader .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = mo.fk_product";
		$sqlHeader .= " WHERE t.rowid = " . $traceId;
		$sqlHeader .= " AND t.entity IN (" . $mrpEntitySql . ")";
		$sqlHeader .= " AND mo.entity IN (" . $mrpEntitySql . ")";
		$sqlHeader .= " AND mo.fk_bom > 0";
		$sqlHeader .= " AND (b.rowid IS NULL OR b.entity IN (" . $bomEntitySql . "))";
		$sqlHeader .= " AND (p.rowid IS NULL OR p.entity IN (" . $productEntitySql . "))";
		$sqlHeader .= $this->db->plimit(1);

		$resHeader = $this->db->query($sqlHeader);
		if (!$resHeader) {
			$this->failInternalRequest('Unable to load created MO detail', $this->db->lasterror());
		}
		$objHeader = $this->db->fetch_object($resHeader);
		$this->db->free($resHeader);
		if (!$objHeader) {
			throw new RestException(404, 'Created MO not found');
		}

		$createdAt = '';
		if (!empty($objHeader->date_creation)) {
			$createdAtTs = $this->db->jdate($objHeader->date_creation);
			if ($createdAtTs > 0) {
				$createdAt = dol_print_date($createdAtTs, '%Y-%m-%dT%H:%M:%SZ', 'gmt');
			}
		}
		$recordRef = trim((string) $objHeader->mo_ref);
		if ($recordRef === '') {
			$recordRef = trim((string) $objHeader->bom_ref);
		}
		$recordLabel = trim((string) $objHeader->product_label);
		if ($recordLabel === '') {
			$recordLabel = trim((string) $objHeader->mo_label);
		}
		if ($recordLabel === '') {
			$recordLabel = trim((string) $objHeader->bom_label);
		}

		$sqlLines = "SELECT c.rowid AS line_id, c.position, c.fk_bomline, c.fk_mo_line, c.fk_component_product,";
		$sqlLines .= " c.component_qty, c.component_batch,";
		$sqlLines .= " bl.qty AS bom_line_qty, bl.description AS bom_line_description,";
		$sqlLines .= " mp.qty AS mo_line_qty,";
		$sqlLines .= " cp.ref AS component_ref, cp.label AS component_label";
		$sqlLines .= " FROM " . $componentTable . " AS c";
		$sqlLines .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bomline AS bl ON bl.rowid = c.fk_bomline";
		$sqlLines .= " LEFT JOIN " . MAIN_DB_PREFIX . "mrp_production AS mp ON mp.rowid = c.fk_mo_line";
		$sqlLines .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS cp ON cp.rowid = c.fk_component_product";
		$sqlLines .= " WHERE c.entity IN (" . $mrpEntitySql . ")";
		$sqlLines .= " AND c.fk_trace = " . $traceId;
		$sqlLines .= " AND (cp.rowid IS NULL OR cp.entity IN (" . $productEntitySql . "))";
		$sqlLines .= " ORDER BY c.position ASC, c.rowid ASC";

		$resLines = $this->db->query($sqlLines);
		if (!$resLines) {
			$this->failInternalRequest('Unable to load created MO lines', $this->db->lasterror());
		}

		$lines = array();
		while ($objLine = $this->db->fetch_object($resLines)) {
			$lines[] = array(
				'line_id' => (int) $objLine->line_id,
				'position' => (int) $objLine->position,
				'component_product_id' => (int) $objLine->fk_component_product,
				'component_ref' => (string) $objLine->component_ref,
				'component_label' => (string) $objLine->component_label,
				'quantity' => (float) price2num($objLine->component_qty, 'MS'),
				'batch' => (string) $objLine->component_batch,
				'bom_line_id' => (int) $objLine->fk_bomline,
				'mo_line_id' => (int) $objLine->fk_mo_line,
				'bom_line_qty' => ($objLine->bom_line_qty !== null ? (float) price2num($objLine->bom_line_qty, 'MS') : null),
				'mo_line_qty' => ($objLine->mo_line_qty !== null ? (float) price2num($objLine->mo_line_qty, 'MS') : null),
				'line_description' => (string) $objLine->bom_line_description,
			);
		}
		$this->db->free($resLines);

		return array(
			'id' => (int) $objHeader->trace_id,
			'date' => (string) $createdAt,
			'ref' => (string) $recordRef,
			'label' => (string) $recordLabel,
			'batch' => (string) $objHeader->inventorycode,
			'quantity' => (float) price2num($objHeader->production_qty, 'MS'),
			'bom_id' => (int) $objHeader->bom_id,
			'bom_ref' => (string) $objHeader->bom_ref,
			'bom_label' => (string) $objHeader->bom_label,
			'product_id' => (int) $objHeader->product_id,
			'product_ref' => (string) $objHeader->product_ref,
			'product_label' => (string) $objHeader->product_label,
			'mo_id' => (int) $objHeader->mo_id,
			'mo_ref' => (string) $objHeader->mo_ref,
			'mo_label' => (string) $objHeader->mo_label,
			'components_count' => count($lines),
			'components' => $lines,
		);
	}

	/**
	 * Backward-compatible alias for older kiosk clients.
	 *
	 * @param int $limit Max items per page
	 * @param int $page  Page offset
	 * @param int $days_back Restrict results to the last N days (0 = no limit)
	 * @return array
	 *
	 * @url GET production/boms/created
	 */
	public function getProductionCreatedBoms($limit = 200, $page = 0, $days_back = 30)
	{
		return $this->getProductionCreatedMos($limit, $page, $days_back);
	}

	/**
	 * Backward-compatible alias for older kiosk clients.
	 *
	 * @param int $id Trace row id
	 * @return array
	 *
	 * @url GET production/boms/created/{id}
	 */
	public function getProductionCreatedBom($id)
	{
		return $this->getProductionCreatedMo($id);
	}

	/**
	 * Return production recipe for one product.
	 *
	 * Priority:
	 * 1) Active BOM lines (default behavior)
	 * 2) Product associations fallback when no active BOM exists
	 *
	 * @param int $product_id Product id
	 * @param int $bom_id Optional BOM id override
	 * @return array
	 *
	 * @url GET production/products/{product_id}/recipe
	 */
	public function getProductionProductRecipe($product_id, $bom_id = 0)
	{
		$this->assertMrpEnabled();
		$this->assertProductionReadRights();

		$product = $this->fetchProduct((int) $product_id);
		$recipeText = $this->loadProductRecipeText((int) $product->id);
		$requestedBomId = (int) $bom_id;
		$source = 'bom';
		$bomPayload = array();
		$lines = array();

		try {
			$bomId = $this->resolveBomForProduct($product, $requestedBomId);

			$bom = new BOM($this->db);
			if ($bom->fetch($bomId) <= 0) {
				throw new RestException(404, 'BOM not found');
			}
			if ((int) $bom->fk_product !== (int) $product->id) {
				throw new RestException(409, 'Resolved BOM does not belong to selected product');
			}
			if ((int) $bom->status !== (int) BOM::STATUS_VALIDATED) {
				throw new RestException(409, 'Resolved BOM is not enabled');
			}
			if (!$this->isEntityInScope((int) $bom->entity, $this->getEntityIdList('bom', true))) {
				throw new RestException(403, 'Resolved BOM is out of current entity scope');
			}

			$lines = $this->loadRecipeLinesForBom((int) $bom->id);
			$bomPayload = array(
				'id' => (int) $bom->id,
				'ref' => (string) $bom->ref,
				'label' => (string) $bom->label,
				'description' => (string) $bom->description,
				'entity' => (int) $bom->entity,
				'qty' => (float) price2num($bom->qty, 'MS'),
			);
		} catch (RestException $e) {
			// When no active BOM exists and no explicit BOM was requested, fallback to product associations.
			$canFallbackToAssociations = (
				$requestedBomId <= 0
				&& (int) $e->getCode() === 409
				&& stripos((string) $e->getMessage(), 'No active BOM found') !== false
			);
			if (!$canFallbackToAssociations) {
				throw $e;
			}

			$associationLines = $this->loadRecipeLinesFromProductAssociations((int) $product->id);
			if (empty($associationLines)) {
				throw new RestException(409, 'No active BOM found for selected product and no product associations are available');
			}

			$source = 'association';
			$lines = $associationLines;
			$bomPayload = array(
				'id' => 0,
				'ref' => '',
				'label' => 'Product associations',
				'description' => 'Fallback recipe built from product associations',
				'entity' => (int) $product->entity,
				'qty' => 1,
			);
		}

		return array(
			'product' => array(
				'id' => (int) $product->id,
				'ref' => (string) $product->ref,
				'label' => (string) $product->label,
				'kreap_recipe' => (string) $recipeText,
			),
			'recipe_text' => (string) $recipeText,
			'bom' => $bomPayload,
			'source' => $source,
			'lines' => $lines,
			'totals' => array(
				'line_count' => count($lines),
				'source' => $source,
			),
		);
	}

	/**
	 * Get label payload for one product/production quantity.
	 *
	 * @param int    $product_id       Product id
	 * @param float  $production_qty   Production quantity
	 * @param float  $units_per_label  Units represented by one label
	 * @param int    $labels_count     Explicit labels count (overrides computed)
	 * @param string $template_code    Optional template code
	 * @param string $langcode         Optional output language (example: en_US, pt_PT)
	 * @return array
	 *
	 * @url GET production/products/{product_id}/labels
	 */
	public function getProductionLabelData($product_id, $production_qty = 1, $units_per_label = 1, $labels_count = 0, $template_code = '', $langcode = '')
	{
		$this->assertLabelReadRights();

		$product = $this->fetchProduct((int) $product_id);
		$this->applyProductAliasToLabel($product);
		return $this->buildLabelPayload($product, $production_qty, $units_per_label, $labels_count, $template_code, array(), $langcode);
	}

	/**
	 * Generate one labels PDF and return file payload as base64.
	 *
	 * Request body example:
	 * {
	 *   "product_id": 345,
	 *   "production_qty": 120,
	 *   "units_per_label": 1,
	 *   "labels_count": 120,
	 *   "template_code": "degema_normal",
	 *   "produced_batch": "2026031422341",
	 *   "mo_id": 341,
	 *   "template_values": {},
	 *   "langcode": "pt_PT"
	 * }
	 *
	 * @param int   $product_id   Product id (path)
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST production/products/{product_id}/labels/pdf
	 */
	public function postProductionLabelPdf($product_id = 0, $request_data = null)
	{
		global $langs, $conf;

		try {
			$this->assertLabelReadRights();

			if (!is_array($request_data)) {
				$request_data = array();
			}

			$productIdFromPath = (int) $product_id;
			$productIdFromBody = (int) (isset($request_data['product_id']) ? $request_data['product_id'] : 0);
			if ($productIdFromPath <= 0) {
				throw new RestException(400, 'Missing product_id');
			}
			if ($productIdFromBody > 0 && $productIdFromBody !== $productIdFromPath) {
				throw new RestException(400, 'product_id in body does not match path');
			}

			$product = $this->fetchProduct($productIdFromPath);
			$this->applyProductAliasToLabel($product);
			$productionQty = price2num(isset($request_data['production_qty']) ? $request_data['production_qty'] : 1, 'MS');
			if ($productionQty <= 0) {
				$productionQty = 1;
			}

			$unitsPerLabel = price2num(isset($request_data['units_per_label']) ? $request_data['units_per_label'] : 1, 'MS');
			if ($unitsPerLabel <= 0) {
				$unitsPerLabel = 1;
			}

			$labelsCount = (int) (isset($request_data['labels_count']) ? $request_data['labels_count'] : 0);
			$templateCode = trim((string) (isset($request_data['template_code']) ? $request_data['template_code'] : ''));
			$templateValues = (!empty($request_data['template_values']) && is_array($request_data['template_values']) ? $request_data['template_values'] : array());
			$langcode = trim((string) (isset($request_data['langcode']) ? $request_data['langcode'] : ''));
			$producedBatch = $this->resolveProducedBatchCodeFromRequest($request_data, (int) (isset($request_data['mo_id']) ? $request_data['mo_id'] : 0));
			$templateCode = $this->resolveLabelTemplateCode($product, $templateCode);
			$templateValues = $this->resolveLabelTemplateValues($product, $templateCode, $templateValues);
			$templateValues = $this->mergeProducedBatchIntoTemplateValues($templateValues, $producedBatch);

			$selectedFields = array();
			if (!empty($request_data['selected_fields']) && is_array($request_data['selected_fields'])) {
				$selectedFields = KreaProductsLabelService::sanitizeSelectedFields($request_data['selected_fields']);
			}
			if (empty($selectedFields) && $templateCode === '') {
				$selectedFields = array('ref', 'label', 'barcode');
			}

			$useTemplateSize = ($templateCode !== '' ? 1 : 0);
			if (isset($request_data['use_template_size'])) {
				$useTemplateSize = (!empty($request_data['use_template_size']) ? 1 : 0);
			}

			$recommendedCount = $this->computeLabelCount($productionQty, $unitsPerLabel, $labelsCount);

			$outputlangs = clone $langs;
			if ($langcode !== '') {
				$outputlangs->setDefaultLang($langcode);
			}
			$outputlangs->load('main');
			$outputlangs->load('products');
			$outputlangs->load('mrp');
			$outputlangs->load('kreaproducts@kreaproducts');

			$formatCode = trim((string) (isset($request_data['format_code']) ? $request_data['format_code'] : ''));
			if ($formatCode === '') {
				$formatCode = KreaProductsLabelService::getDefaultFormatCode(KreaProductsLabelService::getFormatOptions($this->db));
			}

			$entityId = (int) $conf->entity;
			$generated = KreaProductsLabelService::generateProductLabels(
				$this->db,
				$product,
				$entityId,
				$formatCode,
				$selectedFields,
				$recommendedCount,
				$outputlangs,
				$templateCode,
				(bool) $useTemplateSize,
				$templateValues
			);

			if (!empty($generated['error'])) {
				dol_syslog(__METHOD__ . ' generation failed: ' . $generated['error'], LOG_ERR);
				throw new RestException(500, 'Failed to generate labels PDF');
			}

			$fullPath = (!empty($generated['fullpath']) ? (string) $generated['fullpath'] : '');
			$relativeFile = (!empty($generated['relativefile']) ? (string) $generated['relativefile'] : '');

			try {
				if ($fullPath === '' || !is_readable($fullPath)) {
					throw new RestException(500, 'Generated labels PDF file is not readable');
				}

				$pdfBinary = @file_get_contents($fullPath);
				if ($pdfBinary === false || $pdfBinary === '') {
					throw new RestException(500, 'Generated labels PDF file is empty');
				}

				return array(
					'product_id' => (int) $product->id,
					'product_ref' => (string) $product->ref,
					'production_qty' => (float) $productionQty,
					'units_per_label' => (float) $unitsPerLabel,
					'labels_count' => (int) $recommendedCount,
					'template_code' => (string) $templateCode,
					'produced_batch' => (string) $producedBatch,
					'filename' => (!empty($generated['filename']) ? (string) $generated['filename'] : ('labels_' . ((int) $product->id) . '.pdf')),
					'mime_type' => 'application/pdf',
					'content_base64' => base64_encode($pdfBinary),
					'generated_at_utc' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%SZ', 'gmt'),
				);
			} finally {
				if ($relativeFile !== '') {
					KreaProductsLabelService::deleteGeneratedFile($entityId, (int) $product->id, $relativeFile);
				}
			}
		} catch (RestException $ex) {
			throw $ex;
		} catch (Throwable $ex) {
			dol_syslog(__METHOD__ . ' failed: ' . $ex->getMessage(), LOG_ERR);
			throw new RestException(500, 'Failed to generate labels PDF');
		}
	}

	/**
	 * Generate one labels TSPL payload and return command content as base64.
	 *
	 * Request body example:
	 * {
	 *   "product_id": 345,
	 *   "production_qty": 120,
	 *   "units_per_label": 1,
	 *   "labels_count": 120,
	 *   "template_code": "degema_normal",
	 *   "produced_batch": "2026031422341",
	 *   "mo_id": 341,
	 *   "template_values": {},
	 *   "tspl_options": {
	 *     "gap_mm": 3,
	 *     "direction": 1
	 *   },
	 *   "langcode": "pt_PT"
	 * }
	 *
	 * @param int   $product_id   Product id (path)
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST production/products/{product_id}/labels/tspl
	 */
	public function postProductionLabelTspl($product_id = 0, $request_data = null)
	{
		global $langs, $conf;

		try {
			$this->assertLabelReadRights();

			if (!is_array($request_data)) {
				$request_data = array();
			}

			$productIdFromPath = (int) $product_id;
			$productIdFromBody = (int) (isset($request_data['product_id']) ? $request_data['product_id'] : 0);
			if ($productIdFromPath <= 0) {
				throw new RestException(400, 'Missing product_id');
			}
			if ($productIdFromBody > 0 && $productIdFromBody !== $productIdFromPath) {
				throw new RestException(400, 'product_id in body does not match path');
			}

			$product = $this->fetchProduct($productIdFromPath);
			$this->applyProductAliasToLabel($product);
			$productionQty = price2num(isset($request_data['production_qty']) ? $request_data['production_qty'] : 1, 'MS');
			if ($productionQty <= 0) {
				$productionQty = 1;
			}

			$unitsPerLabel = price2num(isset($request_data['units_per_label']) ? $request_data['units_per_label'] : 1, 'MS');
			if ($unitsPerLabel <= 0) {
				$unitsPerLabel = 1;
			}

			$labelsCount = (int) (isset($request_data['labels_count']) ? $request_data['labels_count'] : 0);
			$templateCode = trim((string) (isset($request_data['template_code']) ? $request_data['template_code'] : ''));
			$templateValues = (!empty($request_data['template_values']) && is_array($request_data['template_values']) ? $request_data['template_values'] : array());
			$langcode = trim((string) (isset($request_data['langcode']) ? $request_data['langcode'] : ''));
			$producedBatch = $this->resolveProducedBatchCodeFromRequest($request_data, (int) (isset($request_data['mo_id']) ? $request_data['mo_id'] : 0));
			$templateCode = $this->resolveLabelTemplateCode($product, $templateCode);
			$templateValues = $this->resolveLabelTemplateValues($product, $templateCode, $templateValues);
			$templateValues = $this->mergeProducedBatchIntoTemplateValues($templateValues, $producedBatch);

			$selectedFields = array();
			if (!empty($request_data['selected_fields']) && is_array($request_data['selected_fields'])) {
				$selectedFields = KreaProductsLabelService::sanitizeSelectedFields($request_data['selected_fields']);
			}
			if (empty($selectedFields) && $templateCode === '') {
				$selectedFields = array('ref', 'label', 'barcode');
			}

			$recommendedCount = $this->computeLabelCount($productionQty, $unitsPerLabel, $labelsCount);

			$outputlangs = clone $langs;
			if ($langcode !== '') {
				$outputlangs->setDefaultLang($langcode);
			}
			$outputlangs->load('main');
			$outputlangs->load('products');
			$outputlangs->load('mrp');
			$outputlangs->load('kreaproducts@kreaproducts');

			$tsplOptions = (!empty($request_data['tspl_options']) && is_array($request_data['tspl_options']) ? $request_data['tspl_options'] : array());
			if (isset($request_data['label_width_mm']) && !isset($tsplOptions['label_width_mm'])) {
				$tsplOptions['label_width_mm'] = $request_data['label_width_mm'];
			}
			if (isset($request_data['label_height_mm']) && !isset($tsplOptions['label_height_mm'])) {
				$tsplOptions['label_height_mm'] = $request_data['label_height_mm'];
			}

			$entityId = (int) $conf->entity;
			$generated = KreaProductsLabelService::generateProductLabelsTspl(
				$this->db,
				$product,
				$entityId,
				$selectedFields,
				$recommendedCount,
				$outputlangs,
				$templateCode,
				$templateValues,
				$tsplOptions
			);

			if (!empty($generated['error'])) {
				dol_syslog(__METHOD__ . ' generation failed: ' . $generated['error'], LOG_ERR);
				throw new RestException(500, 'Failed to generate labels TSPL');
			}

			$tsplContent = (!empty($generated['content']) ? (string) $generated['content'] : '');
			if ($tsplContent === '') {
				throw new RestException(500, 'Generated labels TSPL content is empty');
			}

			return array(
				'product_id' => (int) $product->id,
				'product_ref' => (string) $product->ref,
				'production_qty' => (float) $productionQty,
				'units_per_label' => (float) $unitsPerLabel,
				'labels_count' => (int) $recommendedCount,
				'template_code' => (string) $templateCode,
				'produced_batch' => (string) $producedBatch,
				'filename' => (!empty($generated['filename']) ? (string) $generated['filename'] : ('labels_' . ((int) $product->id) . '.tspl')),
				'mime_type' => 'text/plain',
				'content_base64' => base64_encode($tsplContent),
				'generated_at_utc' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%SZ', 'gmt'),
			);
		} catch (RestException $ex) {
			throw $ex;
		} catch (Throwable $ex) {
			dol_syslog(__METHOD__ . ' failed: ' . $ex->getMessage(), LOG_ERR);
			throw new RestException(500, 'Failed to generate labels TSPL');
		}
	}

	/**
	 * Compatibility endpoint for clients calling labels TSPL generation with GET.
	 *
	 * @param int    $product_id       Product id (path)
	 * @param float  $production_qty   Production quantity
	 * @param float  $units_per_label  Units represented by one label
	 * @param int    $labels_count     Explicit labels count
	 * @param string $template_code    Optional template code
	 * @param string $produced_batch   Optional produced batch code
	 * @param string $langcode         Optional output language
	 * @return array
	 *
	 * @url GET production/products/{product_id}/labels/tspl
	 */
	public function getProductionLabelTspl(
		$product_id,
		$production_qty = 1,
		$units_per_label = 1,
		$labels_count = 0,
		$template_code = '',
		$produced_batch = '',
		$langcode = ''
	) {
		$requestData = array(
			'product_id' => (int) $product_id,
			'production_qty' => $production_qty,
			'units_per_label' => $units_per_label,
			'labels_count' => $labels_count,
			'template_code' => $template_code,
			'produced_batch' => $produced_batch,
			'langcode' => $langcode,
		);

		return $this->postProductionLabelTspl((int) $product_id, $requestData);
	}

	/**
	 * Compatibility endpoint for clients posting labels generation without the trailing /pdf path segment.
	 *
	 * @param int   $product_id   Product id (path)
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST production/products/{product_id}/labels
	 */
	public function postProductionLabelData($product_id = 0, $request_data = null)
	{
		return $this->postProductionLabelPdf($product_id, $request_data);
	}

	/**
	 * Compatibility endpoint for clients calling labels PDF generation with GET.
	 * This keeps backward compatibility when proxies/clients rewrite POST to GET.
	 *
	 * @param int    $product_id       Product id (path)
	 * @param float  $production_qty   Production quantity
	 * @param float  $units_per_label  Units represented by one label
	 * @param int    $labels_count     Explicit labels count
	 * @param string $template_code    Optional template code
	 * @param string $produced_batch   Optional produced batch code
	 * @param string $langcode         Optional output language
	 * @return array
	 *
	 * @url GET production/products/{product_id}/labels/pdf
	 */
	public function getProductionLabelPdf(
		$product_id,
		$production_qty = 1,
		$units_per_label = 1,
		$labels_count = 0,
		$template_code = '',
		$produced_batch = '',
		$langcode = ''
	) {
		$requestData = array(
			'product_id' => (int) $product_id,
			'production_qty' => $production_qty,
			'units_per_label' => $units_per_label,
			'labels_count' => $labels_count,
			'template_code' => $template_code,
			'produced_batch' => $produced_batch,
			'langcode' => $langcode,
		);

		return $this->postProductionLabelPdf((int) $product_id, $requestData);
	}

	/**
	 * Run one production operation and return label payload for the produced lot.
	 *
	 * Request body example:
	 * {
	 *   "category_id": 12,
	 *   "product_id": 345,
	 *   "qty": 120,
	 *   "warehouse_id": 1, // optional when defaults are configured
	 *   "bom_id": 0,
	 *   "inventorylabel": "Touch production",
	 *   "inventorycode": "2603123",
	 *   "produced_batch": "2603123",
	 *   "autoclose": 1,
	 *   "units_per_label": 1,
	 *   "labels_count": 120,
	 *   "template_code": "degema_normal",
	 *   "template_values": {},
	 *   "component_lots": [
	 *      {
	 *         "line_id": 101,
	 *         "bom_line_id": 101,
	 *         "component_product_id": 890,
	 *         "qty": 42.5,
	 *         "batch": "LOT-SUGAR-01"
	 *      }
	 *   ]
	 * }
	 *
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST production/run
	 */
	public function postProductionRun($request_data = null)
	{
		$this->assertMrpEnabled();
		$this->assertProductionWriteRights();
		$this->assertLabelReadRights();

		if (!is_array($request_data)) {
			throw new RestException(400, 'Invalid request body');
		}

		$categoryId = (int) (isset($request_data['category_id']) ? $request_data['category_id'] : 0);
		$productId = (int) (isset($request_data['product_id']) ? $request_data['product_id'] : (isset($request_data['fk_product']) ? $request_data['fk_product'] : 0));
		$moId = (int) (isset($request_data['mo_id']) ? $request_data['mo_id'] : 0);
		$hasQtyInput = (isset($request_data['qty']) || isset($request_data['production_qty']));
		$qty = price2num(isset($request_data['qty']) ? $request_data['qty'] : (isset($request_data['production_qty']) ? $request_data['production_qty'] : 0), 'MS');
		$requestedWarehouseId = (int) (isset($request_data['warehouse_id']) ? $request_data['warehouse_id'] : (isset($request_data['fk_warehouse']) ? $request_data['fk_warehouse'] : 0));
		$requestedBomId = (int) (isset($request_data['bom_id']) ? $request_data['bom_id'] : (isset($request_data['fk_bom']) ? $request_data['fk_bom'] : 0));
		$requestedThirdpartyId = (int) (isset($request_data['fk_soc']) ? $request_data['fk_soc'] : (isset($request_data['thirdparty_id']) ? $request_data['thirdparty_id'] : 0));
		$requestedProjectId = (int) (isset($request_data['fk_project']) ? $request_data['fk_project'] : (isset($request_data['project_id']) ? $request_data['project_id'] : 0));
		$requestedLabel = trim((string) (isset($request_data['label']) ? $request_data['label'] : ''));
		$requestedDateStartPlanned = $this->parseRequestDateTimeToTimestamp(
			(isset($request_data['date_start_planned']) ? $request_data['date_start_planned'] : (isset($request_data['date_start']) ? $request_data['date_start'] : ''))
		);
		$requestedDateEndPlanned = $this->parseRequestDateTimeToTimestamp(
			(isset($request_data['date_end_planned']) ? $request_data['date_end_planned'] : (isset($request_data['date_end']) ? $request_data['date_end'] : ''))
		);
		$autoClose = (!empty($request_data['autoclose']) ? 1 : 0);
		$unitsPerLabel = price2num(isset($request_data['units_per_label']) ? $request_data['units_per_label'] : 1, 'MS');
		$labelsCount = (int) (isset($request_data['labels_count']) ? $request_data['labels_count'] : 0);
		$templateCode = trim((string) (isset($request_data['template_code']) ? $request_data['template_code'] : ''));
		$templateValues = (!empty($request_data['template_values']) && is_array($request_data['template_values']) ? $request_data['template_values'] : array());
		$langcode = trim((string) (isset($request_data['langcode']) ? $request_data['langcode'] : ''));
		$requestedProducedBatch = trim((string) (isset($request_data['produced_batch']) ? $request_data['produced_batch'] : (isset($request_data['produced_lot']) ? $request_data['produced_lot'] : (isset($request_data['batch']) ? $request_data['batch'] : ''))));
		$componentLots = $this->normalizeComponentLotsRequest($request_data);

		if ($moId <= 0 && $productId <= 0) {
			throw new RestException(400, 'Missing product_id or mo_id');
		}
		if ($moId <= 0 && $qty <= 0) {
			throw new RestException(400, 'Production qty must be greater than 0');
		}
		$this->assertProductionThirdpartyAvailable($requestedThirdpartyId);
		$this->assertProductionProjectAvailable($requestedProjectId);
		$this->assertProductionTraceSchemaReady();

		$mo = new Mo($this->db);
		$product = null;
		$bomIdUsed = 0;
		$moWasCreated = false;
		$warehouseId = 0;
		$inventoryCode = '';
		$traceSaved = false;
		$traceError = '';
		$labelPayload = array();

		$this->db->begin();
		try {
			if ($moId > 0) {
				$this->lockMoForProduction($moId);
			if ($mo->fetch($moId) <= 0) {
				throw new RestException(404, 'MO not found');
			}
			if (!DolibarrApi::_checkAccessToResource('mrp', $mo->id, 'mrp_mo')) {
				throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
			}

			if ($productId > 0 && (int) $mo->fk_product !== $productId) {
				throw new RestException(400, 'Provided product_id does not match mo_id');
			}

			$product = $this->fetchProduct((int) $mo->fk_product);
			$bomIdUsed = (int) $mo->fk_bom;
			if ($categoryId > 0) {
				$this->assertProductInCategory((int) $product->id, $categoryId);
			}
			if (!empty($product->status_batch)) {
				throw new RestException(409, 'Batch-managed products are not supported by this API workflow yet');
			}

			if (!$hasQtyInput) {
				$qty = (float) $mo->qty;
			}

			$shouldUpdateDraftMo = false;
			if ($hasQtyInput && (int) $mo->status === Mo::STATUS_DRAFT && (float) $mo->qty !== (float) $qty) {
				$mo->oldQty = $mo->qty;
				$mo->qty = (float) $qty;
				$shouldUpdateDraftMo = true;
			} elseif ($hasQtyInput && (int) $mo->status !== Mo::STATUS_DRAFT && (float) $mo->qty !== (float) $qty) {
				throw new RestException(409, 'Provided qty does not match existing non-draft MO quantity');
			}

			if ((int) $mo->status === Mo::STATUS_DRAFT) {
				$resolvedMoLabel = $this->buildMoLabelForProduction($requestedLabel, $request_data, $product);
				if ($resolvedMoLabel !== '' && trim((string) $mo->label) !== $resolvedMoLabel) {
					$mo->label = $resolvedMoLabel;
					$shouldUpdateDraftMo = true;
				}

				$requestedStartForResolve = ($requestedDateStartPlanned > 0 ? $requestedDateStartPlanned : $requestedDateEndPlanned);
				$requestedEndForResolve = ($requestedDateEndPlanned > 0 ? $requestedDateEndPlanned : $requestedStartForResolve);
				$plannedStart = $this->resolveMoPlannedTimestamp(
					$requestedStartForResolve,
					(int) (!empty($mo->date_start_planned) ? $mo->date_start_planned : 0),
					dol_now()
				);
				$plannedEnd = $this->resolveMoPlannedTimestamp(
					$requestedEndForResolve,
					(int) (!empty($mo->date_end_planned) ? $mo->date_end_planned : 0),
					$plannedStart
				);

				if ((int) $mo->date_start_planned !== (int) $plannedStart) {
					$mo->date_start_planned = $plannedStart;
					$shouldUpdateDraftMo = true;
				}
				if ((int) $mo->date_end_planned !== (int) $plannedEnd) {
					$mo->date_end_planned = $plannedEnd;
					$shouldUpdateDraftMo = true;
				}

				if ($requestedThirdpartyId > 0 && (int) $mo->fk_soc !== $requestedThirdpartyId) {
					$mo->fk_soc = $requestedThirdpartyId;
					$shouldUpdateDraftMo = true;
				}
				if ($requestedProjectId > 0 && (int) $mo->fk_project !== $requestedProjectId) {
					$mo->fk_project = $requestedProjectId;
					$shouldUpdateDraftMo = true;
				}

				if ($shouldUpdateDraftMo && $mo->update(DolibarrApiAccess::$user) <= 0) {
					$this->failInternalRequest('Unable to update the manufacturing order', $mo->error, 500);
				}
				if ($shouldUpdateDraftMo) {
					$mo->fetch($mo->id);
				}
			}

			$warehouseId = $this->resolveWarehouseIdForProduction($requestedWarehouseId, $product, $mo);
			} else {
			$product = $this->fetchProduct($productId);
			if ($categoryId > 0) {
				$this->assertProductInCategory((int) $product->id, $categoryId);
			}
			if (!empty($product->status_batch)) {
				throw new RestException(409, 'Batch-managed products are not supported by this API workflow yet');
			}
			$bomIdUsed = $this->resolveBomForProduct($product, $requestedBomId);
			$warehouseId = $this->resolveWarehouseIdForProduction($requestedWarehouseId, $product, null);

			$mo->ref = '(PROV)';
			$mo->fk_product = $product->id;
			$mo->qty = (float) $qty;
			$mo->fk_warehouse = $warehouseId;
			$mo->fk_bom = $bomIdUsed;
			$mo->label = $this->buildMoLabelForProduction($requestedLabel, $request_data, $product);
			$requestedStartForResolve = ($requestedDateStartPlanned > 0 ? $requestedDateStartPlanned : $requestedDateEndPlanned);
			$requestedEndForResolve = ($requestedDateEndPlanned > 0 ? $requestedDateEndPlanned : $requestedStartForResolve);
			$plannedStart = $this->resolveMoPlannedTimestamp($requestedStartForResolve, 0, dol_now());
			$plannedEnd = $this->resolveMoPlannedTimestamp($requestedEndForResolve, 0, $plannedStart);
			$mo->date_start_planned = $plannedStart;
			$mo->date_end_planned = $plannedEnd;
			if ($requestedThirdpartyId > 0) {
				$mo->fk_soc = $requestedThirdpartyId;
			}
			if ($requestedProjectId > 0) {
				$mo->fk_project = $requestedProjectId;
			}

			$newId = $mo->create(DolibarrApiAccess::$user);
			if ($newId <= 0) {
				$this->failInternalRequest('Unable to create the manufacturing order', $mo->error, 500);
			}
			$moWasCreated = true;
			$mo->fetch($newId);
			}

		if ($warehouseId <= 0) {
			throw new RestException(400, 'Missing warehouse_id and no default warehouse is configured for current entity/product');
		}

		if ((int) $mo->status === Mo::STATUS_DRAFT) {
			$validateResult = $mo->validate(DolibarrApiAccess::$user);
			if ($validateResult <= 0) {
				$this->failInternalRequest('Unable to validate the manufacturing order', $mo->error, 500);
			}
			$mo->fetch($mo->id);
		}

		if ((int) $mo->status !== Mo::STATUS_VALIDATED) {
			throw new RestException(409, 'Only a validated, unprocessed MO can be posted by this endpoint');
		}

		$mo->fetchLines();
		$this->disableStockChangeForNonStockMoLines($mo);
		$mo->fetchLines();
		$componentLotMaps = $this->indexComponentLotsByMoLine($componentLots);
		$this->assertComponentLotsMatchMoLines($componentLots, $mo->lines);
		$batchManagedSubproducts = $this->findBatchManagedAssociatedSubproductsForMoLines($mo->lines);
		if (!empty($batchManagedSubproducts)) {
			$cleanupNote = '';
			if ($moWasCreated) {
				$cleanupNote = $this->cleanupAutoCreatedMoIfUnprocessed($mo);
			}

			$message = $this->buildAssociatedBatchConflictMessage($batchManagedSubproducts);
			if ($cleanupNote !== '') {
				$message .= ' ' . $cleanupNote;
			}
			throw new RestException(409, $message);
		}

		$inventoryLabel = trim((string) (!empty($request_data['inventorylabel']) ? $request_data['inventorylabel'] : 'Touch production ' . (!empty($product->ref) ? $product->ref : $product->id)));
		$inventoryCode = $this->normalizeInventoryCode(isset($request_data['inventorycode']) ? $request_data['inventorycode'] : '', (int) $mo->id);
		if ($requestedProducedBatch !== '' && $requestedProducedBatch !== $inventoryCode) {
			dol_syslog(__METHOD__ . ' produced_batch payload overridden by inventorycode policy. Requested=' . $requestedProducedBatch . ', Applied=' . $inventoryCode, LOG_DEBUG);
		}
		$producedBatch = $inventoryCode;

		$arrayToConsume = $this->buildMoProductionPayloadByRole($mo->lines, 'toconsume', $warehouseId, $componentLotMaps, '');
		$arrayToProduce = $this->buildMoProductionPayloadByRole($mo->lines, 'toproduce', $warehouseId, array(), $producedBatch);
		if (empty($arrayToProduce)) {
			throw new RestException(409, 'MO has no line to produce');
		}

			$mosApi = new Mos();
			$this->lockMoForProduction((int) $mo->id);
			if ($mo->fetch((int) $mo->id) <= 0 || (int) $mo->status !== Mo::STATUS_VALIDATED) {
				throw new RestException(409, 'MO was already processed or its status changed');
			}
			if ($this->hasMoExecutionMovements((int) $mo->id)) {
				throw new RestException(409, 'MO already has production stock movements');
			}
			$mosApi->produceAndConsume(
				$mo->id,
				array(
					'inventorylabel' => $inventoryLabel,
					'inventorycode' => $inventoryCode,
					'autoclose' => $autoClose,
					'arraytoconsume' => $arrayToConsume,
					'arraytoproduce' => $arrayToProduce,
					'caller' => 'kreaproducts',
				)
			);
			$mo->fetch($mo->id);
			$this->saveProductionBatchTrace($mo, (float) $qty, $inventoryCode, $componentLotMaps);
			$traceSaved = true;
			$this->applyProductAliasToLabel($product);
			$templateCode = $this->resolveLabelTemplateCode($product, $templateCode);
			$templateValues = $this->resolveLabelTemplateValues($product, $templateCode, $templateValues);
			$templateValues = $this->mergeProducedBatchIntoTemplateValues($templateValues, $producedBatch);
			$labelPayload = $this->buildLabelPayload($product, $qty, $unitsPerLabel, $labelsCount, $templateCode, $templateValues, $langcode);
			if (!$this->db->commit()) {
				throw new RuntimeException('Unable to commit production stock and trace transaction');
			}
		} catch (RestException $ex) {
			// Core Mos::produceAndConsume may throw before rolling back its explicit transaction.
			// Ensure we rollback current connection before any cleanup checks.
			$this->rollbackOpenTransactions();

			$httpCode = (int) $ex->getCode();
			if ($httpCode < 400 || $httpCode > 599) {
				$httpCode = 500;
			}

			$technicalMessage = trim((string) $ex->getMessage());
			$errorMessage = $technicalMessage;
			if ($errorMessage === '') {
				$errorMessage = 'Failed to post production stock movements for inventorycode ' . $inventoryCode . '. Check Dolibarr logs for details.';
			}
			$exposeTechnicalMessage = ($httpCode < 500);

			if ($httpCode >= 500) {
				$batchManagedSubproducts = $this->findBatchManagedAssociatedSubproductsForMoLines(
					(isset($mo->lines) && is_array($mo->lines)) ? $mo->lines : array()
				);
				if (!empty($batchManagedSubproducts)) {
					$httpCode = 409;
					$errorMessage = $this->buildAssociatedBatchConflictMessage($batchManagedSubproducts);
					$exposeTechnicalMessage = true;
				}
			}

			$cleanupNote = '';
			if ($moWasCreated) {
				$cleanupNote = $this->cleanupAutoCreatedMoIfUnprocessed($mo);
			}
			if ($cleanupNote !== '') {
				if ($httpCode >= 500) {
					$httpCode = 409;
				}
			}

			dol_syslog(__METHOD__ . ' produceAndConsume failed for MO ' . ((int) $mo->id) . ': ' . $technicalMessage, LOG_ERR);
			if (!$exposeTechnicalMessage) {
				$errorMessage = 'Production execution failed. Check Dolibarr logs for details.';
			}
			if ($cleanupNote !== '') {
				$errorMessage .= ' ' . $cleanupNote;
			}
			throw new RestException($httpCode, $errorMessage);
		} catch (Throwable $ex) {
			// Ensure pending DB transaction from produceAndConsume is rolled back.
			$this->rollbackOpenTransactions();

			$technicalMessage = trim((string) $ex->getMessage());
			$errorMessage = 'Unexpected production execution error. Check Dolibarr logs for details.';

			$batchManagedSubproducts = $this->findBatchManagedAssociatedSubproductsForMoLines(
				(isset($mo->lines) && is_array($mo->lines)) ? $mo->lines : array()
			);
			if (!empty($batchManagedSubproducts)) {
				$errorMessage = $this->buildAssociatedBatchConflictMessage($batchManagedSubproducts);
				$httpCode = 409;
			} else {
				$httpCode = 500;
			}

			$cleanupNote = '';
			if ($moWasCreated) {
				$cleanupNote = $this->cleanupAutoCreatedMoIfUnprocessed($mo);
			}
			if ($cleanupNote !== '') {
				$errorMessage .= ' ' . $cleanupNote;
			}

			dol_syslog(__METHOD__ . ' unexpected produceAndConsume error for MO ' . ((int) $mo->id) . ': ' . $technicalMessage, LOG_ERR);
			throw new RestException(($cleanupNote !== '' ? 409 : $httpCode), $errorMessage);
		}

		return array(
			'category_id' => $categoryId,
			'product_id' => (int) $product->id,
			'product_ref' => (string) $product->ref,
			'product_label' => (string) $product->label,
			'mo_created' => $moWasCreated,
			'mo_id' => (int) $mo->id,
			'mo_ref' => (string) $mo->ref,
			'mo_status' => (int) $mo->status,
			'bom_id_used' => (int) $bomIdUsed,
			'warehouse_id' => (int) $warehouseId,
			'production_qty' => (float) $qty,
			'inventorycode' => (string) $inventoryCode,
			'produced_batch' => (string) $producedBatch,
			'trace_saved' => $traceSaved,
			'trace_error' => $traceError,
			'stock_updated' => true,
			'label_payload' => $labelPayload,
		);
	}

	/**
	 * Validate a supplier invoice through Dolibarr's complete business lifecycle.
	 *
	 * This endpoint mirrors the supplier-invoice card validation contract: it
	 * uses the current entity's default warehouse when supplier-bill stock
	 * posting applies and no warehouse is supplied. It always calls
	 * FactureFournisseur::validate() with trigger execution enabled. Trigger
	 * suppression is intentionally not supported.
	 *
	 * Request example:
	 * {
	 *   "warehouse_id": 12
	 * }
	 *
	 * @param int   $id Supplier invoice ID
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST supplier-invoices/{id}/validate
	 */
	public function postSupplierInvoiceValidate($id, $request_data = null)
	{
		$invoiceId = (int) $id;
		if ($invoiceId <= 0) {
			throw new RestException(400, 'Invalid supplier invoice id');
		}
		$this->assertSupplierInvoiceValidationRights();

		if (!DolibarrApi::_checkAccessToResource('fournisseur', $invoiceId, 'facture_fourn', 'facture')) {
			throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}
		if ($request_data !== null && !is_array($request_data)) {
			throw new RestException(400, 'Invalid request body');
		}
		$request_data = (is_array($request_data) ? $request_data : array());

		if (array_key_exists('notrigger', $request_data)) {
			throw new RestException(400, 'Trigger suppression is not supported by this endpoint');
		}

		$requestedWarehouseId = (int) (isset($request_data['warehouse_id']) ? $request_data['warehouse_id'] : (isset($request_data['idwarehouse']) ? $request_data['idwarehouse'] : 0));
		if (isset($request_data['warehouse_id']) && isset($request_data['idwarehouse']) && (int) $request_data['warehouse_id'] !== (int) $request_data['idwarehouse']) {
			throw new RestException(400, 'warehouse_id and idwarehouse must identify the same warehouse');
		}

		$invoice = new FactureFournisseur($this->db);
		$fetchResult = $invoice->fetch($invoiceId);
		if ($fetchResult === 0) {
			throw new RestException(404, 'Supplier invoice not found');
		}
		if ($fetchResult < 0) {
			$this->failInternalRequest('Unable to load supplier invoice', $invoice->error);
		}
		if ((int) $invoice->status !== FactureFournisseur::STATUS_DRAFT) {
			throw new RestException(409, 'Supplier invoice is not in draft status');
		}

		$requiresStockWarehouse = $this->supplierInvoiceRequiresStockWarehouse($invoice);
		$warehouseId = $this->resolveSupplierInvoiceWarehouseId($requestedWarehouseId, $requiresStockWarehouse);
		$warehouseSource = ($warehouseId <= 0 ? 'not_required' : ($requestedWarehouseId > 0 ? 'request' : 'entity_default'));

		if (!$this->db->begin()) {
			$this->failInternalRequest('Unable to start supplier invoice validation transaction', $this->db->lasterror(), 500);
		}
		try {
			$this->lockSupplierInvoiceForValidation($invoiceId);
			$fetchResult = $invoice->fetch($invoiceId);
			if ($fetchResult === 0) {
				throw new RestException(404, 'Supplier invoice not found');
			}
			if ($fetchResult < 0) {
				$this->failInternalRequest('Unable to reload supplier invoice for validation', $invoice->error);
			}
			if (!DolibarrApi::_checkAccessToResource('fournisseur', $invoiceId, 'facture_fourn', 'facture')) {
				throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
			}
			if ((int) $invoice->status !== FactureFournisseur::STATUS_DRAFT) {
				throw new RestException(409, 'Supplier invoice was already validated or changed');
			}

			$requiresStockWarehouse = $this->supplierInvoiceRequiresStockWarehouse($invoice);
			$warehouseId = $this->resolveSupplierInvoiceWarehouseId($requestedWarehouseId, $requiresStockWarehouse);
			$warehouseSource = ($warehouseId <= 0 ? 'not_required' : ($requestedWarehouseId > 0 ? 'request' : 'entity_default'));

			// The literal 0 is mandatory: all core and module business triggers must run.
			$validateResult = $invoice->validate(DolibarrApiAccess::$user, '', $warehouseId, 0);
			if ($validateResult === 0) {
				throw new RestException(409, 'Supplier invoice was already validated or changed');
			}
			if ($validateResult < 0) {
				$technicalError = trim((string) $invoice->error);
				if (!empty($invoice->errors)) {
					$technicalError .= ' ' . implode(' | ', array_map('strval', $invoice->errors));
				}
				$this->failInternalRequest('Unable to validate supplier invoice', $technicalError, 500);
			}

			if (!$this->db->commit()) {
				$this->failInternalRequest('Unable to commit supplier invoice validation', $this->db->lasterror(), 500);
			}
		} catch (RestException $exception) {
			$this->rollbackOpenTransactions();
			throw $exception;
		} catch (Throwable $exception) {
			$this->rollbackOpenTransactions();
			$this->failInternalRequest('Unable to validate supplier invoice', $exception->getMessage(), 500);
		}

		return array(
			'id' => (int) $invoice->id,
			'ref' => (string) $invoice->ref,
			'status' => (int) $invoice->status,
			'warehouse_id' => $warehouseId,
			'warehouse_source' => $warehouseSource,
			'stock_validation_path' => ($requiresStockWarehouse ? 1 : 0),
			'triggers_suppressed' => 0,
		);
	}

	/**
	 * Validate every draft supplier invoice belonging to one supplier.
	 *
	 * Each invoice uses the same trigger-safe lifecycle as the single-invoice
	 * endpoint. Validations are isolated so one invalid invoice is reported as
	 * failed without hiding or rolling back invoices already validated safely.
	 *
	 * @param int   $supplier_id Supplier third-party ID
	 * @param array $request_data Request body
	 * @return array
	 *
	 * @url POST suppliers/{supplier_id}/invoices/validate
	 */
	public function postSupplierInvoicesValidateBySupplier($supplier_id, $request_data = null)
	{
		$supplierId = (int) $supplier_id;
		if ($supplierId <= 0) {
			throw new RestException(400, 'Invalid supplier id');
		}
		$this->assertSupplierInvoiceValidationRights();

		if ($request_data !== null && !is_array($request_data)) {
			throw new RestException(400, 'Invalid request body');
		}
		$request_data = (is_array($request_data) ? $request_data : array());
		if (array_key_exists('notrigger', $request_data)) {
			throw new RestException(400, 'Trigger suppression is not supported by this endpoint');
		}
		if (isset($request_data['warehouse_id']) && isset($request_data['idwarehouse']) && (int) $request_data['warehouse_id'] !== (int) $request_data['idwarehouse']) {
			throw new RestException(400, 'warehouse_id and idwarehouse must identify the same warehouse');
		}

		$supplier = new Fournisseur($this->db);
		$fetchResult = $supplier->fetch($supplierId);
		if ($fetchResult === 0 || empty($supplier->fournisseur)) {
			throw new RestException(404, 'Supplier not found');
		}
		if ($fetchResult < 0) {
			$this->failInternalRequest('Unable to load supplier', $supplier->error);
		}
		if (!DolibarrApi::_checkAccessToResource('societe', $supplierId, 'societe')) {
			throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}

		$invoiceIds = $this->loadDraftSupplierInvoiceIds($supplierId);
		$results = array();
		$validatedCount = 0;
		$failedCount = 0;

		foreach ($invoiceIds as $invoiceId) {
			try {
				$validatedInvoice = $this->postSupplierInvoiceValidate($invoiceId, $request_data);
				$validatedInvoice['result'] = 'validated';
				$results[] = $validatedInvoice;
				$validatedCount++;
			} catch (RestException $exception) {
				$httpCode = (int) $exception->getCode();
				if ($httpCode < 400 || $httpCode > 599) {
					$httpCode = 500;
				}
				$results[] = array(
					'id' => (int) $invoiceId,
					'result' => 'failed',
					'http_code' => $httpCode,
					'message' => (string) $exception->getMessage(),
				);
				$failedCount++;
			} catch (Throwable $exception) {
				dol_syslog(__METHOD__ . ' invoice=' . ((int) $invoiceId) . ' ' . $exception->getMessage(), LOG_ERR);
				$results[] = array(
					'id' => (int) $invoiceId,
					'result' => 'failed',
					'http_code' => 500,
					'message' => 'Unable to validate supplier invoice',
				);
				$failedCount++;
			}
		}

		return array(
			'supplier_id' => $supplierId,
			'draft_invoices_found' => count($invoiceIds),
			'validated_count' => $validatedCount,
			'failed_count' => $failedCount,
			'invoices' => $results,
		);
	}

	/**
	 * List supplier purchase-price references for product matching integrations.
	 *
	 * @param int    $export    Compatibility flag accepted by external cache jobs
	 * @param int    $limit     Maximum rows to return
	 * @param int    $fk_soc    Optional supplier id filter
	 * @param string $ref_fourn Optional supplier reference filter
	 * @param int    $exact     Use exact supplier reference match when set
	 * @return array
	 *
	 * @url GET purchase_prices
	 */
	public function getPurchasePrices($export = 0, $limit = 100000, $fk_soc = 0, $ref_fourn = '', $exact = 0)
	{
		$this->assertProductReadRights();

		$limit = (int) $limit;
		if ($limit <= 0) {
			$limit = 100000;
		}
		$limit = min($limit, 100000);

		$productEntitySql = $this->entityListToSql($this->getEntityIdList('product', false));
		$thirdpartyEntitySql = $this->entityListToSql($this->getEntityIdList('societe', false));

		$sql = "SELECT";
		$sql .= " fp.rowid AS supplier_price_id,";
		$sql .= " fp.fk_product,";
		$sql .= " fp.fk_soc,";
		$sql .= " fp.ref_fourn,";
		$sql .= " fp.desc_fourn,";
		$sql .= " fp.quantity,";
		$sql .= " fp.price,";
		$sql .= " fp.unitprice,";
		$sql .= " fp.tva_tx,";
		$sql .= " p.ref AS product_ref,";
		$sql .= " p.label AS product_label,";
		$sql .= " p.entity,";
		$sql .= " s.nom AS supplier_name,";
		$sql .= " s.tva_intra AS supplier_tva_intra";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product AS p";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price AS fp ON fp.fk_product = p.rowid";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON s.rowid = fp.fk_soc";
		$sql .= " WHERE p.entity IN (" . $productEntitySql . ")";
		$sql .= " AND s.entity IN (" . $thirdpartyEntitySql . ")";
		$sql .= " AND fp.ref_fourn IS NOT NULL";
		$sql .= " AND TRIM(fp.ref_fourn) <> ''";
		$sql .= " AND fp.ref_fourn <> '-'";
		if ((int) $fk_soc > 0) {
			$sql .= " AND fp.fk_soc = " . ((int) $fk_soc);
		}
		$refFourn = trim((string) $ref_fourn);
		if ($refFourn !== '') {
			if ((int) $exact > 0) {
				$sql .= " AND fp.ref_fourn = '" . $this->db->escape($refFourn) . "'";
			} else {
				$sql .= " AND fp.ref_fourn LIKE '%" . $this->db->escape($refFourn) . "%'";
			}
		}
		$sql .= " ORDER BY p.ref ASC, fp.ref_fourn ASC, fp.rowid ASC";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load supplier purchase prices', $this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$supplierTvaIntra = (string) $obj->supplier_tva_intra;
			$rows[] = array(
				'supplier_price_id' => (int) $obj->supplier_price_id,
				'fk_product' => (int) $obj->fk_product,
				'product_id' => (int) $obj->fk_product,
				'fk_soc' => (int) $obj->fk_soc,
				'supplier_id' => (int) $obj->fk_soc,
				'supplier_vat' => $this->normalizeSupplierVat($supplierTvaIntra),
				'supplier_tva_intra' => $supplierTvaIntra,
				'ref_fourn' => (string) $obj->ref_fourn,
				'supplier_ref' => (string) $obj->ref_fourn,
				'desc_fourn' => (string) $obj->desc_fourn,
				'supplier_description' => (string) $obj->desc_fourn,
				'quantity' => (float) $obj->quantity,
				'price' => (float) $obj->price,
				'unitprice' => (float) $obj->unitprice,
				'tva_tx' => (float) $obj->tva_tx,
				'product_ref' => (string) $obj->product_ref,
				'product_label' => (string) $obj->product_label,
				'entity' => (int) $obj->entity,
				'supplier_name' => (string) $obj->supplier_name,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Normalize a Dolibarr thirdparty VAT value for API consumers that match by NIF.
	 *
	 * @param string $tvaIntra Thirdparty VAT value
	 * @return string
	 */
	private function normalizeSupplierVat($tvaIntra)
	{
		$vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $tvaIntra));
		return preg_replace('/^[A-Z]+/', '', $vat);
	}

	/**
	 * Build MO label for production execution payload.
	 *
	 * @param string $requestedLabel
	 * @param array  $requestData
	 * @param object $product
	 * @return string
	 */
	protected function buildMoLabelForProduction($requestedLabel, $requestData, $product)
	{
		$label = trim((string) $requestedLabel);
		if ($label === '') {
			$label = trim((string) (isset($requestData['inventorylabel']) ? $requestData['inventorylabel'] : ''));
		}
		if ($label === '') {
			$productRef = (!empty($product->ref) ? (string) $product->ref : (string) $product->id);
			$label = 'Touch production ' . $productRef;
		}

		$label = trim((string) preg_replace('/\s+/', ' ', $label));
		if (function_exists('mb_substr')) {
			return (string) mb_substr($label, 0, 255);
		}

		return (string) substr($label, 0, 255);
	}

	/**
	 * Resolve planned date timestamp with fallback order.
	 *
	 * @param int $requestedTs
	 * @param int $existingTs
	 * @param int $fallbackTs
	 * @return int
	 */
	protected function resolveMoPlannedTimestamp($requestedTs, $existingTs, $fallbackTs)
	{
		$requestedTs = (int) $requestedTs;
		if ($requestedTs > 0) {
			return $requestedTs;
		}

		$existingTs = (int) $existingTs;
		if ($existingTs > 0) {
			return $existingTs;
		}

		$fallbackTs = (int) $fallbackTs;
		if ($fallbackTs > 0) {
			return $fallbackTs;
		}

		return dol_now();
	}

	/**
	 * Parse request date/datetime input into UNIX timestamp.
	 *
	 * @param mixed $rawDateTime
	 * @return int
	 */
	protected function parseRequestDateTimeToTimestamp($rawDateTime)
	{
		if ($rawDateTime === null) {
			return 0;
		}

		if (is_numeric($rawDateTime)) {
			$value = (int) $rawDateTime;
			// Accept milliseconds timestamps from external clients.
			if ($value > 20000000000) {
				$value = (int) floor($value / 1000);
			}
			return ($value > 0 ? $value : 0);
		}

		$raw = trim((string) $rawDateTime);
		if ($raw === '') {
			return 0;
		}

		$parsed = (int) dol_stringtotime($raw);
		return ($parsed > 0 ? $parsed : 0);
	}

	/**
	 * Resolve produced batch code from label request payload.
	 *
	 * Preferred sources:
	 * 1) produced_batch / produced_lot / batch
	 * 2) inventorycode (normalized to YYMM + fk_mo policy when possible)
	 *
	 * @param array $requestData Request payload
	 * @param int   $moId        Optional MO id for normalization
	 * @return string
	 */
	protected function resolveProducedBatchCodeFromRequest($requestData, $moId = 0)
	{
		if (!is_array($requestData)) {
			return '';
		}

		$explicitProducedBatch = trim((string) (
			isset($requestData['produced_batch']) ? $requestData['produced_batch']
			: (isset($requestData['produced_lot']) ? $requestData['produced_lot']
			: (isset($requestData['batch']) ? $requestData['batch'] : ''))
		));
		if ($explicitProducedBatch !== '') {
			return substr($explicitProducedBatch, 0, 128);
		}

		$rawInventoryCode = trim((string) (
			isset($requestData['inventorycode']) ? $requestData['inventorycode']
			: (isset($requestData['inventory_code']) ? $requestData['inventory_code'] : '')
		));
		if ($rawInventoryCode === '') {
			return '';
		}

		$moId = (int) $moId;
		$digits = preg_replace('/[^0-9]/', '', $rawInventoryCode);
		if (is_string($digits) && strlen($digits) > 10) {
			$candidateHourCode = substr($digits, 0, 10);
			if ($this->isValidInventoryHourCode($candidateHourCode)) {
				if ($moId > 0) {
					return substr($candidateHourCode . $moId, 0, 128);
				}
				// Keep explicit suffix only for legacy numeric payloads already in YYYYMMDDHH + fk_mo format.
				if (preg_match('/^[0-9]+$/', $rawInventoryCode) === 1) {
					return substr($digits, 0, 128);
				}
				return $candidateHourCode;
			}
		}

		$normalized = $this->normalizeInventoryCode($rawInventoryCode, $moId);
		return substr($normalized, 0, 128);
	}

	/**
	 * Inject produced batch into template values for label rendering.
	 *
	 * @param array  $templateValues Existing template values
	 * @param string $producedBatch  Produced batch code
	 * @return array
	 */
	protected function mergeProducedBatchIntoTemplateValues($templateValues, $producedBatch)
	{
		$values = (is_array($templateValues) ? $templateValues : array());
		$producedBatch = trim((string) $producedBatch);
		if ($producedBatch === '') {
			return $values;
		}

		// Force lot number to the canonical produced batch code.
		$values['batch.lot_number'] = $producedBatch;
		return $values;
	}

	/**
	 * Normalize inventory code to YYMM + fk_mo format.
	 *
	 * Production lot generation is intentionally based on the current accounting
	 * month plus MO id so stock movements, trace rows, and labels share one
	 * compact canonical lot value.
	 *
	 * @param string $rawCode
	 * @param int    $moId
	 * @return string
	 */
	protected function normalizeInventoryCode($rawCode, $moId = 0)
	{
		$monthCode = '';
		$rawCode = trim((string) $rawCode);
		if ($rawCode !== '') {
			$digits = preg_replace('/[^0-9]/', '', $rawCode);
			if (is_string($digits) && strlen($digits) >= 4) {
				$candidate = substr($digits, 0, 4);
				if ($this->isValidInventoryMonthCode($candidate)) {
					$monthCode = $candidate;
				}
			}
		}

		if ($monthCode === '') {
			$monthCode = substr(dol_print_date(dol_now(), '%Y%m'), 2, 4);
		}

		$moId = (int) $moId;
		if ($moId > 0) {
			return $monthCode . $moId;
		}

		return $monthCode;
	}

	/**
	 * Validate inventory month code format YYMM.
	 *
	 * @param string $inventoryCode
	 * @return bool
	 */
	protected function isValidInventoryMonthCode($inventoryCode)
	{
		$inventoryCode = trim((string) $inventoryCode);
		if (!preg_match('/^[0-9]{4}$/', $inventoryCode)) {
			return false;
		}

		$month = (int) substr($inventoryCode, 2, 2);
		return ($month >= 1 && $month <= 12);
	}

	/**
	 * Validate legacy inventory code format YYYYMMDDHH.
	 *
	 * @param string $inventoryCode
	 * @return bool
	 */
	protected function isValidInventoryHourCode($inventoryCode)
	{
		$inventoryCode = trim((string) $inventoryCode);
		if (!preg_match('/^[0-9]{10}$/', $inventoryCode)) {
			return false;
		}

		$year = (int) substr($inventoryCode, 0, 4);
		$month = (int) substr($inventoryCode, 4, 2);
		$day = (int) substr($inventoryCode, 6, 2);
		$hour = (int) substr($inventoryCode, 8, 2);
		if ($hour < 0 || $hour > 23) {
			return false;
		}

		return checkdate($month, $day, $year);
	}

	/**
	 * Assert read rights for product browsing endpoints.
	 *
	 * @return void
	 */
	protected function assertProductReadRights()
	{
		if (!DolibarrApiAccess::$user->hasRight('produit', 'lire')) {
			throw new RestException(403, 'Missing product read right');
		}
	}

	/**
	 * Assert the same supplier-invoice validation right used by the web card.
	 *
	 * @return void
	 */
	protected function assertSupplierInvoiceValidationRights()
	{
		$canCreate = (
			DolibarrApiAccess::$user->hasRight('fournisseur', 'facture', 'creer')
			|| DolibarrApiAccess::$user->hasRight('supplier_invoice', 'creer')
		);
		$canValidate = (
			(!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $canCreate)
			|| (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && DolibarrApiAccess::$user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate'))
		);
		if (!$canValidate) {
			throw new RestException(403, 'Missing supplier invoice validation right');
		}
	}

	/**
	 * Return whether the native supplier-invoice validation path needs a warehouse.
	 *
	 * @param object $invoice Supplier invoice
	 * @return bool
	 */
	protected function supplierInvoiceRequiresStockWarehouse($invoice)
	{
		if (!isModEnabled('stock') || !getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL')) {
			return false;
		}

		$lineType = (getDolGlobalString('STOCK_SUPPORTS_SERVICES') ? 1 : 2);
		return ($invoice->hasProductsOrServices($lineType) > 0);
	}

	/**
	 * Resolve the supplier-invoice warehouse from the request or entity default.
	 *
	 * @param int  $requestedWarehouseId Requested warehouse ID
	 * @param bool $required Whether stock posting requires a warehouse
	 * @return int
	 */
	protected function resolveSupplierInvoiceWarehouseId($requestedWarehouseId, $required)
	{
		$warehouseId = (int) $requestedWarehouseId;
		if ($warehouseId > 0) {
			$this->assertWarehouseAvailableForSupplierInvoice($warehouseId);
			return $warehouseId;
		}
		if (!$required) {
			return 0;
		}

		$warehouseId = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
		if ($warehouseId <= 0) {
			throw new RestException(400, 'No warehouse was supplied and no default warehouse is configured for the current entity');
		}
		$this->assertWarehouseAvailableForSupplierInvoice($warehouseId);
		return $warehouseId;
	}

	/**
	 * Lock one entity-scoped supplier invoice before validation.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @return void
	 */
	protected function lockSupplierInvoiceForValidation($invoiceId)
	{
		$sql = 'SELECT f.rowid FROM ' . MAIN_DB_PREFIX . 'facture_fourn AS f';
		$sql .= ' WHERE f.rowid = ' . ((int) $invoiceId);
		$sql .= ' AND f.entity IN (' . getEntity('supplier_invoice') . ')';
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to lock supplier invoice for validation', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			throw new RestException(404, 'Supplier invoice not found');
		}
	}

	/**
	 * Load draft supplier invoice IDs in the active supplier-invoice scope.
	 *
	 * @param int $supplierId Supplier third-party ID
	 * @return int[]
	 */
	protected function loadDraftSupplierInvoiceIds($supplierId)
	{
		$sql = 'SELECT f.rowid FROM ' . MAIN_DB_PREFIX . 'facture_fourn AS f';
		$sql .= ' WHERE f.fk_soc = ' . ((int) $supplierId);
		$sql .= ' AND f.fk_statut = ' . FactureFournisseur::STATUS_DRAFT;
		$sql .= ' AND f.entity IN (' . getEntity('supplier_invoice') . ')';
		$sql .= ' ORDER BY f.datef ASC, f.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load draft supplier invoices', $this->db->lasterror());
		}

		$invoiceIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$invoiceIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);
		return $invoiceIds;
	}

	/**
	 * Log an internal failure without exposing database or object details to REST clients.
	 *
	 * @param string $publicMessage Public-safe response message
	 * @param string $technicalMessage Technical detail for Dolibarr logs
	 * @param int $httpCode HTTP status code
	 * @return void
	 * @throws RestException Always
	 */
	protected function failInternalRequest($publicMessage, $technicalMessage, $httpCode = 503)
	{
		dol_syslog(__METHOD__ . ' ' . trim((string) $technicalMessage), LOG_ERR);
		throw new RestException((int) $httpCode, (string) $publicMessage);
	}

	/**
	 * Assert MRP module is enabled.
	 *
	 * @return void
	 */
	protected function assertMrpEnabled()
	{
		if (!isModEnabled('mrp')) {
			throw new RestException(503, 'MRP module is not enabled');
		}
	}

	/**
	 * Assert read rights for production browsing endpoints.
	 *
	 * @return void
	 */
	protected function assertProductionReadRights()
	{
		if (!DolibarrApiAccess::$user->hasRight('categorie', 'lire')) {
			throw new RestException(403, 'Missing category read right');
		}
		if (!DolibarrApiAccess::$user->hasRight('produit', 'lire')) {
			throw new RestException(403, 'Missing product read right');
		}
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403, 'Missing MRP read right');
		}
	}

	/**
	 * Assert write rights for production execution endpoint.
	 *
	 * @return void
	 */
	protected function assertProductionWriteRights()
	{
		$this->assertProductionReadRights();
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'write')) {
			throw new RestException(403, 'Missing MRP write right');
		}
	}

	/**
	 * Assert read rights for label payload.
	 *
	 * @return void
	 */
	protected function assertLabelReadRights()
	{
		if (!DolibarrApiAccess::$user->hasRight('produit', 'lire')) {
			throw new RestException(403, 'Missing product read right');
		}

		$hasModuleLabelRight = (
			DolibarrApiAccess::$user->admin
			|| DolibarrApiAccess::$user->hasRight('kreaproducts', 'labels', 'read')
		);
		if (!$hasModuleLabelRight) {
			throw new RestException(403, 'Missing KreaProducts labels read right');
		}
	}

	/**
	 * Fetch product and enforce API access.
	 *
	 * @param int $productId Product id
	 * @return object
	 */
	protected function fetchProduct($productId)
	{
		$product = new Product($this->db);
		$result = $product->fetch((int) $productId);
		if ($result <= 0) {
			throw new RestException(404, 'Product not found');
		}
		if (!DolibarrApi::_checkAccessToResource('product', $product->id)) {
			throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}

		return $product;
	}

	/**
	 * Resolve warehouse id for production run.
	 *
	 * Resolution order:
	 * 1) request warehouse_id / fk_warehouse
	 * 2) existing MO warehouse
	 * 3) product default warehouse
	 * 4) entity default warehouse (MAIN_DEFAULT_WAREHOUSE)
	 *
	 * @param int $requestedWarehouseId Requested warehouse id from payload
	 * @param object|null $product Product object
	 * @param object|null $mo Manufacturing order object
	 * @return int
	 */
	protected function resolveWarehouseIdForProduction($requestedWarehouseId, $product = null, $mo = null)
	{
		global $conf;

		$warehouseId = (int) $requestedWarehouseId;
		if ($warehouseId > 0) {
			$this->assertWarehouseAvailableForProduction($warehouseId);
			return $warehouseId;
		}

		if (is_object($mo) && !empty($mo->fk_warehouse)) {
			$warehouseId = (int) $mo->fk_warehouse;
			if ($warehouseId > 0) {
				$this->assertWarehouseAvailableForProduction($warehouseId);
				return $warehouseId;
			}
		}

		if (is_object($product) && !empty($product->fk_default_warehouse)) {
			$warehouseId = (int) $product->fk_default_warehouse;
			if ($warehouseId > 0) {
				$this->assertWarehouseAvailableForProduction($warehouseId);
				return $warehouseId;
			}
		}

		$entityDefaultWarehouseId = (int) ($conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
		if ($entityDefaultWarehouseId > 0) {
			$this->assertWarehouseAvailableForProduction($entityDefaultWarehouseId);
			return $entityDefaultWarehouseId;
		}

		return 0;
	}

	/**
	 * Refuse inactive warehouses and warehouses outside the current stock entity scope.
	 *
	 * @param int $warehouseId Warehouse ID
	 * @return void
	 */
	protected function assertWarehouseAvailableForProduction($warehouseId)
	{
		$this->assertWarehouseAvailableInStockScope($warehouseId, 'production');
	}

	/**
	 * Refuse a supplier-invoice warehouse outside the active stock scope.
	 *
	 * @param int $warehouseId Warehouse ID
	 * @return void
	 */
	protected function assertWarehouseAvailableForSupplierInvoice($warehouseId)
	{
		$this->assertWarehouseAvailableInStockScope($warehouseId, 'supplier invoice');
	}

	/**
	 * Refuse inactive warehouses and warehouses outside the current stock entity scope.
	 *
	 * @param int    $warehouseId Warehouse ID
	 * @param string $purpose Fixed operation name for public-safe errors
	 * @return void
	 */
	protected function assertWarehouseAvailableInStockScope($warehouseId, $purpose)
	{
		$warehouseId = (int) $warehouseId;
		$sql = 'SELECT e.rowid FROM '.MAIN_DB_PREFIX.'entrepot as e';
		$sql .= ' WHERE e.rowid = '.$warehouseId;
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' AND e.statut = 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to validate the ' . $purpose . ' warehouse', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			throw new RestException(403, ucfirst($purpose) . ' warehouse is inactive or outside the current entity scope');
		}
	}

	/**
	 * Validate an optional production third party in the active sharing scope.
	 *
	 * @param int $thirdpartyId Third-party ID
	 * @return void
	 */
	protected function assertProductionThirdpartyAvailable($thirdpartyId)
	{
		$thirdpartyId = (int) $thirdpartyId;
		if ($thirdpartyId <= 0) {
			return;
		}

		$sql = 'SELECT s.rowid FROM '.MAIN_DB_PREFIX.'societe as s';
		$sql .= ' WHERE s.rowid = '.$thirdpartyId;
		$sql .= ' AND s.entity IN ('.getEntity('societe').')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to validate the production third party', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj || !DolibarrApi::_checkAccessToResource('societe', $thirdpartyId)) {
			throw new RestException(403, 'Production third party is outside the current entity or user access scope');
		}
	}

	/**
	 * Validate an optional production project in the active sharing scope.
	 *
	 * @param int $projectId Project ID
	 * @return void
	 */
	protected function assertProductionProjectAvailable($projectId)
	{
		$projectId = (int) $projectId;
		if ($projectId <= 0) {
			return;
		}

		$sql = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.'projet as p';
		$sql .= ' WHERE p.rowid = '.$projectId;
		$sql .= ' AND p.entity IN ('.getEntity('project').')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to validate the production project', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj || !DolibarrApi::_checkAccessToResource('project', $projectId)) {
			throw new RestException(403, 'Production project is outside the current entity or user access scope');
		}
	}

	/**
	 * Resolve usable BOM id for one product.
	 *
	 * @param object $product Product object
	 * @param int     $requestedBomId Preferred BOM id
	 * @return int
	 */
	protected function resolveBomForProduct($product, $requestedBomId = 0)
	{
		$allowedBomEntities = $this->getEntityIdList('bom', true);

		if ($requestedBomId > 0) {
			$bom = new BOM($this->db);
			if ($bom->fetch((int) $requestedBomId) > 0) {
				if ((int) $bom->fk_product !== (int) $product->id) {
					throw new RestException(400, 'Requested BOM does not belong to the selected product');
				}
				if ((int) $bom->status !== (int) BOM::STATUS_VALIDATED) {
					throw new RestException(400, 'Requested BOM is not enabled');
				}
				if (!$this->isEntityInScope((int) $bom->entity, $allowedBomEntities)) {
					throw new RestException(403, 'Requested BOM is out of current entity scope');
				}
				return (int) $bom->id;
			}
			throw new RestException(404, 'Requested BOM not found');
		}

		$defaultBomId = (!empty($product->fk_default_bom) ? (int) $product->fk_default_bom : 0);
		if ($defaultBomId > 0) {
			$bom = new BOM($this->db);
			if (
				$bom->fetch($defaultBomId) > 0
				&& (int) $bom->fk_product === (int) $product->id
				&& (int) $bom->status === (int) BOM::STATUS_VALIDATED
				&& $this->isEntityInScope((int) $bom->entity, $allowedBomEntities)
			) {
				return (int) $bom->id;
			}
		}

		$sql = "SELECT rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "bom_bom";
		$sql .= " WHERE fk_product = " . ((int) $product->id);
		$sql .= " AND status = " . ((int) BOM::STATUS_VALIDATED);
		$sql .= " AND entity IN (" . $this->entityListToSql($allowedBomEntities) . ")";
		$sql .= " ORDER BY rowid ASC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load the product BOM', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (empty($obj->rowid)) {
			throw new RestException(409, 'No active BOM found for selected product');
		}

		return (int) $obj->rowid;
	}

	/**
	 * Build consume/produce payload from MO lines by role.
	 *
	 * @param array  $lines               MO lines
	 * @param string $role                Role to export (toconsume/toproduce)
	 * @param int    $defaultWarehouseId  Warehouse fallback id
	 * @param array  $componentLotMaps    Indexed component lot payload
	 * @param string $producedBatch       Produced batch code
	 * @return array
	 */
	protected function buildMoProductionPayloadByRole($lines, $role, $defaultWarehouseId, $componentLotMaps = array(), $producedBatch = '')
	{
		$payload = array();
		if (!is_array($lines)) {
			return $payload;
		}

		foreach ($lines as $line) {
			if (!is_object($line) || (string) $line->role !== (string) $role) {
				continue;
			}

			$entry = array(
				'objectid' => (int) $line->id,
				'qty' => (float) $line->qty,
			);
			$batch = '';
			if ((string) $role === 'toconsume') {
				$lot = $this->resolveComponentLotForMoLine($line, $componentLotMaps);
				if (!empty($lot)) {
					$entry['qty'] = (float) $lot['qty'];
					$batch = (string) $lot['batch'];
				}
			} elseif ((string) $role === 'toproduce') {
				$batch = trim((string) $producedBatch);
			}

			$disableStockChange = !empty($line->disable_stock_change);
			if ($disableStockChange) {
				$entry['fk_warehouse'] = 0;
			} else {
				$warehouseId = (int) $line->fk_warehouse;
				if ($warehouseId <= 0) {
					$warehouseId = (int) $defaultWarehouseId;
				}
					if ($warehouseId <= 0) {
						throw new RestException(409, 'MO line requires warehouse but none is available');
					}
					$this->assertWarehouseAvailableForProduction($warehouseId);
					$entry['fk_warehouse'] = $warehouseId;
			}

			if ($batch !== '') {
				$entry['batch'] = $batch;
			}

			$payload[] = $entry;
		}

		return $payload;
	}

	/**
	 * Normalize component lots payload from production/run request body.
	 *
	 * @param array $requestData Raw request body
	 * @return array<int,array<string,mixed>>
	 */
	protected function normalizeComponentLotsRequest($requestData)
	{
		$rawLots = array();
		if (!empty($requestData['component_lots']) && is_array($requestData['component_lots'])) {
			$rawLots = $requestData['component_lots'];
		} elseif (!empty($requestData['component_batches']) && is_array($requestData['component_batches'])) {
			$rawLots = $requestData['component_batches'];
		}

		$normalized = array();
		foreach ($rawLots as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$lineId = (int) (isset($entry['mo_line_id']) ? $entry['mo_line_id'] : 0);
			$bomLineId = (int) (isset($entry['bom_line_id']) ? $entry['bom_line_id'] : (isset($entry['line_id']) ? $entry['line_id'] : 0));
			$qty = (float) price2num((isset($entry['qty']) ? $entry['qty'] : 0), 'MS');
			if ($qty < 0) {
				throw new RestException(400, 'Component lot qty must be >= 0');
			}

			$componentProductId = (int) (isset($entry['component_product_id']) ? $entry['component_product_id'] : 0);
			$batch = trim((string) (isset($entry['batch']) ? $entry['batch'] : ''));
			$position = (int) (isset($entry['position']) ? $entry['position'] : 0);

			if ($lineId <= 0 && $bomLineId <= 0 && $componentProductId <= 0) {
				continue;
			}

			$normalized[] = array(
				'line_id' => $lineId,
				'bom_line_id' => $bomLineId,
				'position' => $position,
				'component_product_id' => $componentProductId,
				'qty' => $qty,
				'batch' => substr($batch, 0, 128),
			);
		}

		if (!empty($rawLots) && empty($normalized)) {
			throw new RestException(400, 'Invalid component_lots payload');
		}

		return $normalized;
	}

	/**
	 * Build index maps for component lots.
	 *
	 * @param array<int,array<string,mixed>> $componentLots
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	protected function indexComponentLotsByMoLine($componentLots)
	{
		$maps = array(
			'by_mo_line' => array(),
			'by_bom_line' => array(),
		);

		foreach ((array) $componentLots as $lot) {
			if (!is_array($lot)) {
				continue;
			}

			$lineId = (int) (!empty($lot['line_id']) ? $lot['line_id'] : 0);
			$bomLineId = (int) (!empty($lot['bom_line_id']) ? $lot['bom_line_id'] : 0);
			if ($lineId > 0) {
				$maps['by_mo_line'][$lineId] = $lot;
			}
			if ($bomLineId > 0) {
				$maps['by_bom_line'][$bomLineId] = $lot;
			}
		}

		return $maps;
	}

	/**
	 * Resolve one component lot payload line for one MO line.
	 *
	 * @param object $line MO line object
	 * @param array  $componentLotMaps Index maps returned by indexComponentLotsByMoLine
	 * @return array<string,mixed>
	 */
	protected function resolveComponentLotForMoLine($line, $componentLotMaps)
	{
		if (!is_object($line) || !is_array($componentLotMaps)) {
			return array();
		}

		$lineId = (int) (isset($line->id) ? $line->id : 0);
		if ($lineId > 0 && !empty($componentLotMaps['by_mo_line'][$lineId]) && is_array($componentLotMaps['by_mo_line'][$lineId])) {
			return $componentLotMaps['by_mo_line'][$lineId];
		}

		$originType = (string) (isset($line->origin_type) ? $line->origin_type : '');
		$originId = (int) (isset($line->origin_id) ? $line->origin_id : 0);
		if ($originType === 'bomline' && $originId > 0 && !empty($componentLotMaps['by_bom_line'][$originId]) && is_array($componentLotMaps['by_bom_line'][$originId])) {
			return $componentLotMaps['by_bom_line'][$originId];
		}

		return array();
	}

	/**
	 * Validate that provided component lots match MO consume lines.
	 *
	 * @param array<int,array<string,mixed>> $componentLots
	 * @param array                          $moLines
	 * @return void
	 */
	protected function assertComponentLotsMatchMoLines($componentLots, $moLines)
	{
		if (empty($componentLots)) {
			return;
		}

		$consumeByMoLineId = array();
		$consumeByBomLineId = array();
		foreach ((array) $moLines as $line) {
			if (!is_object($line) || (string) $line->role !== 'toconsume') {
				continue;
			}

			$moLineId = (int) (isset($line->id) ? $line->id : 0);
			$bomLineId = ((string) (isset($line->origin_type) ? $line->origin_type : '') === 'bomline' ? (int) (isset($line->origin_id) ? $line->origin_id : 0) : 0);
			if ($moLineId > 0) {
				$consumeByMoLineId[$moLineId] = $line;
			}
			if ($bomLineId > 0) {
				$consumeByBomLineId[$bomLineId] = $line;
			}
		}

		foreach ((array) $componentLots as $lot) {
			if (!is_array($lot)) {
				continue;
			}

			$lineId = (int) (!empty($lot['line_id']) ? $lot['line_id'] : 0);
			$bomLineId = (int) (!empty($lot['bom_line_id']) ? $lot['bom_line_id'] : 0);
			$matchedLine = null;

			if ($lineId > 0 && !empty($consumeByMoLineId[$lineId])) {
				$matchedLine = $consumeByMoLineId[$lineId];
			} elseif ($bomLineId > 0 && !empty($consumeByBomLineId[$bomLineId])) {
				$matchedLine = $consumeByBomLineId[$bomLineId];
			}

			if (!is_object($matchedLine)) {
				throw new RestException(409, 'Component lot line does not match MO consume lines');
			}

			$componentProductId = (int) (!empty($lot['component_product_id']) ? $lot['component_product_id'] : 0);
			if ($componentProductId > 0 && !empty($matchedLine->fk_product) && (int) $matchedLine->fk_product !== $componentProductId) {
				throw new RestException(409, 'Component lot product does not match MO consume line product');
			}
		}
	}

	/**
	 * Mark MO lines as disable_stock_change when Dolibarr stock move will not return
	 * a movement id (for example non-stockable product).
	 * For consume lines we also keep the subproduct-parent safeguard.
	 * This avoids core api_mos false failures on valid produce/consume paths.
	 *
	 * @param object $mo Manufacturing order object
	 * @return void
	 */
	protected function disableStockChangeForNonStockMoLines($mo)
	{
		if (!is_object($mo) || empty($mo->id) || empty($mo->lines) || !is_array($mo->lines)) {
			return;
		}

		$useSubproducts = (getDolGlobalInt('PRODUIT_SOUSPRODUITS') > 0 && !getDolGlobalInt('INDEPENDANT_SUBPRODUCT_STOCK'));
		$productDecisionCache = array();

		foreach ((array) $mo->lines as $line) {
			if (!is_object($line)) {
				continue;
			}

			$role = (string) (!empty($line->role) ? $line->role : '');
			if ($role !== 'toconsume' && $role !== 'toproduce') {
				continue;
			}
			if (!empty($line->disable_stock_change)) {
				continue;
			}

			$productId = (int) (!empty($line->fk_product) ? $line->fk_product : 0);
			if ($productId <= 0) {
				continue;
			}

			if (!array_key_exists($productId, $productDecisionCache)) {
				$productDecisionCache[$productId] = false;

				$product = new Product($this->db);
				if ($product->fetch($productId) <= 0) {
					throw new RestException(500, 'Unable to load component product for MO stock configuration update');
				}

					$disableStockChange = ((int) $product->stockable_product === Product::DISABLED_STOCK);
					if (!$disableStockChange && $useSubproducts && $role === 'toconsume') {
						$disableStockChange = (((int) $product->hasFatherOrChild(1)) > 0);
					}

				$productDecisionCache[$productId] = $disableStockChange;
			}

			if (empty($productDecisionCache[$productId])) {
				continue;
			}

			$lineId = (int) (!empty($line->id) ? $line->id : 0);
			if ($lineId <= 0) {
				continue;
			}

			$moLine = new MoLine($this->db);
				if ($moLine->fetch($lineId) <= 0 || (int) $moLine->fk_mo !== (int) $mo->id) {
					throw new RestException(500, 'Unable to load MO line for stock configuration update');
				}

			$moLine->disable_stock_change = 1;
			if ($moLine->update(DolibarrApiAccess::$user) <= 0) {
				$error = trim((string) $moLine->error);
				if ($error === '') {
					$error = $this->db->lasterror();
				}
				$this->failInternalRequest('Unable to update manufacturing-order stock configuration', $error, 500);
				}
		}
	}

	/**
	 * Find parent->subproduct associations that will fail stock posting because
	 * the associated child product is managed by batch/serial.
	 *
	 * @param array $moLines MO lines from current order
	 * @return array<int,array<string,mixed>>
	 */
	protected function findBatchManagedAssociatedSubproductsForMoLines($moLines)
	{
		if (empty($moLines) || !isModEnabled('productbatch')) {
			return array();
		}
		if (!getDolGlobalString('PRODUIT_SOUSPRODUITS') || getDolGlobalString('INDEPENDANT_SUBPRODUCT_STOCK')) {
			return array();
		}

		$parentProductIds = array();
		foreach ((array) $moLines as $line) {
			if (!is_object($line)) {
				continue;
			}

			$role = (string) (isset($line->role) ? $line->role : '');
			if ($role !== 'toconsume' && $role !== 'toproduce') {
				continue;
			}
			if (!empty($line->disable_stock_change)) {
				continue;
			}

			$productId = (int) (isset($line->fk_product) ? $line->fk_product : 0);
			if ($productId > 0) {
				$parentProductIds[$productId] = $productId;
			}
		}

		if (empty($parentProductIds)) {
			return array();
		}

		$allowedProductEntities = $this->getEntityIdList('product', true);
		$sql = "SELECT pa.fk_product_pere AS parent_id, parent.ref AS parent_ref, parent.label AS parent_label,";
		$sql .= " pa.fk_product_fils AS child_id, child.ref AS child_ref, child.label AS child_label";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = pa.fk_product_pere";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS child ON child.rowid = pa.fk_product_fils";
		$sql .= " WHERE pa.incdec = 1";
		$sql .= " AND pa.fk_product_pere IN (" . implode(',', array_map('intval', array_values($parentProductIds))) . ")";
		$sql .= " AND parent.entity IN (" . $this->entityListToSql($allowedProductEntities) . ")";
		$sql .= " AND child.entity IN (" . $this->entityListToSql($allowedProductEntities) . ")";
		$sql .= " AND child.tobatch > 0";
		$sql .= " ORDER BY parent.ref ASC, child.ref ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to validate associated subproducts', $this->db->lasterror());
		}

		$found = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$key = ((int) $obj->parent_id) . '-' . ((int) $obj->child_id);
			if (!empty($found[$key])) {
				continue;
			}

			$found[$key] = array(
				'parent_id' => (int) $obj->parent_id,
				'parent_ref' => (string) $obj->parent_ref,
				'parent_label' => (string) $obj->parent_label,
				'child_id' => (int) $obj->child_id,
				'child_ref' => (string) $obj->child_ref,
				'child_label' => (string) $obj->child_label,
			);
		}
		$this->db->free($resql);

		return array_values($found);
	}

	/**
	 * Build a deterministic business error for unsupported associated subproducts.
	 *
	 * @param array<int,array<string,mixed>> $batchManagedSubproducts
	 * @return string
	 */
	protected function buildAssociatedBatchConflictMessage($batchManagedSubproducts)
	{
		if (empty($batchManagedSubproducts)) {
			return 'Production blocked by associated batch-managed subproducts.';
		}

		$labels = array();
		$maxItems = 3;
		foreach ((array) $batchManagedSubproducts as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$parentRef = trim((string) (!empty($entry['parent_ref']) ? $entry['parent_ref'] : ('#' . ((int) $entry['parent_id']))));
			$childRef = trim((string) (!empty($entry['child_ref']) ? $entry['child_ref'] : ('#' . ((int) $entry['child_id']))));
			$labels[] = $parentRef . ' -> ' . $childRef;
			if (count($labels) >= $maxItems) {
				break;
			}
		}

		$message = 'Production blocked: Dolibarr stock posting cannot process associated subproducts managed by batch/serial in this workflow';
		if (!empty($labels)) {
			$message .= ' (' . implode(', ', $labels);
			if (count($batchManagedSubproducts) > $maxItems) {
				$message .= ', +' . ((int) count($batchManagedSubproducts) - $maxItems) . ' more';
			}
			$message .= ')';
		}
		$message .= '. Remove/disable these associations or enable INDEPENDANT_SUBPRODUCT_STOCK.';

		return $message;
	}

	/**
	 * Delete a freshly-created MO when production fails before any consumed/produced line.
	 *
	 * @param object $mo Manufacturing order object
	 * @return string Informational cleanup note
	 */
	protected function cleanupAutoCreatedMoIfUnprocessed($mo)
	{
		if (!is_object($mo) || empty($mo->id)) {
			return '';
		}

		$moId = (int) $mo->id;
		if ($moId <= 0) {
			return '';
		}

		if ($mo->fetch($moId) <= 0) {
			return '';
		}

		$moRef = (!empty($mo->ref) ? (string) $mo->ref : ('#' . $moId));
		$sql = "SELECT COUNT(rowid) AS nb";
		$sql .= " FROM " . MAIN_DB_PREFIX . "mrp_production";
		$sql .= " WHERE fk_mo = " . ((int) $moId);
		$sql .= " AND role IN ('consumed','produced')";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' Unable to check execution lines for MO ' . ((int) $moId) . ': ' . $this->db->lasterror(), LOG_WARNING);
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$executedLines = (!empty($obj->nb) ? (int) $obj->nb : 0);
		if ($executedLines > 0) {
			return '';
		}

		$deleteResult = $mo->delete(DolibarrApiAccess::$user, 0, false);
		if ($deleteResult > 0) {
			return 'Auto-created MO ' . $moRef . ' was deleted after failure.';
		}

		dol_syslog(__METHOD__ . ' Unable to delete auto-created MO ' . ((int) $moId) . ': ' . (string) $mo->error, LOG_WARNING);
		return '';
	}

	/**
	 * Save production/component batch trace linked to MO.
	 *
	 * @param object $mo
	 * @param float  $productionQty
	 * @param string $inventoryCode
	 * @param array  $componentLotMaps
	 * @return void
	 */
	protected function saveProductionBatchTrace($mo, $productionQty, $inventoryCode, $componentLotMaps)
	{
		global $conf;

		$entityId = (int) $conf->entity;
		$userId = (int) DolibarrApiAccess::$user->id;
		$traceTable = MAIN_DB_PREFIX . 'kreaproducts_mo_batch';
		$componentTable = MAIN_DB_PREFIX . 'kreaproducts_mo_component_batch';

		$this->db->begin();

		$sql = "INSERT INTO " . $traceTable . " (entity, fk_mo, production_qty, inventorycode, fk_user_creat, date_creation)";
		$sql .= " VALUES (";
		$sql .= ((int) $entityId) . ", ";
		$sql .= ((int) $mo->id) . ", ";
		$sql .= ((float) $productionQty) . ", ";
		$sql .= "'" . $this->db->escape((string) $inventoryCode) . "', ";
		$sql .= ((int) $userId) . ", ";
		$sql .= "'" . $this->db->idate(dol_now()) . "'";
		$sql .= ")";
		$sql .= " ON DUPLICATE KEY UPDATE";
		$sql .= " production_qty = VALUES(production_qty),";
		$sql .= " tms = CURRENT_TIMESTAMP";

		if (!$this->db->query($sql)) {
			$this->db->rollback();
			throw new Exception('Error saving production batch trace: ' . $this->db->lasterror());
		}

		$sqlTrace = "SELECT rowid";
		$sqlTrace .= " FROM " . $traceTable;
		$sqlTrace .= " WHERE entity = " . ((int) $entityId);
		$sqlTrace .= " AND fk_mo = " . ((int) $mo->id);
		$sqlTrace .= " AND inventorycode = '" . $this->db->escape((string) $inventoryCode) . "'";
		$sqlTrace .= $this->db->plimit(1);
		$resTrace = $this->db->query($sqlTrace);
		if (!$resTrace) {
			$this->db->rollback();
			throw new Exception('Error loading production batch trace id: ' . $this->db->lasterror());
		}
		$objTrace = $this->db->fetch_object($resTrace);
		$this->db->free($resTrace);
		$traceId = (!empty($objTrace->rowid) ? (int) $objTrace->rowid : 0);
		if ($traceId <= 0) {
			$this->db->rollback();
			throw new Exception('Unable to resolve production trace row id');
		}

		$sqlDelete = "DELETE FROM " . $componentTable . " WHERE entity = " . ((int) $entityId) . " AND fk_trace = " . ((int) $traceId);
		if (!$this->db->query($sqlDelete)) {
			$this->db->rollback();
			throw new Exception('Error clearing previous component batch trace lines: ' . $this->db->lasterror());
		}

		foreach ((array) $mo->lines as $line) {
			if (!is_object($line) || (string) $line->role !== 'toconsume') {
				continue;
			}

			$lot = $this->resolveComponentLotForMoLine($line, $componentLotMaps);
			$componentProductId = (int) (isset($line->fk_product) ? $line->fk_product : 0);
			if (!empty($lot['component_product_id'])) {
				$componentProductId = (int) $lot['component_product_id'];
			}

			$componentQty = (!empty($lot['qty']) ? (float) $lot['qty'] : (float) $line->qty);
			$componentBatch = (!empty($lot['batch']) ? (string) $lot['batch'] : '');
			$bomLineId = ((string) (isset($line->origin_type) ? $line->origin_type : '') === 'bomline' ? (int) (isset($line->origin_id) ? $line->origin_id : 0) : 0);
			if (!empty($lot['bom_line_id'])) {
				$bomLineId = (int) $lot['bom_line_id'];
			}

			$sqlInsertLine = "INSERT INTO " . $componentTable . " (";
			$sqlInsertLine .= "entity, fk_trace, fk_bomline, fk_mo_line, position, fk_component_product, component_qty, component_batch, fk_user_creat, date_creation";
			$sqlInsertLine .= ") VALUES (";
			$sqlInsertLine .= ((int) $entityId) . ", ";
			$sqlInsertLine .= ((int) $traceId) . ", ";
			$sqlInsertLine .= ((int) $bomLineId) . ", ";
			$sqlInsertLine .= ((int) $line->id) . ", ";
			$sqlInsertLine .= ((int) (isset($line->position) ? $line->position : 0)) . ", ";
			$sqlInsertLine .= ((int) $componentProductId) . ", ";
			$sqlInsertLine .= ((float) $componentQty) . ", ";
			$sqlInsertLine .= "'" . $this->db->escape(substr((string) $componentBatch, 0, 128)) . "', ";
			$sqlInsertLine .= ((int) $userId) . ", ";
			$sqlInsertLine .= "'" . $this->db->idate(dol_now()) . "'";
			$sqlInsertLine .= ")";
			$sqlInsertLine .= " ON DUPLICATE KEY UPDATE";
			$sqlInsertLine .= " fk_bomline = VALUES(fk_bomline),";
			$sqlInsertLine .= " fk_component_product = VALUES(fk_component_product),";
			$sqlInsertLine .= " component_qty = VALUES(component_qty),";
			$sqlInsertLine .= " component_batch = VALUES(component_batch),";
			$sqlInsertLine .= " tms = CURRENT_TIMESTAMP";

			if (!$this->db->query($sqlInsertLine)) {
				$this->db->rollback();
				throw new Exception('Error saving component batch trace line: ' . $this->db->lasterror());
			}
		}

		$this->db->commit();
	}

	/**
	 * Serialize one production posting and validate the MO entity scope.
	 *
	 * @param int $moId Manufacturing order ID
	 * @return void
	 */
	protected function lockMoForProduction($moId)
	{
		$sql = 'SELECT m.rowid FROM '.MAIN_DB_PREFIX.'mrp_mo as m';
		$sql .= ' WHERE m.rowid = '.((int) $moId);
		$sql .= ' AND m.entity IN ('.getEntity('mrp_mo').')';
		$sql .= ' FOR UPDATE';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to lock the manufacturing order', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			throw new RestException(403, 'MO is outside the current entity scope');
		}
	}

	/**
	 * Check whether an MO already has committed execution movements.
	 *
	 * @param int $moId Manufacturing order ID
	 * @return bool
	 */
	protected function hasMoExecutionMovements($moId)
	{
		$sql = 'SELECT mp.rowid FROM '.MAIN_DB_PREFIX.'mrp_production as mp';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'stock_mouvement as sm ON sm.rowid = mp.fk_stock_movement';
		$sql .= ' WHERE mp.fk_mo = '.((int) $moId);
		$sql .= " AND mp.role IN ('consumed','produced')";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to verify previous manufacturing-order production', $this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return (bool) $obj;
	}

	/**
	 * Fully unwind nested Dolibarr transactions after a core lifecycle throws.
	 *
	 * @return void
	 */
	protected function rollbackOpenTransactions()
	{
		$guard = 0;
		while ((int) $this->db->transaction_opened > 0 && $guard < 10) {
			$this->db->rollback();
			$guard++;
		}
	}

	/**
	 * Refuse production when activation migrations were not installed.
	 *
	 * Production requests must never perform DDL because MySQL/MariaDB DDL may
	 * implicitly commit an otherwise atomic stock transaction.
	 *
	 * @return void
	 */
	protected function assertProductionTraceSchemaReady()
	{
		$requiredColumns = array(
			MAIN_DB_PREFIX.'kreaproducts_mo_batch' => array('rowid', 'entity', 'fk_mo', 'production_qty', 'inventorycode', 'fk_user_creat', 'date_creation'),
			MAIN_DB_PREFIX.'kreaproducts_mo_component_batch' => array('rowid', 'entity', 'fk_trace', 'fk_bomline', 'fk_mo_line', 'position', 'fk_component_product', 'component_qty', 'component_batch', 'fk_user_creat', 'date_creation'),
		);
		foreach ($requiredColumns as $tableName => $columns) {
			foreach ($columns as $columnName) {
				if (!$this->tableColumnExists($tableName, $columnName)) {
					throw new RestException(503, 'KreaProducts production trace schema is not installed. Reactivate the module before posting production.');
				}
			}
		}
	}

	/**
	 * Build label payload for product + production quantity.
	 *
	 * @param object $product         Product object
	 * @param float   $productionQty   Produced quantity
	 * @param float   $unitsPerLabel   Units represented by one label
	 * @param int     $labelsCount     Explicit labels count
	 * @param string  $templateCode    Selected template code
	 * @param array   $templateValues  Selected template values
	 * @param string  $langcode        Optional output language
	 * @return array
	 */
	protected function buildLabelPayload($product, $productionQty, $unitsPerLabel, $labelsCount, $templateCode, $templateValues = array(), $langcode = '')
	{
		global $langs, $conf;

		$outputlangs = clone $langs;
		if ($langcode !== '') {
			$outputlangs->setDefaultLang($langcode);
		}
		$outputlangs->load('main');
		$outputlangs->load('products');
		$outputlangs->load('mrp');
		$outputlangs->load('kreaproducts@kreaproducts');

		$entityId = (int) $conf->entity;
		$recommendedCount = $this->computeLabelCount($productionQty, $unitsPerLabel, $labelsCount);
		$standardPreview = KreaProductsLabelService::buildStandardPreviewData($this->db, $product, $outputlangs);
		$formatDetails = KreaProductsLabelService::getFormatDetails($this->db);
		$templateIndex = KreaProductsLabelService::listLabelTemplates($entityId);

		$templates = array();
		foreach ($templateIndex as $code => $meta) {
			$templates[] = $this->sanitizeTemplateMeta($code, $meta);
		}

		$selectedTemplate = array(
			'code' => '',
			'meta' => array(),
			'editable_fields' => array(),
			'viewer' => array(),
		);

		$templateCode = $this->resolveLabelTemplateCode($product, $templateCode);
		$templateValues = $this->resolveLabelTemplateValues($product, $templateCode, $templateValues);
		if ($templateCode !== '') {
			$template = KreaProductsLabelService::loadLabelTemplate($templateCode, $entityId);
			if (!empty($template)) {
				$templateMeta = KreaProductsLabelService::getTemplateMeta($templateCode, $entityId);
				$selectedTemplate['code'] = $templateCode;
				$selectedTemplate['meta'] = $this->sanitizeTemplateMeta($templateCode, $templateMeta);
				$selectedTemplate['editable_fields'] = KreaProductsLabelService::getTemplateEditableFields($template, $product, $outputlangs, (is_array($templateValues) ? $templateValues : array()));
				$templateViewerMap = KreaProductsLabelService::buildLabelTemplateViewerMap(
					$product,
					$outputlangs,
					$entityId,
					array($templateCode => (is_array($templateValues) ? $templateValues : array()))
				);
				if (!empty($templateViewerMap[$templateCode]) && is_array($templateViewerMap[$templateCode])) {
					$selectedTemplate['viewer'] = $templateViewerMap[$templateCode];
				}
			}
		}

		$defaultFormatCode = KreaProductsLabelService::getDefaultFormatCode(KreaProductsLabelService::getFormatOptions($this->db));

		return array(
			'product' => array(
				'id' => (int) $product->id,
				'ref' => (string) $product->ref,
				'label' => (string) $product->label,
				'barcode' => (string) $product->barcode,
			),
			'production_qty' => (float) $productionQty,
			'units_per_label' => (float) $unitsPerLabel,
			'recommended_labels_count' => (int) $recommendedCount,
			'formats' => array(
				'default_code' => (string) $defaultFormatCode,
				'details' => $formatDetails,
			),
			'standard' => array(
				'available_fields' => KreaProductsLabelService::getAvailableFields($outputlangs),
				'preview_data' => $standardPreview,
			),
			'templates' => array(
				'available' => $templates,
				'selected' => $selectedTemplate,
			),
		);
	}

	/**
	 * Return sanitized template metadata for API payloads.
	 *
	 * @param string $code Template code
	 * @param array  $meta Raw metadata
	 * @return array
	 */
	protected function sanitizeTemplateMeta($code, $meta)
	{
		return array(
			'code' => (string) $code,
			'label' => (!empty($meta['label']) ? (string) $meta['label'] : ''),
			'description' => (!empty($meta['description']) ? (string) $meta['description'] : ''),
			'format_code' => (!empty($meta['format_code']) ? (string) $meta['format_code'] : ''),
			'label_size_mm' => (!empty($meta['label_size_mm']) && is_array($meta['label_size_mm']) ? $meta['label_size_mm'] : array()),
			'filename' => (!empty($meta['filename']) ? (string) $meta['filename'] : ''),
			'source' => (!empty($meta['source']) ? (string) $meta['source'] : ''),
			'is_readonly' => !empty($meta['is_readonly']),
		);
	}

	/**
	 * Compute suggested labels count.
	 *
	 * @param float $productionQty Produced quantity
	 * @param float $unitsPerLabel Units represented by one label
	 * @param int   $labelsCount   Explicit labels count
	 * @return int
	 */
	protected function computeLabelCount($productionQty, $unitsPerLabel, $labelsCount)
	{
		$explicit = (int) $labelsCount;
		if ($explicit > 0) {
			$count = $explicit;
		} else {
			$qty = (float) price2num($productionQty, 'MS');
			if ($qty <= 0) {
				$qty = 1.0;
			}

			$perLabel = (float) price2num($unitsPerLabel, 'MS');
			if ($perLabel <= 0) {
				$perLabel = 1.0;
			}

			$count = max(1, (int) ceil($qty / $perLabel));
		}

		$maximum = KreaProductsLabelService::getMaximumLabelCount();
		if ($count > $maximum) {
			throw new RestException(422, 'Requested label count exceeds the configured maximum of ' . $maximum);
		}

		return $count;
	}

	/**
	 * Assert product is linked to category.
	 *
	 * @param int $productId  Product id
	 * @param int $categoryId Category id
	 * @return void
	 */
	protected function assertProductInCategory($productId, $categoryId)
	{
		$this->fetchProductCategoryOrFail((int) $categoryId);

		$sql = "SELECT 1";
		$sql .= " FROM " . MAIN_DB_PREFIX . "categorie_product AS cp";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie AS c ON c.rowid = cp.fk_categorie";
		$sql .= " WHERE cp.fk_product = " . ((int) $productId);
		$sql .= " AND cp.fk_categorie = " . ((int) $categoryId);
		$sql .= " AND c.entity IN (" . getEntity('category') . ")";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to validate the category/product link', $this->db->lasterror());
		}
		$exists = ($this->db->fetch_object($resql) ? true : false);
		$this->db->free($resql);
		if (!$exists) {
			throw new RestException(400, 'Selected product is not linked to selected category');
		}
	}

	/**
	 * Return entity ids available for one element.
	 *
	 * @param string $element Element key used by getEntity()
	 * @param bool   $includeShared Include shared entity 0 in scope
	 * @return array<int>
	 */
	protected function getEntityIdList($element, $includeShared = false)
	{
		$list = array();
		$raw = explode(',', (string) getEntity($element));
		foreach ($raw as $value) {
			$value = trim((string) $value);
			if ($value === '' || !is_numeric($value)) {
				continue;
			}
			$list[] = (int) $value;
		}
		if ($includeShared) {
			$list[] = 0;
		}

		return array_values(array_unique($list));
	}

	/**
	 * Check whether entity id is inside allowed scope.
	 *
	 * @param int   $entityId Entity id to test
	 * @param array $scope    Allowed entity ids
	 * @return bool
	 */
	protected function isEntityInScope($entityId, $scope)
	{
		return in_array((int) $entityId, (array) $scope, true);
	}

	/**
	 * Build SQL-safe comma-separated entity list.
	 *
	 * @param array<int> $entityIds
	 * @return string
	 */
	protected function entityListToSql($entityIds)
	{
		$clean = array();
		foreach ((array) $entityIds as $id) {
			if (!is_numeric($id)) {
				continue;
			}
			$clean[] = (int) $id;
		}
		$clean = array_values(array_unique($clean));
		if (empty($clean)) {
			$clean = array((int) $GLOBALS['conf']->entity);
		}

		return implode(',', $clean);
	}

	/**
	 * Fetch one product category and validate access/scope/type.
	 *
	 * @param int $categoryId Category id
	 * @return array
	 */
	protected function fetchProductCategoryOrFail($categoryId)
	{
		$category = new Categorie($this->db);
		$result = $category->fetch((int) $categoryId);
		if ($result <= 0) {
			throw new RestException(404, 'Category not found');
		}
		if (!DolibarrApi::_checkAccessToResource('categorie', $category->id)) {
			throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}
		$productTypeId = (int) (array_key_exists(Categorie::TYPE_PRODUCT, $category->MAP_ID) ? $category->MAP_ID[Categorie::TYPE_PRODUCT] : -1);
		$isProductType = (
			((string) $category->type === Categorie::TYPE_PRODUCT)
			|| ((int) $category->type === $productTypeId)
		);
		if (!$isProductType) {
			throw new RestException(400, 'Category is not a product category');
		}

		return array(
			'id' => (int) $category->id,
			'label' => (string) $category->label,
			'fk_parent' => (int) $category->fk_parent,
			'description' => (string) $category->description,
			'color' => (string) $category->color,
			'entity' => (int) $category->entity,
		);
	}

	/**
	 * Load all product categories in current entity scope.
	 *
	 * @return array<int,array>
	 */
	protected function loadProductCategoriesIndexed()
	{
		$category = new Categorie($this->db);
		$productTypeId = (int) (array_key_exists(Categorie::TYPE_PRODUCT, $category->MAP_ID) ? $category->MAP_ID[Categorie::TYPE_PRODUCT] : -1);
		if ($productTypeId < 0) {
			throw new RestException(500, 'Unable to resolve product category type id');
		}

		$sql = "SELECT rowid, fk_parent, label, description, color, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "categorie";
		$sql .= " WHERE type = " . $productTypeId;
		$sql .= " AND entity IN (" . getEntity('category') . ")";
		$sql .= " ORDER BY label ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load the category tree', $this->db->lasterror());
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$list[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'fk_parent' => (int) $obj->fk_parent,
				'label' => (string) $obj->label,
				'description' => (string) $obj->description,
				'color' => (string) $obj->color,
				'entity' => (int) $obj->entity,
			);
		}
		$this->db->free($resql);

		return $list;
	}

	/**
	 * Load linked products grouped by category.
	 *
	 * @param int $onlyProducible 1=only products with enabled BOM
	 * @return array<int,array<int,array>>
	 */
	protected function loadProductsByCategory($onlyProducible = 0)
	{
		$bomEntitySql = $this->entityListToSql($this->getEntityIdList('bom', true));

		$sql = "SELECT cp.fk_categorie AS category_id,";
		$sql .= " p.rowid, p.ref, p.label, p.barcode, p.tobatch AS status_batch, p.fk_default_warehouse, p.fk_default_bom,";
		$sql .= " COUNT(DISTINCT CASE WHEN b.status = " . ((int) BOM::STATUS_VALIDATED) . " AND b.entity IN (" . $bomEntitySql . ") THEN b.rowid END) AS enabled_bom_count,";
		$sql .= " MIN(CASE WHEN b.status = " . ((int) BOM::STATUS_VALIDATED) . " AND b.entity IN (" . $bomEntitySql . ") THEN b.rowid END) AS fallback_bom_id";
		$sql .= " FROM " . MAIN_DB_PREFIX . "categorie_product AS cp";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = cp.fk_product";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.fk_product = p.rowid";
		$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " GROUP BY cp.fk_categorie, p.rowid, p.ref, p.label, p.barcode, p.tobatch, p.fk_default_warehouse, p.fk_default_bom";
		if ((int) $onlyProducible > 0) {
			$sql .= " HAVING enabled_bom_count > 0";
		}
		$sql .= " ORDER BY p.label ASC, p.ref ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load category products', $this->db->lasterror());
		}

		$productsByCategory = array();
		$productIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$categoryId = (int) $obj->category_id;
			$defaultBomId = (int) $obj->fk_default_bom;
			if ($defaultBomId <= 0) {
				$defaultBomId = (int) $obj->fallback_bom_id;
			}

			if (!isset($productsByCategory[$categoryId])) {
				$productsByCategory[$categoryId] = array();
			}
			$productsByCategory[$categoryId][] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'barcode' => (string) $obj->barcode,
				'status_batch' => (int) $obj->status_batch,
				'default_warehouse_id' => (int) $obj->fk_default_warehouse,
				'default_bom_id' => (int) $defaultBomId,
				'enabled_bom_count' => (int) $obj->enabled_bom_count,
				'has_enabled_bom' => ((int) $obj->enabled_bom_count > 0 ? 1 : 0),
			);
			$productIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		if (!empty($productsByCategory)) {
			$defaultLayouts = $this->loadProductExtrafieldTextMap($productIds, 'kreap_default_label_layout');
			$aliases = $this->loadProductExtrafieldTextMap($productIds, 'kreap_alias');
			foreach ($productsByCategory as &$rows) {
				if (!is_array($rows)) {
					continue;
				}
				foreach ($rows as &$row) {
					if (!is_array($row) || empty($row['id'])) {
						continue;
					}
					$productId = (int) $row['id'];
					$labelStoragePayload = $this->parseProductLabelStoragePayload(!empty($defaultLayouts[$productId]) ? (string) $defaultLayouts[$productId] : '');
					$layout = (!empty($labelStoragePayload['default_label_layout']) ? (string) $labelStoragePayload['default_label_layout'] : '');
					$alias = (!empty($aliases[$productId]) ? (string) $aliases[$productId] : '');
					$row['kreap_alias'] = $alias;
					$row['default_label_layout'] = $layout;
					$row['array_options'] = array(
						'options_kreap_default_label_layout' => $layout,
						'options_kreap_alias' => $alias,
					);
				}
				unset($row);
			}
			unset($rows);
		}

		return $productsByCategory;
	}

	/**
	 * Load BOM recipe lines for one BOM id.
	 *
	 * @param int $bomId BOM id
	 * @return array<int,array<string,mixed>>
	 */
	protected function loadRecipeLinesForBom($bomId)
	{
		$sql = "SELECT bl.rowid AS line_id, bl.position, bl.qty, bl.description AS line_description,";
		$sql .= " bl.disable_stock_change, bl.fk_bom_child AS child_bom_id,";
		$sql .= " p.rowid AS component_product_id, p.ref AS component_ref, p.label AS component_label";
		$sql .= " FROM " . MAIN_DB_PREFIX . "bom_bomline AS bl";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = bl.fk_product";
		$sql .= " WHERE bl.fk_bom = " . ((int) $bomId);
		$sql .= " AND (p.rowid IS NULL OR p.entity IN (" . getEntity('product') . "))";
		$sql .= " ORDER BY bl.position ASC, bl.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load BOM recipe lines', $this->db->lasterror());
		}

		$lines = array();
		$componentProductIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lineDescription = trim((string) $obj->line_description);
			$componentLabel = trim((string) $obj->component_label);
			if ($componentLabel === '' && $lineDescription !== '') {
				$componentLabel = $lineDescription;
			}
			$componentProductId = (int) $obj->component_product_id;

			$lines[] = array(
				'line_id' => (int) $obj->line_id,
				'position' => (int) $obj->position,
				'qty' => (float) price2num($obj->qty, 'MS'),
				'qty_display' => (float) price2num($obj->qty, 'MS'),
				'component_product_id' => $componentProductId,
				'component_ref' => (string) $obj->component_ref,
				'component_label' => (string) $componentLabel,
				'line_description' => (string) $lineDescription,
				'disable_stock_change' => (!empty($obj->disable_stock_change) ? 1 : 0),
				'child_bom_id' => (int) $obj->child_bom_id,
				'component_unit' => '',
				'component_unit_code' => '',
				'component_unit_label' => '',
				'component_unit_display' => '',
				'component_unit_code_display' => '',
				'component_unit_label_display' => '',
			);
			if ($componentProductId > 0) {
				$componentProductIds[$componentProductId] = $componentProductId;
			}
		}
		$this->db->free($resql);

		$unitsByProductId = $this->loadProductUnitMap(array_values($componentProductIds));
		$componentMoInputByProductId = $this->loadProductExtrafieldBooleanMap(array_values($componentProductIds), 'kreap_lot');
		if (!empty($unitsByProductId)) {
			foreach ($lines as &$line) {
				$productId = (!empty($line['component_product_id']) ? (int) $line['component_product_id'] : 0);
				if ($productId <= 0 || empty($unitsByProductId[$productId])) {
					continue;
				}

				$unit = $unitsByProductId[$productId];
				$line['component_unit'] = (string) (!empty($unit['short']) ? $unit['short'] : '');
				$line['component_unit_code'] = (string) (!empty($unit['code']) ? $unit['code'] : '');
				$line['component_unit_label'] = (string) (!empty($unit['label']) ? $unit['label'] : '');
			}
			unset($line);
		}
		if (!empty($lines)) {
			foreach ($lines as &$line) {
				$this->applyRecipeLineDisplayUnitScaling($line);
			}
			unset($line);
		}

		if (!empty($lines)) {
			foreach ($lines as &$line) {
				$productId = (!empty($line['component_product_id']) ? (int) $line['component_product_id'] : 0);
				$componentMoInput = '1';
				if ($productId > 0 && isset($componentMoInputByProductId[$productId])) {
					$componentMoInput = trim((string) $componentMoInputByProductId[$productId]);
					if ($componentMoInput === '') {
						$componentMoInput = '1';
					}
				}

				$line['component_kreap_lot'] = $componentMoInput;
				$line['kreap_lot'] = $componentMoInput;
				if (empty($line['array_options']) || !is_array($line['array_options'])) {
					$line['array_options'] = array();
				}
				$line['array_options']['options_kreap_lot'] = $componentMoInput;
			}
			unset($line);
		}

		return $lines;
	}

	/**
	 * Load recipe-like lines from product associations for one parent product id.
	 *
	 * @param int $productId Parent product id
	 * @return array<int,array<string,mixed>>
	 */
	protected function loadRecipeLinesFromProductAssociations($productId)
	{
		$sql = "SELECT pa.rowid AS line_id, pa.rang AS position, pa.qty, pa.incdec,";
		$sql .= " p.rowid AS component_product_id, p.ref AS component_ref, p.label AS component_label";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = pa.fk_product_fils";
		$sql .= " WHERE pa.fk_product_pere = " . ((int) $productId);
		$sql .= " AND pa.fk_product_fils <> " . ((int) $productId);
		$sql .= " AND (p.rowid IS NULL OR p.entity IN (" . getEntity('product') . "))";
		$sql .= " ORDER BY pa.rang ASC, pa.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->failInternalRequest('Unable to load association recipe lines', $this->db->lasterror());
		}

		$lines = array();
		$componentProductIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$incdec = (int) $obj->incdec;
			$componentLabel = trim((string) $obj->component_label);
			$componentProductId = (int) $obj->component_product_id;

			$lines[] = array(
				'line_id' => (int) $obj->line_id,
				'position' => (int) $obj->position,
				'qty' => (float) price2num($obj->qty, 'MS'),
				'qty_display' => (float) price2num($obj->qty, 'MS'),
				'component_product_id' => $componentProductId,
				'component_ref' => (string) $obj->component_ref,
				'component_label' => (string) $componentLabel,
				'line_description' => '',
				'disable_stock_change' => ($incdec === 1 ? 0 : 1),
				'child_bom_id' => 0,
				'source' => 'association',
				'incdec' => $incdec,
				'component_unit' => '',
				'component_unit_code' => '',
				'component_unit_label' => '',
				'component_unit_display' => '',
				'component_unit_code_display' => '',
				'component_unit_label_display' => '',
			);
			if ($componentProductId > 0) {
				$componentProductIds[$componentProductId] = $componentProductId;
			}
		}
		$this->db->free($resql);

		$unitsByProductId = $this->loadProductUnitMap(array_values($componentProductIds));
		$componentMoInputByProductId = $this->loadProductExtrafieldBooleanMap(array_values($componentProductIds), 'kreap_lot');
		if (!empty($unitsByProductId)) {
			foreach ($lines as &$line) {
				$productId = (!empty($line['component_product_id']) ? (int) $line['component_product_id'] : 0);
				if ($productId <= 0 || empty($unitsByProductId[$productId])) {
					continue;
				}

				$unit = $unitsByProductId[$productId];
				$line['component_unit'] = (string) (!empty($unit['short']) ? $unit['short'] : '');
				$line['component_unit_code'] = (string) (!empty($unit['code']) ? $unit['code'] : '');
				$line['component_unit_label'] = (string) (!empty($unit['label']) ? $unit['label'] : '');
			}
			unset($line);
		}
		if (!empty($lines)) {
			foreach ($lines as &$line) {
				$this->applyRecipeLineDisplayUnitScaling($line);
			}
			unset($line);
		}

		if (!empty($lines)) {
			foreach ($lines as &$line) {
				$productId = (!empty($line['component_product_id']) ? (int) $line['component_product_id'] : 0);
				$componentMoInput = '1';
				if ($productId > 0 && isset($componentMoInputByProductId[$productId])) {
					$componentMoInput = trim((string) $componentMoInputByProductId[$productId]);
					if ($componentMoInput === '') {
						$componentMoInput = '1';
					}
				}

				$line['component_kreap_lot'] = $componentMoInput;
				$line['kreap_lot'] = $componentMoInput;
				if (empty($line['array_options']) || !is_array($line['array_options'])) {
					$line['array_options'] = array();
				}
				$line['array_options']['options_kreap_lot'] = $componentMoInput;
			}
			unset($line);
		}

		return $lines;
	}

	/**
	 * Load product unit metadata for a set of product ids.
	 *
	 * @param array<int> $productIds Product ids
	 * @return array<int,array<string,string>>
	 */
	protected function loadProductUnitMap($productIds)
	{
		$cleanIds = array();
		foreach ((array) $productIds as $id) {
			if (!is_numeric($id)) {
				continue;
			}
			$id = (int) $id;
			if ($id > 0) {
				$cleanIds[$id] = $id;
			}
		}
		if (empty($cleanIds)) {
			return array();
		}

		$productTable = MAIN_DB_PREFIX . 'product';
		$unitsTable = MAIN_DB_PREFIX . 'c_units';
		$productHasUnit = $this->tableColumnExists($productTable, 'fk_unit');
		$unitsHasRowid = $this->tableColumnExists($unitsTable, 'rowid');
		if (!$productHasUnit || !$unitsHasRowid) {
			return array();
		}

		$unitsHasCode = $this->tableColumnExists($unitsTable, 'code');
		$unitsHasShortLabel = $this->tableColumnExists($unitsTable, 'short_label');
		$unitsHasLabel = $this->tableColumnExists($unitsTable, 'label');

		$sql = "SELECT p.rowid AS product_id";
		$sql .= ($unitsHasCode ? ", u.code AS unit_code" : ", '' AS unit_code");
		$sql .= ($unitsHasShortLabel ? ", u.short_label AS unit_short" : ", '' AS unit_short");
		$sql .= ($unitsHasLabel ? ", u.label AS unit_label" : ", '' AS unit_label");
		$sql .= " FROM " . $productTable . " AS p";
		$sql .= " LEFT JOIN " . $unitsTable . " AS u ON u.rowid = p.fk_unit";
		$sql .= " WHERE p.rowid IN (" . implode(',', $cleanIds) . ")";
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("KreaProductsApi::loadProductUnitMap SQL error: " . $this->db->lasterror(), LOG_WARNING);
			return array();
		}

		$unitsByProduct = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$productId = (int) $obj->product_id;
			if ($productId <= 0) {
				continue;
			}

			$unitCode = trim((string) $obj->unit_code);
			$unitShort = trim((string) $obj->unit_short);
			$unitLabel = trim((string) $obj->unit_label);

			if ($unitShort === '') {
				$unitShort = ($unitCode !== '' ? $unitCode : $unitLabel);
			}
			if ($unitLabel === '') {
				$unitLabel = ($unitShort !== '' ? $unitShort : $unitCode);
			}
			if ($unitCode === '' && $unitShort !== '') {
				$unitCode = $unitShort;
			}

			$unitsByProduct[$productId] = array(
				'code' => $unitCode,
				'short' => $unitShort,
				'label' => $unitLabel,
			);
		}
		$this->db->free($resql);

		return $unitsByProduct;
	}

	/**
	 * Check if recipe/component unit auto-scaling is enabled in module setup.
	 *
	 * @return bool
	 */
	protected function isRecipeUnitAutoScaleEnabled()
	{
		global $conf;

		if (!isset($conf->global->KREAPRODUCTS_AUTO_SCALE_RECIPE_UNITS)) {
			return true;
		}

		$raw = strtolower(trim((string) $conf->global->KREAPRODUCTS_AUTO_SCALE_RECIPE_UNITS));
		if ($raw === '') {
			return true;
		}

		if (in_array($raw, array('0', 'false', 'off', 'no'), true)) {
			return false;
		}

		return true;
	}

	/**
	 * Populate display quantity/unit fields for one recipe line without changing base qty.
	 *
	 * @param array<string,mixed> $line Recipe line
	 * @return void
	 */
	protected function applyRecipeLineDisplayUnitScaling(&$line)
	{
		if (!is_array($line)) {
			return;
		}

		$baseQty = (float) price2num((isset($line['qty']) ? $line['qty'] : 0), 'MS');
		$baseUnit = trim((string) (isset($line['component_unit']) ? $line['component_unit'] : ''));
		$baseUnitCode = trim((string) (isset($line['component_unit_code']) ? $line['component_unit_code'] : ''));
		$baseUnitLabel = trim((string) (isset($line['component_unit_label']) ? $line['component_unit_label'] : ''));

		$line['qty_display'] = $baseQty;
		$line['component_unit_display'] = $baseUnit;
		$line['component_unit_code_display'] = $baseUnitCode;
		$line['component_unit_label_display'] = $baseUnitLabel;

		if (!$this->isRecipeUnitAutoScaleEnabled()) {
			return;
		}

		$meta = $this->resolveRecipeUnitAutoScaleMeta($baseUnitCode, $baseUnit, $baseUnitLabel);
		if (empty($meta)) {
			return;
		}

		$canonicalQty = $baseQty * ((float) $meta['factor_to_canonical']);
		$absCanonicalQty = abs($canonicalQty);
		$showSmallUnit = ($absCanonicalQty < 1);

		if ($showSmallUnit) {
			$line['qty_display'] = (float) price2num($canonicalQty * 1000, 'MS');
			$line['component_unit_display'] = (string) $meta['small_short'];
			$line['component_unit_code_display'] = (string) $meta['small_code'];
			$line['component_unit_label_display'] = (string) $meta['small_label'];
			return;
		}

		$line['qty_display'] = (float) price2num($canonicalQty, 'MS');
		$line['component_unit_display'] = (string) $meta['canonical_short'];
		$line['component_unit_code_display'] = (string) $meta['canonical_code'];
		$line['component_unit_label_display'] = (string) $meta['canonical_label'];
	}

	/**
	 * Resolve quantity scaling metadata for a unit token.
	 *
	 * @param string $unitCode
	 * @param string $unitShort
	 * @param string $unitLabel
	 * @return array<string,mixed>
	 */
	protected function resolveRecipeUnitAutoScaleMeta($unitCode, $unitShort, $unitLabel)
	{
		$tokens = array(
			$this->normalizeRecipeUnitToken($unitCode),
			$this->normalizeRecipeUnitToken($unitShort),
			$this->normalizeRecipeUnitToken($unitLabel),
		);

		$massFactors = array(
			'kg' => 1.0,
			'kilogram' => 1.0,
			'kilograms' => 1.0,
			'kilograma' => 1.0,
			'kilogramas' => 1.0,
			'g' => 0.001,
			'gram' => 0.001,
			'grams' => 0.001,
			'grama' => 0.001,
			'gramas' => 0.001,
			'mg' => 0.000001,
			'milligram' => 0.000001,
			'milligrams' => 0.000001,
			'miligrama' => 0.000001,
			'miligramas' => 0.000001,
		);

		$volumeFactors = array(
			'l' => 1.0,
			'lt' => 1.0,
			'ltr' => 1.0,
			'liter' => 1.0,
			'liters' => 1.0,
			'litre' => 1.0,
			'litres' => 1.0,
			'litro' => 1.0,
			'litros' => 1.0,
			'ml' => 0.001,
			'milliliter' => 0.001,
			'milliliters' => 0.001,
			'millilitre' => 0.001,
			'millilitres' => 0.001,
			'mililitro' => 0.001,
			'mililitros' => 0.001,
			'cl' => 0.01,
			'centiliter' => 0.01,
			'centiliters' => 0.01,
			'centilitre' => 0.01,
			'centilitres' => 0.01,
			'centilitro' => 0.01,
			'centilitros' => 0.01,
			'dl' => 0.1,
			'deciliter' => 0.1,
			'deciliters' => 0.1,
			'decilitre' => 0.1,
			'decilitres' => 0.1,
			'decilitro' => 0.1,
			'decilitros' => 0.1,
		);

		foreach ($tokens as $token) {
			if ($token === '') {
				continue;
			}

			if (isset($massFactors[$token])) {
				return array(
					'type' => 'mass',
					'factor_to_canonical' => (float) $massFactors[$token],
					'canonical_short' => 'kg',
					'canonical_code' => 'kg',
					'canonical_label' => 'Kilogram',
					'small_short' => 'g',
					'small_code' => 'g',
					'small_label' => 'Gram',
				);
			}

			if (isset($volumeFactors[$token])) {
				return array(
					'type' => 'volume',
					'factor_to_canonical' => (float) $volumeFactors[$token],
					'canonical_short' => 'l',
					'canonical_code' => 'l',
					'canonical_label' => 'Liter',
					'small_short' => 'ml',
					'small_code' => 'ml',
					'small_label' => 'Milliliter',
				);
			}
		}

		return array();
	}

	/**
	 * Normalize one unit token for matching.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function normalizeRecipeUnitToken($value)
	{
		$value = trim(strtolower((string) $value));
		if ($value === '') {
			return '';
		}
		if (function_exists('dol_string_unaccent')) {
			$value = dol_string_unaccent($value);
		}

		return preg_replace('/[^a-z0-9]+/', '', $value);
	}

	/**
	 * Load one product extrafield text value map by product id.
	 *
	 * @param array<int> $productIds Product ids
	 * @param string $fieldName Extra field column name
	 * @return array<int,string>
	 */
	protected function loadProductExtrafieldTextMap($productIds, $fieldName)
	{
		$fieldName = trim((string) $fieldName);
		if ($fieldName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
			return array();
		}

		$cleanIds = array();
		foreach ((array) $productIds as $id) {
			if (!is_numeric($id)) {
				continue;
			}
			$id = (int) $id;
			if ($id > 0) {
				$cleanIds[$id] = $id;
			}
		}
		if (empty($cleanIds)) {
			return array();
		}

		$table = MAIN_DB_PREFIX . 'product_extrafields';
		if (!$this->tableColumnExists($table, $fieldName)) {
			return array();
		}
		$hasEntityColumn = $this->tableColumnExists($table, 'entity');

		$sql = "SELECT fk_object, " . $fieldName;
		if ($hasEntityColumn) {
			$sql .= ", entity";
		}
		$sql .= " FROM " . $table;
		$sql .= " WHERE fk_object IN (" . implode(',', $cleanIds) . ")";
		if ($hasEntityColumn) {
			$sql .= " AND entity IN (0," . getEntity('product') . ")";
		}
		$sql .= " ORDER BY fk_object ASC";
		if ($hasEntityColumn) {
			$sql .= ", entity DESC";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("KreaProductsApi::loadProductExtrafieldTextMap SQL error: " . $this->db->lasterror(), LOG_WARNING);
			return array();
		}

		$values = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$productId = (int) $obj->fk_object;
			if ($productId <= 0 || isset($values[$productId])) {
				continue;
			}

			$raw = '';
			if (isset($obj->{$fieldName})) {
				$raw = trim((string) $obj->{$fieldName});
			}
			if ($raw === '') {
				continue;
			}

			$values[$productId] = $raw;
		}
		$this->db->free($resql);

		return $values;
	}

	/**
	 * Load one product extrafield boolean value map by product id.
	 *
	 * This helper preserves rows where the value is null/empty and normalizes
	 * them to "0" so unchecked Dolibarr booleans are treated as disabled.
	 *
	 * @param array<int> $productIds Product ids
	 * @param string $fieldName Extra field column name
	 * @return array<int,string> Product id => "0"|"1"
	 */
	protected function loadProductExtrafieldBooleanMap($productIds, $fieldName)
	{
		$fieldName = trim((string) $fieldName);
		if ($fieldName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
			return array();
		}

		$cleanIds = array();
		foreach ((array) $productIds as $id) {
			if (!is_numeric($id)) {
				continue;
			}
			$id = (int) $id;
			if ($id > 0) {
				$cleanIds[$id] = $id;
			}
		}
		if (empty($cleanIds)) {
			return array();
		}

		$table = MAIN_DB_PREFIX . 'product_extrafields';
		if (!$this->tableColumnExists($table, $fieldName)) {
			return array();
		}
		$hasEntityColumn = $this->tableColumnExists($table, 'entity');

		$sql = "SELECT fk_object, " . $fieldName;
		if ($hasEntityColumn) {
			$sql .= ", entity";
		}
		$sql .= " FROM " . $table;
		$sql .= " WHERE fk_object IN (" . implode(',', $cleanIds) . ")";
		if ($hasEntityColumn) {
			$sql .= " AND entity IN (0," . getEntity('product') . ")";
		}
		$sql .= " ORDER BY fk_object ASC";
		if ($hasEntityColumn) {
			$sql .= ", entity DESC";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("KreaProductsApi::loadProductExtrafieldBooleanMap SQL error: " . $this->db->lasterror(), LOG_WARNING);
			return array();
		}

		$values = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$productId = (int) $obj->fk_object;
			if ($productId <= 0 || isset($values[$productId])) {
				continue;
			}

			$raw = null;
			if (property_exists($obj, $fieldName)) {
				$raw = $obj->{$fieldName};
			}

			$values[$productId] = $this->normalizeBooleanExtrafieldValue($raw, '0');
		}
		$this->db->free($resql);

		return $values;
	}

	/**
	 * Normalize Dolibarr extrafield boolean-ish value to "0"|"1".
	 *
	 * @param mixed $rawValue Raw DB value
	 * @param string $defaultValue Fallback normalized value
	 * @return string
	 */
	protected function normalizeBooleanExtrafieldValue($rawValue, $defaultValue = '0')
	{
		if ($rawValue === null) {
			return $defaultValue;
		}

		$normalized = trim((string) $rawValue);
		if ($normalized === '') {
			return $defaultValue;
		}

		$lower = strtolower($normalized);
		if (in_array($lower, array('1', 'true', 'yes', 'on'), true)) {
			return '1';
		}
		if (in_array($lower, array('0', 'false', 'no', 'off'), true)) {
			return '0';
		}

		if (is_numeric($normalized)) {
			return ((float) $normalized != 0.0 ? '1' : '0');
		}

		return $defaultValue;
	}

	/**
	 * Apply product alias (`kreap_alias`) as runtime product label when available.
	 *
	 * @param object $product Product object with id/label props
	 * @return string Resolved alias (empty when not defined)
	 */
	protected function applyProductAliasToLabel($product)
	{
		if (!is_object($product) || empty($product->id)) {
			return '';
		}

		$productId = (int) $product->id;
		if ($productId <= 0) {
			return '';
		}

		$aliases = $this->loadProductExtrafieldTextMap(array($productId), 'kreap_alias');
		$alias = (!empty($aliases[$productId]) ? trim((string) $aliases[$productId]) : '');
		if ($alias === '') {
			return '';
		}

		$product->label = $alias;
		$product->kreap_alias = $alias;
		return $alias;
	}

	/**
	 * Sanitize one label template code for API-side product storage handling.
	 *
	 * @param string $templateCode Raw template code
	 * @return string
	 */
	protected function sanitizeLabelTemplateCode($templateCode)
	{
		$templateCode = strtolower(trim((string) $templateCode));
		if ($templateCode === '' || preg_match('/^[a-z0-9_.-]+$/', $templateCode) !== 1) {
			return '';
		}

		return $templateCode;
	}

	/**
	 * Sanitize one template source key for API-side product storage handling.
	 *
	 * @param string $source Raw source key
	 * @return string
	 */
	protected function sanitizeLabelTemplateSource($source)
	{
		$source = strtolower(trim((string) $source));
		if ($source === '' || preg_match('/^[a-z0-9_.-]+$/', $source) !== 1) {
			return '';
		}

		return $source;
	}

	/**
	 * Parse product-level label storage payload from `kreap_default_label_layout`.
	 *
	 * @param string $rawValue Raw extrafield value
	 * @return array
	 */
	protected function parseProductLabelStoragePayload($rawValue)
	{
		$payload = array(
			'default_label_layout' => '',
			'template_values' => array(),
		);

		$rawValue = trim((string) $rawValue);
		if ($rawValue === '') {
			return $payload;
		}

		$decoded = json_decode($rawValue, true);
		if (!is_array($decoded)) {
			$payload['default_label_layout'] = $this->sanitizeLabelTemplateCode($rawValue);
			return $payload;
		}

		$defaultLayoutRaw = '';
		if (isset($decoded['default_label_layout'])) {
			$defaultLayoutRaw = (string) $decoded['default_label_layout'];
		} elseif (isset($decoded['default_layout'])) {
			$defaultLayoutRaw = (string) $decoded['default_layout'];
		}
		$payload['default_label_layout'] = $this->sanitizeLabelTemplateCode($defaultLayoutRaw);

		if (!empty($decoded['template_values']) && is_array($decoded['template_values'])) {
			foreach ($decoded['template_values'] as $templateCode => $sourceValues) {
				$sanitizedTemplateCode = $this->sanitizeLabelTemplateCode($templateCode);
				if ($sanitizedTemplateCode === '' || !is_array($sourceValues)) {
					continue;
				}

				$cleanSourceValues = array();
				foreach ($sourceValues as $source => $value) {
					$sanitizedSource = $this->sanitizeLabelTemplateSource($source);
					if ($sanitizedSource === '' || is_array($value) || is_object($value)) {
						continue;
					}

					$cleanValue = (string) $value;
					if (trim($cleanValue) === '') {
						continue;
					}

					$cleanSourceValues[$sanitizedSource] = $cleanValue;
				}

				if (!empty($cleanSourceValues)) {
					$payload['template_values'][$sanitizedTemplateCode] = $cleanSourceValues;
				}
			}
		}

		return $payload;
	}

	/**
	 * Load product label storage payload for one product.
	 *
	 * @param int $productId Product id
	 * @return array
	 */
	protected function loadProductLabelStoragePayload($productId)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return $this->parseProductLabelStoragePayload('');
		}

		$layouts = $this->loadProductExtrafieldTextMap(array($productId), 'kreap_default_label_layout');
		return $this->parseProductLabelStoragePayload(!empty($layouts[$productId]) ? (string) $layouts[$productId] : '');
	}

	/**
	 * Resolve effective label template code for one product.
	 *
	 * Priority:
	 * 1) Explicit API request template code
	 * 2) Product extrafield `kreap_default_label_layout`
	 * 3) Global fallback `KREAPRODUCTS_LABELS_DEFAULT_TEMPLATE_CODE`
	 *
	 * @param object $product Product object with id
	 * @param string $requestedTemplateCode Raw requested template code
	 * @return string
	 */
	protected function resolveLabelTemplateCode($product, $requestedTemplateCode)
	{
		global $conf;

		$templateCode = $this->sanitizeLabelTemplateCode($requestedTemplateCode);
		if ($templateCode !== '') {
			return $templateCode;
		}

		$productId = (is_object($product) && !empty($product->id) ? (int) $product->id : 0);
		if ($productId > 0) {
			$labelStoragePayload = $this->loadProductLabelStoragePayload($productId);
			if (!empty($labelStoragePayload['default_label_layout'])) {
				return (string) $labelStoragePayload['default_label_layout'];
			}
		}

		$globalDefault = trim((string) (!empty($conf->global->KREAPRODUCTS_LABELS_DEFAULT_TEMPLATE_CODE) ? $conf->global->KREAPRODUCTS_LABELS_DEFAULT_TEMPLATE_CODE : ''));
		$globalDefault = $this->sanitizeLabelTemplateCode($globalDefault);
		if ($globalDefault !== '') {
			return $globalDefault;
		}

		return '';
	}

	/**
	 * Resolve template values by merging product defaults and request overrides.
	 *
	 * Product-level values are saved from the label tab and must be honored by
	 * KreaProduction. Request values remain higher priority for runtime fields
	 * such as production dates and produced batch.
	 *
	 * @param object $product Product object with id
	 * @param string $templateCode Effective template code
	 * @param array  $requestedTemplateValues Raw request template values
	 * @return array
	 */
	protected function resolveLabelTemplateValues($product, $templateCode, $requestedTemplateValues)
	{
		$templateCode = $this->sanitizeLabelTemplateCode($templateCode);
		$mergedValues = array();

		$productId = (is_object($product) && !empty($product->id) ? (int) $product->id : 0);
		if ($productId > 0 && $templateCode !== '') {
			$labelStoragePayload = $this->loadProductLabelStoragePayload($productId);
			if (!empty($labelStoragePayload['template_values'][$templateCode]) && is_array($labelStoragePayload['template_values'][$templateCode])) {
				$mergedValues = $labelStoragePayload['template_values'][$templateCode];
			}
		}

		if (is_array($requestedTemplateValues)) {
			foreach ($requestedTemplateValues as $source => $value) {
				$sanitizedSource = $this->sanitizeLabelTemplateSource($source);
				if ($sanitizedSource === '' || is_array($value) || is_object($value)) {
					continue;
				}

				$cleanValue = (string) $value;
				if (trim($cleanValue) === '') {
					continue;
				}

				$mergedValues[$sanitizedSource] = $cleanValue;
			}
		}

		return $mergedValues;
	}

	/**
	 * Load `kreap_recipe` from product extrafields.
	 *
	 * @param int $productId Product id
	 * @return string
	 */
	protected function loadProductRecipeText($productId)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return '';
		}

		$map = $this->loadProductExtrafieldTextMap(array($productId), 'kreap_recipe');
		if (empty($map[$productId])) {
			return '';
		}

		return trim((string) $map[$productId]);
	}

	/**
	 * Check whether one table has a given column.
	 *
	 * @param string $tableName Full database table name
	 * @param string $columnName Column name
	 * @return bool
	 */
	protected function tableColumnExists($tableName, $columnName)
	{
		static $cache = array();

		$tableName = trim((string) $tableName);
		$columnName = trim((string) $columnName);
		if ($tableName === '' || $columnName === '') {
			return false;
		}

		$key = $tableName . '|' . $columnName;
		if (array_key_exists($key, $cache)) {
			return !empty($cache[$key]);
		}

		$exists = false;
		$desc = $this->db->DDLDescTable($tableName);
		if ($desc) {
			while ($obj = $this->db->fetch_object($desc)) {
				$field = '';
				if (!empty($obj->Field)) {
					$field = (string) $obj->Field;
				} elseif (!empty($obj->field)) {
					$field = (string) $obj->field;
				} elseif (!empty($obj->name)) {
					$field = (string) $obj->name;
				}
				if ($field === $columnName) {
					$exists = true;
					break;
				}
			}
			$this->db->free($desc);
		}

		$cache[$key] = ($exists ? 1 : 0);
		return $exists;
	}

	/**
	 * Build one category node recursively.
	 *
	 * @param int   $categoryId
	 * @param array $categoriesById
	 * @param array $childrenMap
	 * @param array $productsByCategory
	 * @return array
	 */
	protected function buildCategoryTreeNode($categoryId, $categoriesById, $childrenMap, $productsByCategory)
	{
		if (empty($categoriesById[$categoryId])) {
			return array();
		}

		$cat = $categoriesById[$categoryId];
		$node = array(
			'id' => (int) $cat['id'],
			'label' => (string) $cat['label'],
			'fk_parent' => (int) $cat['fk_parent'],
			'description' => (string) $cat['description'],
			'color' => (string) $cat['color'],
			'entity' => (int) $cat['entity'],
			'products' => (!empty($productsByCategory[$categoryId]) ? $productsByCategory[$categoryId] : array()),
			'children' => array(),
		);

		if (!empty($childrenMap[$categoryId])) {
			foreach ($childrenMap[$categoryId] as $childId) {
				$childNode = $this->buildCategoryTreeNode((int) $childId, $categoriesById, $childrenMap, $productsByCategory);
				if (!empty($childNode)) {
					$node['children'][] = $childNode;
				}
			}
		}

		return $node;
	}

	/**
	 * Accumulate tree totals for API response.
	 *
	 * @param array $node
	 * @param array $stats
	 * @return void
	 */
	protected function accumulateCategoryTreeStats($node, &$stats)
	{
		if (empty($node) || !is_array($node)) {
			return;
		}

		$stats['categories_count']++;
		if (!empty($node['products']) && is_array($node['products'])) {
			$stats['products_count'] += count($node['products']);
			foreach ($node['products'] as $product) {
				if (!empty($product['has_enabled_bom'])) {
					$stats['producible_products_count']++;
				}
			}
		}

		if (!empty($node['children']) && is_array($node['children'])) {
			foreach ($node['children'] as $childNode) {
				$this->accumulateCategoryTreeStats($childNode, $stats);
			}
		}
	}
}

/**
 * Backward-compatible API class alias for /api/index.php/kreaproducts/... door path.
 * Dolibarr resolves this door with classname "Kreaproducts".
 *
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class Kreaproducts extends KreaProductsApi
{
}
