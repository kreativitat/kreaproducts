<?php
/* Copyright (C) 2007-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *   	\file       htdocs/product/inventory/card.php
 *		\ingroup    inventory
 *		\brief      Inventory card
 */

// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php'))    $res = @include __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = @include __DIR__ . '/../../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../master.inc.php'))  $res = @include __DIR__ . '/../master.inc.php';
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) $res = @include __DIR__ . '/../../master.inc.php';
if (!$res) die('Failed to include main.inc.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/lib/inventory.lib.php';

// Load translation files required by the page
$langs->loadLangs(array("stocks", "other"));

// Get parameters
$id = GETPOST('id', 'int');
$ref        = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm    = GETPOST('confirm', 'alpha');
$cancel     = GETPOST('cancel', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'inventorycard'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha');
$include_sub_warehouse = !empty(GETPOST('include_sub_warehouse')) ? GETPOST('include_sub_warehouse') : 0;
$fk_product = GETPOSTINT('fk_product');

function kreaproducts_inventory_normalize_suffix($label)
{
	$label = trim((string) $label);
	if ($label === '') {
		return '';
	}
	$label = preg_replace('/\s*\([^)]*\)/', '', $label);
	$label = dol_string_unaccent($label);
	$label = strtoupper($label);
	$label = preg_replace('/[^A-Z0-9]+/', '_', $label);
	return trim($label, '_');
}

function kreaproducts_inventory_get_category_label($db, array $categoryIds)
{
	if (empty($categoryIds)) {
		return '';
	}
	$categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
	sort($categoryIds, SORT_NUMERIC);
	$categoryId = $categoryIds[0] ?? 0;
	if (!$categoryId) {
		return '';
	}
	$sql = 'SELECT label FROM '.MAIN_DB_PREFIX.'categorie';
	$sql .= ' WHERE rowid = '.(int) $categoryId;
	$sql .= ' AND entity IN ('.getEntity('category').')';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		return $obj ? (string) $obj->label : '';
	}
	dol_syslog(__METHOD__ . " Error fetching category label: " . $db->lasterror(), LOG_ERR);
	return '';
}

function kreaproducts_inventory_get_product_label($db, $productId)
{
	if ($productId <= 0) {
		return '';
	}
	$sql = 'SELECT ref, label FROM '.MAIN_DB_PREFIX.'product';
	$sql .= ' WHERE rowid = '.(int) $productId;
	$sql .= ' AND entity IN ('.getEntity('product').')';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			return '';
		}
		$label = trim((string) ($obj->label ?? ''));
		return $label !== '' ? $label : (string) $obj->ref;
	}
	dol_syslog(__METHOD__ . " Error fetching product label: " . $db->lasterror(), LOG_ERR);
	return '';
}

function kreaproducts_inventory_get_ref_prefix()
{
	$year = GETPOSTINT('date_inventoryyear');
	$month = GETPOSTINT('date_inventorymonth');
	$day = GETPOSTINT('date_inventoryday');
	$hour = GETPOSTINT('date_inventoryhour');
	$min = GETPOSTINT('date_inventorymin');
	if ($year && $month && $day) {
		$timestamp = dol_mktime($hour, $min, 0, $month, $day, $year);
	} else {
		$timestamp = dol_now();
	}
	return dol_print_date($timestamp, '%Y%m%d');
}

function kreaproducts_inventory_build_ref($db, $prefix, $suffix, $entity, $excludeId = 0)
{
	$prefix = trim((string) $prefix);
	$suffix = trim((string) $suffix);
	if ($prefix === '' || $suffix === '') {
		return '';
	}
	$baseRef = $prefix . '_' . $suffix;
	$sql = 'SELECT ref FROM '.MAIN_DB_PREFIX.'inventory';
	$sql .= ' WHERE entity = '.(int) $entity;
	$sql .= ' AND rowid <> '.(int) $excludeId;
	$sql .= " AND ref LIKE '".$db->escape($baseRef)."%'";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__ . " Error checking existing refs: " . $db->lasterror(), LOG_ERR);
		return $baseRef;
	}
	$hasAny = false;
	$maxVersion = 1;
	while ($obj = $db->fetch_object($resql)) {
		$hasAny = true;
		if ($obj->ref === $baseRef) {
			$maxVersion = max($maxVersion, 1);
		} elseif (preg_match('/^' . preg_quote($baseRef, '/') . '_V(\d+)$/', (string) $obj->ref, $m)) {
			$maxVersion = max($maxVersion, (int) $m[1]);
		}
	}
	return $hasAny ? ($baseRef . '_V' . ($maxVersion + 1)) : $baseRef;
}

function kreaproducts_inventory_get_default_warehouse_id($db)
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'entrepot';
	$sql .= ' WHERE entity IN ('.getEntity('stock').')';
	$sql .= ' AND statut = 1';
	$sql .= ' ORDER BY rowid';
	$sql .= ' LIMIT 1';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		return $obj ? (int) $obj->rowid : 0;
	}
	dol_syslog(__METHOD__ . " Error fetching default warehouse: " . $db->lasterror(), LOG_ERR);
	return 0;
}

function kreaproducts_inventory_get_subcategory_ids($db, $rootId)
{
	$rootId = (int) $rootId;
	if ($rootId <= 0) {
		return array();
	}
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$cat = new Categorie($db);
	$tree = $cat->get_full_arbo(Categorie::TYPE_PRODUCT, $rootId, 1);
	if (!is_array($tree)) {
		return array();
	}
	$childIds = array();
	foreach ($tree as $info) {
		$catId = (int) ($info['id'] ?? 0);
		if ($catId > 0 && $catId !== $rootId) {
			$childIds[] = $catId;
		}
	}
	$childIds = array_values(array_unique($childIds));
	sort($childIds, SORT_NUMERIC);
	return $childIds;
}

if (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
	$result = restrictedArea($user, 'stock', $id, 'inventory&stock');
} else {
	$result = restrictedArea($user, 'stock', $id, 'inventory&stock', 'inventory_advance');
}

// Initialize technical objects
$object = new Inventory($db);
$extrafields = new ExtraFields($db);
if (isset($object->fields['date_inventory'])) {
	$object->fields['date_inventory']['type'] = 'datetime';
	$object->fields['date_inventory']['enabled'] = 1;
	$object->fields['date_inventory']['notnull'] = 1;
}
if (isset($object->fields['fk_warehouse'])) {
	$object->fields['fk_warehouse']['notnull'] = 1;
}
if (isset($object->fields['categories_product']) && !empty($conf->global->KREAPRODUCTS_INVENTORY_CATEGORY_ROOT)) {
	$rootCategoryId = (int) $conf->global->KREAPRODUCTS_INVENTORY_CATEGORY_ROOT;
	$subCategoryIds = kreaproducts_inventory_get_subcategory_ids($db, $rootCategoryId);
	$categoryIdsForFilter = !empty($subCategoryIds) ? implode(',', $subCategoryIds) : (string) $rootCategoryId;
	$object->fields['categories_product']['type'] = 'chkbxlst:categorie:label:rowid::type=0:0:'.$categoryIdsForFilter;
}
// no inventory docs yet
$includedocgeneration = false;
$diroutputmassaction = null;
// $diroutputmassaction = $conf->stock->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array('inventorycard', 'globalcard')); // Note that conf->hooks_modules contains array

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Initialize array of search criterias
$search_all = GETPOST("search_all", 'alpha');
$search = array();
foreach ($object->fields as $key => $val) {
	if (GETPOST('search_'.$key, 'alpha')) {
		$search[$key] = GETPOST('search_'.$key, 'alpha');
	}
}

if (empty($action) && empty($id) && empty($ref)) {
	$action = 'view';
}

if (in_array($action, array('create', 'add'), true) && isset($object->fields['date_inventory'])) {
	$setDefaultDate = !GETPOSTISSET('date_inventoryyear')
		&& !GETPOSTISSET('date_inventorymonth')
		&& !GETPOSTISSET('date_inventoryday');
	$setDefaultTime = !GETPOSTISSET('date_inventoryhour')
		&& !GETPOSTISSET('date_inventorymin');
	if ($setDefaultDate || $setDefaultTime) {
		$nowinfo = dol_getdate(dol_now());
		if ($setDefaultDate) {
			$_POST['date_inventoryyear'] = $nowinfo['year'];
			$_POST['date_inventorymonth'] = $nowinfo['mon'];
			$_POST['date_inventoryday'] = $nowinfo['mday'];
		}
		if ($setDefaultTime) {
			$time = trim($conf->global->KREAPRODUCTS_INVENTORY_DEFAULT_TIME ?? '10:30');
			if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
				$time = '10:30';
			}
			list($hour, $min) = explode(':', $time);
			$_POST['date_inventoryhour'] = (int) $hour;
			$_POST['date_inventorymin'] = (int) $min;
		}
	}
}

if (in_array($action, array('create', 'add'), true) && isset($object->fields['ref'])) {
	$object->fields['ref']['noteditable'] = 1;
}

if (in_array($action, array('create', 'add'), true)) {
	$warehouseId = GETPOSTINT('fk_warehouse');
	if ($warehouseId <= 0) {
		$defaultWarehouseId = kreaproducts_inventory_get_default_warehouse_id($db);
		if ($defaultWarehouseId > 0) {
			$_POST['fk_warehouse'] = $defaultWarehouseId;
			$_REQUEST['fk_warehouse'] = $defaultWarehouseId;
		}
	}
}

// Load object
include DOL_DOCUMENT_ROOT.'/core/actions_fetchobject.inc.php'; // Must be include, not include_once.

// Security check - Protection if external user
//if ($user->socid > 0) accessforbidden();
//if ($user->socid > 0) $socid = $user->socid;
//$result = restrictedArea($user, 'mymodule', $id);

if (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
	$permissiontoread = $user->rights->stock->lire;
	$permissiontoadd = $user->rights->stock->creer;
	$permissiontodelete = $user->rights->stock->supprimer;
	$permissionnote = $user->rights->stock->creer; // Used by the include of actions_setnotes.inc.php
	$permissiondellink = $user->rights->stock->creer; // Used by the include of actions_dellink.inc.php
	$upload_dir = $conf->stock->multidir_output[isset($object->entity) ? $object->entity : 1];
} else {
	$permissiontoread = $user->rights->stock->inventory_advance->read;
	$permissiontoadd = $user->rights->stock->inventory_advance->write;
	$permissiontodelete = $user->rights->stock->inventory_advance->delete;
	$permissionnote = $user->rights->stock->inventory_advance->write; // Used by the include of actions_setnotes.inc.php
	$permissiondellink = $user->rights->stock->inventory_advance->write; // Used by the include of actions_dellink.inc.php
	$upload_dir = $conf->stock->multidir_output[isset($object->entity) ? $object->entity : 1];
}

$isClosedInventory = (!empty($object->id) && (int) $object->status === Inventory::STATUS_RECORDED);
if ($isClosedInventory && in_array($action, array('delete', 'confirm_delete'), true)) {
	setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_DELETE_CLOSED'), null, 'errors');
	$action = '';
	$confirm = '';
}
if ($isClosedInventory && in_array($action, array('confirm_validate', 'validate', 'setdraft', 'confirm_setdraft', 'edit', 'update', 'update_extras', 'deleteline', 'confirm_deleteline'), true)) {
	setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_CLOSED_LOCKED'), null, 'errors');
	$action = '';
	$confirm = '';
}

/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	$savaction = $action;
	$error = 0;

	$backurlforlist = DOL_URL_ROOT.'/product/inventory/list.php';

	if (empty($backtopage) || ($cancel && empty($id))) {
		if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
			if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
				$backtopage = $backurlforlist;
			} else {
				$backtopage = dol_buildpath('/product/inventory/card.php', 1).'?id='.($id > 0 ? $id : '__ID__');
			}
		}
	}
	$triggermodname = 'STOCK_INVENTORY_MODIFY'; // Name of trigger action code to execute when we modify record

	if ($action === 'add') {
		$categoryIds = GETPOST('categories_product', 'array');
		if (!is_array($categoryIds)) {
			$rawCategories = GETPOST('categories_product', 'alphanohtml');
			$categoryIds = $rawCategories !== '' ? preg_split('/[,\s]+/', $rawCategories) : array();
		}
		$categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
		$suffixLabel = kreaproducts_inventory_get_category_label($db, $categoryIds);
		if ($suffixLabel === '') {
			$suffixLabel = kreaproducts_inventory_get_product_label($db, $fk_product);
		}
		$suffix = kreaproducts_inventory_normalize_suffix($suffixLabel);
		if ($suffix === '') {
			$suffix = 'INVENTORY';
		}
		$prefix = kreaproducts_inventory_get_ref_prefix();
		$autoref = kreaproducts_inventory_build_ref($db, $prefix, $suffix, (int) $conf->entity);
		if ($autoref !== '') {
			$_POST['ref'] = $autoref;
			$_REQUEST['ref'] = $autoref;
		}
	}

	// Actions cancel, add, update, update_extras, confirm_validate, confirm_delete, confirm_deleteline, confirm_clone, confirm_close, confirm_setdraft, confirm_reopen
	include DOL_DOCUMENT_ROOT.'/core/actions_addupdatedelete.inc.php';

	// Actions when linking object each other
	include DOL_DOCUMENT_ROOT.'/core/actions_dellink.inc.php';

	// Actions when printing a doc from card
	include DOL_DOCUMENT_ROOT.'/core/actions_printing.inc.php';

	// Action to move up and down lines of object
	//include DOL_DOCUMENT_ROOT.'/core/actions_lineupdown.inc.php';

	// Action to build doc
	include DOL_DOCUMENT_ROOT.'/core/actions_builddoc.inc.php';

	/*if ($action == 'set_thirdparty' && $permissiontoadd)
	{
		$object->setValueFrom('fk_soc', GETPOST('fk_soc', 'int'), '', '', 'date', '', $user, 'MYOBJECT_MODIFY');
	}*/
	if ($action == 'classin' && $permissiontoadd) {
		$object->setProject(GETPOST('projectid', 'int'));
	}

	// Actions to send emails
	$triggersendname = 'INVENTORY_SENTBYMAIL';
	$autocopy = 'MAIN_MAIL_AUTOCOPY_INVENTORY_TO';
	$trackid = 'stockinv'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';

	if (!$error && $savaction == 'confirm_validate' && $action == '' && $object->id > 0) {
		// Switch to the tab inventory
		header("Location: ".DOL_URL_ROOT.'/product/inventory/inventory.php?id='.$object->id);
		exit;
	}
}




/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
$formproject = new FormProjets($db);

$title = $langs->trans("Inventory");

$help_url = 'EN:Module_Stocks_En|FR:Module_Stock|ES:Módulo_Stocks|DE:Modul_Bestände';

llxHeader('', $title, $help_url);



// Part to create
if ($action == 'create') {
	print load_fiche_titre($langs->trans("NewInventory"), '', 'product');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
	}
	if (!empty($backtopageforcancel)) {
		print '<input type="hidden" name="backtopageforcancel" value="'.$backtopageforcancel.'">';
	}

	print dol_get_fiche_head(array(), '');

	print '<table class="border centpercent tableforfieldcreate">'."\n";

	// Common attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_add.tpl.php';

	// Other attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_add.tpl.php';

	//print '<tr><td class="titlefield fieldname_invcode">'.$langs->trans("InventoryCode").'</td><td>INV'.$object->id.'</td></tr>';

	print '</table>'."\n";

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel("Create");

	print '</form>';

	dol_set_focus('input[name="title"]');
}

// Part to edit record
if (($id || $ref) && $action == 'edit') {
	print load_fiche_titre($langs->trans("Inventory"), '', 'product');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.$object->id.'">';
	if ($backtopage) {
		print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
	}
	if ($backtopageforcancel) {
		print '<input type="hidden" name="backtopageforcancel" value="'.$backtopageforcancel.'">';
	}

	print dol_get_fiche_head();

	print '<table class="border centpercent tableforfieldedit">'."\n";

	// Common attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_edit.tpl.php';

	// Other attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_edit.tpl.php';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel();

	print '</form>';
}

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
	$res = $object->fetch_optionals();

	if (isset($object->fields['date_inventory'])) {
		$object->fields['date_inventory']['enabled'] = 1;
		$object->fields['date_inventory']['visible'] = 1;
	}
	if (!empty($object->date_validation)) {
		$object->fields['date_validation']['visible'] = 1;
	}
	if (!empty($object->date_creation)) {
		$object->fields['date_creation']['visible'] = 1;
	}
	if (!empty($object->fk_user_creat)) {
		$object->fields['fk_user_creat']['visible'] = 1;
	}

	$head = inventoryPrepareHead($object);
	print dol_get_fiche_head($head, 'card', $langs->trans("Inventory"), -1, 'stock');

	$formconfirm = '';


	// Confirmation of action xxxx
	if ($action == 'setdraft') {
		$text = $langs->trans('ConfirmSetToDraftInventory', $object->ref);
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('SetToDraft'), $text, 'confirm_setdraft', '', 0, 1, 220);
	}
	// Confirmation to delete
	if ($action == 'delete') {
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('DeleteInventory'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', 0, 1);
	}

	// Clone confirmation
	if ($action == 'clone') {
		// Create an array for form
		$formquestion = array();
		$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('ToClone'), $langs->trans('ConfirmCloneAsk', $object->ref), 'confirm_clone', $formquestion, 'yes', 1);
	}


	if ($action == 'validate') {
		$form = new Form($db);
		$formquestion = '';
		if (getDolGlobalInt('INVENTORY_INCLUDE_SUB_WAREHOUSE') && !empty($object->fk_warehouse)) {
			$formquestion = array(
				array('type' => 'checkbox', 'name' => 'include_sub_warehouse', 'label' => $langs->trans("IncludeSubWarehouse"), 'value' => 1, 'size' => '10'),
			);
			$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans('ValidateInventory'), $langs->trans('IncludeSubWarehouseExplanation'), 'confirm_validate', $formquestion, '', 1);
		}
	}

	// Call Hook formConfirm
	$parameters = array('formConfirm' => $formconfirm);
	$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
	if (empty($reshook)) {
		$formconfirm .= $hookmanager->resPrint;
	} elseif ($reshook > 0) {
		$formconfirm = $hookmanager->resPrint;
	}

	// Print form confirm
	print $formconfirm;


	// Object card
	// ------------------------------------------------------------
	$linkback = '<a href="'.DOL_URL_ROOT.'/product/inventory/list.php'.(!empty($socid) ? '?socid='.$socid : '').'">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<div class="refidno">';
	/*
	// Ref bis
	$morehtmlref.=$form->editfieldkey("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->inventory->creer, 'string', '', 0, 1);
	$morehtmlref.=$form->editfieldval("RefBis", 'ref_client', $object->ref_client, $object, $user->rights->inventory->creer, 'string', '', null, null, '', 1);
	// Thirdparty
	$morehtmlref.='<br>'.$langs->trans('ThirdParty') . ' : ' . $soc->getNomUrl(1);
	// Project
	if (isModEnabled('project'))
	{
		$langs->load("projects");
		$morehtmlref.='<br>'.$langs->trans('Project') . ' ';
		if ($permissiontoadd)
		{
			if ($action != 'classify')
			{
				$morehtmlref .= '<a class="editfielda" href="' . $_SERVER['PHP_SELF'] . '?action=classify&token='.newToken().'&id=' . $object->id . '">' . img_edit($langs->transnoentitiesnoconv('SetProject')) . '</a> : ';
				if ($action == 'classify') {
					//$morehtmlref .= $form->form_project($_SERVER['PHP_SELF'] . '?id=' . $object->id, $object->socid, $object->fk_project, 'projectid', 0, 0, 0, 1, '', 'maxwidth300');
					$morehtmlref .= '<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
					$morehtmlref .= '<input type="hidden" name="action" value="classin">';
					$morehtmlref .= '<input type="hidden" name="token" value="'.newToken().'">';
					$morehtmlref .= $formproject->select_projects($object->socid, $object->fk_project, 'projectid', $maxlength, 0, 1, 0, 1, 0, 0, '', 1);
					$morehtmlref .= '<input type="submit" class="button valignmiddle" value="'.$langs->trans("Modify").'">';
					$morehtmlref .= '</form>';
				} else {
					$morehtmlref.=$form->form_project($_SERVER['PHP_SELF'] . '?id=' . $object->id, $object->socid, $object->fk_project, 'none', 0, 0, 0, 1, '', 'maxwidth300');
				}
			}
		} else {
			if (!empty($object->fk_project)) {
				$proj = new Project($db);
				$proj->fetch($object->fk_project);
				$morehtmlref .= $proj->getNomUrl();
			} else {
				$morehtmlref.='';
			}
		}
	}
	*/
	$morehtmlref .= '</div>';


	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);


	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">'."\n";

	// Common attributes
	include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_view.tpl.php';

	// Other attributes. Fields from hook formObjectOptions and Extrafields.
	include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

	print '</table>';
	print '</div>';
	print '</div>';

	print '<div class="clearboth"></div>';

	print dol_get_fiche_end();


	// Buttons for actions
	if ($action != 'presend' && $action != 'editline') {
		print '<div class="tabsAction">'."\n";
		$parameters = array();
		$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}

		if (empty($reshook)) {
			// Send
			if (empty($user->socid)) {
				print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=presend&mode=init&token='.newToken().'#formmailbeforetitle">'.$langs->trans('SendMail').'</a>'."\n";
			}

			// Back to draft
			if ($object->status == $object::STATUS_VALIDATED) {
				if ($permissiontoadd) {
					print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=setdraft&confirm=yes&token='.newToken().'">'.$langs->trans("SetToDraft").'</a>';
				}
			}
			// Modify
			if ($object->status == $object::STATUS_DRAFT) {
				if ($permissiontoadd) {
					print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>'."\n";
				} else {
					print '<a class="butActionRefused classfortooltip" href="#" title="'.dol_escape_htmltag($langs->trans("NotEnoughPermissions")).'">'.$langs->trans('Modify').'</a>'."\n";
				}
			}

			// Validate
			if ($object->status == $object::STATUS_DRAFT || $object->status == $object::STATUS_CANCELED) {
				if ($permissiontoadd) {
					if (getDolGlobalInt('INVENTORY_INCLUDE_SUB_WAREHOUSE') && !empty($object->fk_warehouse)) {
						print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=validate&token='.newToken().'">'.$langs->trans("Validate").' ('.$langs->trans("ToStart").')</a>';
					} else {
						print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_validate&confirm=yes&token='.newToken().'">'.$langs->trans("Validate").' ('.$langs->trans("ToStart").')</a>';
					}
				}
			}

			// Clone
			if ($permissiontoadd) {
				//print dolGetButtonAction($langs->trans("ToClone"), '', 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&socid='.$object->socid.'&action=clone&object=inventory', 'clone', $permissiontoadd);
			}

			// Delete
			if ((int) $object->status !== $object::STATUS_RECORDED) {
				print dolGetButtonAction($langs->trans("Delete"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delete&token='.newToken(), 'delete', $permissiontodelete);
			}
		}
		print '</div>'."\n";
	}


	// Select mail models is same action as presend
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	if ($action != 'presend') {
		print '<div class="fichecenter"><div class="fichehalfleft">';
		print '<a name="builddoc"></a>'; // ancre

		// Documents
		if ($includedocgeneration) {
			$objref = dol_sanitizeFileName($object->ref);
			$relativepath = $objref.'/'.$objref.'.pdf';
			$filedir = $conf->mymodule->dir_output.'/'.$object->element.'/'.$objref;
			$urlsource = $_SERVER["PHP_SELF"]."?id=".$object->id;
			$genallowed = $user->rights->mymodule->myobject->read; // If you can read, you can build the PDF to read content
			$delallowed = $user->rights->mymodule->myobject->write; // If you can create/edit, you can remove a file on card
			print $formfile->showdocuments('mymodule:MyObject', $object->element.'/'.$objref, $filedir, $urlsource, $genallowed, $delallowed, $object->model_pdf, 1, 0, 0, 28, 0, '', '', '', $langs->defaultlang);
		}

		// Show links to link elements
		$linktoelem = $form->showLinkToObjectBlock($object, null, array('inventory'));
		$somethingshown = $form->showLinkedObjectBlock($object, $linktoelem);


		print '</div><div class="fichehalfright">';

		$MAXEVENT = 10;

		//$morehtmlcenter = dolGetButtonTitle($langs->trans('SeeAll'), '', 'fa fa-bars imgforviewmode', DOL_URL_ROOT.'/product/inventory/inventory_info.php?id='.$object->id);
		$morehtmlcenter = '';

		// List of actions on element
		include_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
		$formactions = new FormActions($db);
		$somethingshown = $formactions->showactions($object, $object->element, 0, 1, '', $MAXEVENT, '', $morehtmlcenter);

		print '</div></div>';
	}


	//Select mail models is same action as presend
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	// Presend form
	$modelmail = 'inventory';
	$defaulttopic = 'InformationMessage';
	$diroutput = $conf->product->dir_output.'/inventory';
	$trackid = 'stockinv'.$object->id;

	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

// End of page
llxFooter();
$db->close();
