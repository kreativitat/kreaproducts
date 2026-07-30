<?php
/* Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/kreaproducts/product_list.php
 * \ingroup    kreaproducts
 * \brief      Simplified product list with hide toggle based on kreap_hideproduct.
 */

// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php'))    $res = @include __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = @include __DIR__ . '/../../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../master.inc.php'))  $res = @include __DIR__ . '/../master.inc.php';
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) $res = @include __DIR__ . '/../../master.inc.php';
if (!$res) die('Failed to include main.inc.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcategory.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

$langs->loadLangs(array('products', 'stocks', 'companies', 'other', 'kreaproducts@kreaproducts'));

if (!$user->rights->produit->lire) {
	accessforbidden();
}

$hookmanager->initHooks(array('productservicelist', 'kreapproductlist'));

// Parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$show_files = GETPOSTINT('show_files');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$optioncss = GETPOST('optioncss', 'alpha');
$mode = GETPOST('mode', 'alpha');

$show_hidden = GETPOSTISSET('show_hidden') ? GETPOSTINT('show_hidden') : 0; // 0 = hide flagged, 1 = show all
$search_ref = GETPOST('search_ref', 'alphanohtml');
$search_label = GETPOST('search_label', 'alphanohtml');
$search_tobuy = GETPOST('search_tobuy', 'alpha');
$search_tosell = GETPOST('search_tosell', 'alpha');
$search_price_level = GETPOST('search_price_level', 'int');
$priceLevel = ($search_price_level > 0) ? (int) $search_price_level : 1;
$useMultiprices = !empty($conf->global->PRODUIT_MULTIPRICES);
$showMarginColumn = !empty($conf->global->KREAPRODUCTS_PRODUCT_LIST_MARGIN_ENABLED);
$searchCategoryProductOperator = 1; // Default to OR between categories
$searchCategoryProductList = GETPOST('search_category_product_list', 'array');
$leftmenu = GETPOST('leftmenu', 'alpha');
$type = (string) GETPOST('type', 'int');
if ($type !== '0' && $type !== '1') {
	$type = '';
}
$configuredSuffixesCsv = isset($conf->global->KREAPRODUCTS_PRODUCT_REF_SUFFIXES) ? (string) $conf->global->KREAPRODUCTS_PRODUCT_REF_SUFFIXES : '';
$configuredSuffixesCsv = trim($configuredSuffixesCsv);
$availableProductSuffixes = array();
if ($configuredSuffixesCsv !== '') {
	foreach (explode(',', $configuredSuffixesCsv) as $rawSuffix) {
		$suffix = strtolower(trim((string) $rawSuffix));
		if ($suffix === '') {
			continue;
		}
		if (!preg_match('/^[a-z0-9._-]+$/', $suffix)) {
			continue;
		}
		$availableProductSuffixes[$suffix] = $suffix;
	}
	$availableProductSuffixes = array_values($availableProductSuffixes);
}
$search_product_suffixes = GETPOSTISARRAY('search_product_suffixes') ? GETPOST('search_product_suffixes', 'array') : array();
$selectedProductSuffixes = array();
if (!empty($search_product_suffixes) && !empty($availableProductSuffixes)) {
	$availableSuffixLookup = array_fill_keys($availableProductSuffixes, true);
	foreach ($search_product_suffixes as $rawSuffix) {
		$suffix = strtolower(trim((string) $rawSuffix));
		if ($suffix === '' || empty($availableSuffixLookup[$suffix])) {
			continue;
		}
		$selectedProductSuffixes[$suffix] = $suffix;
	}
	$selectedProductSuffixes = array_values($selectedProductSuffixes);
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

if (empty($sortfield)) {
	$sortfield = 'p.ref';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
}

if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter_y', 'alpha')) {
	$search_ref = '';
	$search_label = '';
	$search_tobuy = '';
	$search_tosell = '';
	$searchCategoryProductList = array();
	$selectedProductSuffixes = array();
	$searchCategoryProductOperator = 1;
}

$param = '&show_hidden=' . ((int) $show_hidden);
$param .= ($search_ref !== '' ? '&search_ref=' . urlencode($search_ref) : '');
$param .= ($search_label !== '' ? '&search_label=' . urlencode($search_label) : '');
$param .= ($search_tobuy !== '' ? '&search_tobuy=' . urlencode($search_tobuy) : '');
$param .= ($search_tosell !== '' ? '&search_tosell=' . urlencode($search_tosell) : '');
if (!empty($selectedProductSuffixes)) {
	foreach ($selectedProductSuffixes as $suffixIndex => $suffixValue) {
		$param .= '&search_product_suffixes[' . ((int) $suffixIndex) . ']=' . urlencode((string) $suffixValue);
	}
}
if (!empty($searchCategoryProductList)) {
	foreach ($searchCategoryProductList as $key => $valcat) {
		$param .= '&search_category_product_list[' . $key . ']=' . urlencode((string) $valcat);
	}
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}
if ($type !== '' && $type !== null) {
	$param .= '&type=' . urlencode((string) $type);
}
if (!empty($leftmenu)) {
	$param .= '&leftmenu=' . urlencode($leftmenu);
}

$object = new Product($db);
$form = new Form($db);
$formcategory = new FormCategory($db);

$title = $langs->trans('KreapProductSimpleList');
if ($type === '0') {
	$title = $langs->trans('Products');
} elseif ($type === '1') {
	$title = $langs->trans('Services');
}
$picto = ($type === '1') ? 'service' : 'product';

// Fields
$arrayfields = array(
	'p.ref' => array('label' => $langs->trans('Ref'), 'checked' => 1, 'position' => 1),
	'p.label' => array('label' => $langs->trans('Label'), 'checked' => 1, 'position' => 2),
	'cost_price' => array('label' => $langs->trans('KreapCostWithoutVat'), 'checked' => 1, 'position' => 3),
	'sell_price' => array('label' => $langs->trans('KreapPriceWithoutVat'), 'checked' => 1, 'position' => 5),
	'sell_price_ttc' => array('label' => $langs->trans('KreapPriceWithVat'), 'checked' => 1, 'position' => 6),
	'vat_rate' => array('label' => $langs->trans('VAT'), 'checked' => 1, 'position' => 7),
	'p.entity' => array('label' => $langs->trans('Entity'), 'checked' => 1, 'position' => 8),
	'p.tobuy' => array('label' => $langs->trans('Buy'), 'checked' => 1, 'position' => 9),
	'p.tosell' => array('label' => $langs->trans('Sell'), 'checked' => 1, 'position' => 10),
);
if ($showMarginColumn) {
	$arrayfields = array_slice($arrayfields, 0, 3, true) + array(
		'margin_without_vat' => array('label' => $langs->trans('KreapMargin'), 'checked' => 1, 'position' => 4),
	) + array_slice($arrayfields, 3, null, true);
}
if ($show_hidden) {
	$arrayfields['kreap_hideproduct'] = array('label' => $langs->trans('kreap_hideproduct'), 'checked' => 1, 'position' => 11);
}

$allowedSortFields = array(
	'p.ref' => 'p.ref',
	'p.label' => 'p.label',
	'cost_price' => 'cost_price',
	'sell_price' => 'sell_price',
	'sell_price_ttc' => 'sell_price_ttc',
	'vat_rate' => 'vat_rate',
	'p.entity' => 'p.entity',
	'p.tobuy' => 'p.tobuy',
	'p.tosell' => 'p.tosell',
	'kreap_hideproduct' => 'kreap_hideproduct',
);
if (empty($allowedSortFields[$sortfield])) {
	$sortfield = 'p.ref';
}

// SQL build
$sql = "SELECT p.rowid, p.ref, p.label, p.entity, p.tobuy, p.tosell, p.fk_product_type, "
	. "COALESCE(p.cost_price, p.pmp, 0) as cost_price, "
	. "COALESCE(pp.price, p.price, 0) as sell_price, "
	. "COALESCE(pp.price_ttc, p.price_ttc, 0) as sell_price_ttc, "
	. "COALESCE(p.tva_tx, 0) as vat_rate, "
	. "COALESCE(pe.kreap_hideproduct, 0) as kreap_hideproduct";
$sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields as pe ON p.rowid = pe.fk_object";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_price as pp ON pp.rowid = (";
$sql .= "SELECT pp2.rowid FROM " . MAIN_DB_PREFIX . "product_price as pp2";
$sql .= " WHERE pp2.fk_product = p.rowid";
$sql .= " AND pp2.entity IN (" . getEntity('productprice') . ")";
$sql .= " AND pp2.price_level = " . ((int) $priceLevel);
$sql .= " ORDER BY pp2.date_price DESC, pp2.rowid DESC";
$sql .= " LIMIT 1)";
$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";

if (!$show_hidden) {
	$sql .= " AND COALESCE(pe.kreap_hideproduct, 0) = 0";
}
if ($type !== '' && $type !== null && ($type === '0' || $type === '1')) {
	$sql .= " AND p.fk_product_type = " . ((int) $type);
}
if ($search_ref !== '') {
	$sql .= natural_search('p.ref', $search_ref);
}
if ($search_label !== '') {
	$sql .= natural_search('p.label', $search_label);
}
if ($search_tobuy !== '' && $search_tobuy !== '-1') {
	$sql .= " AND p.tobuy = " . ((int) $search_tobuy);
}
if ($search_tosell !== '' && $search_tosell !== '-1') {
	$sql .= " AND p.tosell = " . ((int) $search_tosell);
}
if (!empty($selectedProductSuffixes)) {
	$searchProductSuffixSqlList = array();
	foreach ($selectedProductSuffixes as $suffix) {
		$suffixLength = dol_strlen($suffix);
		if ($suffixLength <= 0) {
			continue;
		}
		$searchProductSuffixSqlList[] = "LOWER(RIGHT(TRIM(p.label), " . ((int) $suffixLength) . ")) = '" . $db->escape($suffix) . "'";
	}
	if (!empty($searchProductSuffixSqlList)) {
		$sql .= " AND (" . implode(' OR ', $searchProductSuffixSqlList) . ")";
	}
}
if (!empty($searchCategoryProductList)) {
	$listofcategoryid = implode(',', array_map('intval', $searchCategoryProductList));
	$searchCategoryProductSqlList = array();
	$searchCategoryProductSqlList[] = " EXISTS (SELECT ck.fk_product FROM " . MAIN_DB_PREFIX . "categorie_product as ck WHERE p.rowid = ck.fk_product AND ck.fk_categorie IN (" . $db->sanitize($listofcategoryid) . "))";
	if (!empty($searchCategoryProductSqlList)) {
		if ($searchCategoryProductOperator == 1) {
			$sql .= " AND (" . implode(' OR ', $searchCategoryProductSqlList) . ")";
		} else {
			$sql .= " AND (" . implode(' AND ', $searchCategoryProductSqlList) . ")";
		}
	}
}

// Count
$sqlcount = "SELECT COUNT(*) as nbtotalofrecords FROM (" . $sql . ") as sub";
$resqlcount = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resqlcount) {
	$objcount = $db->fetch_object($resqlcount);
	$nbtotalofrecords = $objcount ? (int) $objcount->nbtotalofrecords : 0;
	$db->free($resqlcount);
}

$sortorder = strtoupper($sortorder) === 'DESC' ? 'DESC' : 'ASC';
if ($sortfield === 'p.ref') {
	// Natural sort for refs: numeric refs are ordered numerically (1,2,11) before alphanumeric refs.
	if ($db->type === 'pgsql') {
		$sql .= " ORDER BY CASE WHEN p.ref ~ '^[0-9]+$' THEN 0 ELSE 1 END " . $sortorder;
		$sql .= ", CASE WHEN p.ref ~ '^[0-9]+$' THEN CAST(p.ref AS BIGINT) ELSE NULL END " . $sortorder;
		$sql .= ", p.ref " . $sortorder;
	} else {
		$sql .= " ORDER BY CASE WHEN p.ref REGEXP '^[0-9]+$' THEN 0 ELSE 1 END " . $sortorder;
		$sql .= ", CASE WHEN p.ref REGEXP '^[0-9]+$' THEN CAST(p.ref AS UNSIGNED) ELSE NULL END " . $sortorder;
		$sql .= ", p.ref " . $sortorder;
	}
} else {
	$sql .= $db->order($allowedSortFields[$sortfield], $sortorder);
}
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);
$entityLabels = array();
$resEntity = $db->query("SELECT rowid, label FROM " . MAIN_DB_PREFIX . "entity WHERE rowid IN (" . getEntity('product') . ")");
if ($resEntity) {
	while ($o = $db->fetch_object($resEntity)) {
		$entityLabels[$o->rowid] = $o->label;
	}
	$db->free($resEntity);
}

$toggleParams = array(
	'show_hidden' => $show_hidden ? 0 : 1,
	'sortfield' => $sortfield,
	'sortorder' => $sortorder,
	'limit' => $limit,
	'page' => $page,
	'search_ref' => $search_ref,
	'search_label' => $search_label,
	'search_price_level' => $search_price_level,
	'search_tobuy' => $search_tobuy,
	'search_tosell' => $search_tosell,
	'type' => $type,
	'leftmenu' => $leftmenu,
);
if (!empty($searchCategoryProductList)) {
	$toggleParams['search_category_product_list'] = $searchCategoryProductList;
}
if (!empty($selectedProductSuffixes)) {
	$toggleParams['search_product_suffixes'] = $selectedProductSuffixes;
}
$toggleUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query($toggleParams, '', '&', PHP_QUERY_RFC3986);
$toggleLabel = $show_hidden ? $langs->trans('KreapHideHiddenProducts') : $langs->trans('KreapShowHiddenProducts');
$toggleIcon = 'fa fa-toggle-' . ($show_hidden ? 'on' : 'off');
$toggleShortLabel = $show_hidden ? $langs->trans('KreapHideHiddenShort') : $langs->trans('KreapShowHiddenShort');
$toggleHideBackParams = array(
	'show_hidden' => $show_hidden,
	'sortfield' => $sortfield,
	'sortorder' => $sortorder,
	'limit' => $limit,
	'page' => $page,
	'search_ref' => $search_ref,
	'search_label' => $search_label,
	'search_price_level' => $search_price_level,
	'search_tobuy' => $search_tobuy,
	'search_tosell' => $search_tosell,
	'type' => $type,
	'leftmenu' => $leftmenu,
);
if (!empty($searchCategoryProductList)) {
	$toggleHideBackParams['search_category_product_list'] = $searchCategoryProductList;
}
if (!empty($selectedProductSuffixes)) {
	$toggleHideBackParams['search_product_suffixes'] = $selectedProductSuffixes;
}
$toggleHideBackUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query($toggleHideBackParams, '', '&', PHP_QUERY_RFC3986);

// Filter on categories (header area before list)
$moreforfilter = '';
if (isModEnabled('category') && $user->hasRight('categorie', 'read')) {
	$tmptitle = $langs->transnoentitiesnoconv('Category');
	$categoryArray = array();
	$categoryTree = $formcategory->select_all_categories(Categorie::TYPE_PRODUCT, '', '', 512, 0, 2);
	if (is_array($categoryTree)) {
		foreach ($categoryTree as $categoryNode) {
			if (!isset($categoryNode['id'])) {
				continue;
			}
			$fullLabel = !empty($categoryNode['fulllabel']) ? $categoryNode['fulllabel'] : $categoryNode['label'];
			$categoryArray[(int) $categoryNode['id']] = $fullLabel;
		}
	}
	$langs->load('categories');
	$categoryArray[-2] = '- ' . $langs->trans('NotCategorized') . ' -';

	$moreforfilter .= '<div class="divsearchfield">';
	$moreforfilter .= img_picto($tmptitle, 'category', 'class="pictofixedwidth"');
	$moreforfilter .= Form::multiselectarray('search_category_product_list', $categoryArray, $searchCategoryProductList, 0, 0, 'minwidth300', 0, 0, '', '', $tmptitle);
	$moreforfilter .= '</div>';
}
if (!empty($availableProductSuffixes)) {
	$moreforfilter .= '<div class="divsearchfield">';
	$moreforfilter .= img_picto($langs->transnoentitiesnoconv('Label'), 'filter', 'class="pictofixedwidth"');
	$moreforfilter .= '<span class="opacitymedium marginrightonly">' . $langs->trans('KREAPRODUCTS_PRODUCT_SUFFIX') . '</span>';
	foreach ($availableProductSuffixes as $suffix) {
		$inputId = 'search_product_suffix_' . preg_replace('/[^a-z0-9_]/', '_', $suffix);
		$checked = in_array($suffix, $selectedProductSuffixes, true) ? ' checked' : '';
		$moreforfilter .= '<label class="nowrap marginrightonly" for="' . dol_escape_htmltag($inputId) . '">';
		$moreforfilter .= '<input class="flat valignmiddle" type="checkbox" id="' . dol_escape_htmltag($inputId) . '" name="search_product_suffixes[]" value="' . dol_escape_htmltag($suffix) . '"' . $checked . '>';
		$moreforfilter .= ' ' . dol_escape_htmltag(strtoupper($suffix)) . '</label>';
	}
	$moreforfilter .= '</div>';
}

llxHeader('', $title);

$newcardbutton = '';
if ($user->rights->produit->creer) {
	$newcardbutton .= dolGetButtonTitle($langs->trans('NewProduct'), '', 'fa fa-plus-circle', DOL_URL_ROOT . '/product/card.php?action=create&type=0', '', 1, array());
}
$morehtmlright = $newcardbutton;

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'object_' . $picto, 0, $morehtmlright, '', $limit, 0, 0, 1);

print '<form id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '" method="GET" name="formulaire">';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste' . (!empty($moreforfilter) ? ' ' : '') . '">';

if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
}
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="pageplusone" value="' . ($page + 1) . '">';
print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
print '<input type="hidden" name="mode" value="' . dol_escape_htmltag($mode) . '">';
print '<input type="hidden" name="show_hidden" value="' . (int) $show_hidden . '">';
if ($type !== '' && $type !== null) {
	print '<input type="hidden" name="type" value="' . dol_escape_htmltag($type) . '">';
}
if (!empty($leftmenu)) {
	print '<input type="hidden" name="leftmenu" value="' . dol_escape_htmltag($leftmenu) . '">';
}



// Categories/variants row just before filters
if (!empty($moreforfilter)) {
	$colspan = 1;
	foreach ($arrayfields as $val) {
		if (!empty($val['checked'])) {
			$colspan++;
		}
	}
	print '<tr class="liste_titre">';
	print '<td class="liste_titre" colspan="' . $colspan . '">';
	print '<table class="nobordernopadding centpercent"><tr>';
	print '<td class="left">' . $moreforfilter . '</td>';
	print '<td class="right" style="width:1%;">';
	print '<div class="tabBarInsideButAction valignmiddle" style="background: transparent; border: none !important; box-shadow: none; padding: 0; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">';
	print '<span class="valignmiddle" style="color: rgb(96, 96, 111); white-space: nowrap;">' . $toggleShortLabel . '</span>';
	print '<a class="btnTitle" style="background: transparent; color: rgb(96, 96, 111) !important; border: none !important; box-shadow: none; outline: none; padding: 0;" href="' . dol_escape_htmltag($toggleUrl) . '" title="' . dol_escape_htmltag($toggleLabel) . '">';
	print '<span class="fa fa-toggle-' . ($show_hidden ? 'on' : 'off') . ' valignmiddle btnTitle-icon"></span>';
	print '</a>';
	print '</div>';
	print '</td></tr></table>';
	print '</td>';
	print '</tr>';
}

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch"><div class="nowraponall">' . $form->showFilterButtons('right') . '</div></td>';
print '<td class="liste_titre left"><input class="flat width75" type="text" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '"></td>';
print '<td class="liste_titre left"><input class="flat" style="width: 100%; box-sizing: border-box;" type="text" name="search_label" value="' . dol_escape_htmltag($search_label) . '"></td>';
print '<td class="liste_titre right"></td>';
if ($showMarginColumn) {
	print '<td class="liste_titre right"></td>';
}
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre maxwidthonsmartphone" align="center">&nbsp;</td>';
$selectBuy = array('-1' => '&nbsp;', '0' => $langs->trans('Status') . ' (OFF)', '1' => $langs->trans('Status') . ' (ON)');
print '<td class="liste_titre center parentonrightofpage">' . $form->selectarray('search_tobuy', $selectBuy, $search_tobuy, 0, 0, 0, '', 0, 0, 0, '', '', 1) . '</td>';
$selectSell = array('-1' => '&nbsp;', '0' => $langs->trans('Status') . ' (OFF)', '1' => $langs->trans('Status') . ' (ON)');
print '<td class="liste_titre center parentonrightofpage">' . $form->selectarray('search_tosell', $selectSell, $search_tosell, 0, 0, 0, '', 0, 0, 0, '', '', 1) . '</td>';
if ($show_hidden) {
	print '<td class="liste_titre center parentonrightofpage"></td>';
}
print '</tr>';

// Header row
print '<tr class="liste_titre">';
print '<th class="wrapcolumntitle center maxwidthsearch liste_titre"></th>';
foreach ($arrayfields as $key => $val) {
	if (!empty($val['checked'])) {
		$align = '';
		if ($key === 'cost_price' || $key === 'margin_without_vat' || $key === 'sell_price' || $key === 'sell_price_ttc' || $key === 'vat_rate') {
			$align = 'right ';
		} elseif ($key === 'p.entity') {
			$align = 'center nowrap ';
		} elseif ($key === 'p.tobuy' || $key === 'p.tosell' || $key === 'kreap_hideproduct') {
			$align = 'center ';
		}
		if ($key === 'margin_without_vat') {
			print '<th class="' . trim($align . 'liste_titre') . '">' . dol_escape_htmltag($val['label']) . '</th>';
			continue;
		}
		print_liste_field_titre($val['label'], $_SERVER["PHP_SELF"], $key, '', $param, '', $sortfield, $sortorder, $align);
	}
}
print '</tr>';

// Lines
$i = 0;
$var = true;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$var = !$var;
	$productstatic = $object;
	$productstatic->id = $obj->rowid;
	$productstatic->ref = $obj->ref;
	$productstatic->label = $obj->label;
	$productstatic->type = $obj->fk_product_type;
	$productstatic->status = $obj->tosell;
	$productstatic->status_buy = $obj->tobuy;
	$fetchRes = $productstatic->fetch($obj->rowid);
	if ($fetchRes <= 0) {
		$productstatic->price = 0;
		$productstatic->price_ttc = 0;
		$productstatic->cost_price = $obj->cost_price;
	}
	$productstatic->status = $obj->tosell;
	$productstatic->status_buy = $obj->tobuy;

	$priceExclVat = (float) $productstatic->price;
	$priceInclVat = (float) $productstatic->price_ttc;
	if ($useMultiprices) {
		if (isset($productstatic->multiprices[$priceLevel]) && $productstatic->multiprices[$priceLevel] !== '') {
			$priceExclVat = (float) $productstatic->multiprices[$priceLevel];
		}
		if (isset($productstatic->multiprices_ttc[$priceLevel]) && $productstatic->multiprices_ttc[$priceLevel] !== '') {
			$priceInclVat = (float) $productstatic->multiprices_ttc[$priceLevel];
		}
	}
	$vatRate = isset($productstatic->tva_tx) ? (float) $productstatic->tva_tx : (float) $obj->vat_rate;
	if ($useMultiprices && isset($productstatic->multiprices_tva_tx[$priceLevel]) && $productstatic->multiprices_tva_tx[$priceLevel] !== '') {
		$vatRate = (float) $productstatic->multiprices_tva_tx[$priceLevel];
	}
	$costPriceDisplay = isset($productstatic->cost_price) ? $productstatic->cost_price : $obj->cost_price;
	$marginAmountExclVat = $priceExclVat - (float) $costPriceDisplay;
	$marginRateExclVat = ($priceExclVat != 0.0) ? (($marginAmountExclVat / $priceExclVat) * 100) : null;

	print '<tr class="oddeven">';
	print '<td class="center nowrap"></td>';
	foreach ($arrayfields as $key => $val) {
		if (empty($val['checked'])) {
			continue;
		}
		switch ($key) {
			case 'p.ref':
				$link = $productstatic->getNomUrl(1);
				$link = preg_replace('/<a\b/', '<a target="_blank" rel="noopener"', $link, 1);
				print '<td>' . $link . '</td>';
				break;
			case 'p.label':
				print '<td>' . dol_escape_htmltag($obj->label) . '</td>';
				break;
			case 'sell_price':
				print '<td class="right">' . price($priceExclVat) . '</td>';
				break;
			case 'sell_price_ttc':
				print '<td class="right">' . price($priceInclVat) . '</td>';
				break;
			case 'vat_rate':
				print '<td class="right">' . vatrate($vatRate, true) . '</td>';
				break;
			case 'cost_price':
				print '<td class="right">' . price($costPriceDisplay) . '</td>';
				break;
			case 'margin_without_vat':
				$marginRateDisplay = ($marginRateExclVat !== null) ? price($marginRateExclVat, '', '', 0, 2, 2) . '%' : '-';
				$marginTitle = $langs->transnoentitiesnoconv('KREAPRODUCTS_PRODUCT_MARGIN_FORMULA');
				print '<td class="right nowrap" title="' . dol_escape_htmltag($marginTitle) . '">' . $marginRateDisplay . '</td>';
				break;
		case 'p.entity':
			$entityLabel = isset($entityLabels[$obj->entity]) ? $entityLabels[$obj->entity] : $obj->entity;
			print '<td class="center nowrap"><div class="refidno multicompany-entity-card-container" style="white-space: nowrap;"><span class="fa fa-globe"></span><span class="multiselect-selected-title-text" style="white-space: nowrap; max-width: none; overflow: visible; text-overflow: clip;">' . dol_escape_htmltag($entityLabel) . '</span></div></td>';
			break;
		case 'p.tobuy':
			$canEditBuy = ($productstatic->type == Product::TYPE_SERVICE) ? !empty($user->rights->service->creer) : !empty($user->rights->produit->creer);
			if ($canEditBuy) {
				print '<td class="center">' . ajax_object_onoff($productstatic, 'status_buy', 'tobuy', 'ProductStatusOnBuy', 'ProductStatusNotOnBuy') . '</td>';
			} else {
				$isBuyOn = (int) $obj->tobuy;
				$label = $langs->trans($isBuyOn ? 'ProductStatusOnBuy' : 'ProductStatusNotOnBuy');
				$badgeClass = $isBuyOn ? 'badge badge-status4 badge-status' : 'badge badge-status1 badge-status';
				print '<td class="center"><span class="' . $badgeClass . '" title="' . dol_escape_htmltag($label) . '">' . dol_escape_htmltag($label) . '</span></td>';
			}
			break;
		case 'p.tosell':
			$canEditSell = ($productstatic->type == Product::TYPE_SERVICE) ? !empty($user->rights->service->creer) : !empty($user->rights->produit->creer);
			if ($canEditSell) {
				print '<td class="center">' . ajax_object_onoff($productstatic, 'status', 'tosell', 'ProductStatusOnSell', 'ProductStatusNotOnSell') . '</td>';
			} else {
				$isSellOn = (int) $obj->tosell;
				$label = $langs->trans($isSellOn ? 'ProductStatusOnSell' : 'ProductStatusNotOnSell');
				$badgeClass = $isSellOn ? 'badge badge-status4 badge-status' : 'badge badge-status1 badge-status';
				print '<td class="center"><span class="' . $badgeClass . '" title="' . dol_escape_htmltag($label) . '">' . dol_escape_htmltag($label) . '</span></td>';
			}
			break;
		case 'kreap_hideproduct':
			$canEditHide = ($productstatic->type == Product::TYPE_SERVICE) ? !empty($user->rights->service->creer) : !empty($user->rights->produit->creer);
			$isHidden = (int) $obj->kreap_hideproduct;
			$titleOn = $langs->trans('kreap_hideproduct') . ' (ON)';
			$titleOff = $langs->trans('kreap_hideproduct') . ' (OFF)';
			$iconOn = img_picto($titleOn, 'switch_on', '', 0, 0, 0, '', 'font-status4');
			$iconOff = img_picto($titleOff, 'switch_off');
			if ($canEditHide) {
				if (!empty($conf->use_javascript_ajax)) {
					$ajaxUrl = DOL_URL_ROOT . '/custom/kreaproducts/ajax/toggle_hideproduct.php';
					print '<td class="center">';
					print '<script>
						$(function() {
							$("#set_kreap_hideproduct_' . ((int) $obj->rowid) . '").click(function() {
								$.get("' . dol_escape_js($ajaxUrl) . '", {
									action: "set",
									id: "' . ((int) $obj->rowid) . '",
									value: "1",
									token: "' . dol_escape_js(currentToken()) . '"
								}, function() {
									$("#set_kreap_hideproduct_' . ((int) $obj->rowid) . '").hide();
									$("#del_kreap_hideproduct_' . ((int) $obj->rowid) . '").show();
								});
							});
							$("#del_kreap_hideproduct_' . ((int) $obj->rowid) . '").click(function() {
								$.get("' . dol_escape_js($ajaxUrl) . '", {
									action: "set",
									id: "' . ((int) $obj->rowid) . '",
									value: "0",
									token: "' . dol_escape_js(currentToken()) . '"
								}, function() {
									$("#del_kreap_hideproduct_' . ((int) $obj->rowid) . '").hide();
									$("#set_kreap_hideproduct_' . ((int) $obj->rowid) . '").show();
								});
							});
						});
					</script>';
					print '<span id="set_kreap_hideproduct_' . ((int) $obj->rowid) . '" class="linkobject ' . ($isHidden ? 'hideobject' : '') . '">' . $iconOff . '</span>';
					print '<span id="del_kreap_hideproduct_' . ((int) $obj->rowid) . '" class="linkobject ' . ($isHidden ? '' : 'hideobject') . '">' . $iconOn . '</span>';
					print '</td>';
				} else {
					$setUrl = DOL_URL_ROOT . '/custom/kreaproducts/ajax/toggle_hideproduct.php?action=set&token=' . newToken() . '&id=' . ((int) $obj->rowid) . '&value=1&backtopage=' . urlencode($toggleHideBackUrl);
					$delUrl = DOL_URL_ROOT . '/custom/kreaproducts/ajax/toggle_hideproduct.php?action=set&token=' . newToken() . '&id=' . ((int) $obj->rowid) . '&value=0&backtopage=' . urlencode($toggleHideBackUrl);
					print '<td class="center">';
					print '<a id="set_kreap_hideproduct_' . ((int) $obj->rowid) . '" class="linkobject ' . ($isHidden ? 'hideobject' : '') . '" href="' . dol_escape_htmltag($setUrl) . '">' . $iconOff . '</a>';
					print '<a id="del_kreap_hideproduct_' . ((int) $obj->rowid) . '" class="linkobject ' . ($isHidden ? '' : 'hideobject') . '" href="' . dol_escape_htmltag($delUrl) . '">' . $iconOn . '</a>';
					print '</td>';
				}
			} else {
				print '<td class="center">' . ($isHidden ? $iconOn : $iconOff) . '</td>';
			}
			break;
			default:
				print '<td>&nbsp;</td>';
		}
	}
	print '</tr>';
	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="' . (count($arrayfields) + 1) . '"><span class="opacitymedium">' . $langs->trans('None') . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

// Sync the existing pagination controls (limit/page) with this list form
print '<script>
jQuery(function ($) {
	var $limitSelect = $(\'form[name="formlimit"] select[name="limit"], select[name="limit"][id="limit"]\');
	if (!$limitSelect.length) return;

	$limitSelect.off("change.kreaLimit").on("change.kreaLimit", function () {
		var v = $(this).val();
		var $form = $("#searchFormList");
		if (!$form.length) return;

		var $hiddenLimit = $form.find(\'input[name="limit"]\');
		if (!$hiddenLimit.length) {
			$hiddenLimit = $(\'<input type="hidden" name="limit">\').appendTo($form);
		}
		$hiddenLimit.val(v);

		var $page = $form.find(\'input[name="page"]\');
		if ($page.length) {
			$page.val(0);
		}
		var $pagePlusOne = $form.find(\'input[name="pageplusone"]\');
		if ($pagePlusOne.length) {
			$pagePlusOne.val(1);
		}

		$form.trigger("submit");
	});

	var $pageInput = $(\'input.pageplusone\');
	$pageInput.off("change.kreaPage keyup.kreaPage").on("change.kreaPage keyup.kreaPage", function (e) {
		if (e.type === "keyup" && e.key !== "Enter") return;
		var v = parseInt($(this).val(), 10);
		if (isNaN(v)) return;
		var $form = $("#searchFormList");
		if (!$form.length) return;
		var $pageHidden = $form.find(\'input[name="page"]\');
		var $pagePlusHidden = $form.find(\'input[name="pageplusone"]\');
		if ($pageHidden.length) {
			$pageHidden.val(Math.max(0, v - 1));
		}
		if ($pagePlusHidden.length) {
			$pagePlusHidden.val(Math.max(1, v));
		}
		$form.trigger("submit");
	});
});
</script>';

// Reset title bar spacing after filter block printed before title
llxFooter();
$db->close();
