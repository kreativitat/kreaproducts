<?php
/* Copyright (C) 2025 KreaProducts
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

// Load Dolibarr environment
require_once '../../main.inc.php';
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
$searchCategoryProductOperator = 1; // Default to OR between categories
$searchCategoryProductList = GETPOST('search_category_product_list', 'array');

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
	$searchCategoryProductOperator = 1;
}

$param = '&show_hidden=' . ((int) $show_hidden);
$param .= ($search_ref !== '' ? '&search_ref=' . urlencode($search_ref) : '');
$param .= ($search_label !== '' ? '&search_label=' . urlencode($search_label) : '');
$param .= ($search_tobuy !== '' ? '&search_tobuy=' . urlencode($search_tobuy) : '');
$param .= ($search_tosell !== '' ? '&search_tosell=' . urlencode($search_tosell) : '');
if (!empty($searchCategoryProductList)) {
	foreach ($searchCategoryProductList as $key => $valcat) {
		$param .= '&search_category_product_list[' . $key . ']=' . urlencode((string) $valcat);
	}
}

$object = new Product($db);
$form = new Form($db);
$formcategory = new FormCategory($db);

$title = $langs->trans('KreapProductSimpleList');
$picto = 'product';

// Fields
$arrayfields = array(
	'p.ref' => array('label' => $langs->trans('Ref'), 'checked' => 1, 'position' => 1),
	'p.label' => array('label' => $langs->trans('Label'), 'checked' => 1, 'position' => 2),
	'sell_price' => array('label' => $langs->trans('SellingPrice'), 'checked' => 1, 'position' => 3),
	'cost_price' => array('label' => $langs->trans('CostPrice'), 'checked' => 1, 'position' => 4),
	'p.entity' => array('label' => $langs->trans('Entity'), 'checked' => 1, 'position' => 5),
	'p.tobuy' => array('label' => $langs->trans('Status') . ' (' . $langs->trans('Buy') . ')', 'checked' => 1, 'position' => 6),
	'p.tosell' => array('label' => $langs->trans('Status') . ' (' . $langs->trans('Sell') . ')', 'checked' => 1, 'position' => 7),
);

// SQL build
$sql = "SELECT p.rowid, p.ref, p.label, p.entity, p.tobuy, p.tosell, p.fk_product_type, "
	. "COALESCE(p.price, 0) as sell_price, "
	. "COALESCE(p.cost_price, p.pmp, 0) as cost_price, "
	. "COALESCE(pe.kreap_hideproduct, 0) as kreap_hideproduct";
$sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields as pe ON p.rowid = pe.fk_object";
$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";

if (!$show_hidden) {
	$sql .= " AND COALESCE(pe.kreap_hideproduct, 0) = 0";
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

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);
$entityLabels = array();
$resEntity = $db->query("SELECT rowid, label FROM " . MAIN_DB_PREFIX . "entity");
if ($resEntity) {
	while ($o = $db->fetch_object($resEntity)) {
		$entityLabels[$o->rowid] = $o->label;
	}
	$db->free($resEntity);
}

$toggleUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(array(
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
), '', '&', PHP_QUERY_RFC3986);
$toggleLabel = $show_hidden ? $langs->trans('KreapHideHiddenProducts') : $langs->trans('KreapShowHiddenProducts');
$toggleIcon = 'fa fa-toggle-' . ($show_hidden ? 'on' : 'off');

// Filter on categories (header area before list)
$moreforfilter = '';
if (isModEnabled('category') && $user->hasRight('categorie', 'read')) {
	$moreforfilter .= $formcategory->getFilterBox(Categorie::TYPE_PRODUCT, $searchCategoryProductList, 'minwidth300', 1);
	// Remove OR/AND operator checkbox from filter box output
	$moreforfilter = preg_replace('#<input[^>]*search_category_product_operator[^>]*>(?:\\s*<label[^>]*>.*?</label>)?#', '', $moreforfilter);
}

llxHeader('', $title);

print load_fiche_titre($title, '', $picto);

$newcardbutton = '';
if ($user->rights->produit->creer) {
	$newcardbutton .= dolGetButtonTitle($langs->trans('NewProduct'), '', 'fa fa-plus-circle', DOL_URL_ROOT . '/product/card.php?action=create&type=0', '', 1, array());
	$newcardbutton .= dolGetButtonTitle($langs->trans('NewService'), '', 'fa fa-plus-circle', DOL_URL_ROOT . '/product/card.php?action=create&type=1', '', 1, array());
}

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
print '<input type="hidden" name="mode" value="' . dol_escape_htmltag($mode) . '">';
print '<input type="hidden" name="show_hidden" value="' . (int) $show_hidden . '">';



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
	print '<span class="valignmiddle" style="color: rgb(96, 96, 111); white-space: nowrap;">Mostrar ocultos</span>';
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
print '<td class="liste_titre left"><input class="flat width100" type="text" name="search_label" value="' . dol_escape_htmltag($search_label) . '"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre maxwidthonsmartphone" align="center">&nbsp;</td>';
$selectSell = array('-1' => '&nbsp;', '0' => $langs->trans('Status') . ' (OFF)', '1' => $langs->trans('Status') . ' (ON)');
print '<td class="liste_titre center parentonrightofpage">' . $form->selectarray('search_tosell', $selectSell, $search_tosell, 0, 0, 0, '', 0, 0, 0, '', '', 1) . '</td>';
$selectBuy = array('-1' => '&nbsp;', '0' => $langs->trans('Status') . ' (OFF)', '1' => $langs->trans('Status') . ' (ON)');
print '<td class="liste_titre center parentonrightofpage">' . $form->selectarray('search_tobuy', $selectBuy, $search_tobuy, 0, 0, 0, '', 0, 0, 0, '', '', 1) . '</td>';
print '</tr>';

// Header row
print '<tr class="liste_titre">';
print '<th class="wrapcolumntitle center maxwidthsearch liste_titre"></th>';
foreach ($arrayfields as $key => $val) {
	if (!empty($val['checked'])) {
		$align = '';
		if ($key === 'cost_price' || $key === 'sell_price') {
			$align = 'right ';
		}
		if ($key === 'p.entity' || $key === 'p.tobuy' || $key === 'p.tosell') {
			$align = 'center ';
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
				if (empty($productstatic->price)) {
					$productstatic->fetch($obj->rowid);
				}
				print '<td class="right">' . price($productstatic->price) . '</td>';
				break;
			case 'cost_price':
				print '<td class="right">' . price($obj->cost_price) . '</td>';
				break;
			case 'p.entity':
				$entityLabel = isset($entityLabels[$obj->entity]) ? $entityLabels[$obj->entity] : $obj->entity;
				print '<td class="center"><div class="refidno multicompany-entity-card-container"><span class="fa fa-globe"></span><span class="multiselect-selected-title-text">' . dol_escape_htmltag($entityLabel) . '</span></div></td>';
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

// Reset title bar spacing after filter block printed before title
llxFooter();
$db->close();
