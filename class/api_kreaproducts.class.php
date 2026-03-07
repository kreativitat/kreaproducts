<?php
/* Copyright (C) 2026       Kreativitat             <mail@kreativitat.com>
 *
 * This program is dual-licensed under the GNU General Public License (GPL) v3.0 and a proprietary license.
 *
 * GPL-3.0 License:
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
 * Proprietary License:
 * For commercial use, support, or if you prefer not to disclose your source code modifications,
 * please contact Kreativitat at <mail@kreativitat.com> for information on purchasing a proprietary license.
 *
 * For more information, visit <https://www.kreativitat.com>.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
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
	 * @var DoliDB
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
			throw new RestException(503, 'Error when loading production categories: ' . $this->db->lasterror());
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
			throw new RestException(503, 'Error when loading products for category: ' . $this->db->lasterror());
		}

		$products = array();
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
		}
		$this->db->free($resql);

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
	public function postProductionLabelPdf($product_id, $request_data = null)
	{
		global $langs, $conf;

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
			throw new RestException(500, 'Error generating labels PDF: ' . $generated['error']);
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
	}

	/**
	 * Run one production operation and return label payload for the produced lot.
	 *
	 * Request body example:
	 * {
	 *   "category_id": 12,
	 *   "product_id": 345,
	 *   "qty": 120,
	 *   "warehouse_id": 1,
	 *   "bom_id": 0,
	 *   "inventorylabel": "Touch production",
	 *   "inventorycode": "TOUCH-20260307-001",
	 *   "autoclose": 1,
	 *   "units_per_label": 1,
	 *   "labels_count": 120,
	 *   "template_code": "degema_normal",
	 *   "template_values": {}
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
		$warehouseId = (int) (isset($request_data['warehouse_id']) ? $request_data['warehouse_id'] : (isset($request_data['fk_warehouse']) ? $request_data['fk_warehouse'] : 0));
		$requestedBomId = (int) (isset($request_data['bom_id']) ? $request_data['bom_id'] : (isset($request_data['fk_bom']) ? $request_data['fk_bom'] : 0));
		$autoClose = (!empty($request_data['autoclose']) ? 1 : 0);
		$unitsPerLabel = price2num(isset($request_data['units_per_label']) ? $request_data['units_per_label'] : 1, 'MS');
		$labelsCount = (int) (isset($request_data['labels_count']) ? $request_data['labels_count'] : 0);
		$templateCode = trim((string) (isset($request_data['template_code']) ? $request_data['template_code'] : ''));
		$templateValues = (!empty($request_data['template_values']) && is_array($request_data['template_values']) ? $request_data['template_values'] : array());
		$langcode = trim((string) (isset($request_data['langcode']) ? $request_data['langcode'] : ''));

		if ($moId <= 0 && $productId <= 0) {
			throw new RestException(400, 'Missing product_id or mo_id');
		}
		if ($moId <= 0 && $qty <= 0) {
			throw new RestException(400, 'Production qty must be greater than 0');
		}
		if ($warehouseId <= 0) {
			throw new RestException(400, 'Missing warehouse_id');
		}

		$mo = new Mo($this->db);
		$product = null;
		$bomIdUsed = 0;
		$moWasCreated = false;

		if ($moId > 0) {
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

			if ($hasQtyInput && (int) $mo->status === Mo::STATUS_DRAFT && (float) $mo->qty !== (float) $qty) {
				$mo->oldQty = $mo->qty;
				$mo->qty = (float) $qty;
				if ($mo->update(DolibarrApiAccess::$user) <= 0) {
					throw new RestException(500, 'Error updating MO quantity: ' . $mo->error);
				}
				$mo->fetch($mo->id);
			} elseif ($hasQtyInput && (int) $mo->status !== Mo::STATUS_DRAFT && (float) $mo->qty !== (float) $qty) {
				throw new RestException(409, 'Provided qty does not match existing non-draft MO quantity');
			}
		} else {
			$product = $this->fetchProduct($productId);
			if ($categoryId > 0) {
				$this->assertProductInCategory((int) $product->id, $categoryId);
			}
			if (!empty($product->status_batch)) {
				throw new RestException(409, 'Batch-managed products are not supported by this API workflow yet');
			}
			$bomIdUsed = $this->resolveBomForProduct($product, $requestedBomId);

			$mo->ref = '(PROV)';
			$mo->fk_product = $product->id;
			$mo->qty = (float) $qty;
			$mo->fk_warehouse = $warehouseId;
			$mo->fk_bom = $bomIdUsed;
			if (!empty($request_data['label'])) {
				$mo->label = trim((string) $request_data['label']);
			}

			$newId = $mo->create(DolibarrApiAccess::$user);
			if ($newId <= 0) {
				throw new RestException(500, 'Error creating MO: ' . $mo->error);
			}
			$moWasCreated = true;
			$mo->fetch($newId);
		}

		if ((int) $mo->status === Mo::STATUS_DRAFT) {
			$validateResult = $mo->validate(DolibarrApiAccess::$user);
			if ($validateResult <= 0) {
				throw new RestException(500, 'Error validating MO: ' . $mo->error);
			}
			$mo->fetch($mo->id);
		}

		if ((int) $mo->status !== Mo::STATUS_VALIDATED && (int) $mo->status !== Mo::STATUS_INPROGRESS) {
			throw new RestException(409, 'MO status does not allow production');
		}

		$mo->fetchLines();
		$arrayToConsume = $this->buildMoProductionPayloadByRole($mo->lines, 'toconsume', $warehouseId);
		$arrayToProduce = $this->buildMoProductionPayloadByRole($mo->lines, 'toproduce', $warehouseId);
		if (empty($arrayToProduce)) {
			throw new RestException(409, 'MO has no line to produce');
		}

		$inventoryLabel = trim((string) (!empty($request_data['inventorylabel']) ? $request_data['inventorylabel'] : 'Touch production ' . (!empty($product->ref) ? $product->ref : $product->id)));
		$inventoryCode = trim((string) (!empty($request_data['inventorycode']) ? $request_data['inventorycode'] : 'KREAPROD-' . dol_print_date(dol_now(), '%Y%m%d%H%M%S')));

		$mosApi = new Mos();
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
		$labelPayload = $this->buildLabelPayload($product, $qty, $unitsPerLabel, $labelsCount, $templateCode, $templateValues, $langcode);

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
			'stock_updated' => true,
			'label_payload' => $labelPayload,
		);
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
			throw new RestException(503, 'Error loading BOM for product: ' . $this->db->lasterror());
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
	 * @return array
	 */
	protected function buildMoProductionPayloadByRole($lines, $role, $defaultWarehouseId)
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
				$entry['fk_warehouse'] = $warehouseId;
			}

			$payload[] = $entry;
		}

		return $payload;
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
		);

		$templateCode = trim((string) $templateCode);
		if ($templateCode !== '') {
			$template = KreaProductsLabelService::loadLabelTemplate($templateCode, $entityId);
			if (!empty($template)) {
				$templateMeta = KreaProductsLabelService::getTemplateMeta($templateCode, $entityId);
				$selectedTemplate['code'] = $templateCode;
				$selectedTemplate['meta'] = $this->sanitizeTemplateMeta($templateCode, $templateMeta);
				$selectedTemplate['editable_fields'] = KreaProductsLabelService::getTemplateEditableFields($template, $product, $outputlangs, (is_array($templateValues) ? $templateValues : array()));
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
			return $explicit;
		}

		$qty = (float) price2num($productionQty, 'MS');
		if ($qty <= 0) {
			$qty = 1.0;
		}

		$perLabel = (float) price2num($unitsPerLabel, 'MS');
		if ($perLabel <= 0) {
			$perLabel = 1.0;
		}

		return max(1, (int) ceil($qty / $perLabel));
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
			throw new RestException(503, 'Error while validating category/product link: ' . $this->db->lasterror());
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
			throw new RestException(503, 'Error when loading category tree: ' . $this->db->lasterror());
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
			throw new RestException(503, 'Error when loading category products: ' . $this->db->lasterror());
		}

		$productsByCategory = array();
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
		}
		$this->db->free($resql);

		return $productsByCategory;
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
