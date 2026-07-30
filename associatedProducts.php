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
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
dol_include_once('/kreaproducts/class/KreaProductsNutrientUpdater.class.php');
dol_include_once('/kreaproducts/class/KreaProductsAllergenUpdater.class.php');
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
		$valNut = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_nut', $conf->entity);
		if ($valNut !== null) {
			$object->array_options['options_kreap_calc_nut'] = $valNut;
		}
	}
	if ($needsAllergens) {
		$valAll = kreaproducts_get_product_extrafield_value($db, $object->id, 'kreap_calc_allergens', $conf->entity);
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

/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

$productWriteActions = array('save_kreaproducts_nutrition', 'updateAllergens', 'saveAllergens');
if (in_array($action, $productWriteActions, true) && !$usercancreate) {
	accessforbidden();
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

if ($action == 'save_kreaproducts_nutrition' && $usercancreate) {
	kreaproducts_debug_log("Starting save_kreaproducts_nutrition action for product ID: " . (int) $object->id);

	// Check if a nutritional record already exists for this product.
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
	$resql = $db->query($sql);

	$existing_rowid = null;
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		$existing_rowid = $obj->rowid;
		kreaproducts_debug_log("Existing nutrition record found: " . $existing_rowid);
	}
	$db->free($resql);

	// Mandatory fields only for initial insertion.
	$mandatoryData = [
		'fk_product'    => (int) $object->id,
		'date_creation' => date('Y-m-d H:i:s'),
		'fk_user_creat' => $user->id,
	];

	// If no record exists, create one with just the mandatory fields.
	if (!$existing_rowid) {
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional (";
		$sql .= implode(", ", array_keys($mandatoryData)) . ") VALUES ('";
		$sql .= implode("', '", array_map([$db, 'escape'], array_values($mandatoryData))) . "')";
		kreaproducts_debug_log("Executing INSERT SQL: " . $sql);
		$res = $db->query($sql);
		if ($res) {
			$existing_rowid = $db->last_insert_id(MAIN_DB_PREFIX . "kreaproducts_nutritional");
			kreaproducts_debug_log("Nutritional record created with mandatory fields (Row ID: " . $existing_rowid . ")");
		} else {
			dol_syslog("Error inserting mandatory nutritional data: " . $db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans("ErrorSavingData") . ": " . $db->lasterror(), null, 'errors');
			return;
		}
	}

	// Prepare additional nutritional data from the POST values.
	$normalizeDecimalInput = function ($value) {
		if ($value === null) {
			return null;
		}
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		$commaPos = strrpos($value, ',');
		$dotPos = strrpos($value, '.');
		if ($commaPos !== false && $dotPos !== false) {
			if ($commaPos > $dotPos) {
				$value = str_replace('.', '', $value);
				$value = str_replace(',', '.', $value);
			} else {
				$value = str_replace(',', '', $value);
			}
		} elseif ($commaPos !== false) {
			$value = str_replace(',', '.', $value);
		}
		$value = preg_replace('/[^0-9\.\-]/', '', $value);
		if ($value === '' || $value === '-' || $value === '.' || $value === '-.') {
			return null;
		}
		if (!is_numeric($value)) {
			return null;
		}
		return (float) $value;
	};

	$updateData = [
		'energy_kcal'   => $normalizeDecimalInput($_POST['nutritional_energy_kcal'] ?? null),
		'energy_kj'     => $normalizeDecimalInput($_POST['nutritional_energy_kj'] ?? null),
		'fat'           => $normalizeDecimalInput($_POST['nutritional_fat'] ?? null),
		'saturates'     => $normalizeDecimalInput($_POST['nutritional_saturates'] ?? null),
		'carbohydrates' => $normalizeDecimalInput($_POST['nutritional_carbohydrates'] ?? null),
		'sugars'        => $normalizeDecimalInput($_POST['nutritional_sugars'] ?? null),
		'protein'       => $normalizeDecimalInput($_POST['nutritional_protein'] ?? null),
		'salt'          => $normalizeDecimalInput($_POST['nutritional_salt'] ?? null),
		'fiber'         => $normalizeDecimalInput($_POST['nutritional_fiber'] ?? null),
	];

	// Build and execute the UPDATE SQL query.
	$updateSQL = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET ";
	$setClauses = [];
	foreach ($updateData as $field => $value) {
		// If value is null, we explicitly set it to NULL, otherwise use the escaped value.
		$setClauses[] = ($value === null) ? "$field = NULL" : "$field = " . $db->escape($value);
	}
	$updateSQL .= implode(", ", $setClauses);
	$updateSQL .= " WHERE rowid = " . (int)$existing_rowid;

	kreaproducts_debug_log("Executing UPDATE SQL: " . $updateSQL);
	$resUpdate = $db->query($updateSQL);
	if ($resUpdate) {
		kreaproducts_debug_log("Nutritional data successfully updated (Row ID: " . $existing_rowid . ")");
		setEventMessages($langs->trans("NutritionalDataUpdated"), null, 'mesgs');
	} else {
		dol_syslog("Error updating nutritional data: " . $db->lasterror(), LOG_ERR);
		setEventMessages($langs->trans("ErrorUpdatingData") . ": " . $db->lasterror(), null, 'errors');
	}

	// Call the updater method; $user is provided by the Dolibarr environment.
	$result = KreaProductsNutrientUpdater::updateNutrientAttributes($object->id, $user);
	if ($result < 0) {
		$message = "Error updating nutritional attributes for product ID $object->id.";
	} else {
		$message = "Nutritional attributes updated successfully for product ID $object->id.";
	}
}

if ($action === 'copy_nutrition_to_product' && $usercancreate && $enableCopyAvgToProduct) {
	$targetProductId = GETPOSTINT('target_product_id');
	if ($targetProductId > 0) {
		$sqlTarget = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sqlTarget .= " WHERE rowid = " . (int) $targetProductId;
		$sqlTarget .= " AND entity IN (" . getEntity('product') . ")";
		$sqlTarget .= " LIMIT 1";
		$resTarget = $db->query($sqlTarget);
		if (!$resTarget || $db->num_rows($resTarget) <= 0) {
			if ($resTarget) {
				$db->free($resTarget);
			}
			dol_syslog("Blocked nutritional copy to inaccessible product ID " . $targetProductId, LOG_WARNING);
			setEventMessages($langs->trans("Error"), null, 'errors');
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		}
		$db->free($resTarget);

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int) $targetProductId;
		$resql = $db->query($sql);
		$existing_rowid = null;
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$existing_rowid = $obj->rowid;
		}
		if ($resql) {
			$db->free($resql);
		}

		if (!$existing_rowid) {
			$mandatoryData = [
				'fk_product'    => (int) $targetProductId,
				'date_creation' => date('Y-m-d H:i:s'),
				'fk_user_creat' => $user->id,
			];
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional (";
			$sql .= implode(", ", array_keys($mandatoryData)) . ") VALUES ('";
			$sql .= implode("', '", array_map([$db, 'escape'], array_values($mandatoryData))) . "')";
			$res = $db->query($sql);
			if ($res) {
				$existing_rowid = $db->last_insert_id(MAIN_DB_PREFIX . "kreaproducts_nutritional");
			} else {
				dol_syslog("Error inserting mandatory nutritional data: " . $db->lasterror(), LOG_ERR);
				setEventMessages($langs->trans("ErrorSavingData") . ": " . $db->lasterror(), null, 'errors');
				header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
				exit;
			}
		}

		$fields = array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber');
		$updateData = array();
		foreach ($fields as $field) {
			$rawValue = GETPOST('avg_' . $field, 'alpha');
			$val = price2num($rawValue, 'MS');
			$updateData[$field] = ($rawValue !== '' && $val !== '') ? (float) $val : null;
		}

		$updateSQL = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET ";
		$setClauses = [];
		foreach ($updateData as $field => $value) {
			$setClauses[] = ($value === null) ? "$field = NULL" : "$field = " . $db->escape($value);
		}
		$updateSQL .= implode(", ", $setClauses);
		$updateSQL .= " WHERE rowid = " . (int) $existing_rowid;

		$resUpdate = $db->query($updateSQL);
		if ($resUpdate) {
			setEventMessages($langs->trans("KreaProductsCopyAvgSuccess"), null, 'mesgs');
		} else {
			dol_syslog("Error updating nutritional data: " . $db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans("ErrorUpdatingData") . ": " . $db->lasterror(), null, 'errors');
		}

		$result = KreaProductsNutrientUpdater::updateNutrientAttributes($targetProductId, $user);
		if ($result < 0) {
			kreaproducts_debug_log("Error updating nutritional attributes for product ID " . $targetProductId);
		}
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
	exit;
}

if ($action === 'copy_allergens_to_product' && $usercancreate && $enableCopyAllergensToProduct) {
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
		$messages = array($langs->trans("KreaProductsCopyAllergensSuccess"));
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
				if ($result < 0) {
					kreaproducts_debug_log("Error updating nutritional attributes for product ID " . $targetProductId);
				}
				$messages[] = $langs->trans("KreaProductsCopyAvgSuccess");
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

if ($action == 'updateAllergens' && $usercancreate) {
	$result = KreaProductsAllergenUpdater::updateAllergenAttributes($object->id, $user, 0);
	if (!$result || KreaProductsAllergenUpdater::hasErrors()) {
		$errors = KreaProductsAllergenUpdater::getAllErrors();
		if (empty($errors)) {
			$errors = array($langs->trans("Error"));
		}
		setEventMessages($langs->trans("Error"), $errors, 'errors');
	} else {
		$stats = KreaProductsAllergenUpdater::getProcessingStats();
		if (!empty($stats) && isset($stats['allergens_updated']) && (int) $stats['allergens_updated'] === 0) {
			setEventMessages($langs->trans("KREAPRODUCTS_ALLERGENS_UPDATE_EMPTY"), null, 'warnings');
		} else {
			setEventMessages($langs->trans("AllergenUpdateFired"), null, 'mesgs');
		}
	}
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

// Toggle food/non-food flag for nutritional handling
if ($action === 'toggle_is_food' && $usercancreate) {
	$newValue = GETPOST('value', 'int') ? 1 : 0;

	// Ensure a record exists
	$sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int) $object->id;
	$resCheck = $db->query($sqlCheck);
	$rowId = null;
	if ($resCheck && $db->num_rows($resCheck) > 0) {
		$objCheck = $db->fetch_object($resCheck);
		$rowId = (int) $objCheck->rowid;
	}
	if ($resCheck) $db->free($resCheck);

	if (!$rowId) {
		$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional (fk_product, date_creation, fk_user_creat, is_food) VALUES ("
			. (int) $object->id . ", '" . $db->escape(date('Y-m-d H:i:s')) . "', " . (int) $user->id . ", " . (int) $newValue . ")";
		$db->query($sqlInsert);
		$rowId = $db->last_insert_id(MAIN_DB_PREFIX . "kreaproducts_nutritional");
	} else {
		$setParts = array("is_food = " . (int) $newValue);
		if ($newValue === 0) {
			// Reset nutritional fields when marking as non-food
			$zeroFields = array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber');
			foreach ($zeroFields as $zf) {
				$setParts[] = $zf . " = 0";
			}
		}
		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET " . implode(', ', $setParts) . " WHERE rowid = " . (int) $rowId;
		$db->query($sqlUpdate);
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

if ($action == 'saveAllergens' && $usercancreate) {
	// Retrieve submitted allergens (non-traces and traces)
	$selectedAllergens       = (array) GETPOST('KREAPRODUCTS_ALLERGENS', 'array');
	$selectedAllergensTraces = (array) GETPOST('KREAPRODUCTS_ALLERGENS_TRACES', 'array');

	// Example: merge arrays and adjust if "Sem alergenios" (id 1) is selected along with others
	$mergedAllergens = array_unique(array_merge($selectedAllergens, $selectedAllergensTraces));
	if (count($mergedAllergens) > 1 && in_array(1, $mergedAllergens)) {
		$selectedAllergens = array_diff($selectedAllergens, array(1));
		$selectedAllergensTraces = array_diff($selectedAllergensTraces, array(1));
	}

	$errors = array();
	$db->begin();

	// Remove previous allergen associations for this product
	$sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens WHERE fk_product = " . (int)$object->id;
	$resql = $db->query($sql);
	if (!$resql) {
		$errors[] = $db->lasterror();
	}

	// Insert new associations (non-traces)
	if (empty($errors) && !empty($selectedAllergens) && is_array($selectedAllergens)) {
		dol_include_once('/kreaproducts/class/productallergens.class.php');
		foreach ($selectedAllergens as $allergenId) {
			$allergenId = (int)$allergenId;
			if ($allergenId > 0) {
				$prodAllergen = new ProductAllergens($db);
				$prodAllergen->fk_product = $object->id;
				$prodAllergen->fk_allergen  = $allergenId;
				$prodAllergen->traces       = 0;
				$res = $prodAllergen->create($user);
				if ($res < 0) {
					$errors[] = $prodAllergen->error;
					break;
				}
			}
		}
	}

	// Insert associations for allergens with traces (skip duplicates)
	if (empty($errors) && !empty($selectedAllergensTraces) && is_array($selectedAllergensTraces)) {
		dol_include_once('/kreaproducts/class/productallergens.class.php');
		foreach ($selectedAllergensTraces as $allergenId) {
			$allergenId = (int)$allergenId;
			if (!empty($selectedAllergens) && in_array($allergenId, $selectedAllergens)) {
				continue;
			}
			if ($allergenId > 0) {
				$prodAllergenTraces = new ProductAllergens($db);
				$prodAllergenTraces->fk_product = $object->id;
				$prodAllergenTraces->fk_allergen  = $allergenId;
				$prodAllergenTraces->traces       = 1;
				$res = $prodAllergenTraces->create($user);
				if ($res < 0) {
					$errors[] = $prodAllergenTraces->error;
					break;
				}
			}
		}
	}
	if (empty($errors)) {
		$db->commit();
		setEventMessages($langs->trans("AllergenUpdateFired"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("ErrorUpdatingData"), $errors, 'errors');
	}
	// After saving, you might want to switch back to view mode.
	$action = '';
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
			// Food/non-food toggle on left column
			print '<tr><td class="titlefield">' . $langs->trans("KreaFoodProduct") . '</td><td>';
			$foodIcon = $productIsFood ? 'fa-toggle-on font-status4' : 'fa-toggle-off opacitymedium';
			$toggleUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=toggle_is_food&value=' . ($productIsFood ? 0 : 1);
			print '<a class="linkobject" href="' . $toggleUrl . '" title="' . ($productIsFood ? $langs->trans('KreaFoodProduct') : $langs->trans('KreaNonFoodProduct')) . '">';
			print '<span class="fas ' . $foodIcon . '"></span>';
			print '</a>';
			print '</td></tr>';
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
					print '<tr><td>' . $form->textwithpicto($langs->trans("NatureOfProductShort"), $langs->trans("NatureOfProductDesc")) . '</td><td>';
					print $object->getLibFinished();
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

		// Process the recipe extra–field
		if (isset($_POST['options_kreap_recipe'])) {
			$extrafield_value = trim($_POST['options_kreap_recipe']);
			if ($extrafield_value === '') {
				$extrafield_value = null;
			}
			$object->array_options['options_kreap_recipe'] = $extrafield_value;
		} else {
			$extrafield_value = $object->array_options['options_kreap_recipe'];
		}
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label($object->table_element);

		// Only save extra–fields when at least one options_* field is posted.
		$hasOptionsPost = false;
		if (!empty($_POST)) {
			foreach ($_POST as $postKey => $postValue) {
				if (strpos($postKey, 'options_') === 0) {
					$hasOptionsPost = true;
					break;
				}
			}
		}

		if ($hasOptionsPost) {
			// Refresh current values to avoid overwriting with stale data.
			$object->fetch_optionals();
			$originalOptions = $object->array_options;
			// Merge POST values with the existing extra–fields values.
			foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
				$fieldname = 'options_' . $key;
				if (array_key_exists($fieldname, $_POST)) {
					$extrafield_value = trim((string) $_POST[$fieldname]);
					if ($extrafield_value === '') {
						$extrafield_value = null;
					}
					$object->array_options[$fieldname] = $extrafield_value;
				} elseif (array_key_exists($fieldname, $originalOptions)) {
					$object->array_options[$fieldname] = $originalOptions[$fieldname];
				}
			}
			// Save the changes to the database (one call for all extra–fields).
			$object->insertExtraFields();
		}


		if ($productIsFood) {
			// Nutritional table
			// Reusable spacing to keep titles and tables visually consistent.
			$sectionMarginStyle = $sectionSpacingStyle;
			$sectionMarginStyleLarge = 'margin-top: 32px;';
			$tableMarginStyle = 'margin-top: 10px;';

			print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
			print load_fiche_titre($langs->trans("KreaProductsProductAssociations"), '', '');
			print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';
			$key = 'kreap_calc_nut';
			$fieldName = 'options_' . $key;
			$label = $extrafields->attributes['product']['label'][$key] ?? $langs->trans($key);
			kreaproducts_debug_log('label: ' . $label);
			$rawOptions = $extrafields->attributes['product']['param'][$key]['options'] ?? array();
			$rawOptions = kreaproducts_normalize_extrafield_options($rawOptions);
			$options = array('' => '') + $rawOptions;
			$editorType = 'select;' . implode(',', array_map(
				function ($k, $v) {
					global $langs;
					$langs->load("kreaproducts@kreaproducts");
					return "$k:" . $langs->trans($v);
				},
				array_keys($options),
				$options
			));

			$displayLabels = array();
			foreach ($rawOptions as $optKey => $optLabel) {
				$displayLabels[(string) $optKey] = $langs->trans($optLabel);
			}
			$currentValue = array_key_exists($fieldName, $object->array_options) ? $object->array_options[$fieldName] : '';
			if ($currentValue === null || $currentValue === '') {
				$dbValue = kreaproducts_get_product_extrafield_value($db, $object->id, $key, $conf->entity);
				if ($dbValue !== null) {
					$currentValue = $dbValue;
					$object->array_options[$fieldName] = $dbValue;
				}
			}
			$currentKey = ($currentValue === null) ? '' : (string) $currentValue;
			$isEditingField = ($inlineOptionAction === 'editoptions' && $inlineOptionKey === $key);
			print '<tr><td class="titlefield">';
			print $form->editfieldkey($label, $fieldName, $currentValue, $object, $usercancreate, $editorType);
			print '</td><td>';
			if ($isEditingField) {
				print $form->editfieldval($label, $fieldName, $currentValue, $object, $usercancreate, $editorType);
			} else {
				if ($currentKey !== '' && array_key_exists($currentKey, $displayLabels)) {
					print dol_escape_htmltag($displayLabels[$currentKey]);
				} elseif ($currentKey !== '') {
					print dol_escape_htmltag($currentKey);
				}
			}
			print '</td></tr>';
			print '</table>';
			print '</div>';

			if ($conf->global->KREAPRODUCTS_NUTRITIONAL_TABLE_TAB == 1 && $object->array_options['options_kreap_calc_nut'] == 1) {


				dol_include_once('/kreaproducts/class/KreaProductsNutritionalCalculator.class.php');
				if ($usercancreate) {
					$saveCalculationResult = KreaProductsNutritionalCalculator::saveCalculation($object->id, $user);
					if ($saveCalculationResult < 0) {
						dol_syslog('Failed to persist calculated nutritional values for product #' . (int) $object->id, LOG_ERR);
						setEventMessages($langs->trans("ErrorUpdatingData"), null, 'errors');
					}
				}
				KreaProductsNutritionalCalculator::computeAndDisplayNutritional($object->id);
			}

			if ($object->array_options['options_kreap_calc_nut'] == 0) {
				print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
				print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
				print '<input type="hidden" name="action" value="save_kreaproducts_nutrition">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';

				$nutritionalFields = array(
					'KreaProducts_Energy_kcal'   => 'energy_kcal',
					'KreaProducts_Energy_kj'     => 'energy_kj',
					'KreaProducts_Fat'           => 'fat',
					'KreaProducts_Saturates'     => 'saturates',
					'KreaProducts_Carbohydrates' => 'carbohydrates',
					'KreaProducts_Sugars'        => 'sugars',
					'KreaProducts_Protein'       => 'protein',
					'KreaProducts_Salt'          => 'salt',
					'KreaProducts_Fiber'         => 'fiber',
				);

				$sqlColumns = implode(', ', $nutritionalFields);
				$sql = "SELECT $sqlColumns FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
				$resql = $db->query($sql);

				$nutritionalData = new stdClass();
				foreach ($nutritionalFields as $dbField) {
					$nutritionalData->$dbField = '';
				}

				if ($resql && $db->num_rows($resql) > 0) {
					$obj = $db->fetch_object($resql);
					foreach ($nutritionalFields as $dbField) {
						$nutritionalData->$dbField = isset($obj->$dbField) ? $obj->$dbField : '';
					}
				}

				foreach ($nutritionalFields as $label => $dbField) {
					$fieldName = 'nutritional_' . $dbField;
					$object->array_options[$fieldName] = $nutritionalData->$dbField;
					print '<tr><td class="titlefield">';
					print '<label for="' . $fieldName . '">' . $langs->trans($label) . '</label>';
					print '</td><td>';
					if ($usercancreate) {
						print '<input type="text" name="' . $fieldName . '" value="' . dol_escape_htmltag($object->array_options[$fieldName]) . '">';
					} else {
						print dol_escape_htmltag($object->array_options[$fieldName]);
					}
					print '</td></tr>';
				}

				print '</table>';
				print '<div class="opacitymedium" style="margin-top: 8px;">' . $langs->trans("KreaProductsNutritionDisclaimer") . '</div>';
				if ($usercancreate) {
					print '<div class="center" style="margin-top: 12px;"><input type="submit" class="button" value="' . $langs->trans("Save") . '"></div>';
				}
				print '</form>';
				print '</div>';
			}

			// Allergens table
			// Load available allergens from dictionary
			$TAllergens = array();
			$sql = "SELECT rowid, code, label, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE active = 1 ORDER BY label";
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					// Use the code translation as the display value
					$TAllergens[$obj->rowid] = $langs->trans($obj->code);
				}
			} else {
				dol_syslog("Error loading allergens dictionary: " . $db->error(), LOG_ERR);
			}

			// Reload saved allergen associations for current product
			$savedAllergensArray = array();
			$savedAllergensTracesArray = array();
			$sql = "SELECT fk_allergen, traces FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens WHERE fk_product = " . (int)$object->id;
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					if ($obj->traces == 1) {
						$savedAllergensTracesArray[] = $obj->fk_allergen;
					} else {
						$savedAllergensArray[] = $obj->fk_allergen;
					}
				}
			} else {
				dol_syslog("Error retrieving saved allergens: " . $db->error(), LOG_ERR);
			}

			print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
			print load_fiche_titre($langs->trans("KreaProductAllergensTableTitle"), '', '');
			print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';
			$key = 'kreap_calc_allergens';
			$fieldName = 'options_' . $key;
			$label = $extrafields->attributes['product']['label'][$key] ?? $langs->trans($key);
			kreaproducts_debug_log('label: ' . $label);
			$rawOptions = $extrafields->attributes['product']['param'][$key]['options'] ?? array();
			$rawOptions = kreaproducts_normalize_extrafield_options($rawOptions);
			$options = array('' => '') + $rawOptions;
			$editorType = 'select;' . implode(',', array_map(
				function ($k, $v) {
					global $langs;
					$langs->load("kreaproducts@kreaproducts");
					return "$k:" . $langs->trans($v);
				},
				array_keys($options),
				$options
			));

			$displayLabels = array();
			foreach ($rawOptions as $optKey => $optLabel) {
				$displayLabels[(string) $optKey] = $langs->trans($optLabel);
			}
			$currentValue = array_key_exists($fieldName, $object->array_options) ? $object->array_options[$fieldName] : '';
			if ($currentValue === null || $currentValue === '') {
				$dbValue = kreaproducts_get_product_extrafield_value($db, $object->id, $key, $conf->entity);
				if ($dbValue !== null) {
					$currentValue = $dbValue;
					$object->array_options[$fieldName] = $dbValue;
				}
			}
			$currentKey = ($currentValue === null) ? '' : (string) $currentValue;
			$isEditingField = ($inlineOptionAction === 'editoptions' && $inlineOptionKey === $key);
			print '<tr><td class="titlefield">';
			print $form->editfieldkey($label, $fieldName, $currentValue, $object, $usercancreate, $editorType);
			print '</td><td>';
			if ($isEditingField) {
				print $form->editfieldval($label, $fieldName, $currentValue, $object, $usercancreate, $editorType);
			} else {
				if ($currentKey !== '' && array_key_exists($currentKey, $displayLabels)) {
					print dol_escape_htmltag($displayLabels[$currentKey]);
				} elseif ($currentKey !== '') {
					print dol_escape_htmltag($currentKey);
				}
			}
			print '</td></tr>';
			print '</table>';
			print '</div>';

			if ($object->array_options['options_kreap_calc_allergens'] != 2) {
				// Check if we are in edit mode and the user has rights to edit
				if ($usercancreate && $action == 'edit_allergens') {

					// Start the form
					print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
					print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
					print '<input type="hidden" name="action" value="saveAllergens">';
					print '<input type="hidden" name="token" value="' . newToken() . '">';

					// Multiselect for allergens
					print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';
					print '<tr><td>' . $langs->trans("Krea_Products_Allergens") . '</td><td colspan="3">';
					print $form->multiselectarray('KREAPRODUCTS_ALLERGENS', $TAllergens, $savedAllergensArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS"');
					print '</td></tr>';

					// Multiselect for allergens traces
					print '<tr><td>' . $langs->trans("Krea_Products_AllergensTraces") . '</td><td colspan="3">';
					print $form->multiselectarray('KREAPRODUCTS_ALLERGENS_TRACES', $TAllergens, $savedAllergensTracesArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS_TRACES"');
					print '</td></tr>';

					print '<tr><td colspan="4" class="maxwidthonsmartphone" style="height: 10px;"></td></tr>';
					print '</table>';

					// Save button
					print '<div class="center" style="margin-top: 12px;"><input type="submit" class="button" value="' . $langs->trans("Save") . '"></div>';
					print '</form>';
					print '</div>';
				} else {
					// View mode (read-only display)
					print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
					print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';
					print '<tr><td>' . $langs->trans("Allergens") . '</td><td colspan="3">';
					if (!empty($savedAllergensArray)) {
						foreach ($savedAllergensArray as $allergenId) {
							$sql = "SELECT code, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
							$resql = $db->query($sql);
							if ($resql && $obj = $db->fetch_object($resql)) {
								$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
								print '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
								print '<img src="' . $iconPath . '" alt="' . htmlspecialchars($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
								print '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
								print '</div>';
							}
						}
					} else {
						print $langs->trans("NoneSelected");
					}
					print '</td></tr>';

					print '<tr><td>' . $langs->trans("AllergensTraces") . '</td><td colspan="3">';
					if (!empty($savedAllergensTracesArray)) {
						foreach ($savedAllergensTracesArray as $allergenId) {
							$sql = "SELECT code, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
							$resql = $db->query($sql);
							if ($resql && $obj = $db->fetch_object($resql)) {
								$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
								print '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
								print '<img src="' . $iconPath . '" alt="' . htmlspecialchars($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
								print '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
								print '</div>';
							}
						}
					} else {
						print $langs->trans("NoneSelected");
					}
					print '</td></tr>';
					print '</table>';
					print '</div>';

					if ($usercancreate && $object->array_options['options_kreap_calc_allergens'] != 1) {
						print '<div class="center" style="margin-top: 12px;">';
						print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit_allergens#myAllergenButtons">' . $langs->trans("Edit") . '</a>';
						print '<a class="button" href="#" onclick="document.getElementById(\'formUpdateAllergens\').submit(); return false;">' . $langs->trans("updateAllergens") . '</a>';
						print '<form id="formUpdateAllergens" method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '#myAllergenButtons" style="display:none;">';
						print '<input type="hidden" name="action" value="updateAllergens">';
						print '<input type="hidden" name="token" value="' . newToken() . '">';
						print '</form>';
						print '</div>';
					} elseif ($usercancreate) {
						print '<div class="center" style="margin-top: 12px;">';
						print '<a class="button" href="#" onclick="document.getElementById(\'formUpdateAllergens\').submit(); return false;">' . $langs->trans("updateAllergens") . '</a>';
						print '<form id="formUpdateAllergens" method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '#myAllergenButtons" style="display:none;">';
						print '<input type="hidden" name="action" value="updateAllergens">';
						print '<input type="hidden" name="token" value="' . newToken() . '">';
						print '</form>';
						print '</div>';
					}
				}

				// Copy allergens to another product
				if ($usercancreate && $action != 'edit_allergens' && $enableCopyAllergensToProduct) {
					$targetFieldName = 'target_product_id_allergens';
					$entityList = kreaproducts_get_accessible_entities();
					$selectHtml = kreaproducts_select_produits_with_entities($form, 0, $targetFieldName, $entityList, $langs, 'minwidth300');
					print '<div class="fichecenter" style="' . $sectionMarginStyle . '">';
					print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
					print '<input type="hidden" name="action" value="copy_allergens_to_product">';
					print '<input type="hidden" name="token" value="' . newToken() . '">';
					print '<input type="hidden" name="id" value="' . $object->id . '">';
					print '<span class="inline-block" style="margin-right: 8px;">' . $langs->trans("KreaProductsCopyAllergensToProduct") . '</span>';
					print $selectHtml;
					print ' <input type="submit" class="button" value="' . $langs->trans("KreaProductsCopyAllergensButton") . '">';
					print '</form>';
					print '</div>';
				}
				print '<div class="opacitymedium" style="margin-top: 8px;">' . $langs->trans("KreaProductsAllergensDisclaimer") . '</div>';
			}

			if (isset($object->array_options)) {

				// Keep displayed values in sync with the posted data
				if (isset($_POST['kreap_brand'])) {
					$extrafield_value = trim($_POST['options_kreap_brand']);
					if ($extrafield_value === '') {
						$extrafield_value = null;
					}
					$object->array_options['options_kreap_brand'] = $extrafield_value;
				} else {
					$extrafield_value = $object->array_options['options_kreap_brand'];
				}

				if (isset($_POST['kreap_video'])) {
					$extrafield_value = trim($_POST['options_kreap_video']);
					if ($extrafield_value === '') {
						$extrafield_value = null;
					}
					$object->array_options['options_kreap_video'] = $extrafield_value;
				} else {
					$extrafield_value = $object->array_options['options_kreap_video'];
				}

				if (isset($_POST['kreap_description'])) {
					$extrafield_value = trim($_POST['options_kreap_description']);
					if ($extrafield_value === '') {
						$extrafield_value = null;
					}
					$object->array_options['options_kreap_description'] = $extrafield_value;
				} else {
					$extrafield_value = $object->array_options['options_kreap_description'];
				}

				$prepValue = isset($object->array_options['options_kreap_recipe']) ? $object->array_options['options_kreap_recipe'] : '';
				$ingredientsValue = isset($object->array_options['options_kreap_ingredients']) ? $object->array_options['options_kreap_ingredients'] : '';
				$brandValue = isset($object->array_options['options_kreap_brand']) ? $object->array_options['options_kreap_brand'] : '';
				$videoValue = isset($object->array_options['options_kreap_video']) ? $object->array_options['options_kreap_video'] : '';
				$descriptionValue = isset($object->array_options['options_kreap_description']) ? $object->array_options['options_kreap_description'] : '';

				print '<div class="fichecenter" id="myAllergenButtons" style="' . $sectionMarginStyleLarge . '">';
				print '<div class="titre inline-block" style="margin: 0 0 12px;">' . $langs->trans("productRecipeTitle") . '</div>';

				print '<table class="ui-sortable liste nobottom" style="' . $tableMarginStyle . '">';


				print '<tr><td class="titlefield">';
				print $form->editfieldkey($langs->trans("productRecipeInline"), 'options_kreap_recipe', $prepValue, $object, $usercancreate, 'ckeditor');
				print '</td><td>';
				print $form->editfieldval($langs->trans("productRecipeInline"), 'options_kreap_recipe', $prepValue, $object, $usercancreate, 'ckeditor');
				print '</td></tr>';

				print '<tr><td class="titlefield">';
				print $form->editfieldkey($langs->trans("kreap_ingredients_Inline"), 'options_kreap_ingredients', $ingredientsValue, $object, $usercancreate, 'ckeditor');
				print '</td><td>';
				print $form->editfieldval($langs->trans("kreap_ingredients_Inline"), 'options_kreap_ingredients', $ingredientsValue, $object, $usercancreate, 'ckeditor');
				print '</td></tr>';

				print '<tr><td class="titlefield">';
				print $form->editfieldkey($langs->trans("kreap_brand_Inline"), 'options_kreap_brand', $brandValue, $object, $usercancreate, 'string');
				print '</td><td>';
				print $form->editfieldval($langs->trans("kreap_brand_Inline"), 'options_kreap_brand', $brandValue, $object, $usercancreate, 'string');
				print '</td></tr>';

				print '<tr><td class="titlefield">';
				print $form->editfieldkey($langs->trans("kreap_video_Inline"), 'options_kreap_video', $videoValue, $object, $usercancreate, 'url');
				print '</td><td>';
				print $form->editfieldval($langs->trans("kreap_video_Inline"), 'options_kreap_video', $videoValue, $object, $usercancreate, 'url');
				print '</td></tr>';

				print '<tr><td class="titlefield">';
				print $form->editfieldkey($langs->trans("kreap_description_Inline"), 'options_kreap_description', $descriptionValue, $object, $usercancreate, 'ckeditor');
				print '</td><td>';
				print $form->editfieldval($langs->trans("kreap_description_Inline"), 'options_kreap_description', $descriptionValue, $object, $usercancreate, 'ckeditor');
				print '</td></tr>';

				print '</table>';
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
