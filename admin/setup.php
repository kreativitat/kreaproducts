<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
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

/**
 * \file    kreaproducts/admin/setup.php
 * \ingroup kreaproducts
 * \brief   KreaProducts setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/kreaproducts.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcategory.class.php';
//require_once "../class/myclass.class.php";

// Translations
$langs->loadLangs(array("admin", "kreaproducts@kreaproducts"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('kreaproductssetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';


$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);

$kreaObjects = array(
	'nutritional' => array(
		'label' => $langs->trans('Nutritional'),
		'includerefgeneration' => 1,
		'includedocgeneration' => 0,
		'class' => 'Nutritional',
	),
	'productallergens' => array(
		'label' => $langs->trans('ProductAllergens'),
		'includerefgeneration' => 1,
		'includedocgeneration' => 0,
		'class' => 'ProductAllergens',
	),
);

// Ensure simulator is ON by default if not yet defined
if (!isset($conf->global->KREAPRODUCTS_SIM_ENABLE)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_SIM_ENABLE', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_SIM_ENABLE = 1;
}
// Default to replacing core product list with KreaProducts simplified list
if (!isset($conf->global->KREAPRODUCTS_REPLACE_PRODUCT_LIST)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_REPLACE_PRODUCT_LIST', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_REPLACE_PRODUCT_LIST = 1;
}
// Ensure default supplier move time is set
if (!isset($conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_SUPPLIER_MOVE_TIME', '10:00', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_SUPPLIER_MOVE_TIME = '10:00';
}
// Ensure default inventory time is set
if (!isset($conf->global->KREAPRODUCTS_INVENTORY_DEFAULT_TIME)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_INVENTORY_DEFAULT_TIME = '10:30';
}
// Ensure dismantle BOM type has default (was hardcoded 1)
if (!isset($conf->global->KREAPRODUCTS_DISMANTLE_BOMTYPE)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_DISMANTLE_BOMTYPE', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_DISMANTLE_BOMTYPE = '1';
}
// Allergen thresholds (percent of total recipe weight)
if (!isset($conf->global->KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT', '1.0', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT = '1.0';
}
if (!isset($conf->global->KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT', '0.1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT = '0.1';
}
if (!isset($conf->global->KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT = '1';
}
if (!isset($conf->global->KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT = '1';
}
if (!isset($conf->global->KREAPRODUCTS_DEBUG_LOG)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_DEBUG_LOG', '0', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_DEBUG_LOG = '0';
}
if (!isset($conf->global->KREAPRODUCTS_LABELS_TAB_ENABLED)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_LABELS_TAB_ENABLED', '0', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_LABELS_TAB_ENABLED = '0';
}
if (!isset($conf->global->KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED)) {
	dolibarr_set_const($db, 'KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED = '1';
}
if (empty($conf->global->KREAPRODUCTS_UPDATEBUYPRICE_DEFAULT_APPLIED)) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);

	$field_name = "kreap_updatebuyprice";
	$field_label = $langs->trans("kreap_updatebuyprice");
	$field_help = $langs->trans("EnableCostPriceSyncForThisProduct");
	$extrafields->updateExtraField($field_name, $field_label, 'boolean', 7, 3, 'product', 0, 0, '1', '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');

	$sharedProducts = getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED');
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, kreap_updatebuyprice) ";
	$sql .= "SELECT p.rowid, 1 FROM " . MAIN_DB_PREFIX . "product p ";
	$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid ";
	$sql .= "WHERE pe.fk_object IS NULL";
	if (empty($sharedProducts)) {
		$sql .= " AND p.entity = " . ((int) $conf->entity);
	}
	$db->query($sql);

	if (empty($sharedProducts)) {
		$sql = "UPDATE " . MAIN_DB_PREFIX . "product_extrafields pe ";
		$sql .= "JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = pe.fk_object ";
		$sql .= "SET pe.kreap_updatebuyprice = 1 ";
		$sql .= "WHERE p.entity = " . ((int) $conf->entity);
	} else {
		$sql = "UPDATE " . MAIN_DB_PREFIX . "product_extrafields SET kreap_updatebuyprice = 1";
	}
	$db->query($sql);

	dolibarr_set_const($db, 'KREAPRODUCTS_UPDATEBUYPRICE_DEFAULT_APPLIED', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_UPDATEBUYPRICE_DEFAULT_APPLIED = '1';
}

if (empty($conf->global->KREAPRODUCTS_DISMANTLE_DEFAULT_APPLIED)) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);

	$field_name = "kreap_dismantle";
	$field_label = $langs->trans("kreap_dismantle");
	$field_help = $langs->trans("kreap_dismantle_help");
	$extrafields->addExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
	$extrafields->updateExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');

	$sharedProducts = getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED');
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, kreap_dismantle) ";
	$sql .= "SELECT p.rowid, 0 FROM " . MAIN_DB_PREFIX . "product p ";
	$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid ";
	$sql .= "WHERE pe.fk_object IS NULL";
	if (empty($sharedProducts)) {
		$sql .= " AND p.entity = " . ((int) $conf->entity);
	}
	$db->query($sql);

	dolibarr_set_const($db, 'KREAPRODUCTS_DISMANTLE_DEFAULT_APPLIED', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_DISMANTLE_DEFAULT_APPLIED = '1';
}

if (empty($conf->global->KREAPRODUCTS_ALIAS_FIELD_READY)) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);

	$field_name = "kreap_alias";
	$field_label = $langs->trans("kreap_alias");
	$field_help = $langs->trans("kreap_alias_help");
	$extrafields->addExtraField($field_name, $field_label, 'varchar', 305, 255, 'product', 0, 0, '', '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
	$extrafields->updateExtraField($field_name, $field_label, 'varchar', 305, 255, 'product', 0, 0, '', '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');

	$sharedProducts = getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED');
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, kreap_alias) ";
	$sql .= "SELECT p.rowid, NULL FROM " . MAIN_DB_PREFIX . "product p ";
	$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid ";
	$sql .= "WHERE pe.fk_object IS NULL";
	if (empty($sharedProducts)) {
		$sql .= " AND p.entity = " . ((int) $conf->entity);
	}
	$db->query($sql);

	$legacyColumnExists = false;
	$legacyColRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "kreap_zs_descricaocurta");
	if ($legacyColRes) {
		$legacyColumnExists = ($db->num_rows($legacyColRes) > 0);
		$db->free($legacyColRes);
	}

	if ($legacyColumnExists) {
		$sql = "UPDATE " . MAIN_DB_PREFIX . "product_extrafields pe ";
		$sql .= "JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = pe.fk_object ";
		$sql .= "SET pe.kreap_alias = pe.kreap_zs_descricaocurta ";
		$sql .= "WHERE (pe.kreap_alias IS NULL OR pe.kreap_alias = '') ";
		$sql .= "AND pe.kreap_zs_descricaocurta IS NOT NULL AND pe.kreap_zs_descricaocurta <> ''";
		if (empty($sharedProducts)) {
			$sql .= " AND p.entity = " . ((int) $conf->entity);
		}
		$db->query($sql);
	}

	dolibarr_set_const($db, 'KREAPRODUCTS_ALIAS_FIELD_READY', '1', 'chaine', 0, '', $conf->entity);
	$conf->global->KREAPRODUCTS_ALIAS_FIELD_READY = '1';
}

// Setup weight unit with unique labels only
$sql = "SELECT DISTINCT unit_type FROM " . MAIN_DB_PREFIX . "c_units ORDER BY unit_type DESC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->unit_type] = $obj->unit_type;
	}
} else {
	dol_print_error($db);
}

// Product categories tree (for inventory category filter)
$TInventoryCategoryRoots = array('' => $langs->trans('None'));
$formcategory = new FormCategory($db);
$categoryTree = $formcategory->select_all_categories(0, '', '', 64, 0, 1, 1);
if (is_array($categoryTree)) {
	foreach ($categoryTree as $catId => $catLabel) {
		$TInventoryCategoryRoots[$catId] = $catLabel;
	}
}

// Warehouses list (for dismantle moves)
$TWarehouses = array('' => $langs->trans('None'));
$sql = "SELECT rowid, ref, lieu FROM " . MAIN_DB_PREFIX . "entrepot
        WHERE entity IN (0," . ((int) $conf->entity) . ")
        ORDER BY ref";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$label = $obj->ref;
		if (!empty($obj->lieu)) $label .= ' - ' . $obj->lieu;
		$TWarehouses[$obj->rowid] = $label;
	}
}

// BOM types for dismantle (keep 1 as default)
$TBomTypes = array(
	'1' => $langs->trans('KREAPRODUCTS_DISMANTLE_BOMTYPE_OPTION1'),
	'0' => $langs->trans('KREAPRODUCTS_DISMANTLE_BOMTYPE_OPTION0'),
);

// Sincronização de produtos
$formSetup->newItem('ZS_SYNC_PRODUCTS_TITLE')->setAsTitle();
$formSetup->newItem('KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE')->setAsYesNo();

// Lista de produtos
$item = $formSetup->newItem('KREAPRODUCTS_REPLACE_PRODUCT_LIST');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_REPLACE_PRODUCT_LIST_HELP');

// Nutrição e alergénios
$formSetup->newItem('KREAPRODUCTS_NUTRITION_SETTINGS_TITLE')->setAsTitle();
$formSetup->newItem('KREAPRODUCTS_NUTRITIONAL_TABLE_TAB')->setAsYesNo();
$item = $formSetup->newItem('KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT');
$item->setAsYesNo();
$item->helpText = $langs->transnoentities('KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT_HELP');
$item = $formSetup->newItem('KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT');
$item->setAsYesNo();
$item->helpText = $langs->transnoentities('KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT_HELP');

$item = $formSetup->newItem('KREAPRODUCTS_DEFAULT_WEIGHT_LABEL');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

$item = $formSetup->newItem('KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT');
$item->defaultFieldValue = '1.0';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT_HELP');
$item->fieldAttr = array('type' => 'number', 'step' => '0.01', 'min' => '0');

$item = $formSetup->newItem('KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT');
$item->defaultFieldValue = '0.1';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT_HELP');
$item->fieldAttr = array('type' => 'number', 'step' => '0.01', 'min' => '0');

// Etiquetas
$formSetup->newItem('KREAPRODUCTS_LABELS_SETTINGS_TITLE')->setAsTitle();
$item = $formSetup->newItem('KREAPRODUCTS_LABELS_TAB_ENABLED');
$item->setAsYesNo();
$item->defaultFieldValue = '0';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_LABELS_TAB_ENABLED_HELP');

$item = $formSetup->newItem('KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED_HELP');

// Movimentos de stock
$formSetup->newItem('KREAPRODUCTS_STOCK_SETTINGS_TITLE')->setAsTitle();
$formSetup->newItem('KREAPRODUCTS_STOCK_MOVEMENT_DATA')->setAsYesNo();

$item = $formSetup->newItem('KREAPRODUCTS_SUPPLIER_MOVE_TIME');
$item->defaultFieldValue = '10:00';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_SUPPLIER_MOVE_TIME_HELP');
$item->fieldAttr = array('type' => 'time', 'step' => '1');

// Inventário
$formSetup->newItem('KREAPRODUCTS_INVENTORY_SETTINGS_TITLE')->setAsTitle();
$item = $formSetup->newItem('KREAPRODUCTS_INVENTORY_DEFAULT_TIME');
$item->defaultFieldValue = '10:30';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_INVENTORY_DEFAULT_TIME_HELP');
$item->fieldAttr = array('type' => 'time', 'step' => '1');

$item = $formSetup->newItem('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT');
$item->setAsSelect($TInventoryCategoryRoots);
$item->defaultFieldValue = '';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT_HELP');

// Desmontagem
$formSetup->newItem('KREAPRODUCTS_DISMANTLE_SETTINGS_TITLE')->setAsTitle();
$item = $formSetup->newItem('KREAPRODUCTS_DISMANTLE_BOMTYPE');
$item->setAsSelect($TBomTypes);
$item->defaultFieldValue = '1';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_DISMANTLE_BOMTYPE_HELP');

$item = $formSetup->newItem('KREAPRODUCTS_DISMANTLE_WAREHOUSE');
$item->setAsSelect($TWarehouses);
$item->helpText = $langs->transnoentities('KREAPRODUCTS_DISMANTLE_WAREHOUSE_HELP');

// Simulador de preços
$formSetup->newItem('KREAPRODUCTS_SIMULATOR_SETTINGS_TITLE')->setAsTitle();
$item = $formSetup->newItem('KREAPRODUCTS_SIM_ENABLE');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_SIM_ENABLE_HELP');

$item = $formSetup->newItem('KREAPRODUCTS_SIM_DEFAULT_MARKUP');
$item->defaultFieldValue = '3';
$item->helpText = $langs->transnoentities('KREAPRODUCTS_SIM_DEFAULT_MARKUP_HELP');

/*
// Enter here all parameters in your setup page

// Setup conf for selection of an URL
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM1');
$item->fieldOverride = (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];
$item->cssClass = 'minwidth500';

// Setup conf for selection of a simple string input
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM2');
$item->defaultFieldValue = 'default value';

// Setup conf for selection of a simple textarea input but we replace the text of field title
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM3');
$item->nameText = $item->getNameText() . ' more html text ';

// Setup conf for a selection of a thirdparty
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM4');
$item->setAsThirdpartyType();

// Setup conf for a selection of a boolean
$formSetup->newItem('KREAPRODUCTS_MYPARAM5')->setAsYesNo();

// Setup conf for a selection of an email template of type thirdparty
$formSetup->newItem('KREAPRODUCTS_MYPARAM6')->setAsEmailTemplate('thirdparty');

// Setup conf for a selection of a secured key
//$formSetup->newItem('KREAPRODUCTS_MYPARAM7')->setAsSecureKey();

// Setup conf for a selection of a product
$formSetup->newItem('KREAPRODUCTS_MYPARAM8')->setAsProduct();

// Add a title for a new section
$formSetup->newItem('NewSection')->setAsTitle();

$TField = array(
	'test01' => $langs->trans('test01'),
	'test02' => $langs->trans('test02'),
	'test03' => $langs->trans('test03'),
	'test04' => $langs->trans('test04'),
	'test05' => $langs->trans('test05'),
	'test06' => $langs->trans('test06'),
);

// Setup conf for a simple combo list
$formSetup->newItem('KREAPRODUCTS_MYPARAM9')->setAsSelect($TField);

// Setup conf for a multiselect combo list
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM10');
$item->setAsMultiSelect($TField);
$item->helpText = $langs->transnoentities('KREAPRODUCTS_MYPARAM10');



// Setup conf KREAPRODUCTS_MYPARAM10
$item = $formSetup->newItem('KREAPRODUCTS_MYPARAM10');
$item->setAsColor();
$item->defaultFieldValue = '#FF0000';
$item->nameText = $item->getNameText() . ' more html text ';
$item->fieldInputOverride = '';
$item->helpText = $langs->transnoentities('AnHelpMessage');
//$item->fieldValue = '';
//$item->fieldAttr = array() ; // fields attribute only for compatible fields like input text
//$item->fieldOverride = false; // set this var to override field output will override $fieldInputOverride and $fieldOutputOverride too
//$item->fieldInputOverride = false; // set this var to override field input
//$item->fieldOutputOverride = false; // set this var to override field output
*/

$setupnotempty += count($formSetup->items);


$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);


/*
 * Actions
 */

// For retrocompatibility Dolibarr < 15.0
if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

if ($action == 'updateMask') {
	$maskconst = GETPOST('maskconst', 'aZ09');
	$maskvalue = GETPOST('maskvalue', 'alpha');

	if ($maskconst && preg_match('/_MASK$/', $maskconst)) {
		$res = dolibarr_set_const($db, $maskconst, $maskvalue, 'chaine', 0, '', $conf->entity);
		if (!($res > 0)) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
} elseif ($action == 'specimen') {
	$modele = GETPOST('module', 'alpha');
	$tmpobjectkey = GETPOST('object');

	$tmpobject = new $tmpobjectkey($db);
	$tmpobject->initAsSpecimen();

	// Search template files
	$file = '';
	$classname = '';
	$filefound = 0;
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir . "core/modules/kreaproducts/doc/pdf_" . $modele . "_" . strtolower($tmpobjectkey) . ".modules.php", 0);
		if (file_exists($file)) {
			$filefound = 1;
			$classname = "pdf_" . $modele . "_" . strtolower($tmpobjectkey);
			break;
		}
	}

	if ($filefound) {
		require_once $file;

		$module = new $classname($db);

		if ($module->write_file($tmpobject, $langs) > 0) {
			header("Location: " . DOL_URL_ROOT . "/document.php?modulepart=kreaproducts-" . strtolower($tmpobjectkey) . "&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, null, 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
} elseif ($action == 'setmod') {
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey) && !empty($value)) {
		$constforval = 'KREAPRODUCTS_' . strtoupper($tmpobjectkey) . "_ADDON";
		$canActivate = true;

		if (!empty($kreaObjects[$tmpobjectkey])) {
			$objectclass = $kreaObjects[$tmpobjectkey]['class'];
			dol_include_once('/kreaproducts/class/' . strtolower($tmpobjectkey) . '.class.php');
			if (class_exists($objectclass)) {
				$tmpobject = new $objectclass($db);

				dol_include_once('/kreaproducts/core/modules/kreaproducts/' . $value . '.php');
				if (class_exists($value)) {
					$module = new $value($db);
					if (method_exists($module, 'canBeActivated') && ! $module->canBeActivated($tmpobject)) {
						$canActivate = false;
						setEventMessages($module->error ?: $langs->trans("ErrorModuleNotFound"), null, 'errors');
					}
				} else {
					$canActivate = false;
					setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
				}
			} else {
				$canActivate = false;
				setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
			}
		}

		if ($canActivate) {
			dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
		}
	}
} elseif ($action == 'set') {
	// Activate a model
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$tmpobjectkey = GETPOST('object');
		if (!empty($tmpobjectkey)) {
			$constforval = 'KREAPRODUCTS_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
			if (getDolGlobalString($constforval) == "$value") {
				dolibarr_del_const($db, $constforval, $conf->entity);
			}
		}
	}
} elseif ($action == 'setdoc') {
	// Set or unset default model
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey)) {
		$constforval = 'KREAPRODUCTS_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
		if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
			// The constant that was read before the new set
			// We therefore requires a variable to have a coherent view
			$conf->global->{$constforval} = $value;
		}

		// We disable/enable the document template (into llx_document_model table)
		$ret = delDocumentModel($value, $type);
		if ($ret > 0) {
			$ret = addDocumentModel($value, $type, $label, $scandir);
		}
	}
} elseif ($action == 'unsetdoc') {
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey)) {
		$constforval = 'KREAPRODUCTS_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
		dolibarr_del_const($db, $constforval, $conf->entity);
	}
}



/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "KreaProductsSetup";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-kreaproducts page-admin');

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = kreaproductsAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "kreaproducts@kreaproducts");

// Setup page goes here
echo '<span class="opacitymedium">' . $langs->trans("KreaProductsSetupPage") . '</span><br><br>';


if ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
} elseif (!empty($formSetup->items)) {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit&token=' . newToken() . '">' . $langs->trans("Modify") . '</a>';
	print '</div>';
} else {
	print '<br>' . $langs->trans("NothingToSetup");
}


$moduledir = 'kreaproducts';
$myTmpObjects = $kreaObjects;


foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
	if ($myTmpObjectArray['includedocgeneration']) {
		/*
		 * Document templates generators
		 */
		$setupnotempty++;
		$type = strtolower($myTmpObjectKey);

		print load_fiche_titre($langs->trans("DocumentModules", $myTmpObjectKey), '', '');

		// Load array def with activated templates
		$def = array();
		$sql = "SELECT nom";
		$sql .= " FROM " . MAIN_DB_PREFIX . "document_model";
		$sql .= " WHERE type = '" . $db->escape($type) . "'";
		$sql .= " AND entity = " . $conf->entity;
		$resql = $db->query($sql);
		if ($resql) {
			$i = 0;
			$num_rows = $db->num_rows($resql);
			while ($i < $num_rows) {
				$array = $db->fetch_array($resql);
				array_push($def, $array[0]);
				$i++;
			}
		} else {
			dol_print_error($db);
		}

		print '<table class="noborder centpercent">' . "\n";
		print '<tr class="liste_titre">' . "\n";
		print '<td>' . $langs->trans("Name") . '</td>';
		print '<td>' . $langs->trans("Description") . '</td>';
		print '<td class="center" width="60">' . $langs->trans("Status") . "</td>\n";
		print '<td class="center" width="60">' . $langs->trans("Default") . "</td>\n";
		print '<td class="center" width="38">' . $langs->trans("ShortInfo") . '</td>';
		print '<td class="center" width="38">' . $langs->trans("Preview") . '</td>';
		print "</tr>\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			foreach (array('', '/doc') as $valdir) {
				$realpath = $reldir . "core/modules/" . $moduledir . $valdir;
				$dir = dol_buildpath($realpath);

				if (is_dir($dir)) {
					$handle = opendir($dir);
					if (is_resource($handle)) {
						while (($file = readdir($handle)) !== false) {
							$filelist[] = $file;
						}
						closedir($handle);
						arsort($filelist);

						foreach ($filelist as $file) {
							if (preg_match('/\.modules\.php$/i', $file) && preg_match('/^(pdf_|doc_)/', $file)) {
								if (file_exists($dir . '/' . $file)) {
									$name = substr($file, 4, dol_strlen($file) - 16);
									$classname = substr($file, 0, dol_strlen($file) - 12);

									require_once $dir . '/' . $file;
									$module = new $classname($db);

									$modulequalified = 1;
									if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
										$modulequalified = 0;
									}
									if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
										$modulequalified = 0;
									}

									if ($modulequalified) {
										print '<tr class="oddeven"><td width="100">';
										print(empty($module->name) ? $name : $module->name);
										print "</td><td>\n";
										if (method_exists($module, 'info')) {
											print $module->info($langs);
										} else {
											print $module->description;
										}
										print '</td>';

										// Active
										if (in_array($name, $def)) {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=del&token=' . newToken() . '&value=' . urlencode($name) . '">';
											print img_picto($langs->trans("Enabled"), 'switch_on');
											print '</a>';
											print '</td>';
										} else {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=set&token=' . newToken() . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '">' . img_picto($langs->trans("Disabled"), 'switch_off') . '</a>';
											print "</td>";
										}

										// Default
										print '<td class="center">';
										$constforvar = 'KREAPRODUCTS_' . strtoupper($myTmpObjectKey) . '_ADDON_PDF';
										if (getDolGlobalString($constforvar) == $name) {
											//print img_picto($langs->trans("Default"), 'on');
											// Even if choice is the default value, we allow to disable it. Replace this with previous line if you need to disable unset
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=unsetdoc&token=' . newToken() . '&object=' . urlencode(strtolower($myTmpObjectKey)) . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '&amp;type=' . urlencode($type) . '" alt="' . $langs->trans("Disable") . '">' . img_picto($langs->trans("Enabled"), 'on') . '</a>';
										} else {
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=setdoc&token=' . newToken() . '&object=' . urlencode(strtolower($myTmpObjectKey)) . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '" alt="' . $langs->trans("Default") . '">' . img_picto($langs->trans("Disabled"), 'off') . '</a>';
										}
										print '</td>';

										// Info
										$htmltooltip = '' . $langs->trans("Name") . ': ' . $module->name;
										$htmltooltip .= '<br>' . $langs->trans("Type") . ': ' . ($module->type ? $module->type : $langs->trans("Unknown"));
										if ($module->type == 'pdf') {
											$htmltooltip .= '<br>' . $langs->trans("Width") . '/' . $langs->trans("Height") . ': ' . $module->page_largeur . '/' . $module->page_hauteur;
										}
										$htmltooltip .= '<br>' . $langs->trans("Path") . ': ' . preg_replace('/^\//', '', $realpath) . '/' . $file;

										$htmltooltip .= '<br><br><u>' . $langs->trans("FeaturesSupported") . ':</u>';
										$htmltooltip .= '<br>' . $langs->trans("Logo") . ': ' . yn($module->option_logo, 1, 1);
										$htmltooltip .= '<br>' . $langs->trans("MultiLanguage") . ': ' . yn($module->option_multilang, 1, 1);

										print '<td class="center">';
										print $form->textwithpicto('', $htmltooltip, 1, 0);
										print '</td>';

										// Preview
										print '<td class="center">';
										if ($module->type == 'pdf') {
											$newname = preg_replace('/_' . preg_quote(strtolower($myTmpObjectKey), '/') . '/', '', $name);
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=specimen&module=' . urlencode($newname) . '&object=' . urlencode($myTmpObjectKey) . '">' . img_object($langs->trans("Preview"), 'pdf') . '</a>';
										} else {
											print img_object($langs->trans("PreviewNotAvailable"), 'generic');
										}
										print '</td>';

										print "</tr>\n";
									}
								}
							}
						}
					}
				}
			}
		}

		print '</table>';
	}
}

if (empty($setupnotempty)) {
	print '<br>' . $langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
