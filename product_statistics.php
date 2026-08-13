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
 * \file       htdocs/custom/kreaproducts/product_statistics.php
 * \ingroup    kreaproducts
 * \brief      Product and service statistics dashboard.
 */

// Load Dolibarr environment (module in htdocs/ or htdocs/custom/).
$res = defined('DOL_DOCUMENT_ROOT') ? 1 : 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) {
	$res = @include __DIR__.'/../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = @include __DIR__.'/../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../master.inc.php')) {
	$res = @include __DIR__.'/../master.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../master.inc.php')) {
	$res = @include __DIR__.'/../../master.inc.php';
}
if (!$res) {
	die('Failed to include main.inc.php');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/kreaproducts/class/KreaProductsProductStatistics.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'products', 'stocks', 'suppliers', 'margins', 'kreaproducts@kreaproducts'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$period = GETPOST('period', 'aZ09');
$dateStartInput = GETPOST('date_start', 'alpha');
$dateEndInput = GETPOST('date_end', 'alpha');
$allowedPeriods = array('today', 'yesterday', '7d', 'currentmonth', 'lastmonth', '3m', '6m', '12m', 'currentyear', 'previousyear', '24m', 'custom');
if (!in_array($period, $allowedPeriods, true)) {
	$period = '12m';
}

$object = new Product($db);
$result = $object->fetch($id, $ref);
if ($result <= 0) {
	accessforbidden();
}
$id = (int) $object->id;

$fieldtype = !empty($ref) ? 'ref' : 'rowid';
restrictedArea($user, 'produit|service', !empty($ref) ? $ref : $id, 'product&product', '', '', $fieldtype);

$hookmanager->initHooks(array('kreaproductsproductstatistics', 'globalcard'));
$form = new Form($db);

$now = dol_now();
$today = dol_getdate($now);
$todayStart = dol_mktime(0, 0, 0, $today['mon'], $today['mday'], $today['year']);
$periodEndExclusive = dol_time_plus_duree($todayStart, 1, 'd');
$monthStart = dol_mktime(0, 0, 0, $today['mon'], 1, $today['year']);
$yearStart = dol_mktime(0, 0, 0, 1, 1, $today['year']);
$rangeError = false;

if ($period === 'today') {
	$periodStart = $todayStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = dol_time_plus_duree($comparisonEndExclusive, -1, 'd');
} elseif ($period === 'yesterday') {
	$periodStart = dol_time_plus_duree($todayStart, -1, 'd');
	$periodEndExclusive = $todayStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = dol_time_plus_duree($comparisonEndExclusive, -1, 'd');
} elseif ($period === '7d') {
	$periodStart = dol_time_plus_duree($todayStart, -6, 'd');
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = dol_time_plus_duree($comparisonEndExclusive, -7, 'd');
} elseif ($period === 'currentmonth') {
	$periodStart = $monthStart;
	$periodDuration = $periodEndExclusive - $periodStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = $comparisonEndExclusive - $periodDuration;
} elseif ($period === 'lastmonth') {
	$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 1, 1, $today['year']);
	$periodEndExclusive = $monthStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = dol_mktime(0, 0, 0, $today['mon'] - 2, 1, $today['year']);
} elseif ($period === '3m') {
	$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 2, 1, $today['year']);
	$periodDuration = $periodEndExclusive - $periodStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = $comparisonEndExclusive - $periodDuration;
} elseif ($period === '6m') {
	$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 5, 1, $today['year']);
	$periodDuration = $periodEndExclusive - $periodStart;
	$comparisonEndExclusive = $periodStart;
	$comparisonStart = $comparisonEndExclusive - $periodDuration;
} elseif ($period === 'currentyear') {
	$periodStart = $yearStart;
	$comparisonStart = dol_time_plus_duree($periodStart, -1, 'y');
	$comparisonEndExclusive = dol_time_plus_duree($periodEndExclusive, -1, 'y');
} elseif ($period === 'previousyear') {
	$periodStart = dol_time_plus_duree($yearStart, -1, 'y');
	$periodEndExclusive = $yearStart;
	$comparisonStart = dol_time_plus_duree($periodStart, -1, 'y');
	$comparisonEndExclusive = $periodStart;
} elseif ($period === '24m') {
	$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 23, 1, $today['year']);
	$comparisonStart = dol_time_plus_duree($periodStart, -24, 'm');
	$comparisonEndExclusive = $periodStart;
} elseif ($period === 'custom') {
	$startParts = array_map('intval', explode('-', $dateStartInput));
	$endParts = array_map('intval', explode('-', $dateEndInput));
	$validStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStartInput) && count($startParts) === 3;
	$validEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEndInput) && count($endParts) === 3;
	$customStart = $validStart ? dol_mktime(0, 0, 0, $startParts[1], $startParts[2], $startParts[0]) : 0;
	$customEnd = $validEnd ? dol_mktime(0, 0, 0, $endParts[1], $endParts[2], $endParts[0]) : 0;
	$validStart = $validStart && dol_print_date($customStart, '%Y-%m-%d') === $dateStartInput;
	$validEnd = $validEnd && dol_print_date($customEnd, '%Y-%m-%d') === $dateEndInput;
	$maximumRange = 366 * 5 * 86400;
	if (!$validStart || !$validEnd || $customStart > $customEnd || ($customEnd - $customStart) > $maximumRange) {
		$rangeError = true;
		$period = '12m';
		$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 11, 1, $today['year']);
		$comparisonStart = dol_time_plus_duree($periodStart, -12, 'm');
		$comparisonEndExclusive = $periodStart;
	} else {
		$periodStart = $customStart;
		$periodEndExclusive = dol_time_plus_duree($customEnd, 1, 'd');
		$periodDuration = $periodEndExclusive - $periodStart;
		$comparisonEndExclusive = $periodStart;
		$comparisonStart = $comparisonEndExclusive - $periodDuration;
	}
} else {
	$periodStart = dol_mktime(0, 0, 0, $today['mon'] - 11, 1, $today['year']);
	$comparisonStart = dol_time_plus_duree($periodStart, -12, 'm');
	$comparisonEndExclusive = $periodStart;
}

$dateStartInput = dol_print_date($periodStart, '%Y-%m-%d');
$dateEndInput = dol_print_date(dol_time_plus_duree($periodEndExclusive, -1, 'd'), '%Y-%m-%d');

$canReadSales = isModEnabled('invoice') && $user->hasRight('facture', 'lire');
$canReadPurchases = isModEnabled('supplier_invoice') && $user->hasRight('fournisseur', 'facture', 'lire');
$canReadMargin = $canReadSales && isModEnabled('margin') && $user->hasRight('margins', 'liretous');
$canReadStock = $object->isProduct() && isModEnabled('stock') && $user->hasRight('stock', 'lire');
$canReadOperationalFlows = $canReadStock && $object->isStockManaged();
$canReadMrp = isModEnabled('mrp') && $user->hasRight('mrp', 'read');

$priceRightsModule = $object->isService() ? 'service' : 'product';
$priceRightsElement = $object->isService() ? 'service_advance' : 'product_advance';
$canReadSupplierPrice = getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
	? $user->hasRight($priceRightsModule, $priceRightsElement, 'read_supplier_prices')
	: $user->hasRight($priceRightsModule, 'lire');

$statistics = array(
	'sales' => array('current' => array(), 'previous' => array(), 'monthly' => array(), 'top' => array(), 'recent' => array()),
	'purchases' => array('current' => array(), 'previous' => array(), 'monthly' => array(), 'top' => array(), 'recent' => array()),
	'operations' => array('current' => array(), 'previous' => array(), 'monthly' => array(), 'profile' => array(), 'recent' => array()),
);
$statisticsError = false;
$statisticsService = new KreaProductsProductStatistics($db);
$loadResult = $statisticsService->load(
	$id,
	$periodStart,
	$periodEndExclusive,
	$comparisonStart,
	$comparisonEndExclusive,
	$user,
	$canReadSales,
	$canReadPurchases,
	$canReadMargin,
	$canReadOperationalFlows
);
if ($loadResult === -1) {
	$statisticsError = true;
	setEventMessages($langs->trans('KREAPRODUCTS_STATS_LOAD_ERROR'), null, 'errors');
} else {
	$statistics = $loadResult;
}

$stock = array('qty' => 0.0, 'pmp' => 0.0, 'value' => 0.0, 'warehouses' => 0);
if ($canReadStock) {
	$stockResult = $object->load_stock('nobatch,novirtual');
	if ($stockResult >= 0) {
		$stock['qty'] = price2num($object->stock_reel, 'MS');
		$stock['pmp'] = $canReadSupplierPrice ? price2num($object->pmp, 'MU') : 0.0;
		$stock['value'] = $canReadSupplierPrice ? price2num($stock['qty'] * $stock['pmp'], 'MT') : 0.0;
		$stock['warehouses'] = is_array($object->stock_warehouse) ? count($object->stock_warehouse) : 0;
	} else {
		dol_syslog('KreaProducts product statistics failed to load stock for product '.$id.': '.$object->error, LOG_ERR);
		setEventMessages($langs->trans('KREAPRODUCTS_STATS_STOCK_LOAD_ERROR'), null, 'warnings');
	}
}

/**
 * Format a currency amount.
 *
 * @param float $value Amount
 * @return string
 */
function kreaproducts_stats_money($value)
{
	global $conf, $langs;
	return price(price2num($value, 'MT'), 0, $langs, 1, -1, -1, $conf->currency);
}

/**
 * Format a product quantity.
 *
 * @param float $value Quantity
 * @return string
 */
function kreaproducts_stats_quantity($value)
{
	global $langs;
	return price(price2num($value, 'MS'), 0, $langs, 1, -1, 2);
}

/**
 * Render a comparison badge.
 *
 * @param float $current             Current value
 * @param float $previous            Previous value
 * @param bool|null $higherIsPositive A positive change is beneficial; null renders a neutral comparison
 * @return string
 */
function kreaproducts_stats_delta($current, $previous, $higherIsPositive = true)
{
	global $langs;
	$current = (float) $current;
	$previous = (float) $previous;
	if (abs($previous) < 0.0000001) {
		if (abs($current) < 0.0000001) {
			return '<span class="kps-stat-delta kps-stat-neutral">'.$langs->trans('KREAPRODUCTS_STATS_NO_CHANGE').'</span>';
		}
		return '<span class="kps-stat-delta kps-stat-positive">'.$langs->trans('KREAPRODUCTS_STATS_NEW_ACTIVITY').'</span>';
	}
	$change = (($current - $previous) / abs($previous)) * 100;
	if ($higherIsPositive === null) {
		$arrow = $change >= 0 ? '&#8593;' : '&#8595;';
		return '<span class="kps-stat-delta kps-stat-neutral">'.$arrow.' '.price(abs($change), 0, $langs, 1, 0, 1).'%</span>';
	}
	$isPositive = $change >= 0 ? $higherIsPositive : !$higherIsPositive;
	$class = $isPositive ? 'kps-stat-positive' : 'kps-stat-negative';
	$arrow = $change >= 0 ? '&#8593;' : '&#8595;';
	return '<span class="kps-stat-delta '.$class.'">'.$arrow.' '.price(abs($change), 0, $langs, 1, 0, 1).'%</span>';
}

/**
 * Render one KPI card.
 *
 * @param string $label             Label
 * @param string $value             Formatted value
 * @param float  $current           Current raw value
 * @param float  $previous          Previous raw value
 * @param bool|null $higherIsPositive Positive change is beneficial; null renders a neutral comparison
 * @param string $subtitle          Optional subtitle
 * @return void
 */
function kreaproducts_stats_kpi($label, $value, $current, $previous, $higherIsPositive = true, $subtitle = '')
{
	global $langs;
	print '<div class="kps-stat-card">';
	print '<div class="kps-stat-card-label">'.dol_escape_htmltag($label).'</div>';
	print '<div class="kps-stat-card-value">'.$value.'</div>';
	print '<div class="kps-stat-card-meta">'.kreaproducts_stats_delta($current, $previous, $higherIsPositive);
	print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_STATS_VS_PREVIOUS')).'</span>';
	if ($subtitle !== '') {
		print '<span class="opacitymedium">'.dol_escape_htmltag($subtitle).'</span>';
	}
	print '</div></div>';
}

/**
 * Render a point-in-time or structural KPI without a period comparison.
 *
 * @param string $label Label
 * @param string $value Formatted value
 * @param string $subtitle Optional subtitle
 * @return void
 */
function kreaproducts_stats_value_card($label, $value, $subtitle = '')
{
	print '<div class="kps-stat-card">';
	print '<div class="kps-stat-card-label">'.dol_escape_htmltag($label).'</div>';
	print '<div class="kps-stat-card-value">'.$value.'</div>';
	if ($subtitle !== '') {
		print '<div class="kps-stat-card-meta"><span class="opacitymedium">'.dol_escape_htmltag($subtitle).'</span></div>';
	}
	print '</div>';
}

/**
 * Build an inline, responsive monthly value chart.
 *
 * @param array<int,string>               $monthKeys Month keys
 * @param array<int,array<string,mixed>>  $series    Chart series
 * @param string                          $valueType money or quantity
 * @return string
 */
function kreaproducts_stats_chart($monthKeys, $series, $valueType = 'money')
{
	global $langs;
	$series = array_values(array_filter($series, static function ($item) {
		return !empty($item['enabled']);
	}));
	if (empty($series) || empty($monthKeys)) {
		return '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA').'</div>';
	}

	$allValues = array(0.0);
	$drawSeries = $series;
	foreach ($drawSeries as $drawIndex => &$drawItem) {
		$drawItem['_draw_index'] = $drawIndex;
	}
	unset($drawItem);
	usort($drawSeries, static function ($left, $right) {
		$leftLayer = isset($left['layer']) ? (int) $left['layer'] : 10;
		$rightLayer = isset($right['layer']) ? (int) $right['layer'] : 10;
		if ($leftLayer === $rightLayer) {
			return (int) $left['_draw_index'] <=> (int) $right['_draw_index'];
		}
		return $leftLayer <=> $rightLayer;
	});

	foreach ($drawSeries as $item) {
		foreach ($monthKeys as $monthKey) {
			$allValues[] = (float) ($item['values'][$monthKey] ?? 0);
		}
	}
	$minimum = min($allValues);
	$maximum = max($allValues);
	if (abs($maximum - $minimum) < 0.0000001) {
		if (abs($maximum) < 0.0000001) {
			return '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
		}
		$minimum = min(0, $minimum);
		$maximum = max(1, $maximum);
	}

	$width = 1000;
	$height = 330;
	$left = 76;
	$right = 22;
	$top = 32;
	$bottom = 58;
	$plotWidth = $width - $left - $right;
	$plotHeight = $height - $top - $bottom;
	$valueRange = $maximum - $minimum;
	$count = count($monthKeys);
	$xStep = $count > 1 ? $plotWidth / ($count - 1) : 0;
	$labelStep = max(1, (int) ceil($count / 8));

	$html = '<div class="kps-stat-chart-wrap"><svg class="kps-stat-chart" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_STATS_MONTHLY_TREND')).'">';
	for ($grid = 0; $grid <= 4; $grid++) {
		$ratio = $grid / 4;
		$y = $top + ($plotHeight * $ratio);
		$value = $maximum - ($valueRange * $ratio);
		$html .= '<line class="kps-stat-grid" x1="'.$left.'" y1="'.$y.'" x2="'.($width - $right).'" y2="'.$y.'" />';
		$formattedValue = $valueType === 'quantity' ? kreaproducts_stats_quantity($value) : kreaproducts_stats_money($value);
		$html .= '<text class="kps-stat-axis-value" x="'.($left - 10).'" y="'.($y + 4).'" text-anchor="end">'.dol_escape_htmltag($formattedValue).'</text>';
	}

	$zeroY = $top + (($maximum - 0) / $valueRange * $plotHeight);
	$html .= '<line class="kps-stat-zero" x1="'.$left.'" y1="'.$zeroY.'" x2="'.($width - $right).'" y2="'.$zeroY.'" />';

	foreach ($monthKeys as $index => $monthKey) {
		if (($index % $labelStep) !== 0 && $index !== $count - 1) {
			continue;
		}
		$x = $left + ($index * $xStep);
		$timestamp = dol_stringtotime($monthKey.'-01');
		$html .= '<text class="kps-stat-axis-label" x="'.$x.'" y="'.($height - 24).'" text-anchor="middle">'.dol_escape_htmltag(dol_print_date($timestamp, '%b %Y')).'</text>';
	}

	foreach ($drawSeries as $item) {
		$points = array();
		$circles = '';
		$strokeWidth = isset($item['stroke_width']) ? max(1.0, min(8.0, (float) $item['stroke_width'])) : 3.0;
		$pointRadius = isset($item['point_radius']) ? max(1.5, min(7.0, (float) $item['point_radius'])) : 3.5;
		$dashAttribute = !empty($item['dasharray']) ? ' stroke-dasharray="'.dol_escape_htmltag($item['dasharray']).'"' : '';
		foreach ($monthKeys as $index => $monthKey) {
			$value = (float) ($item['values'][$monthKey] ?? 0);
			$x = $left + ($index * $xStep);
			$y = $top + (($maximum - $value) / $valueRange * $plotHeight);
			$points[] = round($x, 2).','.round($y, 2);
			$circles .= '<circle cx="'.round($x, 2).'" cy="'.round($y, 2).'" r="'.$pointRadius.'" fill="'.dol_escape_htmltag($item['color']).'">';
			$formattedValue = $valueType === 'quantity' ? kreaproducts_stats_quantity($value) : kreaproducts_stats_money($value);
			$circles .= '<title>'.dol_escape_htmltag($item['label'].' · '.$monthKey.': '.$formattedValue).'</title></circle>';
		}
		$html .= '<polyline class="kps-stat-series" fill="none" stroke="'.dol_escape_htmltag($item['color']).'" stroke-width="'.$strokeWidth.'"'.$dashAttribute.' points="'.implode(' ', $points).'" />'.$circles;
	}
	$html .= '</svg><div class="kps-stat-legend">';
	foreach ($series as $item) {
		$html .= '<span><i style="background:'.dol_escape_htmltag($item['color']).';"></i>'.dol_escape_htmltag($item['label']).'</span>';
	}
	$html .= '</div></div>';
	return $html;
}

$monthKeys = array();
$cursor = dol_mktime(0, 0, 0, (int) dol_print_date($periodStart, '%m'), 1, (int) dol_print_date($periodStart, '%Y'));
while ($cursor < $periodEndExclusive && count($monthKeys) < 61) {
	$monthKeys[] = dol_print_date($cursor, '%Y-%m');
	$cursor = dol_time_plus_duree($cursor, 1, 'm');
}

$salesCurrent = $statistics['sales']['current'];
$salesPrevious = $statistics['sales']['previous'];
$purchaseCurrent = $statistics['purchases']['current'];
$purchasePrevious = $statistics['purchases']['previous'];
$operationsCurrent = $statistics['operations']['current'];
$operationsPrevious = $statistics['operations']['previous'];
$operationalProfile = $statistics['operations']['profile'];

$hasSalesActivity = !empty($salesCurrent['documents']) || !empty($salesPrevious['documents']) || abs((float) ($salesCurrent['qty'] ?? 0)) > 0.0000001 || abs((float) ($salesPrevious['qty'] ?? 0)) > 0.0000001;
$hasPurchaseActivity = !empty($purchaseCurrent['documents']) || !empty($purchasePrevious['documents']) || abs((float) ($purchaseCurrent['qty'] ?? 0)) > 0.0000001 || abs((float) ($purchasePrevious['qty'] ?? 0)) > 0.0000001;
$isManufactured = !empty($operationalProfile['manufactured']);
$isIngredient = !empty($operationalProfile['relation_count']) || (!empty($operationalProfile['ingredient']) && empty($object->tosell));
$isInternalProduct = $object->isProduct() && empty($object->tosell) && empty($object->tobuy);
$showOperations = $canReadOperationalFlows && ($isManufactured || $isIngredient || $isInternalProduct || !empty($operationsCurrent['manufacturing_usage']) || !empty($operationsPrevious['manufacturing_usage']));
$showSales = $canReadSales && (!empty($object->tosell) || $hasSalesActivity);
$showPurchases = $canReadPurchases && (!empty($object->tobuy) || $hasPurchaseActivity);
$showMargin = $canReadMargin && $showSales;

$salesMonthly = array();
$marginMonthly = array();
$purchaseMonthly = array();
$producedMonthly = array();
$customerUsageMonthly = array();
$manufacturingUsageMonthly = array();
$supplierReceiptMonthly = array();
foreach ($monthKeys as $monthKey) {
	$salesMonthly[$monthKey] = (float) ($statistics['sales']['monthly'][$monthKey]['amount'] ?? 0);
	$marginMonthly[$monthKey] = (float) ($statistics['sales']['monthly'][$monthKey]['margin'] ?? 0);
	$purchaseMonthly[$monthKey] = (float) ($statistics['purchases']['monthly'][$monthKey]['amount'] ?? 0);
	$producedMonthly[$monthKey] = (float) ($statistics['operations']['monthly'][$monthKey]['produced'] ?? 0);
	$customerUsageMonthly[$monthKey] = (float) ($statistics['operations']['monthly'][$monthKey]['customer_usage'] ?? 0);
	$manufacturingUsageMonthly[$monthKey] = (float) ($statistics['operations']['monthly'][$monthKey]['manufacturing_usage'] ?? 0);
	$supplierReceiptMonthly[$monthKey] = (float) ($statistics['operations']['monthly'][$monthKey]['supplier_receipts'] ?? 0);
}

$title = $langs->trans('Product').' '.dol_trunc($object->label, 16).' - '.$langs->trans('Statistics');
if ($object->isService()) {
	$title = $langs->trans('Service').' '.dol_trunc($object->label, 16).' - '.$langs->trans('Statistics');
}
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-kreaproducts page-kreaproducts-product-statistics');

print '<style>';
print '.kps-stat-toolbar{display:flex;gap:12px;align-items:end;flex-wrap:wrap;padding:14px 16px;margin:14px 0 18px;background:var(--colorbacklineimpair,#f7f7f7);border:1px solid var(--colortableborder,#ddd);border-radius:6px}.kps-stat-filter{display:flex;flex-direction:column;gap:5px}.kps-stat-filter label{font-size:.88em;font-weight:600}.kps-stat-custom-dates{display:flex;gap:10px;flex-wrap:wrap}.kps-stat-period-label{margin-left:auto;align-self:center;font-weight:600}.kps-stat-profile{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;margin:0 0 14px;background:#eef7f2;border:1px solid #b7dbc5;border-radius:7px}.kps-stat-profile-badge{flex:0 0 auto;padding:4px 9px;border-radius:999px;background:#177245;color:#fff;font-size:.82em;font-weight:700}.kps-stat-profile-text{line-height:1.45}.kps-stat-grid-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0 0 22px}.kps-stat-card{min-height:112px;padding:16px;background:var(--colorbackcard,#fff);border:1px solid var(--colortableborder,#ddd);border-radius:7px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.kps-stat-card-label{font-size:.88em;font-weight:600;color:var(--colortextmuted,#666)}.kps-stat-card-value{font-size:1.65em;line-height:1.3;font-weight:700;margin:7px 0 9px}.kps-stat-card-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.82em}.kps-stat-delta{display:inline-flex;padding:2px 7px;border-radius:999px;font-weight:700}.kps-stat-positive{color:#177245;background:#e8f5ed}.kps-stat-negative{color:#b42318;background:#fcebea}.kps-stat-neutral{color:var(--colortextmuted,#666);background:var(--colorbacklineimpair,#eee)}.kps-stat-section{margin:0 0 24px}.kps-stat-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}.kps-stat-section-title h3{margin:0;font-size:1.1em}.kps-stat-chart-wrap{background:var(--colorbackcard,#fff);border:1px solid var(--colortableborder,#ddd);border-radius:7px;padding:12px;overflow:hidden}.kps-stat-chart{display:block;width:100%;min-width:620px;max-height:360px}.kps-stat-grid{stroke:var(--colortableborder,#ddd);stroke-width:1}.kps-stat-zero{stroke:var(--colortextmuted,#777);stroke-width:1.2}.kps-stat-series{stroke-width:3;stroke-linejoin:round;stroke-linecap:round}.kps-stat-axis-value,.kps-stat-axis-label{fill:var(--colortextmuted,#666);font-size:12px}.kps-stat-legend{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;margin-top:6px;font-size:.9em}.kps-stat-legend span{display:inline-flex;align-items:center;gap:6px}.kps-stat-legend i{width:20px;height:3px;border-radius:3px}.kps-stat-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.kps-stat-panel{min-width:0}.kps-stat-empty{padding:40px 16px;text-align:center;color:var(--colortextmuted,#666);background:var(--colorbackcard,#fff);border:1px dashed var(--colortableborder,#ccc);border-radius:7px}.kps-stat-table td,.kps-stat-table th{vertical-align:middle}.kps-stat-table .amount{white-space:nowrap}.kps-stat-breakdown-title{font-weight:600;padding:10px 0}.kps-stat-cost-warning{margin:8px 0 0}.kps-stat-right-link{white-space:nowrap}.kps-stat-qty-positive{color:#177245;font-weight:600}.kps-stat-qty-negative{color:#b42318;font-weight:600}@media(max-width:900px){.kps-stat-columns{grid-template-columns:1fr}.kps-stat-period-label{width:100%;margin-left:0}.kps-stat-chart-wrap{overflow-x:auto}.kps-stat-profile{flex-direction:column}}';
print '</style>';

$head = product_prepare_head($object);
$titre = $langs->trans('CardProduct'.$object->type);
$picto = $object->isService() ? 'service' : 'product';
print dol_get_fiche_head($head, 'krea_stats', $titre, -1, $picto);

$parameters = array('id' => $id, 'period_start' => $periodStart, 'period_end' => $periodEndExclusive);
$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object);
print $hookmanager->resPrint;
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1&type='.(int) $object->type.'">'.$langs->trans('BackToList').'</a>';
$object->next_prev_filter = '(te.fk_product_type:=:'.((int) $object->type).')';
$shownav = empty($user->socid) || in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')), true);
dol_banner_tab($object, 'ref', $linkback, $shownav ? 1 : 0, 'ref', '', '', '', 0, '', '', 1);
print dol_get_fiche_end();

if ($rangeError) {
	print '<div class="warning">'.$langs->trans('KREAPRODUCTS_STATS_INVALID_RANGE').'</div>';
}

print '<form method="get" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="kps-stat-toolbar">';
print '<input type="hidden" name="id" value="'.$id.'">';
print '<div class="kps-stat-filter"><label for="kps-stat-period">'.$langs->trans('KREAPRODUCTS_STATS_PERIOD').'</label>';
$periodOptions = array(
	'today' => $langs->trans('KREAPRODUCTS_STATS_TODAY'),
	'yesterday' => $langs->trans('KREAPRODUCTS_STATS_YESTERDAY'),
	'7d' => $langs->trans('KREAPRODUCTS_STATS_LAST_7_DAYS'),
	'currentmonth' => $langs->trans('KREAPRODUCTS_STATS_CURRENT_MONTH'),
	'lastmonth' => $langs->trans('KREAPRODUCTS_STATS_LAST_MONTH'),
	'3m' => $langs->trans('KREAPRODUCTS_STATS_LAST_3_MONTHS'),
	'6m' => $langs->trans('KREAPRODUCTS_STATS_LAST_6_MONTHS'),
	'12m' => $langs->trans('KREAPRODUCTS_STATS_LAST_12_MONTHS'),
	'currentyear' => $langs->trans('KREAPRODUCTS_STATS_CURRENT_YEAR'),
	'previousyear' => $langs->trans('KREAPRODUCTS_STATS_PREVIOUS_YEAR'),
	'24m' => $langs->trans('KREAPRODUCTS_STATS_LAST_24_MONTHS'),
	'custom' => $langs->trans('KREAPRODUCTS_STATS_CUSTOM_RANGE'),
);
print '<select class="flat minwidth150" id="kps-stat-period" name="period">';
foreach ($periodOptions as $periodKey => $periodLabel) {
	print '<option value="'.dol_escape_htmltag($periodKey).'"'.($period === $periodKey ? ' selected' : '').'>'.dol_escape_htmltag($periodLabel).'</option>';
}
print '</select></div>';
print '<div class="kps-stat-custom-dates" id="kps-stat-custom-dates"'.($period === 'custom' ? '' : ' style="display:none;"').'>';
print '<div class="kps-stat-filter"><label for="kps-stat-date-start">'.$langs->trans('DateStart').'</label><input type="date" class="flat" id="kps-stat-date-start" name="date_start" value="'.dol_escape_htmltag($dateStartInput).'"></div>';
print '<div class="kps-stat-filter"><label for="kps-stat-date-end">'.$langs->trans('DateEnd').'</label><input type="date" class="flat" id="kps-stat-date-end" name="date_end" value="'.dol_escape_htmltag($dateEndInput).'"></div>';
print '</div>';
print '<div><button type="submit" class="button small">'.$langs->trans('Refresh').'</button></div>';
print '<div class="kps-stat-period-label">'.dol_print_date($periodStart, 'day').' &#8211; '.dol_print_date(dol_time_plus_duree($periodEndExclusive, -1, 'd'), 'day').'</div>';
print '</form>';
if (!empty($conf->use_javascript_ajax)) {
	print '<script>document.addEventListener("DOMContentLoaded",function(){var select=document.getElementById("kps-stat-period");var dates=document.getElementById("kps-stat-custom-dates");if(select&&dates){select.addEventListener("change",function(){dates.style.display=this.value==="custom"?"flex":"none";});}});</script>';
}

if (!$statisticsError) {
	if ($showOperations) {
		if ($isManufactured && $isIngredient) {
			$profileLabel = $langs->trans('KREAPRODUCTS_STATS_PROFILE_PRODUCED_INGREDIENT');
			$profileDescription = $langs->trans('KREAPRODUCTS_STATS_PROFILE_PRODUCED_INGREDIENT_DESC');
		} elseif ($isManufactured) {
			$profileLabel = $langs->trans('KREAPRODUCTS_STATS_PROFILE_MANUFACTURED');
			$profileDescription = $langs->trans('KREAPRODUCTS_STATS_PROFILE_MANUFACTURED_DESC');
		} elseif ($isIngredient) {
			$profileLabel = $langs->trans('KREAPRODUCTS_STATS_PROFILE_INGREDIENT');
			$profileDescription = $langs->trans('KREAPRODUCTS_STATS_PROFILE_INGREDIENT_DESC');
		} else {
			$profileLabel = $langs->trans('KREAPRODUCTS_STATS_PROFILE_INTERNAL');
			$profileDescription = $langs->trans('KREAPRODUCTS_STATS_PROFILE_INTERNAL_DESC');
		}
		print '<div class="kps-stat-profile"><span class="kps-stat-profile-badge">'.dol_escape_htmltag($profileLabel).'</span><div class="kps-stat-profile-text">'.dol_escape_htmltag($profileDescription).'</div></div>';
	}

	print '<div class="kps-stat-grid-cards">';
	if ($showOperations) {
		if ($isManufactured || !empty($operationsCurrent['produced']) || !empty($operationsPrevious['produced'])) {
			$productionSubtitle = $langs->trans('KREAPRODUCTS_STATS_OPERATIONS_COUNT', (int) $operationsCurrent['production_orders']);
			kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_UNITS_PRODUCED'), kreaproducts_stats_quantity($operationsCurrent['produced']), $operationsCurrent['produced'], $operationsPrevious['produced'], true, $productionSubtitle);
		}
		if ($isIngredient || !empty($operationsCurrent['customer_usage']) || !empty($operationsPrevious['customer_usage'])) {
			$usageSubtitle = $langs->trans('KREAPRODUCTS_STATS_CUSTOMER_DOCUMENTS_COUNT', (int) $operationsCurrent['customer_documents']);
			kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_CUSTOMER_PRODUCT_USAGE'), kreaproducts_stats_quantity($operationsCurrent['customer_usage']), $operationsCurrent['customer_usage'], $operationsPrevious['customer_usage'], true, $usageSubtitle);
		}
		if (!empty($operationsCurrent['manufacturing_usage']) || !empty($operationsPrevious['manufacturing_usage'])) {
			$manufacturingSubtitle = $langs->trans('KREAPRODUCTS_STATS_OPERATIONS_COUNT', (int) $operationsCurrent['manufacturing_orders']);
			kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_MANUFACTURING_USAGE'), kreaproducts_stats_quantity($operationsCurrent['manufacturing_usage']), $operationsCurrent['manufacturing_usage'], $operationsPrevious['manufacturing_usage'], true, $manufacturingSubtitle);
		}
		if (!empty($operationsCurrent['supplier_receipts']) || !empty($operationsPrevious['supplier_receipts']) || !empty($operationalProfile['received'])) {
			$supplierReceiptSubtitle = $langs->trans('KREAPRODUCTS_STATS_SUPPLIER_DOCUMENTS_COUNT', (int) $operationsCurrent['supplier_documents']);
			kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_SUPPLIER_STOCK_RECEIPTS'), kreaproducts_stats_quantity($operationsCurrent['supplier_receipts']), $operationsCurrent['supplier_receipts'], $operationsPrevious['supplier_receipts'], true, $supplierReceiptSubtitle);
		}
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_OPERATIONAL_NET'), kreaproducts_stats_quantity($operationsCurrent['operational_net']), $operationsCurrent['operational_net'], $operationsPrevious['operational_net'], null);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_INVENTORY_ADJUSTMENT'), kreaproducts_stats_quantity($operationsCurrent['inventory_net']), $operationsCurrent['inventory_net'], $operationsPrevious['inventory_net'], null, $langs->trans('KREAPRODUCTS_STATS_INVENTORY_EVENTS_COUNT', (int) $operationsCurrent['inventory_events']));
		if (!empty($operationalProfile['relation_count'])) {
			kreaproducts_stats_value_card($langs->trans('KREAPRODUCTS_STATS_USED_IN_PRODUCTS'), (string) ((int) $operationalProfile['relation_count']), $langs->trans('KREAPRODUCTS_STATS_ACTIVE_RELATIONS'));
		}
		$periodDays = max(1, (int) ceil(($periodEndExclusive - $periodStart) / 86400));
		$averageDailyUsage = (float) $operationsCurrent['usage'] / $periodDays;
		if ($averageDailyUsage > 0.0000001) {
			$daysOfCover = max(0, (float) $stock['qty']) / $averageDailyUsage;
			kreaproducts_stats_value_card($langs->trans('KREAPRODUCTS_STATS_STOCK_COVER'), price($daysOfCover, 0, $langs, 1, -1, 1), $langs->trans('KREAPRODUCTS_STATS_DAYS_AT_CURRENT_USAGE'));
		}
		if ($canReadSupplierPrice && (float) $operationsCurrent['usage'] > 0.0000001) {
			$estimatedUsageValue = (float) $operationsCurrent['usage'] * (float) $stock['pmp'];
			kreaproducts_stats_value_card($langs->trans('KREAPRODUCTS_STATS_ESTIMATED_USAGE_VALUE'), kreaproducts_stats_money($estimatedUsageValue), $langs->trans('KREAPRODUCTS_STATS_AT_CURRENT_PMP'));
		}
	}
	if ($showSales) {
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_NET_REVENUE'), kreaproducts_stats_money($salesCurrent['amount']), $salesCurrent['amount'], $salesPrevious['amount']);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_UNITS_SOLD'), kreaproducts_stats_quantity($salesCurrent['qty']), $salesCurrent['qty'], $salesPrevious['qty']);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_CUSTOMER_INVOICES'), (string) ((int) $salesCurrent['documents']), $salesCurrent['documents'], $salesPrevious['documents']);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_CUSTOMERS'), (string) ((int) $salesCurrent['partners']), $salesCurrent['partners'], $salesPrevious['partners']);
	}
	if ($showMargin) {
		$coverage = abs((float) $salesCurrent['amount']) > 0.0000001 ? min(100, abs((float) $salesCurrent['costed_amount'] / (float) $salesCurrent['amount']) * 100) : 100;
		$coverageText = $langs->trans('KREAPRODUCTS_STATS_COST_COVERAGE', price($coverage, 0, $langs, 1, 0, 1).'%');
		kreaproducts_stats_kpi($langs->trans('KreapGrossMargin'), kreaproducts_stats_money($salesCurrent['margin']), $salesCurrent['margin'], $salesPrevious['margin'], true, $coverageText);
	}
	if ($showPurchases) {
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_PURCHASE_SPEND'), kreaproducts_stats_money($purchaseCurrent['amount']), $purchaseCurrent['amount'], $purchasePrevious['amount'], false);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_UNITS_PURCHASED'), kreaproducts_stats_quantity($purchaseCurrent['qty']), $purchaseCurrent['qty'], $purchasePrevious['qty']);
		kreaproducts_stats_kpi($langs->trans('KREAPRODUCTS_STATS_SUPPLIER_INVOICES'), (string) ((int) $purchaseCurrent['documents']), $purchaseCurrent['documents'], $purchasePrevious['documents']);
	}
	if ($canReadStock) {
		print '<div class="kps-stat-card"><div class="kps-stat-card-label">'.dol_escape_htmltag($langs->trans('PhysicalStock')).'</div><div class="kps-stat-card-value">'.kreaproducts_stats_quantity($stock['qty']).'</div><div class="kps-stat-card-meta"><span class="opacitymedium">'.$langs->trans('KREAPRODUCTS_STATS_WAREHOUSES', (int) $stock['warehouses']).'</span></div></div>';
		if ($canReadSupplierPrice) {
			print '<div class="kps-stat-card"><div class="kps-stat-card-label">'.dol_escape_htmltag($langs->trans('EstimatedStockValue')).'</div><div class="kps-stat-card-value">'.kreaproducts_stats_money($stock['value']).'</div><div class="kps-stat-card-meta"><span class="opacitymedium">'.$langs->trans('AverageUnitPricePMPShort').': '.kreaproducts_stats_money($stock['pmp']).'</span></div></div>';
		}
	}
	print '</div>';

	if (!$showSales && !$showPurchases && !$showOperations && !$canReadStock) {
		print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_AUTHORIZED_DATA').'</div>';
	} else {
		if ($showOperations) {
			print '<section class="kps-stat-section"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_MONTHLY_OPERATIONAL_FLOW').'</h3></div>';
			$operationalChartSeries = array(
				array('enabled' => $isManufactured || array_sum($producedMonthly) > 0, 'label' => $langs->trans('KREAPRODUCTS_STATS_UNITS_PRODUCED'), 'color' => '#219653', 'values' => $producedMonthly),
				array('enabled' => $isIngredient || array_sum($customerUsageMonthly) > 0, 'label' => $langs->trans('KREAPRODUCTS_STATS_CUSTOMER_PRODUCT_USAGE'), 'color' => '#eb5757', 'values' => $customerUsageMonthly),
				array('enabled' => array_sum($manufacturingUsageMonthly) > 0, 'label' => $langs->trans('KREAPRODUCTS_STATS_MANUFACTURING_USAGE'), 'color' => '#9b51e0', 'values' => $manufacturingUsageMonthly),
				array('enabled' => array_sum($supplierReceiptMonthly) > 0, 'label' => $langs->trans('KREAPRODUCTS_STATS_SUPPLIER_STOCK_RECEIPTS'), 'color' => '#2f80ed', 'values' => $supplierReceiptMonthly),
			);
			print kreaproducts_stats_chart($monthKeys, $operationalChartSeries, 'quantity');
			print '<div class="kps-stat-breakdown"><div class="kps-stat-breakdown-title">'.$langs->trans('KREAPRODUCTS_STATS_MONTHLY_BREAKDOWN').'</div><div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><thead><tr class="liste_titre"><th>'.$langs->trans('Month').'</th>';
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_UNITS_PRODUCED').'</th>';
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_CUSTOMER_PRODUCT_USAGE').'</th>';
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_MANUFACTURING_USAGE').'</th>';
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_SUPPLIER_STOCK_RECEIPTS').'</th>';
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_OPERATIONAL_NET').'</th></tr></thead><tbody>';
			foreach ($monthKeys as $monthKey) {
				$monthTimestamp = dol_stringtotime($monthKey.'-01');
				$monthOperations = $statistics['operations']['monthly'][$monthKey] ?? array();
				print '<tr class="oddeven"><td>'.dol_print_date($monthTimestamp, '%B %Y').'</td>';
				print '<td class="right">'.kreaproducts_stats_quantity($monthOperations['produced'] ?? 0).'</td>';
				print '<td class="right">'.kreaproducts_stats_quantity($monthOperations['customer_usage'] ?? 0).'</td>';
				print '<td class="right">'.kreaproducts_stats_quantity($monthOperations['manufacturing_usage'] ?? 0).'</td>';
				print '<td class="right">'.kreaproducts_stats_quantity($monthOperations['supplier_receipts'] ?? 0).'</td>';
				print '<td class="right">'.kreaproducts_stats_quantity($monthOperations['operational_net'] ?? 0).'</td></tr>';
			}
			print '</tbody><tfoot><tr class="liste_total"><td>'.$langs->trans('Total').'</td>';
			print '<td class="right">'.kreaproducts_stats_quantity($operationsCurrent['produced']).'</td>';
			print '<td class="right">'.kreaproducts_stats_quantity($operationsCurrent['customer_usage']).'</td>';
			print '<td class="right">'.kreaproducts_stats_quantity($operationsCurrent['manufacturing_usage']).'</td>';
			print '<td class="right">'.kreaproducts_stats_quantity($operationsCurrent['supplier_receipts']).'</td>';
			print '<td class="right">'.kreaproducts_stats_quantity($operationsCurrent['operational_net']).'</td></tr></tfoot></table></div></div></section>';

			print '<div class="kps-stat-columns">';
			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_USED_BY_PRODUCTS').'</h3></div>';
			if (empty($operationalProfile['relations'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_PRODUCT_RELATIONS').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('Product').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('Qty').'</th></tr>';
				foreach ($operationalProfile['relations'] as $relation) {
					print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.(int) $relation['id'].'">'.dol_escape_htmltag($relation['ref']).'</a></td><td>'.dol_escape_htmltag($relation['label']).'</td><td class="right">'.kreaproducts_stats_quantity($relation['qty']).'</td></tr>';
				}
				print '</table></div>';
				if ((int) $operationalProfile['relation_count'] > count($operationalProfile['relations'])) {
					print '<div class="opacitymedium small">'.$langs->trans('KREAPRODUCTS_STATS_SHOWING_RELATIONS', count($operationalProfile['relations']), (int) $operationalProfile['relation_count']).'</div>';
				}
			}
			print '</section>';

			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_RECENT_STOCK_ACTIVITY').'</h3></div>';
			if (empty($statistics['operations']['recent'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Origin').'</th><th>'.$langs->trans('Warehouse').'</th><th class="right">'.$langs->trans('Qty').'</th></tr>';
				foreach ($statistics['operations']['recent'] as $row) {
					$originType = (string) $row['origin_type'];
					$originLabel = $langs->trans('KREAPRODUCTS_STATS_SOURCE_OTHER');
					$originUrl = '';
					if ($originType === 'facture') {
						$originLabel = $langs->trans('KREAPRODUCTS_STATS_SOURCE_CUSTOMER');
						if ($canReadSales && !empty($row['origin_id'])) {
							$originUrl = DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $row['origin_id'];
						}
					} elseif ($originType === 'invoice_supplier') {
						$originLabel = $langs->trans('KREAPRODUCTS_STATS_SOURCE_SUPPLIER');
						if ($canReadPurchases && !empty($row['origin_id'])) {
							$originUrl = DOL_URL_ROOT.'/fourn/facture/card.php?id='.(int) $row['origin_id'];
						}
					} elseif ($originType === 'mo') {
						$originLabel = $langs->trans('KREAPRODUCTS_STATS_SOURCE_PRODUCTION');
						if ($canReadMrp && !empty($row['origin_id'])) {
							$originUrl = DOL_URL_ROOT.'/mrp/mo_card.php?id='.(int) $row['origin_id'];
						}
					} elseif ($originType === 'inventory') {
						$originLabel = $langs->trans('KREAPRODUCTS_STATS_SOURCE_INVENTORY');
					}
					$originHtml = dol_escape_htmltag($originLabel);
					if ($originUrl !== '') {
						$originHtml = '<a href="'.dol_escape_htmltag($originUrl).'">'.$originHtml.'</a>';
					}
					$qtyClass = (float) $row['qty'] >= 0 ? 'kps-stat-qty-positive' : 'kps-stat-qty-negative';
					print '<tr class="oddeven"><td>'.dol_print_date($row['date'], 'dayhour').'</td><td>'.$originHtml.'</td><td>'.dol_escape_htmltag($row['warehouse_ref']).'</td><td class="right '.$qtyClass.'">'.kreaproducts_stats_quantity($row['qty']).'</td></tr>';
				}
				print '</table></div>';
			}
			print '</section></div>';
		}

		if ($showSales || $showPurchases) {
		print '<section class="kps-stat-section"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_MONTHLY_PERFORMANCE').'</h3></div>';
		$chartSeries = array(
			array('enabled' => $showSales, 'label' => $langs->trans('KREAPRODUCTS_STATS_NET_REVENUE'), 'color' => '#2f80ed', 'values' => $salesMonthly, 'layer' => 30, 'stroke_width' => 4, 'point_radius' => 4),
			array('enabled' => $showMargin, 'label' => $langs->trans('KreapGrossMargin'), 'color' => '#219653', 'values' => $marginMonthly, 'layer' => 10, 'stroke_width' => 6, 'point_radius' => 6, 'dasharray' => '9 6'),
			array('enabled' => $showPurchases, 'label' => $langs->trans('KREAPRODUCTS_STATS_PURCHASE_SPEND'), 'color' => '#f2994a', 'values' => $purchaseMonthly, 'layer' => 20),
		);
		print kreaproducts_stats_chart($monthKeys, $chartSeries);
		print '<div class="kps-stat-breakdown"><div class="kps-stat-breakdown-title">'.$langs->trans('KREAPRODUCTS_STATS_MONTHLY_BREAKDOWN').'</div><div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><thead><tr class="liste_titre"><th>'.$langs->trans('Month').'</th>';
		if ($showSales) {
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_NET_REVENUE').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_UNITS_SOLD').'</th>';
		}
		if ($showMargin) {
			print '<th class="right">'.$langs->trans('KreapGrossMargin').'</th>';
		}
		if ($showPurchases) {
			print '<th class="right">'.$langs->trans('KREAPRODUCTS_STATS_PURCHASE_SPEND').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_UNITS_PURCHASED').'</th>';
		}
		print '</tr></thead><tbody>';
		foreach ($monthKeys as $monthIndex => $monthKey) {
			$monthTimestamp = dol_stringtotime($monthKey.'-01');
			$rowClass = ($monthIndex % 2 === 0) ? 'oddeven' : 'oddeven';
			print '<tr class="'.$rowClass.'"><td>'.dol_print_date($monthTimestamp, '%B %Y').'</td>';
			if ($showSales) {
				print '<td class="right amount">'.kreaproducts_stats_money($salesMonthly[$monthKey]).'</td><td class="right">'.kreaproducts_stats_quantity($statistics['sales']['monthly'][$monthKey]['qty'] ?? 0).'</td>';
			}
			if ($showMargin) {
				print '<td class="right amount">'.kreaproducts_stats_money($marginMonthly[$monthKey]).'</td>';
			}
			if ($showPurchases) {
				print '<td class="right amount">'.kreaproducts_stats_money($purchaseMonthly[$monthKey]).'</td><td class="right">'.kreaproducts_stats_quantity($statistics['purchases']['monthly'][$monthKey]['qty'] ?? 0).'</td>';
			}
			print '</tr>';
		}
		print '</tbody><tfoot><tr class="liste_total"><td>'.$langs->trans('Total').'</td>';
		if ($showSales) {
			print '<td class="right amount">'.kreaproducts_stats_money($salesCurrent['amount']).'</td><td class="right">'.kreaproducts_stats_quantity($salesCurrent['qty']).'</td>';
		}
		if ($showMargin) {
			print '<td class="right amount">'.kreaproducts_stats_money($salesCurrent['margin']).'</td>';
		}
		if ($showPurchases) {
			print '<td class="right amount">'.kreaproducts_stats_money($purchaseCurrent['amount']).'</td><td class="right">'.kreaproducts_stats_quantity($purchaseCurrent['qty']).'</td>';
		}
		print '</tr></tfoot></table></div></div></section>';
		}

		if ($showMargin && !empty($salesCurrent['missing_cost_lines'])) {
			print '<div class="warning kps-stat-cost-warning">'.$langs->trans('KREAPRODUCTS_STATS_INCOMPLETE_MARGIN', (int) $salesCurrent['missing_cost_lines']).'</div>';
		}

		if ($showSales) {
			print '<div class="kps-stat-columns kps-stat-customer-columns">';
			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_TOP_CUSTOMERS').'</h3><a class="kps-stat-right-link" href="'.DOL_URL_ROOT.'/product/stats/facture.php?id='.$id.'">'.$langs->trans('KREAPRODUCTS_STATS_VIEW_INVOICES').'</a></div>';
			if (empty($statistics['sales']['top'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('ThirdParty').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_NET_REVENUE').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_UNITS_SOLD').'</th></tr>';
				foreach ($statistics['sales']['top'] as $row) {
					print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $row['id'].'">'.dol_escape_htmltag($row['name']).'</a></td><td class="right amount">'.kreaproducts_stats_money($row['amount']).'</td><td class="right">'.kreaproducts_stats_quantity($row['qty']).'</td></tr>';
				}
				print '</table></div>';
			}
			print '</section>';
			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_RECENT_CUSTOMER_INVOICES').'</h3></div>';
			if (empty($statistics['sales']['recent'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('ThirdParty').'</th><th class="right">'.$langs->trans('AmountHT').'</th></tr>';
				foreach ($statistics['sales']['recent'] as $row) {
					print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $row['id'].'">'.dol_escape_htmltag($row['ref']).'</a></td><td>'.dol_print_date($row['date'], 'day').'</td><td class="tdoverflowmax150">'.dol_escape_htmltag($row['thirdparty_name']).'</td><td class="right amount">'.kreaproducts_stats_money($row['amount']).'</td></tr>';
				}
				print '</table></div>';
			}
			print '</section>';
			print '</div>';
		}

		if ($showPurchases) {
			print '<div class="kps-stat-columns kps-stat-supplier-columns">';
			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_TOP_SUPPLIERS').'</h3><a class="kps-stat-right-link" href="'.DOL_URL_ROOT.'/product/stats/facture_fournisseur.php?id='.$id.'">'.$langs->trans('KREAPRODUCTS_STATS_VIEW_INVOICES').'</a></div>';
			if (empty($statistics['purchases']['top'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('Supplier').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_PURCHASE_SPEND').'</th><th class="right">'.$langs->trans('KREAPRODUCTS_STATS_UNITS_PURCHASED').'</th></tr>';
				foreach ($statistics['purchases']['top'] as $row) {
					print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $row['id'].'">'.dol_escape_htmltag($row['name']).'</a></td><td class="right amount">'.kreaproducts_stats_money($row['amount']).'</td><td class="right">'.kreaproducts_stats_quantity($row['qty']).'</td></tr>';
				}
				print '</table></div>';
			}
			print '</section>';
			print '<section class="kps-stat-section kps-stat-panel"><div class="kps-stat-section-title"><h3>'.$langs->trans('KREAPRODUCTS_STATS_RECENT_SUPPLIER_INVOICES').'</h3></div>';
			if (empty($statistics['purchases']['recent'])) {
				print '<div class="kps-stat-empty">'.$langs->trans('KREAPRODUCTS_STATS_NO_DATA_PERIOD').'</div>';
			} else {
				print '<div class="div-table-responsive"><table class="noborder centpercent kps-stat-table"><tr class="liste_titre"><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Supplier').'</th><th class="right">'.$langs->trans('AmountHT').'</th></tr>';
				foreach ($statistics['purchases']['recent'] as $row) {
					$invoiceLabel = $row['ref_supplier'] !== '' ? $row['ref'].' · '.$row['ref_supplier'] : $row['ref'];
					print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?id='.(int) $row['id'].'">'.dol_escape_htmltag($invoiceLabel).'</a></td><td>'.dol_print_date($row['date'], 'day').'</td><td class="tdoverflowmax150">'.dol_escape_htmltag($row['thirdparty_name']).'</td><td class="right amount">'.kreaproducts_stats_money($row['amount']).'</td></tr>';
				}
				print '</table></div>';
			}
			print '</section>';
			print '</div>';
		}
	}
}

llxFooter();
$db->close();
