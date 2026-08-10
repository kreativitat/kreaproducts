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
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */

/**
 *  \file       htdocs/custom/kreaproducts/associatedProducts.php
 *  \ingroup    product
 *  \brief      Page of product file
 */

// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php'))    $res = @include __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = @include __DIR__ . '/../../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../master.inc.php'))  $res = @include __DIR__ . '/../master.inc.php';
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) $res = @include __DIR__ . '/../../master.inc.php';
if (!$res) die('Failed to include main.inc.php');
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/parsemd.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
dol_include_once('/kreaproducts/class/KreaProductsNutrientUpdater.class.php');
dol_include_once('/kreaproducts/class/KreaProductsAllergenUpdater.class.php');
dol_include_once('/kreaproducts/class/KreaProductsLlmProductDataService.class.php');
dol_include_once('/kreaproducts/lib/kreaproducts.lib.php');

// Load translation files required by the page
$langs->loadLangs(array('bills', 'products', 'stocks', 'other', 'dolizsynch@dolizsynch', 'kreaproducts@kreaproducts'));

if (!function_exists('kreaproducts_debug_log')) {
	function kreaproducts_debug_log($message)
	{
		global $conf;
		if (!empty($conf->global->KREAPRODUCTS_DEBUG_LOG)) {
			dol_syslog($message, LOG_DEBUG);
		}
	}
}

if (!function_exists('kreaproducts_get_accessible_entities')) {
	function kreaproducts_get_accessible_entities()
	{
		global $conf, $mc, $user;

		$entityIds = array((int) $conf->entity);
		if (isModEnabled('multicompany') && is_object($mc)) {
			$canAccessAll = (!empty($user->admin) && empty($user->entity));
			if (!empty($conf->global->MULTICOMPANY_TRANSVERSE_MODE) || $canAccessAll) {
				$list = $mc->getEntitiesList(false, false, true);
				if (!empty($list)) {
					$entityIds = array_map('intval', array_keys($list));
				}
			}
		}

		$entityIds = array_values(array_unique(array_filter($entityIds, 'is_numeric')));
		return $entityIds;
	}
}

if (!function_exists('kreaproducts_select_produits_with_entities')) {
	function kreaproducts_select_produits_with_entities($form, $selected, $htmlname, $entityList, $langs, $morecss = 'minwidth300')
	{
		$entityList = array_values(array_unique(array_filter($entityList, 'is_numeric')));
		$method = new ReflectionMethod($form, 'select_produits');
		$args = array();

		foreach ($method->getParameters() as $param) {
			switch ($param->getName()) {
				case 'selected':
					$args[] = $selected;
					break;
				case 'htmlname':
					$args[] = $htmlname;
					break;
				case 'filtertype':
					$args[] = '';
					break;
				case 'limit':
					$args[] = 0;
					break;
				case 'price_level':
					$args[] = 0;
					break;
				case 'status':
					$args[] = -1;
					break;
				case 'finished':
					$args[] = 2;
					break;
				case 'selected_input_value':
					$args[] = '';
					break;
				case 'hidelabel':
					$args[] = 0;
					break;
				case 'ajaxoptions':
					$args[] = array();
					break;
				case 'socid':
					$args[] = 0;
					break;
				case 'showempty':
					$args[] = $langs->trans("RefOrLabel");
					break;
				case 'forcecombo':
					$args[] = 0;
					break;
				case 'morecss':
					$args[] = $morecss;
					break;
				case 'hidepriceinlabel':
					$args[] = 0;
					break;
				case 'warehouseStatus':
					$args[] = '';
					break;
				case 'selected_combinations':
					$args[] = null;
					break;
				case 'nooutput':
					$args[] = 1;
					break;
				case 'status_purchase':
					$args[] = -1;
					break;
				case 'warehouseId':
					$args[] = 0;
					break;
				case 'entitylist':
				case 'entityList':
					$args[] = $entityList;
					break;
				default:
					$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
			}
		}

		return $method->invokeArgs($form, $args);
	}
}

if (!function_exists('kreaproducts_weight_to_kg')) {
	function kreaproducts_weight_to_kg($weight, $weightUnit)
	{
		$weight = (float) price2num($weight, 'MS');
		if ($weight <= 0) {
			return 0.0;
		}

		if (is_numeric($weightUnit)) {
			$unitScale = (int) $weightUnit;
			switch ($unitScale) {
				case 98: // ounce
					return $weight / 35.274;
				case 99: // pound
					return $weight / 2.20462;
				default:
					// Dolibarr stores mass scale powers around kilogram base (-3 g, 0 kg, 3 t).
					return $weight * pow(10, $unitScale);
			}
		}

		$weightUnit = strtolower(trim((string) $weightUnit));
		switch ($weightUnit) {
			case 'kg':
				return $weight;
			case 'g':
				return $weight / 1000;
			case 'mg':
				return $weight / 1000000;
			case 'lb':
			case 'lbs':
				return $weight / 2.20462;
			case 'oz':
				return $weight / 35.274;
			default:
				return $weight;
		}
	}
}

if (!function_exists('kreaproducts_has_bom_for_product')) {
	function kreaproducts_has_bom_for_product($db, $productId)
	{
		global $conf;

		$productId = (int) $productId;
		if ($productId <= 0 || empty($conf->bom->enabled)) {
			return false;
		}

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom"
			. " WHERE fk_product = " . $productId
			. " AND bomtype = 1"
			. " AND status IN (0,1)"
			. " AND entity IN (0," . getEntity('bom') . ")"
			. " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("Error checking BOM for product " . $productId . ": " . $db->lasterror(), LOG_ERR);
			return false;
		}
		$hasBom = ($db->num_rows($resql) > 0);
		$db->free($resql);

		return $hasBom;
	}
}

if (!function_exists('kreaproducts_normalize_extrafield_options')) {
	function kreaproducts_normalize_extrafield_options($rawOptions)
	{
		if (is_array($rawOptions)) {
			return $rawOptions;
		}
		if (!is_string($rawOptions) || trim($rawOptions) === '') {
			return array();
		}
		$options = array();
		foreach (explode(',', $rawOptions) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			$kv = explode(':', $part, 2);
			$key = $kv[0];
			$label = isset($kv[1]) ? $kv[1] : $kv[0];
			$options[$key] = $label;
		}
		return $options;
	}
}

if (!function_exists('kreaproducts_get_product_extrafield_value')) {
	function kreaproducts_get_product_extrafield_value($db, $productId, $field, $entity = null)
	{
		static $hasEntityColumn = null;
		static $cache = array();

		$productId = (int) $productId;
		$field = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $field);
		if ($productId <= 0 || $field === '') {
			return null;
		}

		$cacheKey = $productId . ':' . $field . ':' . (int) $entity;
		if (array_key_exists($cacheKey, $cache)) {
			return $cache[$cacheKey];
		}

		if ($hasEntityColumn === null) {
			$hasEntityColumn = false;
			$colRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "entity");
			if ($colRes) {
				$hasEntityColumn = ($db->num_rows($colRes) > 0);
				$db->free($colRes);
			}
		}

		$sql = "SELECT " . $field;
		if ($hasEntityColumn) {
			$sql .= ", entity";
		}
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . $productId;
		if ($hasEntityColumn && $entity !== null) {
			$sql .= " AND entity IN (0," . (int) $entity . ") ORDER BY entity DESC";
		}
		$sql .= " LIMIT 1";

		$res = $db->query($sql);
		$value = null;
		if ($res && ($obj = $db->fetch_object($res))) {
			if (property_exists($obj, $field)) {
				$value = $obj->{$field};
			}
			$db->free($res);
		}

		$cache[$cacheKey] = $value;
		return $value;
	}
}

if (!function_exists('kreaproducts_product_extrafield_row_exists')) {
	function kreaproducts_product_extrafield_row_exists($db, $productId, $entity = null)
	{
		static $hasEntityColumn = null;
		static $cache = array();

		$productId = (int) $productId;
		if ($productId <= 0) {
			return false;
		}

		$cacheKey = $productId . ':' . (int) $entity;
		if (array_key_exists($cacheKey, $cache)) {
			return $cache[$cacheKey];
		}

		if ($hasEntityColumn === null) {
			$hasEntityColumn = false;
			$colRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "entity");
			if ($colRes) {
				$hasEntityColumn = ($db->num_rows($colRes) > 0);
				$db->free($colRes);
			}
		}

		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . $productId;
		if ($hasEntityColumn && $entity !== null) {
			$sql .= " AND entity IN (0," . (int) $entity . ") ORDER BY entity DESC";
		}
		$sql .= " LIMIT 1";

		$exists = false;
		$res = $db->query($sql);
		if ($res) {
			$exists = ($db->num_rows($res) > 0);
			$db->free($res);
		}

		$cache[$cacheKey] = $exists;
		return $exists;
	}
}

if (!function_exists('kreaproducts_set_product_extrafield_value')) {
	function kreaproducts_set_product_extrafield_value($db, $productId, $field, $value)
	{
		$productId = (int) $productId;
		$field = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $field);
		if ($productId <= 0 || $field === '') {
			return -1;
		}

		$componentEntity = null;
		$sqlComponent = "SELECT rowid, entity"
			. " FROM " . MAIN_DB_PREFIX . "product"
			. " WHERE rowid = " . $productId
			. " AND entity IN (" . getEntity('product') . ")"
			. " LIMIT 1";
		$resComponent = $db->query($sqlComponent);
		if ($resComponent && ($objComponent = $db->fetch_object($resComponent))) {
			$componentEntity = (int) $objComponent->entity;
		}
		if ($resComponent) {
			$db->free($resComponent);
		}
		if ($componentEntity === null) {
			return -1;
		}

		$hasEntityColumn = false;
		$colRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "entity");
		if ($colRes) {
			$hasEntityColumn = ($db->num_rows($colRes) > 0);
			$db->free($colRes);
		}

		if ($hasEntityColumn) {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, entity, " . $field . ") VALUES ("
				. $productId . ", " . $componentEntity . ", " . (int) $value . ") "
				. "ON DUPLICATE KEY UPDATE " . $field . " = " . (int) $value;
		} else {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, " . $field . ") VALUES ("
				. $productId . ", " . (int) $value . ") "
				. "ON DUPLICATE KEY UPDATE " . $field . " = " . (int) $value;
		}

		if (!$db->query($sql)) {
			dol_syslog("Error updating product extrafield " . $field . " for product " . $productId . ": " . $db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}
}

if (!function_exists('kreaproducts_normalize_extrafield_boolean')) {
	function kreaproducts_normalize_extrafield_boolean($rawValue, $defaultWhenNull = 1)
	{
		if ($rawValue === null) {
			return ((int) $defaultWhenNull > 0 ? 1 : 0);
		}

		$normalized = strtolower(trim((string) $rawValue));
		if ($normalized === '') {
			return 0;
		}
		if (in_array($normalized, array('1', 'true', 'on', 'yes'), true)) {
			return 1;
		}
		if (in_array($normalized, array('0', 'false', 'off', 'no'), true)) {
			return 0;
		}

		return ((int) $rawValue > 0 ? 1 : 0);
	}
}

if (!function_exists('kreaproducts_copy_nutritional_values_to_product')) {
	/**
	 * Copy saved nutritional values from one product to another.
	 *
	 * @return int 1 when values were copied, 0 when no source values exist, -1 on error
	 */
	function kreaproducts_copy_nutritional_values_to_product($db, $sourceProductId, $targetProductId, $user)
	{
		$sourceProductId = (int) $sourceProductId;
		$targetProductId = (int) $targetProductId;
		if ($sourceProductId <= 0 || $targetProductId <= 0) {
			return -1;
		}

		$entityList = getEntity('product');
		$sqlTarget = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sqlTarget .= " WHERE rowid = " . $targetProductId;
		$sqlTarget .= " AND entity IN (" . $entityList . ")";
		$sqlTarget .= " LIMIT 1";
		$resTarget = $db->query($sqlTarget);
		if (!$resTarget) {
			dol_syslog("Error checking target product entity before nutritional copy: " . $db->lasterror(), LOG_ERR);
			return -1;
		}
		$targetExists = ($db->num_rows($resTarget) > 0);
		$db->free($resTarget);
		if (!$targetExists) {
			dol_syslog("Blocked nutritional copy to inaccessible product ID " . $targetProductId, LOG_WARNING);
			return -1;
		}

		$fields = array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber');
		$sqlSource = "SELECT n." . implode(", n.", $fields);
		$sqlSource .= " FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional AS n";
		$sqlSource .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = n.fk_product";
		$sqlSource .= " WHERE n.fk_product = " . $sourceProductId;
		$sqlSource .= " AND p.entity IN (" . $entityList . ")";
		$sqlSource .= " ORDER BY n.rowid ASC LIMIT 1";
		$resSource = $db->query($sqlSource);
		if (!$resSource) {
			dol_syslog("Error reading source nutritional data: " . $db->lasterror(), LOG_ERR);
			return -1;
		}
		if ($db->num_rows($resSource) <= 0) {
			$db->free($resSource);
			return 0;
		}
		$sourceValues = $db->fetch_object($resSource);
		$db->free($resSource);

		$sqlExisting = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional";
		$sqlExisting .= " WHERE fk_product = " . $targetProductId;
		$sqlExisting .= " ORDER BY rowid ASC LIMIT 1";
		$resExisting = $db->query($sqlExisting);
		if (!$resExisting) {
			dol_syslog("Error checking target nutritional data: " . $db->lasterror(), LOG_ERR);
			return -1;
		}
		$existingRowId = 0;
		if ($db->num_rows($resExisting) > 0) {
			$objExisting = $db->fetch_object($resExisting);
			$existingRowId = (int) $objExisting->rowid;
		}
		$db->free($resExisting);

		if ($existingRowId <= 0) {
			$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional";
			$sqlInsert .= " (fk_product, date_creation, fk_user_creat)";
			$sqlInsert .= " VALUES (" . $targetProductId . ", '" . $db->idate(dol_now()) . "', " . (int) $user->id . ")";
			$resInsert = $db->query($sqlInsert);
			if (!$resInsert) {
				dol_syslog("Error creating target nutritional data: " . $db->lasterror(), LOG_ERR);
				return -1;
			}
			$existingRowId = (int) $db->last_insert_id(MAIN_DB_PREFIX . "kreaproducts_nutritional");
		}

		$setClauses = array();
		foreach ($fields as $field) {
			$value = isset($sourceValues->$field) ? $sourceValues->$field : null;
			$setClauses[] = ($value === null) ? $field . " = NULL" : $field . " = " . (float) $value;
		}

		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET " . implode(", ", $setClauses);
		$sqlUpdate .= " WHERE rowid = " . $existingRowId;
		$resUpdate = $db->query($sqlUpdate);
		if (!$resUpdate) {
			dol_syslog("Error copying nutritional data: " . $db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}
}

$id     = GETPOST('id', 'int');
$ref    = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel  = GETPOST('cancel', 'alpha');
$key     = GETPOST('key');
$parent  = GETPOST('parent');
$productIsFood = 1;
$enableCopyAvgToProduct = !isset($conf->global->KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT)
	|| !empty($conf->global->KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT);
$enableCopyAllergensToProduct = !isset($conf->global->KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT)
	|| !empty($conf->global->KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT);

// Security check
if (!empty($user->socid)) {
	$socid = $user->socid;
}
$fieldvalue = (!empty($id) ? $id : (!empty($ref) ? $ref : ''));
$fieldtype  = (!empty($ref) ? 'ref' : 'rowid');

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('productcompositioncard', 'globalcard'));

$object = new Product($db);
$objectid = 0;
if ($id > 0 || !empty($ref)) {
	$result = $object->fetch($id, $ref);
	// IMPORTANT: load all extra–fields so that current values are available.
	$object->fetch_optionals();
	$objectid = $object->id;
	$id = $object->id;
}
// Ensure calc extrafields are available even if fetch_optionals misses them.
if (!empty($object->id)) {
	if (!is_array($object->array_options)) {
		$object->array_options = array();
	}
	$needsNut = !array_key_exists('options_kreap_calc_nut', $object->array_options)
		|| $object->array_options['options_kreap_calc_nut'] === ''
		|| $object->array_options['options_kreap_calc_nut'] === null;
	$needsAllergens = !array_key_exists('options_kreap_calc_allergens', $object->array_options)
		|| $object->array_options['options_kreap_calc_allergens'] === ''
		|| $object->array_options['options_kreap_calc_allergens'] === null;
	if ($needsNut) {
		$valNut = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_nut', $object->entity);
		if ($valNut !== null) {
			$object->array_options['options_kreap_calc_nut'] = $valNut;
		}
	}
	if ($needsAllergens) {
		$valAll = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_allergens', $object->entity);
		if ($valAll !== null) {
			$object->array_options['options_kreap_calc_allergens'] = $valAll;
		}
	}
}

$result = restrictedArea($user, 'produit|service', $fieldvalue, 'product&product', '', '', $fieldtype);

if ($object->id > 0) {
	if ($object->type == $object::TYPE_PRODUCT) {
		restrictedArea($user, 'produit', $object->id, 'product&product', '', '');
	}
	if ($object->type == $object::TYPE_SERVICE) {
		restrictedArea($user, 'service', $object->id, 'product&product', '', '');
	}
} else {
	restrictedArea($user, 'produit|service', $fieldvalue, 'product&product', '', '', $fieldtype);
}
$usercanread   = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->lire) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'lire')));
$usercancreate = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->creer) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'creer')));
$usercandelete = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->supprimer) || ($object->type == Product::TYPE_SERVICE && $user->rights->service->supprimer));
$canUseLlmProductData = $usercancreate
	&& $user->hasRight('kreaproducts', 'nutritional', 'write')
	&& $user->hasRight('kreaproducts', 'productallergens', 'write');
$canManageNutritionAllergens = $usercancreate
	&& $user->hasRight('kreaproducts', 'nutritional', 'write')
	&& $user->hasRight('kreaproducts', 'productallergens', 'write');
$llmManualDataMode = isset($object->array_options['options_kreap_calc_nut'], $object->array_options['options_kreap_calc_allergens'])
	&& (string) $object->array_options['options_kreap_calc_nut'] === '0'
	&& (string) $object->array_options['options_kreap_calc_allergens'] === '0';
$llmSuggestion = null;
$llmSourceText = '';
$otherCharacteristicsSubmittedValues = array();
$otherCharacteristicsFieldDefinitions = array(
	'options_kreap_brand' => array('label' => 'kreap_brand_Inline', 'type' => 'text', 'maxlength' => 300),
	'options_kreap_video' => array('label' => 'kreap_video_Inline', 'type' => 'url', 'maxlength' => 300),
	'options_kreap_description' => array('label' => 'kreap_description_Inline', 'type' => 'textarea', 'format' => 'markdown', 'rows' => 5, 'maxlength' => 9999),
	'options_kreap_ingredients' => array('label' => 'kreap_ingredients_Inline', 'type' => 'textarea', 'format' => 'markdown', 'rows' => 5, 'maxlength' => 9999),
	'options_kreap_recipe' => array('label' => 'productRecipeInline', 'type' => 'textarea', 'format' => 'markdown', 'rows' => 7, 'maxlength' => 9999),
);

// Convert raw database extra-field values before any action, hook, renderer, or editor consumes them.
$object->array_options = kreaproducts_import_characteristic_database_values(
	$object->array_options ?? array(),
	$otherCharacteristicsFieldDefinitions
);

/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

$productWriteActions = array(
	'save_nutrition_allergens_mode',
	'save_nutrition_allergens',
	'update_nutrition_allergens',
	'copy_nutrition_allergens_to_product',
	'generate_llm_product_data',
	'apply_llm_product_data',
	'save_other_characteristics',
);
if (in_array($action, $productWriteActions, true) && !$usercancreate) {
	accessforbidden();
}

$unifiedProductDataActions = array(
	'save_nutrition_allergens_mode',
	'save_nutrition_allergens',
	'update_nutrition_allergens',
	'copy_nutrition_allergens_to_product',
);
if (in_array($action, $unifiedProductDataActions, true)) {
	if (!$canManageNutritionAllergens) {
		accessforbidden($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_PERMISSION_REQUIRED'));
	}
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		accessforbidden($langs->trans('KREAPRODUCTS_LLM_ERROR_POST_REQUIRED'));
	}
	$submittedToken = GETPOST('token', 'alphanohtml');
	if ($submittedToken === ''
		|| (!hash_equals((string) currentToken(), (string) $submittedToken)
			&& !hash_equals((string) newToken(), (string) $submittedToken))) {
		accessforbidden($langs->trans('KreapInvalidCsrfToken'));
	}
}

$llmWriteActions = array('generate_llm_product_data', 'apply_llm_product_data');
if (in_array($action, $llmWriteActions, true)) {
	if (!$canUseLlmProductData) {
		accessforbidden();
	}
	if (!$llmManualDataMode) {
		accessforbidden($langs->trans('KREAPRODUCTS_LLM_ERROR_MANUAL_MODE'));
	}
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		accessforbidden($langs->trans('KREAPRODUCTS_LLM_ERROR_POST_REQUIRED'));
	}
	$submittedToken = GETPOST('token', 'alphanohtml');
	if ($submittedToken === ''
		|| (!hash_equals((string) currentToken(), (string) $submittedToken)
			&& !hash_equals((string) newToken(), (string) $submittedToken))) {
		accessforbidden($langs->trans('KreapInvalidCsrfToken'));
	}
}

if ($action === 'save_other_characteristics') {
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		accessforbidden($langs->trans('KREAPRODUCTS_LLM_ERROR_POST_REQUIRED'));
	}
	$submittedToken = GETPOST('token', 'alphanohtml');
	if ($submittedToken === ''
		|| (!hash_equals((string) currentToken(), (string) $submittedToken)
			&& !hash_equals((string) newToken(), (string) $submittedToken))) {
		accessforbidden($langs->trans('KreapInvalidCsrfToken'));
	}
}

$inlineOptionAction = '';
$inlineOptionKey = '';
if (preg_match('/^(editoptions|setoptions)_(.+)$/', $action, $inlineOptionMatch)) {
	$inlineOptionAction = $inlineOptionMatch[1];
	$inlineOptionKey = $inlineOptionMatch[2];
} elseif ($action === 'editoptions' || $action === 'setoptions') {
	$inlineOptionAction = $action;
	if (!empty($key)) {
		$inlineOptionKey = $key;
	} else {
		// Fallback: infer from the single options_* field present in the request.
		$requestKeys = array_merge(array_keys($_GET ?? array()), array_keys($_POST ?? array()));
		foreach ($requestKeys as $requestKey) {
			if (strpos($requestKey, 'options_') === 0) {
				$inlineOptionKey = substr($requestKey, strlen('options_'));
				break;
			}
		}
	}
}

// Handle inline extra-field updates locally to avoid wiping other extrafields.
if ($inlineOptionAction === 'setoptions' && $usercancreate && !empty($object->id) && $inlineOptionKey !== '') {
	require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label($object->table_element);
	$object->fetch_optionals();
	$originalOptions = $object->array_options;

	$fieldName = 'options_' . $inlineOptionKey;
	if (array_key_exists($fieldName, $_POST) || array_key_exists($fieldName, $_GET) || GETPOSTISSET('value')) {
		$rawValue = GETPOSTISSET($fieldName) ? GETPOST($fieldName, 'alpha') : GETPOST('value', 'alpha');
		$value = trim((string) $rawValue);
		if ($value === '') {
			$value = null;
		}
		$object->array_options[$fieldName] = $value;
	}

	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
		$fname = 'options_' . $key;
		if (!array_key_exists($fname, $object->array_options) && array_key_exists($fname, $originalOptions)) {
			$object->array_options[$fname] = $originalOptions[$fname];
		}
	}

	$object->insertExtraFields();
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if ($inlineOptionAction === 'editoptions') {
	// Refresh extra–fields in case hooks touched array_options.
	$object->fetch_optionals();
}

if ($action === 'generate_llm_product_data') {
	$llmSourceText = GETPOST('llm_source_text', 'restricthtml');
	$llmService = new KreaProductsLlmProductDataService($db);
	$llmSuggestion = $llmService->generateSuggestion($object, $llmSourceText);
	if ($llmSuggestion === null) {
		setEventMessages($langs->trans($llmService->getLastErrorKey()), null, 'errors');
	}
}

if ($action === 'apply_llm_product_data') {
	$nutrition = array();
	foreach (KreaProductsLlmProductDataService::getNutritionFields() as $field) {
		$rawValue = trim((string) GETPOST('llm_nutrition_'.$field, 'alphanohtml'));
		$nutrition[$field] = ($rawValue === '' ? null : price2num($rawValue, 'MU'));
	}
	try {
		$reviewedSuggestion = KreaProductsLlmProductDataService::buildSubmittedSuggestion(
			$nutrition,
			(array) GETPOST('llm_allergens_contains', 'array'),
			(array) GETPOST('llm_allergens_traces', 'array'),
			GETPOST('llm_confidence', 'alpha'),
			GETPOST('llm_notes', 'restricthtml')
		);
		$llmService = new KreaProductsLlmProductDataService($db);
		if ($llmService->applySuggestion($object->id, $reviewedSuggestion, $user) > 0) {
			setEventMessages($langs->trans('KREAPRODUCTS_LLM_APPLY_SUCCESS'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($langs->trans($llmService->getLastErrorKey()), null, 'errors');
	} catch (InvalidArgumentException $exception) {
		dol_syslog('Invalid reviewed LLM product data for product '.(int) $object->id.': '.$exception->getMessage(), LOG_ERR);
		setEventMessages($langs->trans('KREAPRODUCTS_LLM_ERROR_INVALID_DATA'), null, 'errors');
	}
}

if ($action === 'save_other_characteristics') {
	$errors = array();
	foreach ($otherCharacteristicsFieldDefinitions as $fieldName => $definition) {
		$value = ($definition['format'] ?? '') === 'markdown'
			? kreaproducts_normalize_markdown(GETPOST($fieldName, 'none'))
			: kreaproducts_normalize_plain_text(GETPOST($fieldName, 'none'));
		$otherCharacteristicsSubmittedValues[$fieldName] = $value;
		if (dol_strlen($value) > (int) $definition['maxlength']) {
			$errors[] = $langs->trans(
				'KREAPRODUCTS_OTHER_CHARACTERISTICS_VALUE_TOO_LONG',
				$langs->trans($definition['label']),
				(int) $definition['maxlength']
			);
		}
	}

	$videoValue = $otherCharacteristicsSubmittedValues['options_kreap_video'];
	if ($videoValue !== '' && !kreaproducts_is_http_url($videoValue)) {
		$errors[] = $langs->trans('KREAPRODUCTS_OTHER_CHARACTERISTICS_INVALID_VIDEO_URL');
	}

	if (empty($errors)) {
		require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$object->fetch_optionals();
		foreach ($otherCharacteristicsSubmittedValues as $fieldName => $value) {
			$object->array_options[$fieldName] = ($value === '' ? null : $value);
		}

		$db->begin();
		$result = $object->insertExtraFields();
		if ($result < 0) {
			$db->rollback();
			dol_syslog('Unable to save product characteristics for product #'.(int) $object->id.': '.$object->error, LOG_ERR);
			$errors[] = $langs->trans('KREAPRODUCTS_OTHER_CHARACTERISTICS_SAVE_ERROR');
		} else {
			$db->commit();
			setEventMessages($langs->trans('KREAPRODUCTS_OTHER_CHARACTERISTICS_SAVED'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-other-characteristics');
			exit;
		}
	}

	if (!empty($errors)) {
		setEventMessages('', $errors, 'errors');
		$action = 'edit_other_characteristics';
	}
}

if ($action === 'save_nutrition_allergens_mode') {
	$mode = GETPOSTISSET('nutrition_allergen_mode') ? GETPOSTINT('nutrition_allergen_mode') : -1;
	if (!in_array($mode, array(0, 1, 2), true)) {
		setEventMessages($langs->trans('ErrorBadValueForParameter'), null, 'errors');
	} else {
		$error = 0;
		$db->begin();
		if (kreaproducts_set_product_extrafield_value($db, $object->id, 'kreap_calc_nut', $mode) <= 0
			|| kreaproducts_set_product_extrafield_value($db, $object->id, 'kreap_calc_allergens', $mode) <= 0) {
			$error++;
		}

		if (!$error) {
			$isFood = ($mode === 2 ? 0 : 1);
			$sql = 'SELECT n.rowid FROM '.MAIN_DB_PREFIX.'kreaproducts_nutritional AS n';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = n.fk_product';
			$sql .= ' AND p.entity IN ('.getEntity('product').')';
			$sql .= ' WHERE n.fk_product = '.(int) $object->id.' LIMIT 1 FOR UPDATE';
			$resql = $db->query($sql);
			if (!$resql) {
				$error++;
			} elseif ($db->num_rows($resql) > 0) {
				$row = $db->fetch_object($resql);
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'kreaproducts_nutritional';
				$sql .= ' SET is_food = '.$isFood.', fk_user_modif = '.(int) $user->id;
				$sql .= ' WHERE rowid = '.(int) $row->rowid;
				if (!$db->query($sql)) {
					$error++;
				}
			} else {
				$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'kreaproducts_nutritional';
				$sql .= ' (fk_product, date_creation, fk_user_creat, is_food) VALUES (';
				$sql .= (int) $object->id.", '".$db->idate(dol_now())."', ".(int) $user->id.', '.$isFood.')';
				if (!$db->query($sql)) {
					$error++;
				}
			}
			if ($resql) {
				$db->free($resql);
			}
		}
		if (!$error && $mode === 1) {
			dol_include_once('/kreaproducts/class/KreaProductsNutritionalCalculator.class.php');
			if (KreaProductsNutritionalCalculator::saveCalculation($object->id, $user) <= 0
				|| !KreaProductsAllergenUpdater::updateAllergenAttributes($object->id, $user, 0)
				|| KreaProductsAllergenUpdater::hasErrors()) {
				$error++;
			}
		}

		if ($error) {
			$db->rollback();
			dol_syslog('Unable to save the unified nutrition/allergen mode for product #'.(int) $object->id.': '.$db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_MODE_ERROR'), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_MODE_SAVED'), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens');
	exit;
}

if ($action === 'save_nutrition_allergens') {
	$currentNutritionMode = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_nut', $object->entity);
	$currentAllergenMode = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_allergens', $object->entity);
	if ((string) $currentNutritionMode !== '0' || (string) $currentAllergenMode !== '0') {
		accessforbidden($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_MANUAL_REQUIRED'));
	}

	$nutritionFields = array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber');
	$nutrition = array();
	foreach ($nutritionFields as $field) {
		$rawValue = trim((string) GETPOST('nutritional_'.$field, 'alphanohtml'));
		$nutrition[$field] = ($rawValue === '' ? null : price2num($rawValue, 'MU'));
	}
	$contains = array_values(array_unique(array_filter(array_map('intval', (array) GETPOST('KREAPRODUCTS_ALLERGENS', 'array')))));
	$traces = array_values(array_unique(array_filter(array_map('intval', (array) GETPOST('KREAPRODUCTS_ALLERGENS_TRACES', 'array')))));
	$traces = array_values(array_diff($traces, $contains));
	$submittedAllergenIds = array_values(array_unique(array_merge($contains, $traces)));

	$error = 0;
	if (!empty($submittedAllergenIds)) {
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_kreaproducts';
		$sql .= ' WHERE active = 1 AND rowid IN ('.implode(',', $submittedAllergenIds).')';
		$resql = $db->query($sql);
		$validAllergenIds = array();
		if ($resql) {
			while ($row = $db->fetch_object($resql)) {
				$validAllergenIds[] = (int) $row->rowid;
			}
			$db->free($resql);
		}
		if (!$resql || count($validAllergenIds) !== count($submittedAllergenIds)) {
			$error++;
		}
	}

	if (!$error) {
		dol_include_once('/kreaproducts/class/nutritional.class.php');
		dol_include_once('/kreaproducts/class/productallergens.class.php');
		$db->begin();
		try {
			$nutritional = new Nutritional($db);
			$fetchResult = $nutritional->fetchByProduct($object->id);
			if ($fetchResult < 0) {
				throw new RuntimeException('Unable to load the nutritional record');
			}
			$nutritional->fk_product = (int) $object->id;
			$nutritional->is_food = 1;
			foreach ($nutrition as $field => $value) {
				$nutritional->{$field} = $value;
			}
			if ($fetchResult > 0) {
				$nutritional->fk_user_modif = (int) $user->id;
				$result = $nutritional->update($user);
			} else {
				$nutritional->fk_user_creat = (int) $user->id;
				$result = $nutritional->create($user);
			}
			if ($result <= 0) {
				throw new RuntimeException($nutritional->error ?: 'Unable to save nutritional values');
			}

			$sql = 'DELETE pa FROM '.MAIN_DB_PREFIX.'kreaproducts_productallergens AS pa';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = pa.fk_product';
			$sql .= ' AND p.entity IN ('.getEntity('product').')';
			$sql .= ' WHERE pa.fk_product = '.(int) $object->id;
			if (!$db->query($sql)) {
				throw new RuntimeException('Unable to replace product allergens');
			}

			foreach (array(0 => $contains, 1 => $traces) as $isTrace => $allergenIds) {
				foreach ($allergenIds as $allergenId) {
					$productAllergen = new ProductAllergens($db);
					$productAllergen->fk_product = (int) $object->id;
					$productAllergen->fk_allergen = (int) $allergenId;
					$productAllergen->traces = (int) $isTrace;
					if ($productAllergen->create($user) <= 0) {
						throw new RuntimeException($productAllergen->error ?: 'Unable to save a product allergen');
					}
				}
			}
			$db->commit();
		} catch (Throwable $exception) {
			$db->rollback();
			$error++;
			dol_syslog('Unable to save unified nutrition/allergen data for product #'.(int) $object->id.': '.$exception->getMessage(), LOG_ERR);
		}
	}

	if ($error) {
		setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_SAVE_ERROR'), null, 'errors');
	} else {
		if (!KreaProductsNutrientUpdater::updateNutrientAttributes($object->id, $user)) {
			dol_syslog('Nutrition/allergen values were saved, but the nutrient cascade reported an error for product #'.(int) $object->id, LOG_ERR);
		}
		setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_SAVED'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens');
	exit;
}

if ($action === 'update_nutrition_allergens') {
	$currentNutritionMode = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_nut', $object->entity);
	$currentAllergenMode = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_allergens', $object->entity);
	if ((string) $currentNutritionMode !== '1' || (string) $currentAllergenMode !== '1') {
		accessforbidden($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_CALCULATED_REQUIRED'));
	}

	dol_include_once('/kreaproducts/class/KreaProductsNutritionalCalculator.class.php');
	$error = 0;
	$db->begin();
	if (KreaProductsNutritionalCalculator::saveCalculation($object->id, $user) <= 0) {
		$error++;
	}
	if (!$error && (!KreaProductsAllergenUpdater::updateAllergenAttributes($object->id, $user, 0) || KreaProductsAllergenUpdater::hasErrors())) {
		$error++;
	}
	if ($error) {
		$db->rollback();
		$errors = array_merge(KreaProductsNutritionalCalculator::getAllErrors(), KreaProductsAllergenUpdater::getAllErrors());
		setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_UPDATE_ERROR'), $errors, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_UPDATED'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens');
	exit;
}

if (empty($reshook)) {
	// Add subproduct to product
	if ($action == 'add_prod' && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
		$error = 0;
		$maxprod = GETPOST("max_prod", 'int');
		for ($i = 0; $i < $maxprod; $i++) {
			$qty = price2num(GETPOST("prod_qty_" . $i, 'alpha'), 'MS');
			if ($qty > 0) {
				if ($object->add_sousproduit($id, GETPOST("prod_id_" . $i, 'int'), $qty, GETPOST("prod_incdec_" . $i, 'int')) > 0) {
					$action = 'edit';
				} else {
					$error++;
					$action = 're-edit';
					if ($object->error == "isFatherOfThis") {
						setEventMessages($langs->trans("ErrorAssociationIsFatherOfThis"), null, 'errors');
					} else {
						setEventMessages($object->error, $object->errors, 'errors');
					}
				}
			} else {
				if ($object->del_sousproduit($id, GETPOST("prod_id_" . $i, 'int')) > 0) {
					$action = 'edit';
				} else {
					$error++;
					$action = 're-edit';
					setEventMessages($object->error, $object->errors, 'errors');
				}
			}
		}
		if (!$error) {
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		}
	} elseif ($action === 'save_composed_product' && $usercancreate) {
		$TProduct = GETPOST('TProduct', 'array');
		$error = 0;
		$errors = array();
		if (!empty($TProduct)) {
			foreach ($TProduct as $id_product => $row) {
				$id_product = (int) $id_product;
				$qty = price2num(isset($row['qty']) ? $row['qty'] : 0, 'MS');
				if ($qty > 0) {
					$result = $object->update_sousproduit($id, $id_product, $qty, isset($row['incdec']) ? 1 : 0);
					if ($result < 0) {
						$error++;
						$errors = array_merge($errors, !empty($object->errors) ? $object->errors : array($object->error));
					}
					if (!$error && !empty($conf->global->KREAPRODUCTION_ENABLE)) {
						$lotValue = isset($row['kreap_lot']) ? 1 : 0;
						if (kreaproducts_set_product_extrafield_value($db, $id_product, 'kreap_lot', $lotValue) < 0) {
							$error++;
							$errors[] = $langs->trans("ErrorRecordNotFound") . ' #' . $id_product;
						}
					}
				} else {
					$result = $object->del_sousproduit($id, $id_product);
					if ($result < 0) {
						$error++;
						$errors = array_merge($errors, !empty($object->errors) ? $object->errors : array($object->error));
					}
				}
			}
			if ($error) {
				setEventMessages($langs->trans("Error"), array_filter($errors), 'errors');
			} else {
				setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			}
		}
		$action = '';
		header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
		exit;
	} elseif ($action === 'update_sim_price' && getDolGlobalInt('KREAPRODUCTS_SIM_ENABLE', 1) && ($user->rights->produit->creer || $user->rights->service->creer)) {
		$newpriceInput = price2num(GETPOST('sim_price_value', 'alpha'), 'MU');
		$priceLevelPost = GETPOST('price_level', 'int');
		$priceLevelUsed = ($priceLevelPost > 0 ? $priceLevelPost : 1);
		if ($id && $newpriceInput > 0) {
			$object->fetch($id);
			$baseType = strtoupper(!empty($conf->global->PRODUCT_PRICE_BASE_TYPE) ? $conf->global->PRODUCT_PRICE_BASE_TYPE : 'HT');
			// Resolve VAT: priority to level VAT, then product VAT, then VAT code lookup
			$vat = 0;
			$defaultVatCode = '';
			if (!empty($conf->global->PRODUIT_MULTIPRICES) && isset($object->multiprices_tva_tx[$priceLevelUsed]) && $object->multiprices_tva_tx[$priceLevelUsed] !== '') {
				$vat = (float) $object->multiprices_tva_tx[$priceLevelUsed];
			} elseif ($object->tva_tx !== null && $object->tva_tx !== '') {
				$vat = (float) $object->tva_tx;
			}

			// Load current price row for selected level to get VAT and VAT code if set per level
			$sqlpr = "SELECT rowid, tva_tx, default_vat_code FROM " . MAIN_DB_PREFIX . "product_price WHERE fk_product = " . ((int)$object->id) . " AND price_level = " . ((int)$priceLevelUsed) . " ORDER BY rowid DESC LIMIT 1";
			$respr = $db->query($sqlpr);
			if ($respr && ($rowpr = $db->fetch_object($respr))) {
				if ($rowpr->tva_tx !== null && $rowpr->tva_tx > 0) {
					$vat = (float) $rowpr->tva_tx;
				}
				if (!empty($rowpr->default_vat_code)) {
					$defaultVatCode = $rowpr->default_vat_code;
				}
			}
			if ($respr) $db->free($respr);
			if ($vat <= 0 && $object->tva_tx > 0) {
				$vat = (float) $object->tva_tx;
			}
			if (empty($defaultVatCode) && !empty($object->default_vat_code)) {
				$defaultVatCode = $object->default_vat_code;
			}
			// If still no VAT but we have a VAT code, try to resolve the rate
			if ($vat <= 0 && !empty($defaultVatCode)) {
				$sqlvat = "SELECT taux FROM " . MAIN_DB_PREFIX . "c_tva WHERE code = '" . $db->escape($defaultVatCode) . "' AND active = 1 ORDER BY taux DESC LIMIT 1";
				$resvat = $db->query($sqlvat);
				if ($resvat && ($rowvat = $db->fetch_object($resvat))) {
					$vat = (float) $rowvat->taux;
				}
				if ($resvat) $db->free($resvat);
			}

			if ($baseType === 'TTC') {
				// newprice is TTC; compute HT
				$newprice_ttc = $newpriceInput;
				$newprice_ht = ($vat >= 0) ? ($newprice_ttc / (1 + ($vat / 100))) : $newprice_ttc;
			} else {
				// newprice is HT; compute TTC
				$newprice_ht = $newpriceInput;
				$newprice_ttc = ($vat >= 0) ? ($newprice_ht * (1 + ($vat / 100))) : $newprice_ht;
			}

			// Update price for selected level using Dolibarr API only
			$newpriceForBase = ($baseType === 'TTC') ? $newprice_ttc : $newprice_ht;
			$res = $object->updatePrice($newpriceForBase, $baseType, $user, $vat, 0, $priceLevelUsed, 0, 0, 0, array(), $defaultVatCode, '', 0);

			if ($res > 0) {
				setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			} else {
				setEventMessages($langs->trans("Error"), null, 'errors');
			}
		}
		header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
		exit;
	}
}

if ($action === 'copy_nutrition_allergens_to_product' && $canManageNutritionAllergens && ($enableCopyAllergensToProduct || $enableCopyAvgToProduct)) {
	$targetProductId = GETPOSTINT('target_product_id_allergens');
	if ($targetProductId <= 0) {
		$targetProductId = GETPOSTINT('target_product_id');
	}
	if ($targetProductId > 0) {
		$allergensToCopy = array();
		$sql = "SELECT pa.fk_allergen, pa.traces";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens AS pa";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = pa.fk_product";
		$sql .= " WHERE pa.fk_product = " . (int) $object->id;
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$key = (int) $obj->fk_allergen . '-' . (int) $obj->traces;
				if (!isset($allergensToCopy[$key])) {
					$allergensToCopy[$key] = array(
						'fk_allergen' => (int) $obj->fk_allergen,
						'traces' => (int) $obj->traces,
					);
				}
			}
			$db->free($resql);
		} else {
			dol_syslog("Error reading source allergens: " . $db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans("ErrorUpdatingData") . ": " . $db->lasterror(), null, 'errors');
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		}

		$sqlTarget = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sqlTarget .= " WHERE rowid = " . (int) $targetProductId;
		$sqlTarget .= " AND entity IN (" . getEntity('product') . ")";
		$sqlTarget .= " LIMIT 1";
		$resTarget = $db->query($sqlTarget);
		if (!$resTarget || $db->num_rows($resTarget) <= 0) {
			if ($resTarget) {
				$db->free($resTarget);
			}
			dol_syslog("Blocked allergen and nutritional copy to inaccessible product ID " . $targetProductId, LOG_WARNING);
			setEventMessages($langs->trans("Error"), null, 'errors');
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		}
		$db->free($resTarget);

		$error = 0;
		$messages = array($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_COPIED'));
		$nutritionCopyResult = 0;
		$db->begin();

		$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens";
		$sqlDelete .= " WHERE fk_product = " . (int) $targetProductId;
		$resdel = $db->query($sqlDelete);
		if (!$resdel) {
			$error++;
			dol_syslog("Error deleting target allergens: " . $db->lasterror(), LOG_ERR);
		}

		if (!$error && !empty($allergensToCopy)) {
			dol_include_once('/kreaproducts/class/productallergens.class.php');
			foreach ($allergensToCopy as $row) {
				$prodAllergen = new ProductAllergens($db);
				$prodAllergen->fk_product = $targetProductId;
				$prodAllergen->fk_allergen = $row['fk_allergen'];
				$prodAllergen->traces = $row['traces'];
				if ($prodAllergen->create($user) <= 0) {
					$error++;
					dol_syslog("Error creating target allergen: " . $prodAllergen->error, LOG_ERR);
					break;
				}
			}
		}

		if (!$error) {
			$nutritionCopyResult = kreaproducts_copy_nutritional_values_to_product($db, $object->id, $targetProductId, $user);
			if ($nutritionCopyResult < 0) {
				$error++;
			}
		}

		if (!$error) {
			$db->commit();
			if ($nutritionCopyResult > 0) {
				$result = KreaProductsNutrientUpdater::updateNutrientAttributes($targetProductId, $user);
				if (!$result) {
					kreaproducts_debug_log("Error updating nutritional attributes for product ID " . $targetProductId);
				}
			}
			setEventMessages('', $messages, 'mesgs');
		} else {
			$db->rollback();
			setEventMessages($langs->trans("ErrorUpdatingData") . ": " . $db->lasterror(), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($action === 'setweight' && $usercancreate) {
	$object->fetch($object->id);
	$object->weight = GETPOST('weight', 'alpha');
	$submittedWeightUnit = GETPOSTISSET('weight_units') ? GETPOST('weight_units', 'alphanohtml') : null;
	$object->weight_units = kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $object->weight_units);

	$result = $object->update($object->id, $user);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($action === 'setfinished' && $usercancreate) {
	$submittedNature = GETPOSTISSET('finished') ? GETPOSTINT('finished') : -2;
	$natureIsValid = ($submittedNature === -1);

	if ($submittedNature >= 0) {
		$sqlNature = "SELECT code FROM " . MAIN_DB_PREFIX . "c_product_nature";
		$sqlNature .= " WHERE code = " . (int) $submittedNature . " AND active = 1";
		$resNature = $db->query($sqlNature);
		if ($resNature) {
			$natureIsValid = ($db->num_rows($resNature) > 0);
			$db->free($resNature);
		} else {
			dol_syslog("Unable to validate product nature: " . $db->lasterror(), LOG_ERR);
		}
	}

	if (!$natureIsValid) {
		setEventMessages($langs->trans("ErrorBadValueForParameter", "finished"), null, 'errors');
	} else {
		$object->oldcopy = dol_clone($object, 1);
		$object->finished = ($submittedNature >= 0) ? $submittedNature : null;
		$result = $object->update($object->id, $user);
		if ($result > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}

	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($usercancreate && preg_match('/^setweight_(\d+)$/', $action, $matches)) {
	$childId = (int) $matches[1];
	$childProduct = new Product($db);
	if ($childId > 0 && $childProduct->fetch($childId) > 0) {
		$childProduct->weight = GETPOST('weight', 'alpha');
		$submittedWeightUnit = GETPOSTISSET('weight_units') ? GETPOST('weight_units', 'alphanohtml') : null;
		$childProduct->weight_units = kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $childProduct->weight_units);
		$result = $childProduct->update($childId, $user);
		if ($result > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($childProduct->error, $childProduct->errors, 'errors');
		}
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($action === 'toggle_dismantle' && $usercancreate) {
	$newValue = GETPOST('value', 'int') ? 1 : 0;

	if (kreaproducts_has_bom_for_product($db, $object->id)) {
		// Ensure the extrafield column exists before inserting.
		$columnReady = false;
		$colRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "kreap_dismantle");
		if ($colRes) {
			$columnReady = ($db->num_rows($colRes) > 0);
			$db->free($colRes);
		}
		if (!$columnReady) {
			require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
			$extrafields = new ExtraFields($db);
			$field_name = "kreap_dismantle";
			$field_label = $langs->trans("kreap_dismantle");
			$field_help = $langs->trans("kreap_dismantle_help");
			$extrafields->addExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
			$extrafields->updateExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, kreap_dismantle) VALUES ("
			. (int) $object->id . ", " . (int) $newValue . ") "
			. "ON DUPLICATE KEY UPDATE kreap_dismantle = " . (int) $newValue;
		if (!$db->query($sql)) {
			setEventMessages($langs->trans("Error"), array($db->lasterror()), 'errors');
		}
	}

	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($action === 'toggle_component_kreap_lot' && $usercancreate && !empty($conf->global->KREAPRODUCTION_ENABLE)) {
	$componentProductId = GETPOST('component_product_id', 'int');
	$newValue = GETPOST('value', 'int') ? 1 : 0;

	if ($componentProductId > 0) {
		if (kreaproducts_set_product_extrafield_value($db, $componentProductId, 'kreap_lot', $newValue) > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans("ErrorBadValueForParameter"), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

/*
 * View
 */

$form         = new Form($db);
$formproduct  = new FormProduct($db);
$product_fourn = new ProductFournisseur($db);
$productstatic = new Product($db);

// action recherche des produits par mot-cle et/ou par categorie
if ($action == 'search') {
	$current_lang = $langs->getDefaultLang();
	$sql = 'SELECT DISTINCT p.rowid, p.ref, p.label, p.fk_product_type as type, p.barcode, p.price, p.price_ttc, p.price_base_type, p.entity,';
	$sql .= ' p.fk_product_type, p.tms as datem, p.tobatch';
	$sql .= ', p.tosell as status, p.tobuy as status_buy';
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		$sql .= ', pl.label as labelm, pl.description as descriptionm';
	}
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object);
	$sql .= $hookmanager->resPrint;
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'product as p';
	$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'categorie_product as cp ON p.rowid = cp.fk_product';
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_lang as pl ON pl.fk_product = p.rowid AND lang='" . ($current_lang) . "'";
	}
	$sql .= ' WHERE p.entity IN (' . getEntity('product') . ')';
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object);
	$sql .= $hookmanager->resPrint;
	if ($key != "") {
		$params = array('p.ref', 'p.label', 'p.description', 'p.note');
		if (getDolGlobalInt('MAIN_MULTILANGS')) {
			$params[] = 'pl.label';
			$params[] = 'pl.description';
			$params[] = 'pl.note';
		}
		if (isModEnabled('barcode')) {
			$params[] = 'p.barcode';
		}
		$sql .= natural_search($params, $key);
	}
	if (isModEnabled('categorie') && !empty($parent) && $parent != -1) {
		$sql .= " AND cp.fk_categorie ='" . $db->escape($parent) . "'";
	}
	$sql .= " ORDER BY p.ref ASC";
	$resql = $db->query($sql);
}

$title = $langs->trans('ProductServiceCard');
$help_url = '';
$shortlabel = dol_trunc($object->label, 16);
if (GETPOST("type") == '0' || ($object->type == Product::TYPE_PRODUCT)) {
	$title = $langs->trans('Product') . " " . $shortlabel . " - " . $langs->trans('AssociatedProducts');
	$help_url = 'EN:Module_Products|FR:Module_Produits|ES:M&oacute;dulo_Productos|DE:Modul_Produkte';
}
if (GETPOST("type") == '1' || ($object->type == Product::TYPE_SERVICE)) {
	$title = $langs->trans('Service') . " " . $shortlabel . " - " . $langs->trans('AssociatedProducts');
	$help_url = 'EN:Module_Services_En|FR:Module_Services|ES:M&oacute;dulo_Servicios|DE:Modul_Leistungen';
}

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-kreaproducts page-card_krea_subproduct');

$head = product_prepare_head($object);
$titre = $langs->trans("CardProduct" . $object->type);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');
print dol_get_fiche_head($head, 'krea_subproduct', $titre, -1, $picto);

if ($id > 0 || !empty($ref)) {
	// Product card
	if ($user->hasRight('produit', 'lire') || $user->hasRight('service', 'lire')) {
		$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
		$shownav = 1;
		if ($user->socid && !in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
			$shownav = 0;
		}
		dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref', '');

		// Food/non-food toggle (default: food)
		$productIsFood = 1;
		$sqlFoodFlag = "SELECT rowid, is_food FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int) $object->id . " LIMIT 1";
		$resFoodFlag = $db->query($sqlFoodFlag);
		$foodRowId = null;
		if ($resFoodFlag && ($foodObj = $db->fetch_object($resFoodFlag))) {
			$productIsFood = ($foodObj->is_food !== null) ? (int) $foodObj->is_food : 1;
			$foodRowId = (int) $foodObj->rowid;
		}
		if ($resFoodFlag) $db->free($resFoodFlag);
		$productHasBom = kreaproducts_has_bom_for_product($db, $object->id);

		if ($object->type != Product::TYPE_SERVICE || getDolGlobalString('STOCK_SUPPORTS_SERVICES') || !getDolGlobalString('PRODUIT_MULTIPRICES')) {
			$showWeightForm = $usercancreate && $object->type != Product::TYPE_SERVICE && !getDolGlobalString('PRODUCT_DISABLE_WEIGHT');
			print '<div class="fichecenter">';
			print '<div class="fichehalfleft">';
			print '<div class="underbanner clearboth"></div>';
			print '<table class="border centpercent tableforfield">';
			// Type
			if (isModEnabled("product") && isModEnabled("service")) {
				$typeformat = 'select;0:' . $langs->trans("Product") . ',1:' . $langs->trans("Service");
				print '<tr><td class="titlefield">';
				print (!getDolGlobalString('PRODUCT_DENY_CHANGE_PRODUCT_TYPE')) ? $form->editfieldkey("Type", 'fk_product_type', $object->type, $object, $usercancreate, $typeformat) : $langs->trans('Type');
				print '</td><td>';
				print $form->editfieldval("Type", 'fk_product_type', $object->type, $object, $usercancreate, $typeformat);
				print '</td></tr>';
			}
			// Dismantle toggle (only if product has a BOM)
			if ($productHasBom) {
				$dismantleFieldName = 'options_kreap_dismantle';
				$dismantleValue = isset($object->array_options[$dismantleFieldName]) ? (int) $object->array_options[$dismantleFieldName] : 0;
				$dismantleIcon = $dismantleValue ? 'fa-toggle-on font-status4' : 'fa-toggle-off opacitymedium';
				$dismantleToggleUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=toggle_dismantle&value=' . ($dismantleValue ? 0 : 1);
				print '<tr><td class="titlefield">' . $langs->trans("kreap_dismantle") . '</td><td>';
				print '<a class="linkobject" href="' . $dismantleToggleUrl . '" title="' . $langs->trans("kreap_dismantle") . '">';
				print '<span class="fas ' . $dismantleIcon . '"></span>';
				print '</a>';
				print '</td></tr>';
			}
			if ($showWeightForm) {
				$weightDisplay = '';
				if ($object->weight != '') {
					$weightDisplay = $object->weight . ' ' . measuringUnitString(0, 'weight', $object->weight_units);
				}
				$selectedWeightUnit = kreaproducts_weight_unit_select_value(GETPOSTISSET('weight_units') ? GETPOST('weight_units', 'alphanohtml') : $object->weight_units);
				$weightEdit = '<input name="weight" size="5" value="' . dol_escape_htmltag(GETPOSTISSET('weight') ? GETPOST('weight') : $object->weight) . '"> ';
				$weightEdit .= $formproduct->selectMeasuringUnits("weight_units", "weight", $selectedWeightUnit, 0, 2);
				print '<tr><td class="titlefield">';
				print $form->editfieldkey("Weight", 'weight', $object->weight, $object, $usercancreate, 'asis');
				print '</td><td>';
				print $form->editfieldval("Weight", 'weight', $weightDisplay, $object, $usercancreate, 'asis', $weightEdit);
				print '</td></tr>';
			}
			print '</table>';
			print '</div><div class="fichehalfright">';
			print '<div class="underbanner clearboth"></div>';
			print '<table class="border centpercent tableforfield">';
			// Nature
			if ($object->type != Product::TYPE_SERVICE) {
				if (!getDolGlobalString('PRODUCT_DISABLE_NATURE')) {
					$selectedNature = GETPOSTISSET('finished') ? GETPOSTINT('finished') : (string) $object->finished;
					$natureEdit = $formproduct->selectProductNature('finished', $selectedNature);
					print '<tr><td class="titlefield">';
					print $form->editfieldkey("NatureOfProductShort", 'finished', $object->finished, $object, $usercancreate, 'asis', '', 0, 0, 'id', $langs->trans("NatureOfProductDesc"));
					print '</td><td>';
					print $form->editfieldval("NatureOfProductShort", 'finished', $object->getLibFinished(), $object, $usercancreate, 'asis', $natureEdit);
					print '</td></tr>';
				}
			}
			if (!getDolGlobalString('PRODUIT_MULTIPRICES')) {
				// Price
				print '<tr><td class="titlefield">' . $langs->trans("SellingPrice") . '</td><td>';
				if ($object->price_base_type == 'TTC') {
					print price($object->price_ttc) . ' ' . $langs->trans($object->price_base_type);
				} else {
					print price($object->price) . ' ' . $langs->trans($object->price_base_type ? $object->price_base_type : 'HT');
				}
				print '</td></tr>';
				// Price minimum
				print '<tr><td>' . $langs->trans("MinPrice") . '</td><td>';
				if ($object->price_base_type == 'TTC') {
					print price($object->price_min_ttc) . ' ' . $langs->trans($object->price_base_type);
				} else {
					print price($object->price_min) . ' ' . $langs->trans($object->price_base_type ? $object->price_base_type : 'HT');
				}
				print '</td></tr>';
			}

			print '</table>';
			print '</div>';
			print '</div>';
		}
		print dol_get_fiche_end();

		print '<br>';
		$sectionSpacingStyle = 'margin-top: 22px;';

		/**
		 * This code snippet checks if the current product has an associated **disassemble BOM** (Bill of Materials) in the system. 
		 * If a BOM of type "disassemble" exists (configured by `KREAPRODUCTS_DISMANTLE_BOMTYPE`), the script fetches and displays the **origin product** 
		 * associated with the BOM. The origin product is displayed as a clickable link that directs the user to the product's 
		 * detailed page. 
		 * 
		 * Additionally, this logic only executes if the BOM module is enabled in the Dolibarr system.
		 */
		if (!empty($conf->bom->enabled)) {
			$productRef = trim((string) $object->ref);
			$productRefEscaped = ($productRef !== '') ? $db->escape($productRef) : '';

			// Fetch all BOMs and the origin product for the current product
				$sql_bom = "SELECT b.rowid, b.ref AS bom_ref, b.bomtype, b.fk_product AS fk_product_origin,
	                               p.ref, p.label, p.stock AS stock_reel, p.cost_price, p.pmp, p.weight, p.weight_units,
	                               bl.qty as line_qty
                FROM " . MAIN_DB_PREFIX . "bom_bom AS b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline AS bl ON b.rowid = bl.fk_bom
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS cb ON cb.rowid = bl.fk_bom_child
                JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "product AS lp ON lp.rowid = bl.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "product AS cp ON cp.rowid = cb.fk_product
                WHERE (COALESCE(bl.fk_product, cb.fk_product) = " . (int)$object->id;
			if ($productRefEscaped !== '') {
				$sql_bom .= " OR lp.ref = '" . $productRefEscaped . "' OR cp.ref = '" . $productRefEscaped . "'";
			}
			$sql_bom .= ") AND b.bomtype = 1
                AND b.status IN (0,1)
                AND b.entity IN (0," . getEntity('bom') . ")
                AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                AND p.entity IN (0," . getEntity('product') . ")
                AND (lp.rowid IS NULL OR lp.entity IN (0," . getEntity('product') . "))
                AND (cp.rowid IS NULL OR cp.entity IN (0," . getEntity('product') . "))";

			$resql_bom = $db->query($sql_bom);
			$boms = [];

			// Check if the query returns results
			if ($resql_bom) {
				while ($obj_bom = $db->fetch_object($resql_bom)) {
						// Store each BOM's origin product and BOM row ID
						$boms[] = array(
							'bom_id' => $obj_bom->rowid,        // The BOM ID
							'bom_ref' => $obj_bom->bom_ref,     // The BOM ref
							'product_id' => $obj_bom->fk_product_origin,  // The origin product ID
							'ref' => $obj_bom->ref,             // The origin product reference
							'label' => $obj_bom->label,          // The origin product label
							'qty' => $obj_bom->line_qty,         // Quantity of current product in BOM
							'stock_reel' => price2num($obj_bom->stock_reel, 'MS'),
							'cost_price' => price2num($obj_bom->cost_price, 'MU'),
							'pmp' => price2num($obj_bom->pmp, 'MU'),
							'weight' => price2num($obj_bom->weight, 'MS'),
							'weight_units' => $obj_bom->weight_units
						);
					}
				}
			if (count($boms) > 0) {

				// Only display the table if there is at least one BOM
				print '<div class="fichecenter" style="' . $sectionSpacingStyle . '">';

				// Print the title of the section
				print load_fiche_titre($langs->trans("BOMExistsAndOriginProduct"), '', '');

				// Begin table structure
				print '<table class="liste">';
				print '<tr class="liste_titre">';

					// Column headers
					print '<td>' . $langs->trans('BOMExists') . '</td>';
					print '<td>' . $langs->trans('OriginProductId') . '</td>';
					print '<td>' . $langs->trans('OriginProduct') . '</td>';
					print '<td class="right">' . $langs->trans('Stock') . '</td>';
					print '<td class="right">' . $langs->trans('Qty') . '</td>';
					print '<td class="right">Peso (kg)</td>';
					print '<td class="right">Custo comp.</td>';
					print '</tr>';


				// If BOMs exist, display each one
					foreach ($boms as $bom) {
						print '<tr class="oddeven">';
						// Link to the BOM (assuming there is a page that shows BOM details)
						$bomRefLabel = trim((string) $bom['bom_ref']) !== '' ? $bom['bom_ref'] : ($langs->trans('kreaproducts_BOM') . ' #' . $bom['bom_id']);
						print '<td><a href="' . dol_buildpath('/bom/bom_card.php?id=' . $bom['bom_id'], 1) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($bomRefLabel) . '</a></td>';

						// Display the origin product with a link to the product card
						print '<td><a href="' . dol_buildpath('/product/card.php?id=' . $bom['product_id'], 1) . '" target="_blank" rel="noopener noreferrer">' . $bom['ref'] . '</a></td>';
						print '<td><a href="' . dol_buildpath('/product/card.php?id=' . $bom['product_id'], 1) . '" target="_blank" rel="noopener noreferrer">' . $bom['label'] . '</a></td>';

						// Show fraction of BOM that corresponds to 1 unit of this component (1 / qty)
						$qtyBeforeDismantle = ((float) $bom['qty'] > 0) ? (1 / (float) $bom['qty']) : 0.0;
						$unitWeightKg = kreaproducts_weight_to_kg($bom['weight'], $bom['weight_units']);
						$lineWeightKg = $unitWeightKg * $qtyBeforeDismantle;
						$unitCost = (float) $bom['cost_price'];
						if ($unitCost <= 0 && !empty($bom['pmp'])) {
							$unitCost = (float) $bom['pmp'];
						}
						$lineCost = (float) price2num($unitCost * $qtyBeforeDismantle, 'MT');
						print '<td class="right">' . number_format((float) $bom['stock_reel'], 4, '.', '') . '</td>';
						print '<td class="right">' . number_format((float) $qtyBeforeDismantle, 3, '.', '') . '</td>';
						print '<td class="right" style="white-space: nowrap;">' . number_format((float) $lineWeightKg, 3, '.', '') . ' kg</td>';
						print '<td class="right" style="white-space: nowrap;">' . ($unitCost > 0 ? price($lineCost, '', '', 0, 0, 4, $conf->currency) : '&mdash;') . '</td>';
						print '</tr>';
					}


				print '</table>';
				print '</div>';
			}
		}

		$prodsfather = $object->getFather(); // Parent Products
		$object->get_sousproduits_arbo(); // Load $object->sousprods
		$parent_label = $object->label;
		$prods_arbo = $object->get_arbo_each_prod();
		$tmpid = $id;
		if (!empty($conf->use_javascript_ajax)) {
			$nboflines = $prods_arbo;
			$table_element_line = 'product_association';
			include DOL_DOCUMENT_ROOT . '/core/tpl/ajaxrow.tpl.php';
		}
		$id = $tmpid;
		$nbofsubsubproducts = count($prods_arbo);
		$prodschild = $object->getChildsArbo($id, 1);
		$nbofsubproducts = count($prodschild);

		// Helper to render ZoneSoft menu chips for a product
		$zsMenuCache = array();
		$zsProdDataCache = array(); // Cache by ZS code: ['desc' => ..., 'fk_product' => ...]
		$zsFamilyDataCache = array(); // Cache by family key: ['label' => ...]
		$zsFamilyProductsCache = array(); // Cache by family key: [['codigo' => ..., 'desc' => ..., 'fk_product' => ...], ...]
		$renderZsMenu = function ($productId) use (&$zsMenuCache, &$zsProdDataCache, &$zsFamilyDataCache, &$zsFamilyProductsCache, $db, $langs, $conf) {
			if (empty($conf->dolizsynch->enabled)) {
				return '';
			}
			if (isset($zsMenuCache[$productId])) {
				return $zsMenuCache[$productId];
			}

			$html = '';

			$menuJsonRaw = '';

			// Prefer loading menu by ZS code (product ref) because fk_product may have stale rows.
			$sqlRef = "SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . ((int) $productId) . " " . $db->plimit(1);
			$resRef = $db->query($sqlRef);
			$zsCodeRef = '';
			if ($resRef && ($objRef = $db->fetch_object($resRef))) {
				$zsCodeRef = trim((string) $objRef->ref);
			}
			if ($zsCodeRef !== '' && ctype_digit($zsCodeRef)) {
				$sqlMenuByCode = "SELECT niveismenu";
				$sqlMenuByCode .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
				$sqlMenuByCode .= " WHERE codigo = " . ((int) $zsCodeRef);
				$sqlMenuByCode .= " AND niveismenu IS NOT NULL";
				$sqlMenuByCode .= " ORDER BY rowid DESC " . $db->plimit(1);
				$resMenuByCode = $db->query($sqlMenuByCode);
				if ($resMenuByCode && ($objMenuByCode = $db->fetch_object($resMenuByCode)) && !empty($objMenuByCode->niveismenu)) {
					$menuJsonRaw = (string) $objMenuByCode->niveismenu;
					kreaproducts_debug_log("DoliZSynch Menu: loaded by codigo {$zsCodeRef} for product {$productId}");
				}
			}

			// Fallback to fk_product if menu not found by code.
			if ($menuJsonRaw === '') {
				$sql = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . ((int) $productId) . " ORDER BY rowid DESC " . $db->plimit(1);
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj && !empty($obj->niveismenu)) {
						$menuJsonRaw = (string) $obj->niveismenu;
						kreaproducts_debug_log("DoliZSynch Menu: loaded by fk_product {$productId}");
					}
				}
			}

			if ($menuJsonRaw !== '') {
				$data = json_decode($menuJsonRaw, true);
					if (json_last_error() === JSON_ERROR_NONE && is_array($data) && !empty($data)) {
						kreaproducts_debug_log("DoliZSynch Menu: found " . count($data) . " menu levels for product {$productId}");
						$chips = array();
						foreach ($data as $idx => $menu) {
							$level = isset($menu['nivel']) ? (int) $menu['nivel'] : ($idx + 1);
							$labelRaw = isset($menu['descricao']) ? $menu['descricao'] : $langs->trans("DoliZSynchProductMenu");
							$label = dol_escape_htmltag(dol_trunc($labelRaw, 40));
							$isRequired = !empty($menu['obrigatorio']);
							$badgeTitle = '#' . $level . ' ' . $label;
							$productsHtml = '';
							if (!empty($menu['niveismenuext']) && is_array($menu['niveismenuext'])) {
								$productLines = array();
								foreach ($menu['niveismenuext'] as $prod) {
									$fixo = isset($prod['fixo']) ? (int) $prod['fixo'] : 1;
									$codeRaw = isset($prod['codigo']) ? trim((string) $prod['codigo']) : '';
									$code = dol_escape_htmltag($codeRaw);

									// "fixo=2" means family menu item: expand and show all active products in that family.
									if ($fixo === 2 && $codeRaw !== '') {
										$familyId = 0;
										$subfamilyId = null;
										$familyParts = explode('_', $codeRaw);
										if (isset($familyParts[0]) && is_numeric($familyParts[0])) {
											$familyId = (int) $familyParts[0];
										}
										if (isset($familyParts[1]) && is_numeric($familyParts[1])) {
											$tmpSubFamily = (int) $familyParts[1];
											if ($tmpSubFamily > 0) {
												$subfamilyId = $tmpSubFamily;
											}
										}

										if ($familyId > 0) {
											$familyCacheKey = $familyId . '|' . ((null !== $subfamilyId) ? $subfamilyId : '*');

											if (!isset($zsFamilyDataCache[$familyCacheKey])) {
												$familyLabelRaw = '';
												$sqlFamily = "SELECT family_name, subfamily_name";
												$sqlFamily .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily";
												$sqlFamily .= " WHERE family_id = " . ((int) $familyId);
												if (null !== $subfamilyId) {
													$sqlFamily .= " AND subfamily_id = " . ((int) $subfamilyId);
												}
												if (null === $subfamilyId) {
													$sqlFamily .= " ORDER BY (subfamily_id = 0) DESC, rowid ASC";
												} else {
													$sqlFamily .= " ORDER BY rowid ASC";
												}
												$sqlFamily .= " " . $db->plimit(1);
												$resFamily = $db->query($sqlFamily);
												if ($resFamily && ($objFamily = $db->fetch_object($resFamily))) {
													$familyName = trim((string) $objFamily->family_name);
													$subFamilyName = trim((string) $objFamily->subfamily_name);
													$familyLabelRaw = $familyName;
													if ($subFamilyName !== '' && $subFamilyName !== '-') {
														$familyLabelRaw .= ' >> ' . $subFamilyName;
													}
												}
												if ($familyLabelRaw === '') {
													$familyLabelRaw = isset($prod['descricao']) && $prod['descricao'] !== '' ? (string) $prod['descricao'] : ('Family ' . $familyId);
												}
												$zsFamilyDataCache[$familyCacheKey] = array(
													'label' => $familyLabelRaw
												);
											}

												if (!isset($zsFamilyProductsCache[$familyCacheKey])) {
													$familyProducts = array();
													$seenFamilyProducts = array();
													$sqlFamilyProductsBase = "SELECT z.codigo, z.descricao, z.fk_product";
													$sqlFamilyProductsBase .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct AS z";
													$sqlFamilyProductsBase .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = z.fk_product";
													$sqlFamilyProductsBase .= " AND p.entity IN (" . getEntity('product') . ")";
													$sqlFamilyProductsBase .= " WHERE z.familia = " . ((int) $familyId);
													if (null !== $subfamilyId) {
														$sqlFamilyProductsBase .= " AND z.subfam = " . ((int) $subfamilyId);
													}
													$sqlFamilyProductsBase .= " AND (z.restricted IS NULL OR z.restricted = 0)";
													$sqlFamilyProductsBase .= " AND (p.rowid IS NULL OR p.tosell = 1)";

													$sqlFamilyProducts = $sqlFamilyProductsBase . " ORDER BY z.codigo ASC";
													$resFamilyProducts = $db->query($sqlFamilyProducts);

													if ($resFamilyProducts) {
														while ($objFamilyProduct = $db->fetch_object($resFamilyProducts)) {
															$familyCode = trim((string) $objFamilyProduct->codigo);
														if ($familyCode === '' || isset($seenFamilyProducts[$familyCode])) {
															continue;
														}
														$seenFamilyProducts[$familyCode] = 1;
														$familyProducts[] = array(
															'codigo' => $familyCode,
															'desc' => isset($objFamilyProduct->descricao) ? (string) $objFamilyProduct->descricao : '',
															'fk_product' => !empty($objFamilyProduct->fk_product) ? (int) $objFamilyProduct->fk_product : null
														);
													}
												}
												$zsFamilyProductsCache[$familyCacheKey] = $familyProducts;
											}

											$familyLabelRaw = $zsFamilyDataCache[$familyCacheKey]['label'];
											if ($familyLabelRaw !== '') {
												$productLines[] = '<div class="zs-menu-product"><span class="zs-prod-desc"><strong>' . dol_escape_htmltag(dol_trunc($familyLabelRaw, 80)) . '</strong></span></div>';
											}

											foreach ($zsFamilyProductsCache[$familyCacheKey] as $familyProduct) {
												$familyCodeEscaped = dol_escape_htmltag((string) $familyProduct['codigo']);
												$familyDescEscaped = dol_escape_htmltag(dol_trunc((string) $familyProduct['desc'], 80));
												$familyProdLink = !empty($familyProduct['fk_product']) ? (int) $familyProduct['fk_product'] : 0;
												$familyLinkWrappedCode = '#' . $familyCodeEscaped;
												if ($familyProdLink > 0) {
													$familyLinkWrappedCode = '<a class="zs-prod-link" target="_blank" href="' . DOL_URL_ROOT . '/product/card.php?id=' . $familyProdLink . '">#' . $familyCodeEscaped . '</a>';
												}
												$productLines[] = '<div class="zs-menu-product"><span class="zs-prod-code">' . $familyLinkWrappedCode . '</span><span class="zs-prod-desc">' . $familyDescEscaped . '</span><span class="zs-prod-price"></span></div>';
											}
										}
										continue;
									}

									$descRaw = '';
									if (isset($prod['descricao']) && $prod['descricao'] !== '') {
										$descRaw = $prod['descricao'];
									} elseif ($codeRaw !== '' && is_numeric($codeRaw)) {
										// Fallback: fetch description from synced products by codigo
										if (!isset($zsProdDataCache[$codeRaw])) {
											$sqlDesc = "SELECT descricao, fk_product FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE codigo = " . ((int) $codeRaw) . " LIMIT 1";
											$resDesc = $db->query($sqlDesc);
											if ($resDesc && ($rowDesc = $db->fetch_object($resDesc))) {
												$zsProdDataCache[$codeRaw] = array(
													'desc' => $rowDesc->descricao,
													'fk_product' => !empty($rowDesc->fk_product) ? (int) $rowDesc->fk_product : null,
												);
											} else {
												$zsProdDataCache[$codeRaw] = array('desc' => '', 'fk_product' => null);
											}
										}
										$descRaw = $zsProdDataCache[$codeRaw]['desc'];
									}
									$desc = dol_escape_htmltag(dol_trunc($descRaw, 80));
									// Get product link if available in cache or via lookup
									$fkProdLink = null;
									if (isset($zsProdDataCache[$codeRaw]) && !empty($zsProdDataCache[$codeRaw]['fk_product'])) {
										$fkProdLink = $zsProdDataCache[$codeRaw]['fk_product'];
									} elseif (!isset($zsProdDataCache[$codeRaw]) && $codeRaw !== '' && is_numeric($codeRaw)) {
										$sqlDesc = "SELECT fk_product FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE codigo = " . ((int) $codeRaw) . " LIMIT 1";
										$resDesc = $db->query($sqlDesc);
										if ($resDesc && ($rowDesc = $db->fetch_object($resDesc))) {
											$fkProdLink = !empty($rowDesc->fk_product) ? (int) $rowDesc->fk_product : null;
											$zsProdDataCache[$codeRaw] = array(
												'desc' => $descRaw,
												'fk_product' => $fkProdLink,
											);
										}
									}
									$price = isset($prod['preco']) ? price2num($prod['preco'], 'MU') : 0;
									$priceStr = ($price > 0) ? '(' . price($price, '', '', 0, 2, 2) . ')' : '';
									$linkWrappedCode = '#' . $code;
									if (!empty($fkProdLink)) {
										$linkWrappedCode = '<a class="zs-prod-link" target="_blank" href="' . DOL_URL_ROOT . '/product/card.php?id=' . (int) $fkProdLink . '">#' . $code . '</a>';
									}
										$productLines[] = '<div class="zs-menu-product"><span class="zs-prod-code">' . $linkWrappedCode . '</span><span class="zs-prod-desc">' . $desc . '</span><span class="zs-prod-price">' . $priceStr . '</span></div>';
									}
									$productsHtml = '<div class="zs-menu-products">' . implode('', $productLines) . '</div>';
								}
							$chips[] = '<div class="zs-menu-card">'
								. '<div class="zs-menu-meta">'
								. '<div class="zs-level-badge">'
								. '<div class="zs-badge-title">' . $badgeTitle . '</div>'
								. '</div>'
								. ($isRequired ? '<div class="zs-req-pill">' . dol_escape_htmltag($langs->trans('KreapRequired')) . '</div>' : '')
								. '</div>'
								. $productsHtml
								. '</div>';
						}
						$html = implode('', $chips);
				} else {
					kreaproducts_debug_log("DoliZSynch Menu: niveismenu JSON empty or invalid for product {$productId}");
				}
			} else {
				kreaproducts_debug_log("DoliZSynch Menu: no niveismenu for product {$productId}");
			}

			$zsMenuCache[$productId] = $html;
			return $html;
		};

		// Lightweight styles for the menu chips (printed once)
		if (empty($GLOBALS['DOLIZSYNCH_MENU_STYLES_PRINTED'])) {
			print '<style>
				.zs-menu-cell { min-width: 220px; display: flex; flex-direction: column; gap: 10px; }
				.zs-menu-card { position: relative; padding: 12px 14px; margin: 6px 0; border-radius: 10px; background: linear-gradient(135deg,#ffffff,#f7fbff); border: 1px solid #e4ebf5; box-shadow: 0 2px 8px rgba(20, 40, 60, 0.08); display: grid; grid-template-columns: 220px 1fr; column-gap: 16px; align-items: start; }
				.zs-menu-card::before { content: \"\"; position: absolute; left: 10px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: linear-gradient(180deg,#2c7be5,#6ec1ff); }
				.zs-menu-meta { position: relative; padding-left: 12px; display: flex; flex-direction: column; gap: 6px; grid-column: 1; }
				.zs-level-badge { display: inline-flex; flex-direction: column; gap: 4px; min-width: 140px; padding: 8px 12px; border-radius: 12px; background: var(--colorbackhmenu1, #2c7be5); color: #fff; box-shadow: 0 1px 6px rgba(0,0,0,0.18); }
				.zs-badge-title { font-weight: 800; letter-spacing: 0.3px; }
				.zs-badge-sub { font-size: 11px; font-weight: 700; text-transform: uppercase; opacity: 0.9; }
				.zs-req-pill { margin-top: 6px; padding: 3px 8px; border-radius: 999px; background: #ffeaea; color: #c0392b; font-weight: 700; font-size: 11px; width: fit-content; box-shadow: 0 1px 2px rgba(0,0,0,0.08); text-transform: uppercase; letter-spacing: 0.2px; }
				.zs-menu-products { grid-column: 2; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
				.zs-menu-product { display: inline-flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
				.zs-prod-code { font-weight: 800; color: #1f2937; flex: 0 0 auto; }
				.zs-prod-desc { color: #111827; font-size: 13px; flex: 0 0 auto; }
				.zs-prod-link { font-weight: 800; }
				.zs-menu-card a:link,
				.zs-menu-card a:visited,
				.zs-menu-card a:hover,
				.zs-menu-card a:active,
				.zs-menu-card .classlink {
					color: #000 !important;
					text-decoration: none !important;
				}
				.zs-prod-price { color: #111827; font-weight: 700; flex: 0 0 auto; }
			</style>';
			$GLOBALS['DOLIZSYNCH_MENU_STYLES_PRINTED'] = true;
		}

		// Show current product Menu (ZS) chips (from llxnm_dolizsynch_zsproduct.niveismenu)
		$currentMenuHtml = $renderZsMenu($object->id);
		if (!empty($currentMenuHtml)) {
			print '<div class="fichecenter" style="' . $sectionSpacingStyle . ' margin-bottom:10px;">';
			$title = $langs->trans("DoliZSynchProductMenu");
			$title = str_replace('(ZS)', '', $title);
			$title = trim($title);
			print load_fiche_titre($title, '', '');
			print '<div class="zs-menu-cell">' . $currentMenuHtml . '</div>';
			print '</div>';
		}

		// Hide the child list only when this product is already part of a BOM and has no own components.
		$hasBomParents = !empty($boms);
		$hideChildList = ($hasBomParents && $nbofsubproducts === 0);

		if (!$hideChildList) {
			print '<div class="fichecenter" style="' . $sectionSpacingStyle . '">';
			$atleastonenotdefined = 0;
			print load_fiche_titre($langs->trans("ProductAssociationList"), '', '');
			print '<style>
				#tablelines .krea-label-col.tdoverflowmax150 { max-width: 560px; }
				#tablelines .krea-kreaplot-cell { display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; }
				@media (max-width: 768px) {
					.krea-tablelines-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
					#tablelines { table-layout: auto !important; }
					#tablelines .krea-pos-col { width: 34px !important; min-width: 28px !important; max-width: 40px !important; padding-left: 6px; padding-right: 6px; white-space: nowrap; }
					#tablelines .krea-label-col { white-space: normal !important; overflow: visible !important; text-overflow: clip !important; word-break: break-word; }
					#tablelines .krea-label-col.tdoverflowmax150 { max-width: none !important; }
				}
			</style>';
			print '<form name="formComposedProduct" action="' . $_SERVER['PHP_SELF'] . '" method="post">';
			print '<input type="hidden" name="token" value="' . newToken() . '" />';
			print '<input type="hidden" name="action" value="save_composed_product" />';
			print '<input type="hidden" name="id" value="' . $id . '" />';
			print '<div class="krea-tablelines-wrap">';
			print '<table id="tablelines" class="ui-sortable liste nobottom" style="table-layout: fixed; width: 100%;">';
			$headerCellStyle = 'white-space: nowrap; line-height: 1.1; overflow: hidden; text-overflow: ellipsis;';
			$getShortLabel = function ($key, $fallback) use ($langs) {
				$translated = $langs->trans($key);
				return ($translated === $key) ? $fallback : $translated;
			};
			$headerPosLabel = $getShortLabel('KreapHeaderPosShort', 'Pos.');
			$headerChildLabel = $getShortLabel('KreapHeaderChildShort', 'Ref.');
			$headerNameLabel = $getShortLabel('KreapHeaderLabelShort', 'Nome');
			$headerIngredientCostLabel = $getShortLabel('KreapIngredientCostShort', 'Custo ingr.');
			$headerQtyLabel = $getShortLabel('KreapHeaderQtyShort', 'Qtd.');
			$headerWeightKgLabel = $getShortLabel('KreapWeightKgShort', 'Peso (kg)');
			$headerComponentCostLabel = $getShortLabel('KreapComponentCostShort', 'Custo comp.');
			$headerParentStockLabel = $getShortLabel('KreapParentStockAdjustShort', 'Stock +/-');
			$showKreaProductionLotColumn = (!empty($conf->global->KREAPRODUCTION_ENABLE));
			$componentTableWidths = array(
				'pos' => '44px',
				'ref' => '104px',
				'name_min' => '320px',
				'ingredient_cost' => '116px',
				'stock' => '88px',
				'qty' => '76px',
				'qty_input' => '68px',
				'weight_kg' => '96px',
				'component_cost' => '122px',
				'kreap_lot' => '88px',
				'parent_stock_adjust' => '88px',
				'move' => '18px',
			);
			print '<tr class="liste_titre nodrag nodrop">';
			// Rank
			print '<td class="krea-pos-col" style="width:' . $componentTableWidths['pos'] . '; ' . $headerCellStyle . '">' . $headerPosLabel . '</td>';
			// Product ref
			print '<td style="width:' . $componentTableWidths['ref'] . '; ' . $headerCellStyle . '">' . $headerChildLabel . '</td>';
			// Product label
			print '<td class="krea-label-col" style="min-width:' . $componentTableWidths['name_min'] . '; ' . $headerCellStyle . '">' . $headerNameLabel . '</td>';
			// ZS Menu column removed in list view
			// Ingredient cost (single column)
			print '<td class="right" style="width:' . $componentTableWidths['ingredient_cost'] . '; ' . $headerCellStyle . '">' . $headerIngredientCostLabel . '</td>';
			// Stock
			if (isModEnabled('stock')) {
				print '<td class="right" style="width:' . $componentTableWidths['stock'] . '; ' . $headerCellStyle . '">' . $langs->trans('Stock') . '</td>';
			}
			// Hook fields
			$parameters = array();
			$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters);
			$hookTitle = $hookmanager->resPrint;
			$hookColumnCount = 0;
			if (!empty($hookTitle)) {
				$hookColumnCount = substr_count($hookTitle, '<td') + substr_count($hookTitle, '<th');
			}
			print $hookTitle;
			// Qty in kit
			print '<td class="center" style="width:' . $componentTableWidths['qty'] . '; ' . $headerCellStyle . '">' . $headerQtyLabel . '</td>';
			// Weight in kg
			print '<td class="right" style="width:' . $componentTableWidths['weight_kg'] . '; ' . $headerCellStyle . '">' . $headerWeightKgLabel . '</td>';
			// Valor por componente
			print '<td class="right" style="width:' . $componentTableWidths['component_cost'] . '; ' . $headerCellStyle . '">' . $headerComponentCostLabel . '</td>';
			if ($showKreaProductionLotColumn) {
				$headerKreaLotShort = $getShortLabel('KreapHeaderShowInMoWord', 'Lote');
				$headerKreaLot = $form->textwithpicto($headerKreaLotShort, $langs->trans('kreap_lot_help'));
				print '<td class="center" style="width:' . $componentTableWidths['kreap_lot'] . '; ' . $headerCellStyle . '">' . $headerKreaLot . '</td>';
			}
			// Stoc inc/dev
			print '<td class="center" style="width:' . $componentTableWidths['parent_stock_adjust'] . '; ' . $headerCellStyle . '">' . $headerParentStockLabel . '</td>';
			// Move
			print '<td class="linecolmove" style="width:' . $componentTableWidths['move'] . ';"></td>';
			print '</tr>' . "\n";

			$totalsell = 0;
			$total = 0;
			$totalWeightKg = 0.0;
			if (count($prods_arbo)) {
				foreach ($prods_arbo as $value) {
					$productstatic->fetch($value['id']);
					if ($value['level'] <= 1) {
						print '<tr id="' . $object->sousprods[$parent_label][$value['id']][6] . '" class="drag drop oddeven level1">';
						// Rank
						print '<td class="krea-pos-col">' . $object->sousprods[$parent_label][$value['id']][7] . '</td>';
						$notdefined = 0;
						$nb_of_subproduct = $value['nb'];
						// Product ref
						print '<td>' . $productstatic->getNomUrl(1, 'auto') . '</td>';
						// Product label
						print '<td title="' . dol_escape_htmltag($productstatic->label) . '" class="krea-label-col tdoverflowmax150">' . dol_escape_htmltag($productstatic->label) . '</td>';
						// For avoid a non-numeric value
						$fourn_unitprice = !empty($productstatic->cost_price) ? $productstatic->cost_price : (!empty($product_fourn->fourn_unitprice) ? $product_fourn->fourn_unitprice : $product_fourn->pmp);
						$fourn_remise_percent = (!empty($product_fourn->fourn_remise_percent) ? $product_fourn->fourn_remise_percent : 0);
						$fourn_remise = (!empty($product_fourn->fourn_remise) ? $product_fourn->fourn_remise : 0);
						$unitline = price2num(($fourn_unitprice * (1 - ($fourn_remise_percent / 100)) - $fourn_remise), 'MU');
						$totalline = price2num($value['nb'] * ($fourn_unitprice * (1 - ($fourn_remise_percent / 100)) - $fourn_remise), 'MT');
						$unitWeightKg = kreaproducts_weight_to_kg($productstatic->weight, $productstatic->weight_units);
						$lineWeightKg = $unitWeightKg * (float) $nb_of_subproduct;
						$isMoInputEnabled = (
							kreaproducts_product_extrafield_row_exists($db, (int) $productstatic->id, $conf->entity)
								? kreaproducts_normalize_extrafield_boolean(
									kreaproducts_get_product_extrafield_value($db, (int) $productstatic->id, 'kreap_lot', $conf->entity),
									0
								)
								: 1
						);
						$total +=  $totalline;
						$totalWeightKg += $lineWeightKg;
						print '<td class="right nowraponall" style="width:' . $componentTableWidths['ingredient_cost'] . ';">';
						print ($notdefined ? '' : ($value['nb'] > 1 ? $value['nb'] . 'x ' : '') . '<span class="amount">' . price($unitline, '', '', 0, 0, 4, $conf->currency)) . '</span>';
						print '</td>';
						// Stock
						if (isModEnabled('stock')) {
							print '<td class="right" style="white-space: nowrap;">' . number_format((float)$value['stock'], 4, '.', '') . '</td>';
						}
						// Hook fields
						$parameters = array();
						$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $productstatic);
						print $hookmanager->resPrint;
						// Qty + IncDec
						$custo_ingrediente = $fourn_unitprice * $nb_of_subproduct;
						if ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')) {
							print '<td class="center"><input type="text" value="' . number_format((float) $nb_of_subproduct, 3, '.', '') . '" name="TProduct[' . $productstatic->id . '][qty]" class="right" style="width:' . $componentTableWidths['qty_input'] . ';" /></td>';
							print '<td class="right" style="white-space: nowrap;">' . number_format((float) $lineWeightKg, 3, '.', '') . ' kg</td>';
							print '<td class="right" style="width:' . $componentTableWidths['component_cost'] . '; white-space: nowrap;">' . number_format((float)$custo_ingrediente, 4, '.', '') . " €" . '</td>';
							if ($showKreaProductionLotColumn) {
								print '<td class="center"><span class="krea-kreaplot-cell"><input type="checkbox" name="TProduct[' . (int) $productstatic->id . '][kreap_lot]" value="1" ' . ($isMoInputEnabled ? 'checked' : '') . ' /></span></td>';
							}
							print '<td class="center"><input type="checkbox" name="TProduct[' . $productstatic->id . '][incdec]" value="1" ' . ($value['incdec'] == 1 ? 'checked' : '') . ' /></td>';
						} else {
							print '<td class="right">' . number_format((float) $nb_of_subproduct, 3, '.', '') . '</td>';
							print '<td class="right" style="white-space: nowrap;">' . number_format((float) $lineWeightKg, 3, '.', '') . ' kg</td>';
							print '<td class="right" style="white-space: nowrap;">' . number_format((float)$custo_ingrediente, 4, '.', '') . " €" . '</td>';
							if ($showKreaProductionLotColumn) {
								print '<td class="center"><span class="krea-kreaplot-cell"><input type="checkbox" ' . ($isMoInputEnabled ? 'checked' : '') . ' readonly disabled></span></td>';
							}
							print '<td>' . ($value['incdec'] == 1 ? 'x' : '') . '</td>';
						}
						// Move action
						print '<td class="linecolmove tdlineupdown center"></td>';
						print '</tr>' . "\n";
					} else {
						$hide = '';
						if (!getDolGlobalString('PRODUCT_SHOW_SUB_SUB_PRODUCTS')) {
							$hide = ' hideobject';
						}
						print '<tr class="oddeven' . $hide . '" id="sub-' . $value['id_parent'] . '" data-ignoreidfordnd=1>';
						$productstatic->ref = $value['ref'];
						// Rank
						print '<td class="krea-pos-col"></td>';
						// Product ref
						print '<td>';
						for ($i = 0; $i < $value['level']; $i++) {
							print ' &nbsp; &nbsp; ';
						}
						print $productstatic->getNomUrl(1, 'auto');
						print '</td>';
						// Product label
						print '<td class="krea-label-col">' . dol_escape_htmltag($productstatic->label) . '</td>';
						// Cost placeholder for nested rows
						print '<td>&nbsp;</td>';
						// Stock
						if (isModEnabled('stock')) {
							print '<td></td>';
						}
						// Hook fields
						$parameters = array();
						$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $productstatic);
						print $hookmanager->resPrint;
						// Qty in kit
						print '<td class="right">' . number_format((float) $value['nb'], 3, '.', '') . '</td>';
						// Weight in kg placeholder
						print '<td>&nbsp;</td>';
						// Cost per component placeholder
						print '<td>&nbsp;</td>';
						if ($showKreaProductionLotColumn) {
							print '<td>&nbsp;</td>';
						}
						// Inc/dec
						print '<td>&nbsp;</td>';
						// Action move
						print '<td>&nbsp;</td>';
						print '</tr>' . "\n";
					}
				}
				// Total
				print '<tr class="liste_total">';
				$colspanBeforeAmount = 4; // Position, Ref, Label, Ingredient cost
				if (isModEnabled('stock')) {
					$colspanBeforeAmount++;
				}
				$colspanBeforeAmount += $hookColumnCount;
				$colspanBeforeAmount += 1; // Qty col
				print '<td class="liste_total right" colspan="' . $colspanBeforeAmount . '">' . $langs->trans("TotalBuyingPriceMinShort") . '</td>';
				print '<td class="liste_total right" style="white-space: nowrap;">' . number_format((float) $totalWeightKg, 3, '.', '') . ' kg</td>';
				print '<td class="liste_total right" style="white-space: nowrap;">';
				if ($atleastonenotdefined) {
					print $langs->trans("Unknown") . ' (' . $langs->trans("SomeSubProductHaveNoPrices") . ')';
				}
				print($atleastonenotdefined ? '' : price($total, '', '', 0, 0, 4, $conf->currency));
				print '</td>';
				if ($showKreaProductionLotColumn) {
					print '<td></td>';
				}
				print '<td class="center">'; // Inc/dec col
				if ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')) {
					print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
				}
				print '</td>';
				print '<td></td>'; // Move col
				print '</tr>' . "\n";
			} else {
				// Show an empty state row when no components exist but the table is displayed.
				$colspan = 9; // Position, Ingredient, Label, Cost, Stock?, Qty, Weight, Cost per component, Inc/Dec
				if (isModEnabled('stock')) {
					$colspan++; // account for stock column
				}
				$colspan += $hookColumnCount;
				if ($showKreaProductionLotColumn) {
					$colspan++;
				}
				print '<tr class="oddeven">';
				print '<td colspan="' . $colspan . '" class="opacitymedium">' . $langs->trans("None") . '</td>';
				print '</tr>';
			}
			print '</table>';
			print '</div>';
			print '</form>';
			print '</div>';
			// Open product links in a new tab on the association list
			print '<script>
				(function() {
					var links = document.querySelectorAll("#tablelines a");
					links.forEach(function(a) {
						if (!a.target || a.target === "_self") {
							a.target = "_blank";
							a.rel = "noopener noreferrer";
						}
					});
				})();
			</script>';
		}

		// Form with product to add (moved before simulator); hide when the child list is hidden
		if (!$hideChildList && ((empty($action) || $action == 'view' || $action == 'edit' || $action == 'search' || $action == 're-edit') && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')))) {
			$rowspan = 1;
			if (isModEnabled('categorie')) {
				$rowspan++;
			}
			print '<form action="' . DOL_URL_ROOT . '/custom/kreaproducts/associatedProducts.php?id=' . $id . '" method="POST">';
			print '<input type="hidden" name="action" value="search">';
			print '<input type="hidden" name="id" value="' . $id . '">';
			print '<div class="inline-block">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print $langs->trans("KeywordFilter") . ': ';
			print '<input type="text" name="key" value="' . $key . '"> &nbsp; ';
			print '</div>';
			if (isModEnabled('categorie')) {
				require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
				print '<div class="inline-block">' . $langs->trans("CategoryFilter") . ': ';
				print $form->select_all_categories(Categorie::TYPE_PRODUCT, $parent, 'parent') . ' &nbsp; </div>';
				print ajax_combobox('parent');
			}
			print '<div class="inline-block" style="margin-top:6px;"><input type="submit" class="button small" value="' . $langs->trans("Search") . '"></div>';
			print '</form>';
			// Add a little breathing room before the next section
			print '<div style="margin-bottom: 14px;"></div>';
		}

		// List of products (search results) - keep before the metrics block
		if ($action == 'search') {
			print '<form action="' . DOL_URL_ROOT . '/custom/kreaproducts/associatedProducts.php?id=' . $id . '" method="post">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="add_prod">';
			print '<input type="hidden" name="id" value="' . $id . '">';
			print '<table class="noborder centpercent">';
			$headerCellStyle = 'white-space: normal; line-height: 1.2; word-wrap: break-word;';
			print '<tr class="liste_titre">';
			print '<th class="liste_titre" style="' . $headerCellStyle . '">' . $langs->trans("ComposedProduct") . '</th>';
			print '<th class="liste_titre" style="' . $headerCellStyle . '">' . $langs->trans("Label") . '</th>';
			print '<th class="liste_titre right" style="' . $headerCellStyle . '">' . $langs->trans("Qty") . '</th>';
			print '<th class="center" style="' . $headerCellStyle . '">' . $langs->trans('KreapParentStockAdjust') . '</th>';
			print '</tr>';
			if ($resql) {
				$num = $db->num_rows($resql);
				$i = 0;
				if ($num == 0) {
					print '<tr><td colspan="4">' . $langs->trans("NoMatchFound") . '</td></tr>';
				}
				$MAX = 100;
				while ($i < min($num, $MAX)) {
					$objp = $db->fetch_object($resql);
					if ($objp->rowid != $id) {
						$prod_arbo = new Product($db);
						$prod_arbo->id = $objp->rowid;
						if (getDolGlobalString('PRODUCT_USE_DEPRECATED_ASSEMBLY_AND_STOCK_KIT_TYPE')) {
							if ($prod_arbo->type == 2 || $prod_arbo->type == 3) {
								$is_pere = 0;
								$prod_arbo->get_sousproduits_arbo();
								$prods_arbo = $prod_arbo->get_arbo_each_prod();
								if (count($prods_arbo) > 0) {
									foreach ($prods_arbo as $key => $value) {
										if ($value[1] == $id) {
											$is_pere = 1;
										}
									}
								}
								if ($is_pere == 1) {
									$i++;
									continue;
								}
							}
						}
						print "\n";
						print '<tr class="oddeven">';
						$productstatic->id = $objp->rowid;
						$productstatic->ref = $objp->ref;
						$productstatic->label = $objp->label;
						$productstatic->type = $objp->type;
						$productstatic->entity = $objp->entity;
						$productstatic->status = $objp->status;
						$productstatic->status_buy = $objp->status_buy;
						print '<td>' . $productstatic->getNomUrl(1, '', 24) . '</td>';
						$labeltoshow = $objp->label;
						if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($objp->labelm)) {
							$labeltoshow = $objp->labelm;
						}
						print '<td>' . $labeltoshow . '</td>';
						if ($object->is_sousproduit($id, $objp->rowid)) {
							$qty = $object->is_sousproduit_qty;
							$incdec = $object->is_sousproduit_incdec;
						} else {
							$qty = 0;
							$incdec = 0;
						}
						$qtyInputValue = ($qty === '' || $qty === null) ? '' : number_format((float) $qty, 3, '.', '');
						print '<td class="right"><input type="hidden" name="prod_id_' . $i . '" value="' . $objp->rowid . '"><input type="text" size="2" name="prod_qty_' . $i . '" value="' . $qtyInputValue . '"></td>';
						print '<td class="center">';
						if ($qty) {
							print '<input type="checkbox" name="prod_incdec_' . $i . '" value="1" ' . ($incdec ? 'checked' : '') . '>';
						} else {
							print '<input type="checkbox" name="prod_incdec_' . $i . '" value="1" checked>';
						}
						print '</td>';
						print '</tr>';
					}
					$i++;
				}
				if ($num > $MAX) {
					print '<tr class="oddeven">';
					print '<td><span class="opacitymedium">' . $langs->trans("More") . '...</span></td>';
					print '<td></td>';
					print '<td></td>';
					print '<td></td>';
					print '</tr>';
				}
			} else {
				dol_print_error($db);
			}
			print '</table>';
			print '<input type="hidden" name="max_prod" value="' . $i . '">';
			if ($num > 0) {
				print '<div class="center">';
				print '<input type="submit" class="button button-save" name="save" value="' . $langs->trans("Add") . '/' . $langs->trans("Update") . '">';
				print '<input type="submit" class="button button-cancel" name="cancel" value="' . $langs->trans("Cancel") . '">';
				print '</div>';
			}
			print '</form>';
		}

		/**
		 * This code snippet checks if the current product acts as a parent in any **assemble BOM** (Bill of Materials) in the system.
		 * If a BOM of type "assemble" exists (where `bomtype = 0`), the script fetches and displays the **components (sons)**
		 * associated with the BOM. Each component is displayed as a clickable link that directs the user to the component's
		 * detailed page.
		 *
		 * Additionally, this logic only executes if the BOM module is enabled in the Dolibarr system.
		 */
		if (!empty($conf->bom->enabled)) {
			$productRef = trim((string) $object->ref);
			$productRefEscaped = ($productRef !== '') ? $db->escape($productRef) : '';

			// Fetch all BOMs and the components for the current product
			$sql_bom = "SELECT b.rowid AS bom_id, b.ref AS bom_ref, b.bomtype,
	                               COALESCE(bl.fk_product, cb.fk_product) AS fk_product_component,
	                               bl.qty as line_qty,
	                               bl.position AS line_position,
	                               COALESCE(p.ref, cprod.ref) AS ref,
	                               COALESCE(p.label, cprod.label) AS label,
	                               COALESCE(p.cost_price, cprod.cost_price) AS cost_price,
	                               COALESCE(p.pmp, cprod.pmp) AS pmp,
	                               COALESCE(p.stock, cprod.stock) AS stock_reel,
	                               COALESCE(p.weight, cprod.weight) AS weight,
	                               COALESCE(p.weight_units, cprod.weight_units) AS weight_units
                FROM " . MAIN_DB_PREFIX . "bom_bom AS b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline AS bl ON b.rowid = bl.fk_bom
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS cb ON cb.rowid = bl.fk_bom_child
                LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = bl.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "product AS cprod ON cprod.rowid = cb.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "product AS bp ON bp.rowid = b.fk_product
                WHERE (b.fk_product = " . (int)$object->id;
			if ($productRefEscaped !== '') {
				$sql_bom .= " OR bp.ref = '" . $productRefEscaped . "'";
			}
			$sql_bom .= ")
                AND b.bomtype IN (0,1)
                AND b.status IN (0,1)
                AND b.entity IN (0," . getEntity('bom') . ")
                AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                AND (p.rowid IS NULL OR p.entity IN (0," . getEntity('product') . "))
                AND (cprod.rowid IS NULL OR cprod.entity IN (0," . getEntity('product') . "))
                AND (bp.rowid IS NULL OR bp.entity IN (0," . getEntity('product') . "))
                AND COALESCE(bl.fk_product, cb.fk_product) IS NOT NULL
                ORDER BY b.rowid ASC, bl.position ASC, bl.rowid ASC"; // . " AND b.bomtype = 1";

			$resql_bom = $db->query($sql_bom);
			$componentsByBom = array();

			// Check if the query returns results
			if ($resql_bom) {
				while ($obj_bom = $db->fetch_object($resql_bom)) {
					$productComponentId = (int) $obj_bom->fk_product_component;
					if ($productComponentId <= 0) {
						continue;
					}

					$bomId = (int) $obj_bom->bom_id;
					if (!isset($componentsByBom[$bomId])) {
						$componentsByBom[$bomId] = array(
							'bom_id' => $bomId,
							'bom_ref' => (string) $obj_bom->bom_ref,
							'bomtype' => (int) $obj_bom->bomtype,
							'lines' => array(),
						);
					}

						$componentsByBom[$bomId]['lines'][] = array(
							'product_id' => $productComponentId,
							'ref' => (string) $obj_bom->ref,
							'label' => (string) $obj_bom->label,
							'qty' => (float) price2num($obj_bom->line_qty, 'MS'),
							'line_position' => (int) $obj_bom->line_position,
							'cost_price' => (float) price2num($obj_bom->cost_price, 'MU'),
							'pmp' => (float) price2num($obj_bom->pmp, 'MU'),
							'stock_reel' => (float) price2num($obj_bom->stock_reel, 'MS'),
							'weight' => (float) price2num($obj_bom->weight, 'MS'),
							'weight_units' => $obj_bom->weight_units,
							'mo_input_enabled' => (
								kreaproducts_product_extrafield_row_exists($db, $productComponentId, $conf->entity)
									? kreaproducts_normalize_extrafield_boolean(
										kreaproducts_get_product_extrafield_value($db, $productComponentId, 'kreap_lot', $conf->entity),
										0
									)
									: 1
							),
						);
				}
				$db->free($resql_bom);
			}
			if (count($componentsByBom) > 0) {
				if (empty($GLOBALS['KREAPRODUCTS_MRP_COMPONENTS_TABLE_STYLE_PRINTED'])) {
					print '<style>
							@media (max-width: 768px) {
								.krea-mrp-components-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
								.krea-mrp-components-table { table-layout: auto !important; }
								.krea-mrp-components-table .krea-pos-col { width: 34px !important; min-width: 28px !important; max-width: 40px !important; padding-left: 6px; padding-right: 6px; white-space: nowrap; }
								.krea-mrp-components-table .krea-label-col { white-space: normal !important; overflow: visible !important; text-overflow: clip !important; word-break: break-word; }
							}
							.krea-bom-title-link,
							.krea-bom-title-link:link,
							.krea-bom-title-link:visited,
							.krea-bom-title-link:hover,
							.krea-bom-title-link:active,
								.krea-bom-title-link:focus {
									color: inherit !important;
									text-decoration: none !important;
									font: inherit;
									cursor: inherit;
								}
								.krea-kreaplot-cell {
									display: inline-flex;
									align-items: center;
									justify-content: center;
									gap: 8px;
									white-space: nowrap;
								}
								.krea-kreaplot-cell form {
									display: inline-flex;
									align-items: center;
									margin: 0;
								}
								.krea-kreaplot-toggle {
									display: inline-flex;
									align-items: center;
									cursor: pointer;
								}
								.krea-kreaplot-toggle input[type="checkbox"] {
									pointer-events: none;
								}
							</style>';
					$GLOBALS['KREAPRODUCTS_MRP_COMPONENTS_TABLE_STYLE_PRINTED'] = true;
				}

				$getShortLabel = function ($key, $fallback) use ($langs) {
					$translated = $langs->trans($key);
					return ($translated === $key) ? $fallback : $translated;
				};
				$headerCellStyle = 'white-space: nowrap; line-height: 1.1; overflow: hidden; text-overflow: ellipsis;';
				$headerPosLabel = $getShortLabel('KreapHeaderPosShort', 'Pos.');
				$headerChildLabel = $getShortLabel('KreapHeaderChildShort', 'Ref.');
				$headerNameLabel = $getShortLabel('KreapHeaderLabelShort', 'Nome');
				$headerIngredientCostLabel = $getShortLabel('KreapIngredientCostShort', 'Custo ingr.');
				$headerQtyLabel = $getShortLabel('KreapHeaderQtyShort', 'Qtd.');
				$headerWeightKgLabel = $getShortLabel('KreapWeightKgShort', 'Peso (kg)');
				$headerComponentCostLabel = $getShortLabel('KreapComponentCostShort', 'Custo comp.');
				$showKreaProductionMoColumn = (!empty($conf->global->KREAPRODUCTION_ENABLE));
				$hasStockColumn = isModEnabled('stock');
				$mrpNameMinWidth = '300px';
				if ($showKreaProductionMoColumn && $hasStockColumn) {
					$mrpNameMinWidth = '240px';
				} elseif ($showKreaProductionMoColumn || $hasStockColumn) {
					$mrpNameMinWidth = '270px';
				}
				$mrpWidths = array(
					'pos' => '44px',
					'ref' => '104px',
					'name_min' => $mrpNameMinWidth,
					'ingredient_cost' => '116px',
					'stock' => '88px',
					'qty' => '76px',
					'weight_kg' => '96px',
					'component_cost' => '122px',
					'kreap_lot' => '88px',
				);

				foreach ($componentsByBom as $bomData) {
					$bomId = (int) $bomData['bom_id'];
					$bomRefRaw = trim((string) $bomData['bom_ref']);
					$bomLabelForTitle = ($bomRefRaw !== '' ? $bomRefRaw : ($langs->trans('kreaproducts_BOM') . ' #' . $bomId));
					$bomUrl = dol_buildpath('/bom/bom_card.php?id=' . $bomId, 1);

					print '<div class="fichecenter" style="' . $sectionSpacingStyle . '">';

					$title = 'MRP - ' . $langs->trans("BOMReference");
					$title .= ' <a class="krea-bom-title-link" href="' . $bomUrl . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($bomLabelForTitle) . '</a>';
					print load_fiche_titre($title, '', '');

					print '<div class="krea-mrp-components-wrap">';
					print '<table class="krea-mrp-components-table liste nobottom" style="table-layout: fixed; width: 100%;">';
					print '<tr class="liste_titre">';
					print '<td class="krea-pos-col" style="width:' . $mrpWidths['pos'] . '; ' . $headerCellStyle . '">' . $headerPosLabel . '</td>';
					print '<td style="width:' . $mrpWidths['ref'] . '; ' . $headerCellStyle . '">' . $headerChildLabel . '</td>';
					print '<td class="krea-label-col" style="min-width:' . $mrpWidths['name_min'] . '; ' . $headerCellStyle . '">' . $headerNameLabel . '</td>';
					print '<td class="right" style="width:' . $mrpWidths['ingredient_cost'] . '; ' . $headerCellStyle . '">' . $headerIngredientCostLabel . '</td>';
					if ($hasStockColumn) {
						print '<td class="right" style="width:' . $mrpWidths['stock'] . '; ' . $headerCellStyle . '">' . $langs->trans('Stock') . '</td>';
					}
						print '<td class="center" style="width:' . $mrpWidths['qty'] . '; ' . $headerCellStyle . '">' . $headerQtyLabel . '</td>';
						print '<td class="right" style="width:' . $mrpWidths['weight_kg'] . '; ' . $headerCellStyle . '">' . $headerWeightKgLabel . '</td>';
						print '<td class="right" style="width:' . $mrpWidths['component_cost'] . '; ' . $headerCellStyle . '">' . $headerComponentCostLabel . '</td>';
						if ($showKreaProductionMoColumn) {
							$headerKreaLotShort = $getShortLabel('KreapHeaderShowInMoWord', 'Lote');
							$headerKreaLot = $form->textwithpicto($headerKreaLotShort, $langs->trans('kreap_lot_help'));
							print '<td class="center" style="width:' . $mrpWidths['kreap_lot'] . '; ' . $headerCellStyle . '">' . $headerKreaLot . '</td>';
						}
						print '</tr>';

					$linePosition = 1;
					$totalComponentCost = 0.0;
					$totalWeightKg = 0.0;
					foreach ((array) $bomData['lines'] as $component) {
						$unitCost = (float) $component['cost_price'];
						if ($unitCost <= 0 && !empty($component['pmp'])) {
							$unitCost = (float) $component['pmp'];
						}
						$lineQty = (float) $component['qty'];
						$unitWeightKg = kreaproducts_weight_to_kg($component['weight'], $component['weight_units']);
						$lineWeightKg = $unitWeightKg * $lineQty;
						$lineCost = (float) price2num($unitCost * $lineQty, 'MT');
						$displayPosition = ((int) $component['line_position'] > 0 ? (int) $component['line_position'] : $linePosition);
						$totalWeightKg += $lineWeightKg;
						$totalComponentCost += $lineCost;

						print '<tr class="oddeven">';
						print '<td class="krea-pos-col">' . $displayPosition . '</td>';
						print '<td><a href="' . dol_buildpath('/product/card.php?id=' . (int) $component['product_id'], 1) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($component['ref']) . '</a></td>';
						print '<td class="krea-label-col">' . dol_escape_htmltag($component['label']) . '</td>';
						print '<td class="right nowraponall">';
						if ($unitCost > 0) {
							print '<span class="amount">' . price($unitCost, '', '', 0, 0, 4, $conf->currency) . '</span>';
						} else {
							print '&mdash;';
						}
						print '</td>';
						if ($hasStockColumn) {
							print '<td class="right" style="white-space: nowrap;">' . number_format((float) $component['stock_reel'], 4, '.', '') . '</td>';
						}
						print '<td class="center">' . number_format((float) $lineQty, 3, '.', '') . '</td>';
						print '<td class="right" style="white-space: nowrap;">' . number_format((float) $lineWeightKg, 3, '.', '') . ' kg</td>';
							print '<td class="right" style="white-space: nowrap;">';
							if ($unitCost > 0) {
								print '<span class="amount">' . price($lineCost, '', '', 0, 0, 4, $conf->currency) . '</span>';
							} else {
								print '&mdash;';
							}
							print '</td>';
							if ($showKreaProductionMoColumn) {
								$componentProductId = (int) $component['product_id'];
								$isMoInputEnabled = ((int) $component['mo_input_enabled'] === 1);
								$toggleValue = ($isMoInputEnabled ? 0 : 1);

								print '<td class="center" style="white-space: nowrap;">';
								print '<span class="krea-kreaplot-cell">';
								if ($usercancreate) {
									print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '">';
									print '<input type="hidden" name="token" value="' . newToken() . '">';
									print '<input type="hidden" name="action" value="toggle_component_kreap_lot">';
									print '<input type="hidden" name="component_product_id" value="' . $componentProductId . '">';
									print '<input type="hidden" name="value" value="' . $toggleValue . '">';
									print '<label class="krea-kreaplot-toggle" onclick="this.closest(\'form\').submit(); return false;" aria-label="' . dol_escape_htmltag($langs->trans('kreap_lot')) . '">';
									print '<input type="checkbox" ' . ($isMoInputEnabled ? 'checked' : '') . ' readonly disabled>';
									print '</label>';
									print '</form>';
								} else {
									print '<input type="checkbox" ' . ($isMoInputEnabled ? 'checked' : '') . ' readonly disabled>';
								}
								print '</span>';
								print '</td>';
							}
							print '</tr>';

						$linePosition++;
					}
					$totalLabelColspan = ($hasStockColumn ? 6 : 5);
					print '<tr class="liste_total">';
						print '<td class="right" colspan="' . $totalLabelColspan . '">' . $langs->trans("Total") . '</td>';
						print '<td class="right" style="white-space: nowrap;">' . number_format((float) $totalWeightKg, 3, '.', '') . ' kg</td>';
						print '<td class="right" style="white-space: nowrap;"><span class="amount">' . price($totalComponentCost, '', '', 0, 0, 4, $conf->currency) . '</span></td>';
						if ($showKreaProductionMoColumn) {
							print '<td></td>';
						}
						print '</tr>';

					print '</table>';
					print '</div>';
					print '</div>';
				}
			}
		}

		// Lista de kits com este produto como componente
		if (count($prodsfather) > 0) {
			print '<div class="fichecenter" style="' . $sectionSpacingStyle . '">';
			print '<style>
				@media (max-width: 768px) {
					#krea-parentlist-title .titre,
					#krea-parentlist-title .titre > span { display: block; width: 100%; }
				}
			</style>';
			print load_fiche_titre($langs->trans("ProductParentList"), '', '', 0, 'krea-parentlist-title');
			print '<table class="liste">';
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('ParentProducts') . '</td>';
			print '<td>' . $langs->trans('Label') . '</td>';
			print '<td class="right">' . $langs->trans('Qty') . '</td>';
			print '</tr>';
			foreach ($prodsfather as $value) {
				$idprod = $value["id"];
				$productstatic->id = $idprod;
				$productstatic->type = $value["fk_product_type"];
				$productstatic->ref = $value['ref'];
				$productstatic->label = $value['label'];
				$productstatic->entity = $value['entity'];
				$productstatic->status = $value['status'];
				$productstatic->status_buy = $value['status_buy'];
				$qtyValue = (float) price2num($value['qty'], 'MS');

				print '<tr class="oddeven">';
				print '<td>' . $productstatic->getNomUrl(1, 'auto') . '</td>';
				print '<td>' . dol_escape_htmltag($productstatic->label) . '</td>';
				print '<td class="right">' . number_format($qtyValue, 3, '.', '') . ' un</td>';
				print '</tr>';
			}
			print '</table>';
			print '</div>';
		}

		/*
    	* This code retrieves the menus where a specific product exists in the 'niveismenu' field,
    	* checks all JSON levels for the product code, and returns the corresponding menu details.
    	* 
    	* - Retrieves the current product's reference.
    	* - Fetches all records with 'niveismenu' from the 'dolizsynch_zsproduct' and 'product' tables.
    	* - Decodes the JSON 'niveismenu' field and iterates over the nested structure.
    	* - If the product's code matches, the corresponding menu information is stored.
    	* - Duplicates are removed, and the result is displayed in a table format.
    	* - Handles invalid JSON and SQL errors.
    	*/
		if (!empty($conf->dolizsynch->enabled)) {
			// Get current product's ref as codigo
			$current_codigo = $object->ref;

			// Fetch all records with niveismenu
			$sql_menu = "SELECT b.rowid, p.rowid as product_id, p.ref, p.label, b.niveismenu";
			$sql_menu .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct AS b";
			$sql_menu .= " JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product";

			$resql_menu = $db->query($sql_menu);
			$menus = [];

			if ($resql_menu) {
				while ($obj_menu = $db->fetch_object($resql_menu)) {
					if (!empty($obj_menu->niveismenu)) {
						$niveismenuData = json_decode($obj_menu->niveismenu, true);
						if (json_last_error() === JSON_ERROR_NONE && is_array($niveismenuData)) {
							foreach ($niveismenuData as $menuLevel) {
								if (isset($menuLevel['niveismenuext']) && is_array($menuLevel['niveismenuext'])) {
									foreach ($menuLevel['niveismenuext'] as $produto) {
										if (isset($produto['codigo']) && (string)$produto['codigo'] == (string)$current_codigo) {
											$menus[] = array(
												'menu_id' => $obj_menu->product_id,
												'ref' => $obj_menu->ref,
												'label' => $obj_menu->label,
												'descricao' => isset($menuLevel['descricao']) ? $menuLevel['descricao'] : 'N/A'
											);
											break; // Assuming a product is only once per menuLevel
										}
									}
								}
							}
						} else {
							dol_syslog("Invalid JSON in 'niveismenu' for product ID " . $obj_menu->rowid, LOG_ERR);
						}
					}
				}
			} else {
				dol_syslog("Error executing SQL query for 'niveismenu': " . $db->lasterror(), LOG_ERR);
			}

			// Remove duplicate menus
			$menus = array_unique($menus, SORT_REGULAR);

			if (count($menus) > 0) {
				// Display the table
				//print '<br>';
				print '<div class="fichecenter" style="' . $sectionSpacingStyle . '">';
				print load_fiche_titre($langs->trans("MenuWhereProductExistsAndOriginProduct"), '', '');
				print '<table class="liste">';
				print '<tr class="liste_titre">';
				print '<td>' . $langs->trans('Reference') . '</td>';
				print '<td>' . $langs->trans('Label') . '</td>';
				print '</tr>';

				foreach ($menus as $menu) {
					print '<tr class="oddeven">';
					print '<td><a href="' . dol_buildpath('/product/card.php?id=' . urlencode($menu['menu_id']), 1) . '" target="_blank">' . htmlspecialchars($menu['ref']) . '</a></td>';

					print '<td>' . htmlspecialchars($menu['label']) . '</td>';
					print '</tr>';
				}

				print '</table>';
				print '</div>';
			}
		}

		// Unified nutrition and allergen workspace.
		$sectionMarginStyle = $sectionSpacingStyle;
		$sectionMarginStyleLarge = 'margin-top: 32px;';
		$tableMarginStyle = 'margin-top: 10px;';
		$nutritionAllergenMode = 0;
		if (!$productIsFood) {
			$nutritionAllergenMode = 2;
		} elseif ((string) ($object->array_options['options_kreap_calc_nut'] ?? '') === '1'
			&& (string) ($object->array_options['options_kreap_calc_allergens'] ?? '') === '1') {
			$nutritionAllergenMode = 1;
		}
		$isEditingNutritionAllergens = ($action === 'edit_nutrition_allergens' && $nutritionAllergenMode === 0 && $canManageNutritionAllergens);
		$llmProvider = strtolower(trim((string) getDolGlobalString('KREAPRODUCTS_LLM_PROVIDER')));
		$showLlmProductDataModal = $canUseLlmProductData && $nutritionAllergenMode === 0 && $llmProvider !== '';
		$openLlmProductDataModal = in_array($action, array('generate_llm_product_data', 'apply_llm_product_data'), true);
		$showCopyProductDataModal = $canManageNutritionAllergens
			&& $nutritionAllergenMode !== 2
			&& ($enableCopyAllergensToProduct || $enableCopyAvgToProduct);

		$nutritionalFields = array(
			'KreaProducts_Energy_kcal' => 'energy_kcal',
			'KreaProducts_Energy_kj' => 'energy_kj',
			'KreaProducts_Fat' => 'fat',
			'KreaProducts_Saturates' => 'saturates',
			'KreaProducts_Carbohydrates' => 'carbohydrates',
			'KreaProducts_Sugars' => 'sugars',
			'KreaProducts_Protein' => 'protein',
			'KreaProducts_Salt' => 'salt',
			'KreaProducts_Fiber' => 'fiber',
		);
		$nutritionalData = array_fill_keys(array_values($nutritionalFields), null);
		$sql = 'SELECT n.'.implode(', n.', array_values($nutritionalFields));
		$sql .= ' FROM '.MAIN_DB_PREFIX.'kreaproducts_nutritional AS n';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = n.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE n.fk_product = '.(int) $object->id.' ORDER BY n.rowid ASC LIMIT 1';
		$resql = $db->query($sql);
		if ($resql && ($row = $db->fetch_object($resql))) {
			foreach ($nutritionalData as $field => $unused) {
				$nutritionalData[$field] = $row->{$field};
			}
		}
		if ($resql) {
			$db->free($resql);
		}

		$allergenDictionary = array();
		$sql = 'SELECT rowid, code, icon FROM '.MAIN_DB_PREFIX.'c_kreaproducts WHERE active = 1 ORDER BY label';
		$resql = $db->query($sql);
		if ($resql) {
			while ($row = $db->fetch_object($resql)) {
				$allergenDictionary[(int) $row->rowid] = array(
					'label' => $langs->trans($row->code),
					'icon' => (string) $row->icon,
				);
			}
			$db->free($resql);
		} else {
			dol_syslog('Unable to load the allergen dictionary: '.$db->lasterror(), LOG_ERR);
		}
		$allergenSelectOptions = array();
		foreach ($allergenDictionary as $allergenId => $allergen) {
			$allergenSelectOptions[$allergenId] = $allergen['label'];
		}

		$savedAllergensArray = array();
		$savedAllergensTracesArray = array();
		$sql = 'SELECT pa.fk_allergen, pa.traces';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'kreaproducts_productallergens AS pa';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = pa.fk_product';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= ' WHERE pa.fk_product = '.(int) $object->id;
		$resql = $db->query($sql);
		if ($resql) {
			while ($row = $db->fetch_object($resql)) {
				if ((int) $row->traces === 1) {
					$savedAllergensTracesArray[] = (int) $row->fk_allergen;
				} else {
					$savedAllergensArray[] = (int) $row->fk_allergen;
				}
			}
			$db->free($resql);
		} else {
			dol_syslog('Unable to load saved product allergens: '.$db->lasterror(), LOG_ERR);
		}

		print '<div class="fichecenter" id="kreaproducts-nutrition-allergens" style="'.$sectionMarginStyle.'">';
		print load_fiche_titre($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_TITLE'), '', '');
		if ($isEditingNutritionAllergens) {
			print '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens">';
			print '<input type="hidden" name="action" value="save_nutrition_allergens">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
		}
		$isCalculatedNutritionMode = ($nutritionAllergenMode === 1);
		$nutritionAllergenColumnCount = $isCalculatedNutritionMode ? 14 : 2;
		$nutritionAllergenLabelColspan = $isCalculatedNutritionMode ? 3 : 1;
		$nutritionAllergenValueColspan = $isCalculatedNutritionMode ? 11 : 1;
		if ($isCalculatedNutritionMode) {
			dol_include_once('/kreaproducts/class/KreaProductsNutritionalCalculator.class.php');
			KreaProductsNutritionalCalculator::printNutritionalTableStyles();
			print '<div class="krea-nutrition-table-wrap" style="'.$tableMarginStyle.'">';
		}
		print '<table class="liste centpercent kreaproducts-nutrition-allergens-table'.($isCalculatedNutritionMode ? ' krea-nutrition-table' : '').'"'.($isCalculatedNutritionMode ? '' : ' style="'.$tableMarginStyle.'"').'>';
		print '<tr'.($isCalculatedNutritionMode ? ' class="krea-mode-row"' : '').'><td class="titlefield" colspan="'.$nutritionAllergenLabelColspan.'">'.$langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_MODE').'</td><td colspan="'.$nutritionAllergenValueColspan.'">';
		if ($canManageNutritionAllergens && !$isEditingNutritionAllergens) {
			print '<form method="post" class="inline-block" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens">';
			print '<input type="hidden" name="action" value="save_nutrition_allergens_mode">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<select name="nutrition_allergen_mode" class="minwidth300">';
			foreach (array(
				0 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_ENTERED',
				1 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_CALCULATED',
				2 => 'NaoEUmAlimento',
			) as $modeValue => $modeLabel) {
				print '<option value="'.$modeValue.'"'.($nutritionAllergenMode === $modeValue ? ' selected' : '').'>';
				print dol_escape_htmltag($langs->trans($modeLabel)).'</option>';
			}
			print '</select> ';
			print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
			print '</form>';
		} else {
			$modeLabels = array(
				0 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_ENTERED',
				1 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_CALCULATED',
				2 => 'NaoEUmAlimento',
			);
			print dol_escape_htmltag($langs->trans($modeLabels[$nutritionAllergenMode]));
		}
		print '</td></tr>';

		if ($nutritionAllergenMode === 2) {
			print '<tr><td colspan="'.$nutritionAllergenColumnCount.'"><span class="opacitymedium">';
			print $langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_NOT_FOOD_HELP');
			print '</span></td></tr>';
		} else {
			print '<tr class="liste_titre krea-section-title"><td colspan="'.$nutritionAllergenColumnCount.'">'.$langs->trans('KreaProductsProductAssociations').'</td></tr>';
			if ($nutritionAllergenMode === 1) {
				KreaProductsNutritionalCalculator::computeAndDisplayNutritional($object->id, true);
			} else {
				foreach ($nutritionalFields as $labelKey => $field) {
					print '<tr><td class="titlefield" colspan="'.$nutritionAllergenLabelColspan.'"><label for="nutritional_'.$field.'">'.$langs->trans($labelKey).'</label></td><td colspan="'.$nutritionAllergenValueColspan.'">';
					if ($isEditingNutritionAllergens) {
						print '<input type="number" min="0" step="0.0001" class="maxwidth100" id="nutritional_'.$field.'" name="nutritional_'.$field.'" value="';
						print dol_escape_htmltag($nutritionalData[$field] === null ? '' : $nutritionalData[$field]).'">';
					} else {
						print ($nutritionalData[$field] === null || $nutritionalData[$field] === '')
							? '<span class="opacitymedium">'.$langs->trans('NotAvailable').'</span>'
							: dol_escape_htmltag($nutritionalData[$field]);
					}
					print '</td></tr>';
				}
			}

			print '<tr class="liste_titre krea-section-title"><td colspan="'.$nutritionAllergenColumnCount.'">'.$langs->trans('KreaProductAllergensTableTitle').'</td></tr>';
			foreach (array(
				array('label' => 'Krea_Products_Allergens', 'name' => 'KREAPRODUCTS_ALLERGENS', 'selected' => $savedAllergensArray),
				array('label' => 'Krea_Products_AllergensTraces', 'name' => 'KREAPRODUCTS_ALLERGENS_TRACES', 'selected' => $savedAllergensTracesArray),
			) as $allergenRow) {
				print '<tr class="krea-allergen-row"><td class="titlefield" colspan="'.$nutritionAllergenLabelColspan.'">'.$langs->trans($allergenRow['label']).'</td><td colspan="'.$nutritionAllergenValueColspan.'">';
				if ($isEditingNutritionAllergens) {
					print $form->multiselectarray(
						$allergenRow['name'],
						$allergenSelectOptions,
						$allergenRow['selected'],
						0,
						0,
						'minwidth500',
						0,
						'100%',
						'',
						'id="'.$allergenRow['name'].'"'
					);
				} elseif (empty($allergenRow['selected'])) {
					print '<span class="opacitymedium">'.$langs->trans('NoneSelected').'</span>';
				} else {
					foreach ($allergenRow['selected'] as $allergenId) {
						if (!isset($allergenDictionary[$allergenId])) {
							continue;
						}
						$allergen = $allergenDictionary[$allergenId];
						print '<span class="badge badge-pill kreaproducts-allergen-pill marginrightonly" style="background:var(--butactionbg, #555);color:var(--textbutaction, #fff);border-color:transparent;">';
						if ($allergen['icon'] !== '') {
							print '<img src="'.DOL_URL_ROOT.'/custom/kreaproducts/img/'.dol_escape_htmltag($allergen['icon']).'" alt="" width="16" height="16" class="valignmiddle kreaproducts-allergen-pill-icon" style="filter:brightness(0) invert(1);"> ';
						}
						print dol_escape_htmltag($allergen['label']).'</span>';
					}
				}
				print '</td></tr>';
			}
		}
		print '</table>';
		if ($isCalculatedNutritionMode) {
			print '</div>';
		}

		if ($isEditingNutritionAllergens) {
			print '<div class="center" style="margin-top:12px;">';
			print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"> ';
			print '<input type="submit" class="button button-cancel" name="cancel" value="'.dol_escape_htmltag($langs->trans('Cancel')).'">';
			print '</div>';
			print '</form>';
		} elseif ($nutritionAllergenMode !== 2 && ($canManageNutritionAllergens || $showCopyProductDataModal || $showLlmProductDataModal)) {
			if ($canManageNutritionAllergens && $nutritionAllergenMode === 1) {
				print '<form id="kreaproducts-recalculate-nutrition-allergens-form" method="post" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'#kreaproducts-nutrition-allergens" style="display:none;">';
				print '<input type="hidden" name="action" value="update_nutrition_allergens">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '</form>';
			}
			print '<div class="tabsAction">';
			if ($canManageNutritionAllergens && $nutritionAllergenMode === 0) {
				$editLabel = $langs->trans('Edit');
				$editHtml = '<span class="fas fa-pencil-alt paddingright" aria-hidden="true"></span>'.dol_escape_htmltag($editLabel);
				$editUrl = $_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=edit_nutrition_allergens#kreaproducts-nutrition-allergens';
				print dolGetButtonAction($editLabel, $editHtml, 'edit', $editUrl, 'kreaproducts-edit-nutrition-allergens', 1, array('attr' => array('title' => '', 'aria-label' => $editLabel)));
			} elseif ($canManageNutritionAllergens && $nutritionAllergenMode === 1) {
				$recalculateLabel = $langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_RECALCULATE');
				$recalculateHtml = '<span class="fas fa-sync-alt paddingright" aria-hidden="true"></span>'.dol_escape_htmltag($recalculateLabel);
				print dolGetButtonAction(
					$recalculateLabel,
					$recalculateHtml,
					'default',
					'#',
					'kreaproducts-recalculate-nutrition-allergens',
					1,
					array('attr' => array(
						'title' => '',
						'aria-label' => $recalculateLabel,
						'onclick' => 'document.getElementById(\'kreaproducts-recalculate-nutrition-allergens-form\').submit(); return false;',
					))
				);
			}
			if ($showCopyProductDataModal) {
				$copyLabel = $langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_COPY');
				$copyHtml = '<span class="fas fa-copy paddingright" aria-hidden="true"></span>'.dol_escape_htmltag($copyLabel);
				print dolGetButtonAction($copyLabel, $copyHtml, 'default', '#', 'kreaproducts-open-copy-product-data-modal', 1, array('attr' => array('title' => '', 'aria-label' => $copyLabel)));
			}
			if ($showLlmProductDataModal) {
				$llmLabel = $langs->trans('KREAPRODUCTS_LLM_OPEN');
				$llmHtml = '<span class="fas fa-wand-magic-sparkles paddingright" aria-hidden="true"></span>'.dol_escape_htmltag($llmLabel);
				print dolGetButtonAction($llmLabel, $llmHtml, 'default', '#', 'kreaproducts-open-llm-modal', 1, array('attr' => array('title' => '', 'aria-label' => $llmLabel)));
			}
			print '</div>';
		}
		print '</div>';

		if ($showCopyProductDataModal) {
			$targetFieldName = 'target_product_id_allergens';
			$entityList = kreaproducts_get_accessible_entities();
			$selectHtml = kreaproducts_select_produits_with_entities($form, 0, $targetFieldName, $entityList, $langs, 'minwidth300');
			print '<div id="kreaproducts-copy-product-data-modal" style="display:none;">';
			print '<p>'.$langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_COPY_HELP').'</p>';
			print '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'">';
			print '<input type="hidden" name="action" value="copy_nutrition_allergens_to_product">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="id" value="'.(int) $object->id.'">';
			print '<div class="center">'.$selectHtml.'</div>';
			print '<div class="center" style="margin-top:12px;"><input type="submit" class="button button-save" value="';
			print dol_escape_htmltag($langs->trans('KREAPRODUCTS_NUTRITION_ALLERGENS_COPY')).'"></div>';
			print '</form>';
			print '</div>';
		}

		if ($showLlmProductDataModal) {
			if ($llmSourceText === '') {
				$sourceParts = array();
				$ingredients = trim((string) ($object->array_options['options_kreap_ingredients'] ?? ''));
				$kreaDescription = trim((string) ($object->array_options['options_kreap_description'] ?? ''));
				$coreDescription = trim((string) ($object->description ?? ''));
				if ($ingredients !== '') {
					$sourceParts[] = $langs->trans('kreap_ingredients_Inline').":\n".html_entity_decode(strip_tags($ingredients), ENT_QUOTES, 'UTF-8');
				}
				if ($kreaDescription !== '') {
					$sourceParts[] = $langs->trans('kreap_description_Inline').":\n".html_entity_decode(strip_tags($kreaDescription), ENT_QUOTES, 'UTF-8');
				}
				if ($coreDescription !== '' && $coreDescription !== $kreaDescription) {
					$sourceParts[] = $langs->trans('Description').":\n".html_entity_decode(strip_tags($coreDescription), ENT_QUOTES, 'UTF-8');
				}
				$llmSourceText = implode("\n\n", $sourceParts);
			}

			print '<div id="kreaproducts-llm-modal" style="display:none;">';
			print '<div class="opacitymedium">'.$langs->trans(
				'KREAPRODUCTS_LLM_PRODUCT_DATA_HELP',
				dol_escape_htmltag($llmProvider),
				dol_escape_htmltag(getDolGlobalString('KREAPRODUCTS_LLM_MODEL'))
			).'</div>';
			print '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'" style="margin-top:10px;">';
			print '<input type="hidden" name="action" value="generate_llm_product_data">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<label for="llm_source_text"><strong>'.$langs->trans('KREAPRODUCTS_LLM_SOURCE_TEXT').'</strong></label>';
			print '<textarea id="llm_source_text" name="llm_source_text" rows="7" class="centpercent" maxlength="12000" style="margin-top:6px;">'.dol_escape_htmltag($llmSourceText).'</textarea>';
			print '<div class="center" style="margin-top:10px;"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_LLM_GENERATE')).'"></div>';
			print '</form>';

			if (is_array($llmSuggestion)) {
				$nutritionLabels = array(
					'energy_kcal' => 'KreaProducts_Energy_kcal',
					'energy_kj' => 'KreaProducts_Energy_kj',
					'fat' => 'KreaProducts_Fat',
					'saturates' => 'KreaProducts_Saturates',
					'carbohydrates' => 'KreaProducts_Carbohydrates',
					'sugars' => 'KreaProducts_Sugars',
					'protein' => 'KreaProducts_Protein',
					'salt' => 'KreaProducts_Salt',
					'fiber' => 'KreaProducts_Fiber',
				);
				$contains = array();
				$traces = array();
				foreach ($llmSuggestion['allergens'] as $suggestedAllergen) {
					if ($suggestedAllergen['presence'] === 'contains') {
						$contains[] = $suggestedAllergen['code'];
					} else {
						$traces[] = $suggestedAllergen['code'];
					}
				}

				print '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'" style="margin-top:18px;">';
				print '<input type="hidden" name="action" value="apply_llm_product_data">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="llm_confidence" value="'.dol_escape_htmltag($llmSuggestion['confidence']).'">';
				print '<input type="hidden" name="llm_notes" value="'.dol_escape_htmltag($llmSuggestion['notes']).'">';
				print '<div class="warning">'.$langs->trans('KREAPRODUCTS_LLM_REVIEW_WARNING').'</div>';
				print '<table class="border centpercent" style="margin-top:10px;">';
				print '<tr class="liste_titre"><td>'.$langs->trans('KREAPRODUCTS_LLM_NUTRIENT').'</td><td>'.$langs->trans('KREAPRODUCTS_LLM_VALUE_PER_100G').'</td></tr>';
				foreach ($nutritionLabels as $field => $labelKey) {
					$value = $llmSuggestion['nutrition_per_100g'][$field];
					print '<tr><td>'.$langs->trans($labelKey).'</td><td>';
					print '<input type="number" min="0" step="0.0001" name="llm_nutrition_'.$field.'" value="'.dol_escape_htmltag($value === null ? '' : $value).'">';
					print '</td></tr>';
				}
				print '</table>';
				print '<table class="border centpercent" style="margin-top:10px;">';
				print '<tr class="liste_titre"><td>'.$langs->trans('Allergens').'</td><td class="center">'.$langs->trans('KREAPRODUCTS_LLM_CONTAINS').'</td><td class="center">'.$langs->trans('AllergensTraces').'</td></tr>';
				foreach (KreaProductsLlmProductDataService::getAllergenCodes() as $allergenCode) {
					print '<tr><td>'.$langs->trans($allergenCode).'</td>';
					print '<td class="center"><input type="checkbox" name="llm_allergens_contains[]" value="'.$allergenCode.'"'.(in_array($allergenCode, $contains, true) ? ' checked' : '').'></td>';
					print '<td class="center"><input type="checkbox" name="llm_allergens_traces[]" value="'.$allergenCode.'"'.(in_array($allergenCode, $traces, true) ? ' checked' : '').'></td></tr>';
				}
				print '</table>';
				print '<div style="margin-top:10px;"><strong>'.$langs->trans('KREAPRODUCTS_LLM_CONFIDENCE').':</strong> '.$langs->trans('KREAPRODUCTS_LLM_CONFIDENCE_'.strtoupper($llmSuggestion['confidence'])).'</div>';
				if ($llmSuggestion['notes'] !== '') {
					print '<div style="margin-top:6px;"><strong>'.$langs->trans('Notes').':</strong> '.dol_escape_htmltag($llmSuggestion['notes']).'</div>';
				}
				print '<div class="center" style="margin-top:12px;"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_LLM_APPLY')).'"></div>';
				print '</form>';
			}
			print '<div class="opacitymedium" style="margin-top:8px;">'.$langs->trans('KREAPRODUCTS_LLM_DISCLAIMER').'</div>';
			print '</div>';
		}

		if ($showCopyProductDataModal || $showLlmProductDataModal) {
			print '<script type="text/javascript">'."\n";
			print 'jQuery(document).ready(function () {'."\n";
			if ($showCopyProductDataModal) {
				print '    var copyDialog = jQuery("#kreaproducts-copy-product-data-modal").dialog({'."\n";
				print '        autoOpen: false, modal: true, width: Math.min(620, Math.max(320, window.innerWidth - 40)),'."\n";
				print '        title: "'.dol_escape_js($langs->transnoentitiesnoconv('KREAPRODUCTS_NUTRITION_ALLERGENS_COPY')).'"'."\n";
				print '    });'."\n";
				print '    jQuery("#kreaproducts-open-copy-product-data-modal").on("click", function (event) {'."\n";
				print '        event.preventDefault(); copyDialog.dialog("open");'."\n";
				print '    });'."\n";
			}
			if ($showLlmProductDataModal) {
				print '    var llmDialog = jQuery("#kreaproducts-llm-modal").dialog({'."\n";
				print '        autoOpen: '.($openLlmProductDataModal ? 'true' : 'false').', modal: true,'."\n";
				print '        width: Math.min(900, Math.max(320, window.innerWidth - 40)),'."\n";
				print '        maxHeight: Math.max(400, window.innerHeight - 80),'."\n";
				print '        title: "'.dol_escape_js($langs->transnoentitiesnoconv('KREAPRODUCTS_LLM_PRODUCT_DATA_TITLE')).'"'."\n";
				print '    });'."\n";
				print '    jQuery("#kreaproducts-open-llm-modal").on("click", function (event) {'."\n";
				print '        event.preventDefault(); llmDialog.dialog("open");'."\n";
				print '    });'."\n";
			}
			print '});'."\n";
			print '</script>'."\n";
		}

		if ($productIsFood) {
			if (isset($object->array_options)) {
				$isEditingOtherCharacteristics = ($action === 'edit_other_characteristics');
				$otherCharacteristicsValues = array();
				foreach ($otherCharacteristicsFieldDefinitions as $fieldName => $definition) {
					if (array_key_exists($fieldName, $otherCharacteristicsSubmittedValues)) {
						$otherCharacteristicsValues[$fieldName] = $otherCharacteristicsSubmittedValues[$fieldName];
					} else {
						$otherCharacteristicsValues[$fieldName] = $object->array_options[$fieldName] ?? '';
					}
				}

				print '<div class="fichecenter" id="kreaproducts-other-characteristics" style="' . $sectionMarginStyleLarge . '">';
				print '<div class="titre inline-block" style="margin: 0 0 12px;">' . $langs->trans("productRecipeTitle") . '</div>';

				if ($isEditingOtherCharacteristics) {
					print '<form method="post" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . (int) $object->id . '#kreaproducts-other-characteristics">';
					print '<input type="hidden" name="action" value="save_other_characteristics">';
					print '<input type="hidden" name="token" value="' . newToken() . '">';
				}

				print '<table class="border centpercent tableforfield" style="' . $tableMarginStyle . '">';
				foreach ($otherCharacteristicsFieldDefinitions as $fieldName => $definition) {
					$value = $otherCharacteristicsValues[$fieldName];
					if ($isEditingOtherCharacteristics && ($definition['format'] ?? '') === 'markdown') {
						// Final invariant: a Markdown textarea must never receive database HTML.
						$value = kreaproducts_normalize_markdown($value);
					}
					$label = $langs->trans($definition['label']);
					print '<tr><td class="titlefield tdtop"><label for="' . $fieldName . '">' . dol_escape_htmltag($label) . '</label></td><td>';
					if ($isEditingOtherCharacteristics) {
						if ($definition['type'] === 'textarea') {
							print '<textarea id="' . $fieldName . '" name="' . $fieldName . '" rows="' . (int) $definition['rows'] . '" maxlength="' . (int) $definition['maxlength'] . '" class="flat centpercent" style="resize:vertical;">' . dol_escape_htmltag($value, 0, 1) . '</textarea>';
						} else {
							print '<input id="' . $fieldName . '" name="' . $fieldName . '" type="' . $definition['type'] . '" maxlength="' . (int) $definition['maxlength'] . '" class="flat minwidth300" style="width:100%; max-width:900px;" value="' . dol_escape_htmltag($value) . '">';
						}
					} elseif ($value === '') {
						print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans('NotDefined')) . '</span>';
					} elseif ($definition['type'] === 'url' && kreaproducts_is_http_url($value)) {
						print '<a href="' . dol_escape_htmltag($value) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($value) . '</a>';
					} elseif (($definition['format'] ?? '') === 'markdown') {
						print '<div class="kreaproducts-markdown wordbreakimp">' . dolMd2Html($value, 'parsedown') . '</div>';
					} else {
						print '<div class="wordbreakimp">' . dol_nl2br(dol_escape_htmltag($value)) . '</div>';
					}
					print '</td></tr>';
				}
				print '</table>';

				if ($isEditingOtherCharacteristics) {
					print '<div class="center" style="margin-top:12px;">';
					print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans('Save')) . '"> ';
					print '<input type="submit" class="button button-cancel" name="cancel" value="' . dol_escape_htmltag($langs->trans('Cancel')) . '">';
					print '</div>';
					print '</form>';
				} elseif ($usercancreate) {
					$editLabel = $langs->trans('Edit');
					$editHtml = '<span class="fas fa-pencil-alt paddingright" aria-hidden="true"></span>' . dol_escape_htmltag($editLabel);
					$editUrl = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=edit_other_characteristics#kreaproducts-other-characteristics';
					print '<div class="tabsAction">';
					print dolGetButtonAction($editLabel, $editHtml, 'edit', $editUrl, 'kreaproducts-edit-other-characteristics', 1, array('attr' => array('title' => '', 'aria-label' => $editLabel)));
					print '</div>';
				}
				print '</div>';
			}
		} // end productIsFood inner extrafields
	} // end productIsFood
}



print '<script>
	(function() {
		var key = "kreaproducts_associated_scroll";
		function storeScroll() {
			try {
				var y = window.pageYOffset || document.documentElement.scrollTop || 0;
				sessionStorage.setItem(key, String(y));
			} catch (e) {}
		}
		function restoreScroll() {
			try {
				var saved = sessionStorage.getItem(key);
				if (saved !== null) {
					sessionStorage.removeItem(key);
					var y = parseInt(saved, 10);
					if (!isNaN(y)) {
						window.scrollTo(0, y);
					}
				}
			} catch (e) {}
		}
		document.addEventListener("click", function(e) {
			var link = e.target.closest(".editfielda");
			if (link) {
				storeScroll();
			}
		});
		document.addEventListener("submit", function(e) {
			var form = e.target;
			if (!form || !form.querySelector) return;
			var actionInput = form.querySelector("input[name=action]");
			if (actionInput && actionInput.value && actionInput.value.indexOf("set") === 0) {
				storeScroll();
			}
		}, true);
		window.addEventListener("load", restoreScroll);
	})();
</script>';

llxFooter();
$db->close();
