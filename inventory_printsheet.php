<?php
/* Copyright (C) 2025
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

// Load Dolibarr environment
if (!defined('DOL_DOCUMENT_ROOT')) {
	require '../../main.inc.php';
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

// Load translation files required by the page
$langs->loadLangs(array('stocks', 'products', 'kreaproducts@kreaproducts'));

// Security check
if ($user->socid > 0) {	// Protection if external user
	accessforbidden();
}
$permissiontoread = $user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read');
if (!$permissiontoread) {
	accessforbidden();
}

function kreaproducts_inventory_print_clean_label($label)
{
	$label = trim((string) $label);
	if ($label === '') {
		return '';
	}
	$label = preg_replace('/\s*\([^)]*\)/', '', $label);
	return dol_strtoupper(trim($label));
}

function kreaproducts_inventory_print_fetch_categories($db, $rootCategoryId)
{
	$categories = array();
	$sql = "SELECT rowid, label";
	$sql .= " FROM ".MAIN_DB_PREFIX."categorie";
	$sql .= " WHERE type = 0";
	$sql .= " AND entity IN (".getEntity('category').")";
	if ($rootCategoryId > 0) {
		$sql .= " AND fk_parent = ".((int) $rootCategoryId);
	} else {
		$sql .= " AND fk_parent = 0";
	}
	$sql .= " ORDER BY label";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$categories[] = $obj;
		}
	} else {
		dol_syslog(__METHOD__.' Error fetching categories: '.$db->lasterror(), LOG_ERR);
	}

	return $categories;
}

function kreaproducts_inventory_print_get_descendant_ids($db, $categoryId)
{
	$categoryId = (int) $categoryId;
	if ($categoryId <= 0) {
		return array();
	}
	$cat = new Categorie($db);
	$tree = $cat->get_full_arbo(Categorie::TYPE_PRODUCT, $categoryId, 1);
	if (!is_array($tree)) {
		return array();
	}
	$ids = array();
	foreach ($tree as $info) {
		$catId = (int) ($info['id'] ?? 0);
		if ($catId > 0) {
			$ids[] = $catId;
		}
	}
	$ids = array_values(array_unique($ids));
	sort($ids, SORT_NUMERIC);
	return $ids;
}

function kreaproducts_inventory_print_fetch_products($db, array $categoryIds)
{
	$products = array();
	if (empty($categoryIds)) {
		return $products;
	}
	$categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
	if (empty($categoryIds)) {
		return $products;
	}
	$sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.fk_unit, u.unit_type, u.label as unit_label, u.short_label as unit_short";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " JOIN ".MAIN_DB_PREFIX."categorie_product as cp ON cp.fk_product = p.rowid";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_units as u ON u.rowid = p.fk_unit";
	$sql .= " WHERE cp.fk_categorie IN (".$db->sanitize(implode(',', $categoryIds)).")";
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$sql .= " AND p.fk_product_type = 0";
	$sql .= " ORDER BY (CASE WHEN p.label IS NULL OR p.label = '' THEN p.ref ELSE p.label END), p.ref";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$products[] = $obj;
		}
	} else {
		dol_syslog(__METHOD__.' Error fetching products: '.$db->lasterror(), LOG_ERR);
	}

	return $products;
}

function kreaproducts_inventory_print_unit_label($langs, $unitType)
{
	if ($unitType === 'weight') {
		return $langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_UNIT_WEIGHT');
	}
	if ($unitType === 'volume') {
		return $langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_UNIT_VOLUME');
	}
	return $langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_UNIT_COUNT');
}

function kreaproducts_inventory_print_wrap_header($label)
{
	$label = trim((string) $label);
	if ($label === '') {
		return '';
	}
	if (strpos($label, '(') !== false) {
		$label = preg_replace('/\s*(\()/', "\n$1", $label, 1);
	}
	if (dol_strlen($label) > 26) {
		$label = preg_replace('/\s+(\S+)$/', "\n$1", $label);
	}
	return $label;
}

function kreaproducts_inventory_print_page_header($pdf, $outputlangs, $storeLabel, $dateLabel, array $cols, $font, $fontSize)
{
	$startX = $pdf->GetX();
	$startY = $pdf->GetY();
	$totalLeft = $cols['code'] + $cols['label'];
	$totalRight = $cols['count'] + $cols['unit'];
	$title = $outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_TITLE'));
	$storeLabel = $outputlangs->convToOutputCharset($storeLabel);
	$dateLabel = $outputlangs->convToOutputCharset($dateLabel);

	$pdf->SetFont($font, 'B', $fontSize + 6);
	$pdf->MultiCell($totalLeft, 12, $title, 1, 'L', false, 0, $startX, $startY, true, 0, false, true, 12, 'M');

	$pdf->SetFont($font, '', $fontSize);
	$pdf->MultiCell($totalRight, 6, $outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_STORE')).': '.$storeLabel, 1, 'L', false, 0, $startX + $totalLeft, $startY);
	$pdf->MultiCell($totalRight, 6, $outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_DATE')).': '.$dateLabel, 1, 'L', false, 0, $startX + $totalLeft, $startY + 6);

	$pdf->SetXY($startX, $startY + 12);
	$pdf->SetFont($font, '', max(7, $fontSize - 2));
	$headerHeight = 10;
	$labelCode = kreaproducts_inventory_print_wrap_header($outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_CODE')));
	$labelProduct = $outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_PRODUCT'));
	$labelCounted = kreaproducts_inventory_print_wrap_header($outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_COUNTED')));
	$labelUnit = kreaproducts_inventory_print_wrap_header($outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_PRINT_UNIT')));

	$pdf->MultiCell($cols['code'], $headerHeight, $labelCode, 1, 'C', false, 0, $startX, $startY + 12, true, 0, false, true, $headerHeight, 'M');
	$pdf->MultiCell($cols['label'], $headerHeight, $labelProduct, 1, 'C', false, 0, $startX + $cols['code'], $startY + 12, true, 0, false, true, $headerHeight, 'M');
	$pdf->MultiCell($cols['count'], $headerHeight, $labelCounted, 1, 'C', false, 0, $startX + $cols['code'] + $cols['label'], $startY + 12, true, 0, false, true, $headerHeight, 'M');
	$pdf->MultiCell($cols['unit'], $headerHeight, $labelUnit, 1, 'C', false, 1, $startX + $cols['code'] + $cols['label'] + $cols['count'], $startY + 12, true, 0, false, true, $headerHeight, 'M');
}

$rootCategoryId = getDolGlobalInt('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT');
$categories = kreaproducts_inventory_print_fetch_categories($db, $rootCategoryId);

$warehouseId = GETPOSTINT('warehouse_id');
if ($warehouseId <= 0) {
	$warehouseId = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
}
$storeLabel = '';
if ($warehouseId > 0) {
	$warehouse = new Entrepot($db);
	if ($warehouse->fetch($warehouseId) > 0) {
		$storeLabel = $warehouse->ref;
	}
}
$storeLabel = $storeLabel !== '' ? $storeLabel : '-';

$dateLabel = '';

// PDF generation
$outputlangs = $langs;
if (!is_object($outputlangs)) {
	$outputlangs = new Translate('', $conf);
	$outputlangs->setDefaultLang($langs->getDefaultLang());
}
$outputlangs->charset_output = getDolGlobalString('MAIN_USE_FPDF') ? 'ISO-8859-1' : 'UTF-8';


$pdf = pdf_getInstance();
$defaultFont = pdf_getPDFFont($outputlangs);
$defaultFontSize = pdf_getPDFFontSize($outputlangs);

if (class_exists('TCPDF')) {
	$pdf->setPrintHeader(false);
	$pdf->setPrintFooter(false);
	$defaultFont = 'dejavusans';
}
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->SetFont($defaultFont, '', $defaultFontSize);
$pdf->AddPage();

$cols = array(
	'code' => 25,
	'label' => 95,
	'count' => 35,
	'unit' => 35,
);
$totalWidth = $cols['code'] + $cols['label'] + $cols['count'] + $cols['unit'];
$rowHeight = 6;
$pageHeight = method_exists($pdf, 'getPageHeight') ? $pdf->getPageHeight() : 297;
$marginBottom = method_exists($pdf, 'getBreakMargin') ? $pdf->getBreakMargin() : 10;

kreaproducts_inventory_print_page_header($pdf, $outputlangs, $storeLabel, $dateLabel, $cols, $defaultFont, $defaultFontSize);

foreach ($categories as $category) {
	$catLabel = kreaproducts_inventory_print_clean_label($category->label);
	$catLabel = $outputlangs->convToOutputCharset($catLabel);
	$categoryIds = kreaproducts_inventory_print_get_descendant_ids($db, $category->rowid);
	if (empty($categoryIds)) {
		$categoryIds = array((int) $category->rowid);
	}
	$products = kreaproducts_inventory_print_fetch_products($db, $categoryIds);

	if ($pdf->GetY() + $rowHeight > ($pageHeight - $marginBottom)) {
		$pdf->AddPage();
		kreaproducts_inventory_print_page_header($pdf, $outputlangs, $storeLabel, $dateLabel, $cols, $defaultFont, $defaultFontSize);
	}

	$pdf->SetFont($defaultFont, 'B', $defaultFontSize);
	$pdf->SetFillColor(235, 235, 235);
	$pdf->Cell($totalWidth, $rowHeight, $catLabel, 1, 1, 'L', true);

	$pdf->SetFont($defaultFont, '', $defaultFontSize - 1);
	foreach ($products as $product) {
		if ($pdf->GetY() + $rowHeight > ($pageHeight - $marginBottom)) {
			$pdf->AddPage();
			kreaproducts_inventory_print_page_header($pdf, $outputlangs, $storeLabel, $dateLabel, $cols, $defaultFont, $defaultFontSize);
		}
	$ref = trim((string) $product->ref);
	$label = trim((string) $product->label);
	if ($label === '') {
		$label = $ref;
	}
	$ref = $outputlangs->convToOutputCharset($ref);
	$label = $outputlangs->convToOutputCharset($label);
	$unitLabel = $outputlangs->convToOutputCharset(kreaproducts_inventory_print_unit_label($outputlangs, $product->unit_type));

		$pdf->Cell($cols['code'], $rowHeight, dol_trunc($ref, 20), 1, 0, 'L');
		$pdf->Cell($cols['label'], $rowHeight, dol_trunc($label, 60), 1, 0, 'L');
		$pdf->Cell($cols['count'], $rowHeight, '', 1, 0, 'C');
		$pdf->Cell($cols['unit'], $rowHeight, $unitLabel, 1, 1, 'L');
	}
}

$filename = 'inventario_' . dol_print_date(dol_now(), '%Y%m%d') . '.pdf';
$pdf->Output($filename, 'I');
exit;
