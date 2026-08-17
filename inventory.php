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
 */

/**
 * \file       inventory.php
 * \ingroup    kreaproducts
 * \brief      Dedicated KreaProducts physical-inventory page
 */

$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) {
	$res = @include_once __DIR__.'/../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = @include_once __DIR__.'/../../main.inc.php';
}
if (!$res) {
	die('Failed to include main.inc.php');
}

require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
dol_include_once('/kreaproducts/class/KreaProductsMobileInventoryService.class.php');

/**
 * @var Conf      $conf
 * @var DoliDB    $db
 * @var Form      $form
 * @var Translate $langs
 * @var User      $user
 */

$langs->loadLangs(array('stocks', 'other', 'kreaproducts@kreaproducts'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alphanohtml');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$tab = GETPOST('tab', 'aZ09');
$statisticsProductId = GETPOSTINT('product_id');
if (!in_array($tab, array('inventory', 'statistics'), true)) {
	$tab = 'inventory';
}
if ($action !== '') {
	$tab = 'inventory';
}

if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('stock', 'lire')) {
	accessforbidden();
}
if (getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
	&& !$user->hasRight('stock', 'inventory_advance', 'read')
) {
	accessforbidden();
}

$service = new KreaProductsMobileInventoryService($db, $user, $langs, $conf);
$inventoryJs = array('/custom/kreaproducts/js/kreaproducts_inventory.js');
$isWriteAction = in_array($action, array('start_inventory', 'save_counts', 'save_and_close', 'confirm_close', 'confirm_delete', 'confirm_reverse'), true);
if ($isWriteAction && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
	|| !hash_equals((string) currentToken(), (string) GETPOST('token', 'alphanohtml')))
) {
	accessforbidden($langs->trans('ErrorBadToken'));
}

if ($id <= 0 && $ref !== '') {
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'inventory';
	$sql .= " WHERE ref='".$db->escape($ref)."' AND entity=".((int) $conf->entity);
	$resql = $db->query($sql);
	$referenceRow = $resql ? $db->fetch_object($resql) : false;
	if ($resql) {
		$db->free($resql);
	}
	$id = $referenceRow ? (int) $referenceRow->rowid : 0;
}

if ($action === 'start_inventory') {
	try {
		$startedInventory = $service->startInventory(GETPOSTINT('category_id'), 0);
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $startedInventory['id']));
		exit;
	} catch (KreaProductsStockApiException $exception) {
		setEventMessages($exception->getMessage(), null, 'errors');
		$action = '';
	}
}

if ($id <= 0) {
	try {
		$templateData = $service->listTemplates();
	} catch (KreaProductsStockApiException $exception) {
		accessforbidden($exception->getMessage());
	}

	llxHeader('', $langs->trans('KREAPRODUCTS_INVENTORY_BY_CATEGORY'), '', '', 0, 0, $inventoryJs, '', '', 'mod-product page-inventory_inventory');
	print '<style>';
	print '.kps-category-card .kps-category-action{display:inline-flex!important;align-items:center;justify-content:center;width:160px!important;min-width:160px!important;max-width:160px!important;height:38px!important;min-height:38px!important;box-sizing:border-box}';
	print '.kps-category-new{background:#edf8ef!important;border-color:#b9dbbf!important;color:#245d2e!important}';
	print '.kps-category-existing{background:#fff8df!important;border-color:#ead28a!important;color:#75550b!important}';
	print '</style>';
	$categoryHead = array();
	$categoryHead[] = array($_SERVER['PHP_SELF'], $langs->trans('KREAPRODUCTS_INVENTORY_BY_CATEGORY'), 'categories');
	print dol_get_fiche_head($categoryHead, 'categories', $langs->trans('Inventory'), -1, 'stock');
	$categoryReturnUrl = dol_buildpath('/custom/kreaproducts/inventory_list.php', 1).'?restore_lastsearch_values=1';
	print '<div class="pagination paginationref"><ul class="right">';
	print '<!-- morehtml --><li class="noborder litext clearbothonsmartphone">';
	print '<a href="'.dol_escape_htmltag($categoryReturnUrl).'">'.$langs->trans('BackToList').'</a>';
	print '</li></ul></div><div class="clearboth"></div>';
	print '<p class="opacitymedium">'.$langs->trans('KREAPRODUCTS_INVENTORY_SELECT_CATEGORY').'</p>';
	$blockingOpenInventory = !empty($templateData['blocking_open_inventory']) ? $templateData['blocking_open_inventory'] : null;
	if ($blockingOpenInventory) {
		$blockingUrl = $_SERVER['PHP_SELF'].'?id='.((int) $blockingOpenInventory['id']);
		print '<div class="warning">'.img_warning().' '.$langs->trans(
			'KREAPRODUCTS_INVENTORY_OPEN_BLOCKED_LINK',
			'<a href="'.dol_escape_htmltag($blockingUrl).'">'.dol_escape_htmltag((string) $blockingOpenInventory['ref']).'</a>'
		).'</div><br>';
	}
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Category').'</th>';
	print '<th class="right">'.$langs->trans('Products').'</th>';
	print '<th class="right">'.$langs->trans('Action').'</th>';
	print '</tr>';
	foreach ($templateData['templates'] as $template) {
		$openInventory = !empty($template['open_inventory']) ? $template['open_inventory'] : null;
		$isBlockedByOtherOpenInventory = $blockingOpenInventory && !$openInventory;
		$categoryButtonClass = $openInventory ? 'kps-category-existing' : 'kps-category-new';
		print '<tr class="oddeven">';
		print '<td><strong>'.dol_escape_htmltag((string) $template['label']).'</strong></td>';
		print '<td class="right">'.((int) $template['product_count']).'</td>';
		print '<td class="right">';
		print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="kps-category-card">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="start_inventory">';
		print '<input type="hidden" name="category_id" value="'.((int) $template['id']).'">';
		print '<button type="submit" class="button kps-category-action '.$categoryButtonClass.'" data-loading-label="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_INVENTORY_OPENING')).'"'.(!$service->canCount() || $isBlockedByOtherOpenInventory ? ' disabled="disabled"' : '').'>';
		print $openInventory ? $langs->trans('KREAPRODUCTS_INVENTORY_CONTINUE') : $langs->trans('KREAPRODUCTS_INVENTORY_START');
		print '</button>';
		print '</form>';
		print '</td></tr>';
	}
	print '</table>';
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

$sql = 'SELECT ref, import_key FROM '.MAIN_DB_PREFIX.'inventory';
$sql .= ' WHERE rowid='.$id.' AND entity='.((int) $conf->entity);
$resql = $db->query($sql);
$inventoryRow = $resql ? $db->fetch_object($resql) : false;
if ($resql) {
	$db->free($resql);
}
if (!$inventoryRow) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
if ((string) $inventoryRow->import_key !== 'KPS' && preg_match('/^(?:KPS|KS)-/', (string) $inventoryRow->ref) !== 1) {
	header('Location: '.DOL_URL_ROOT.'/product/inventory/inventory.php?id='.$id);
	exit;
}

$object = new Inventory($db);
if ($object->fetch($id) <= 0 || (int) $object->entity !== (int) $conf->entity) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

try {
	$inventory = $service->getInventory($id);
	$object->fetch($id);
} catch (KreaProductsStockApiException $exception) {
	accessforbidden($exception->getMessage());
}
$canViewInventoryAnalysis = $service->canViewInventoryAnalysis();
if ($tab === 'statistics' && !$canViewInventoryAnalysis) {
	accessforbidden($langs->trans('ErrorForbidden'));
}

try {
	if ($action === 'save_counts' || $action === 'save_and_close') {
		$continueToClose = $action === 'save_and_close';
		$counts = array();
		foreach ($inventory['lines'] as $line) {
			$fieldName = 'count_'.((int) $line['id']);
			if (!GETPOSTISSET($fieldName)) {
				continue;
			}
			$rawQuantity = trim((string) GETPOST($fieldName, 'alpha'));
			$normalizedQuantity = str_replace(',', '.', $rawQuantity);
			if ($rawQuantity !== '' && !is_numeric($normalizedQuantity)) {
				throw new KreaProductsStockApiException($langs->trans('KREAPRODUCTS_ERROR_COUNT_NUMERIC_OR_BLANK'), 400);
			}
			$counts[] = array(
				'line_id' => (int) $line['id'],
				'quantity' => $rawQuantity === '' ? null : $normalizedQuantity,
			);
		}
		$selectedValueDate = !empty($inventory['can_edit_value_date']) && GETPOSTISSET('date_inventory')
			? GETPOST('date_inventory', 'alphanohtml')
			: '';
		$inventory = $service->saveCounts($id, $counts, $selectedValueDate);
		setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_COUNTS_SAVED'), null, 'mesgs');
		if ($continueToClose) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id.'&action=close_inventory');
			exit;
		}
		$action = '';
	} elseif ($action === 'confirm_close' && $confirm === 'yes') {
		$inventory = $service->closeInventory($id, (int) $inventory['counted_lines'] < (int) $inventory['total_lines']);
		$object->fetch($id);
		setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_RECORDED'), null, 'mesgs');
		$action = '';
	} elseif ($action === 'confirm_delete' && $confirm === 'yes') {
		$service->deleteInventory($id);
		setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_DELETED'), null, 'mesgs');
		header('Location: '.dol_buildpath('/custom/kreaproducts/inventory_list.php', 1));
		exit;
	} elseif ($action === 'confirm_reverse' && $confirm === 'yes') {
		$inventory = $service->reverseInventory($id);
		setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_REVERSED'), null, 'mesgs');
		$action = '';
	}
} catch (KreaProductsStockApiException $exception) {
	setEventMessages($exception->getMessage(), null, 'errors');
	$inventory = $service->getInventory($id);
	$action = '';
}

$form = new Form($db);
$formconfirm = '';
$confirmationUseAjax = 0; // Dolibarr AJAX confirmations submit by GET; inventory writes are POST-only.
if ($action === 'close_inventory' && !empty($inventory['can_close'])) {
	$uncounted = max(0, (int) $inventory['total_lines'] - (int) $inventory['counted_lines']);
	$message = $uncounted > 0
		? $langs->trans('KREAPRODUCTS_INVENTORY_UNCOUNTED_CONFIRM', $uncounted)
		: $langs->trans('ConfirmFinish');
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('Close'),
		$message,
		'confirm_close',
		'',
		'yes',
		$confirmationUseAjax
	);
} elseif ($action === 'delete_inventory' && !empty($inventory['can_delete'])) {
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('Delete'),
		$langs->trans('KREAPRODUCTS_INVENTORY_DELETE_CONFIRM'),
		'confirm_delete',
		'',
		0,
		$confirmationUseAjax
	);
} elseif ($action === 'reverse_inventory' && !empty($inventory['can_reverse'])) {
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('KREAPRODUCTS_INVENTORY_REVERSE_ACTION'),
		$langs->trans('KREAPRODUCTS_INVENTORY_REVERSE_CONFIRM'),
		'confirm_reverse',
		'',
		0,
		$confirmationUseAjax
	);
}

llxHeader('', $langs->trans('Inventory'), '', '', 0, 0, $inventoryJs, '', '', 'mod-product page-inventory_inventory');

print '<style>';
print 'div.tabsAction>.kps-inventory-action{display:inline-flex!important;align-items:center;justify-content:center;height:38px!important;min-height:38px!important;box-sizing:border-box;vertical-align:top;white-space:nowrap;margin-bottom:1.4em!important;font-family:inherit!important;font-size:inherit!important;font-weight:bold!important;font-style:normal!important;font-variant:normal!important;font-stretch:normal!important;line-height:1.2!important;letter-spacing:normal!important;text-transform:uppercase!important}';
print '.kps-counted-row td{background:rgba(46,160,67,.06)}';
print '.kps-inventory-search{display:flex;justify-content:flex-start;margin-bottom:24px}';
print '</style>';
print $formconfirm;
$head = array();
$head[] = array($_SERVER['PHP_SELF'].'?id='.$id.'&tab=inventory', $langs->trans('Inventory'), 'inventory');
if ($canViewInventoryAnalysis) {
	$head[] = array($_SERVER['PHP_SELF'].'?id='.$id.'&tab=statistics', $langs->trans('KREAPRODUCTS_INVENTORY_STATISTICS_TAB'), 'statistics');
}
print dol_get_fiche_head($head, $tab, $langs->trans('Inventory'), -1, 'stock');
$linkback = '<a href="'.dol_buildpath('/custom/kreaproducts/inventory_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.dol_escape_htmltag((string) $inventory['title']).'</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

if ($tab === 'statistics') {
	try {
		$statistics = $service->getInventoryStatistics($id, 15);
	} catch (KreaProductsStockApiException $exception) {
		setEventMessages($exception->getMessage(), null, 'errors');
		$statistics = null;
	}

	if ($statistics !== null) {
		$periodStartLabel = date('d/m/Y', strtotime((string) $statistics['period_start']));
		$periodEndLabel = date('d/m/Y', strtotime((string) $statistics['period_end']));
		print '<div class="fichecenter">';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('Warehouse').'</td><td>'.dol_escape_htmltag((string) $inventory['warehouse_ref']).'</td></tr>';
		print '<tr><td>'.$langs->trans('KREAPRODUCTS_INVENTORY_STATISTICS_PERIOD').'</td><td>'.$langs->trans('KREAPRODUCTS_INVENTORY_STATISTICS_PERIOD_RANGE', $periodStartLabel, $periodEndLabel).'</td></tr>';
		print '</table>';
		print '</div><div class="clearboth"></div><br>';

		$graphDirectory = $conf->stock->dir_temp.'/kreaproducts';
		dol_mkdir($graphDirectory);
		$graphWidth = empty($_SESSION['dol_screenwidth']) ? 900 : max(600, min(1200, (int) $_SESSION['dol_screenwidth'] - 180));
		$legend = array(
			$langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_INTAKE'),
			$langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_CONSUMPTION'),
		);

		if (!empty($statistics['products'])) {
			$selectedProductStatistics = null;
			foreach ($statistics['products'] as $productStatistics) {
				if ((int) $productStatistics['product_id'] === $statisticsProductId) {
					$selectedProductStatistics = $productStatistics;
					break;
				}
			}
			if ($selectedProductStatistics === null) {
				$selectedProductStatistics = reset($statistics['products']);
			}

			print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="marginbottomonly">';
			print '<input type="hidden" name="id" value="'.$id.'">';
			print '<input type="hidden" name="tab" value="statistics">';
			print '<label for="kps-statistics-product" class="marginrightonly"><strong>'.$langs->trans('Product').'</strong></label>';
			print '<select class="flat minwidth300" id="kps-statistics-product" name="product_id" data-kps-statistics-product>';
			foreach ($statistics['products'] as $productStatistics) {
				$productId = (int) $productStatistics['product_id'];
				$productOptionLabel = trim((string) $productStatistics['ref'].' - '.(string) $productStatistics['label']);
				print '<option value="'.$productId.'"'.($productId === (int) $selectedProductStatistics['product_id'] ? ' selected' : '').'>'.dol_escape_htmltag($productOptionLabel).'</option>';
			}
			print '</select>';
			print '<noscript><button type="submit" class="button small">'.$langs->trans('Refresh').'</button></noscript>';
			print '</form>';

			$productGraphData = array();
			foreach ($selectedProductStatistics['daily'] as $dayStatistics) {
				$productGraphData[] = array(
					(string) $dayStatistics['label'],
					(float) $dayStatistics['intake'],
					(float) $dayStatistics['consumption'],
				);
			}

			$productId = (int) $selectedProductStatistics['product_id'];
			$productGraph = new DolGraph();
			$productGraph->SetData($productGraphData);
			$productGraph->SetLegend($legend);
			$productGraph->SetType(array('bars', 'bars'));
			$productGraph->SetWidth($graphWidth);
			$productGraph->SetHeight(320);
			$productGraph->SetMinValue(0);
			$productGraph->SetMaxValue(max(1, $productGraph->GetCeilMaxValue()));
			$productGraph->SetShading(3);
			$productGraph->SetTitle(dol_escape_htmltag(trim((string) $selectedProductStatistics['ref'].' - '.(string) $selectedProductStatistics['label'])));
			$productGraphFile = $graphDirectory.'/inventory-'.$id.'-product-'.$productId.'-15d.png';
			$productGraphUrl = DOL_URL_ROOT.'/viewimage.php?modulepart=graph_stock&file='.urlencode('kreaproducts/'.basename($productGraphFile));
			$productGraph->draw($productGraphFile, $productGraphUrl);
			print '<div class="center overflowauto">'.$productGraph->show().'</div>';
			print '<br>';
		}

		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th>'.$langs->trans('Ref').'</th>';
		print '<th>'.$langs->trans('Product').'</th>';
		print '<th class="right">'.$langs->trans('KREAPRODUCTS_INVENTORY_INTAKE').'</th>';
		print '<th class="right">'.$langs->trans('KREAPRODUCTS_INVENTORY_CONSUMPTION').'</th>';
		print '<th class="right">'.$langs->trans('KREAPRODUCTS_INVENTORY_NET_FLOW').'</th>';
		print '</tr>';
		foreach ($statistics['products'] as $productStatistics) {
			$productCardUrl = dol_buildpath('/product/card.php', 1).'?id='.((int) $productStatistics['product_id']);
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_escape_htmltag($productCardUrl).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag((string) $productStatistics['ref']).'</a></td>';
			print '<td>'.dol_escape_htmltag((string) $productStatistics['label']).'</td>';
			print '<td class="right">'.dol_escape_htmltag((string) price2num((float) $productStatistics['intake'], 'MS')).'</td>';
			print '<td class="right">'.dol_escape_htmltag((string) price2num((float) $productStatistics['consumption'], 'MS')).'</td>';
			print '<td class="right">'.dol_escape_htmltag((string) price2num((float) $productStatistics['net'], 'MS')).'</td>';
			print '</tr>';
		}
		print '</table>';
		print '</div>';
	}

	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

if (!empty($inventory['correction_mode'])) {
	$correctionDate = dol_print_date((int) $inventory['date_inventory'], 'day');
	print '<div class="warning">'.img_warning().' '.$langs->trans('KREAPRODUCTS_INVENTORY_CORRECTION_DATE_WARNING', $correctionDate, $correctionDate).'</div><br>';
}

print '<div class="fichecenter">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Warehouse').'</td><td>'.dol_escape_htmltag((string) $inventory['warehouse_ref']).'</td></tr>';
print '<tr><td>'.$langs->trans('KREAPRODUCTS_VALUE_DATE').'</td><td>';
if (!empty($inventory['can_edit_value_date'])) {
	$valueDateInput = dol_print_date((int) $inventory['date_inventory'], '%Y-%m-%d', 'tzuserrel');
	$maxValueDateInput = dol_print_date((int) $inventory['max_value_date'], '%Y-%m-%d', 'tzuserrel');
	print '<input type="date" class="flat" id="kps-value-date" name="date_inventory" value="'.dol_escape_htmltag($valueDateInput).'" max="'.dol_escape_htmltag($maxValueDateInput).'" form="kps-inventory-count-form" data-kps-value-date>';
} else {
	print dol_print_date((int) $inventory['date_inventory'], 'day');
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('Progress').'</td><td id="kps-count-progress" data-template="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_INVENTORY_COUNT_PROGRESS_TEMPLATE')).'">'.$langs->trans('KREAPRODUCTS_INVENTORY_COUNT_PROGRESS', (int) $inventory['counted_lines'], (int) $inventory['total_lines']).'</td></tr>';
print '</table>';
print '</div>';

print '<div class="clearboth"></div><br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" id="kps-inventory-count-form">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_counts">';
print '<input type="hidden" name="id" value="'.$id.'">';
$showBatchColumn = false;
foreach ($inventory['lines'] as $inventoryLine) {
	if ((string) $inventoryLine['batch'] !== '') {
		$showBatchColumn = true;
		break;
	}
}
print '<div class="kps-inventory-search">';
print '<input type="search" class="flat minwidth300" id="kps-product-search" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'">';
print '</div>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Product').'</th>';
if ($showBatchColumn) {
	print '<th>'.$langs->trans('KREAPRODUCTS_INVENTORY_LOT_SERIAL').'</th>';
}
if ($canViewInventoryAnalysis) {
	print '<th class="right nowraponall">'.$langs->trans('KREAPRODUCTS_INVENTORY_VIRTUAL_STOCK').'</th>';
}
print '<th class="right">'.$langs->trans('RealQty').'</th>';
if ($canViewInventoryAnalysis) {
	print '<th class="right nowraponall">'.$langs->trans('KREAPRODUCTS_INVENTORY_ABSOLUTE_DEVIATION').'</th>';
	print '<th class="right nowraponall">'.$langs->trans('KREAPRODUCTS_INVENTORY_RELATIVE_DEVIATION').'</th>';
}
print '</tr>';

foreach ($inventory['lines'] as $line) {
	$isCounted = !empty($line['counted']);
	$value = $isCounted ? (string) $line['quantity'] : '';
	$expectedQuantity = $canViewInventoryAnalysis ? (float) $line['virtual_stock_at_business_close'] : 0.0;
	$absoluteDeviation = $canViewInventoryAnalysis && $isCounted ? (float) $line['quantity'] - $expectedQuantity : null;
	$relativeDeviation = $canViewInventoryAnalysis && $isCounted && abs($expectedQuantity) >= 0.0001
		? $absoluteDeviation / abs($expectedQuantity) * 100
		: null;
	$searchText = mb_strtolower(trim((string) $line['label'].' '.(string) $line['ref'].' '.(string) $line['batch']));
	$productCardUrl = dol_buildpath('/product/card.php', 1).'?id='.((int) $line['product_id']);
	print '<tr class="oddeven'.($isCounted ? ' kps-counted-row' : '').'" data-kps-product-row data-search-text="'.dol_escape_htmltag($searchText).'">';
	print '<td><a href="'.dol_escape_htmltag($productCardUrl).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag((string) $line['ref']).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $line['label']).'</td>';
	if ($showBatchColumn) {
		print '<td>'.dol_escape_htmltag((string) $line['batch']).'</td>';
	}
	if ($canViewInventoryAnalysis) {
		print '<td class="right nowraponall">'.dol_escape_htmltag((string) price2num($expectedQuantity, 'MS')).'</td>';
	}
	print '<td class="right">';
	if (!empty($inventory['editable'])) {
		print '<input type="text" inputmode="decimal" class="flat right" size="12"';
		print ' name="count_'.((int) $line['id']).'" value="'.dol_escape_htmltag($value).'"';
		print ' data-kps-count';
		if ($canViewInventoryAnalysis) {
			print ' data-kps-expected-quantity="'.dol_escape_htmltag((string) $expectedQuantity).'"';
		}
		print ' aria-label="'.dol_escape_htmltag($langs->trans('RealQty').' '.(string) $line['label']).'">';
	} else {
		print $isCounted ? dol_escape_htmltag($value) : '<span class="opacitymedium">—</span>';
	}
	print '</td>';
	if ($canViewInventoryAnalysis) {
		print '<td class="right nowraponall" data-kps-absolute-deviation>';
		print $absoluteDeviation === null ? '<span class="opacitymedium">—</span>' : dol_escape_htmltag((string) price2num($absoluteDeviation, 'MS'));
		print '</td>';
		print '<td class="right nowraponall" data-kps-relative-deviation>';
		print $relativeDeviation === null ? '<span class="opacitymedium">—</span>' : dol_escape_htmltag(number_format($relativeDeviation, 2, '.', '').'%');
		print '</td>';
	}
	print '</tr>';
}
print '</table>';
print '</div>';

print '<div class="opacitymedium small paddingtop">'.$langs->trans('KREAPRODUCTS_INVENTORY_BLANK_HELP').'</div>';
print '</form>';
print dol_get_fiche_end();
print '<div class="tabsAction">';
if (!empty($inventory['editable'])) {
	print '<button type="submit" form="kps-inventory-count-form" class="butAction kps-inventory-action" id="kps-save-counts" data-saving-label="'.dol_escape_htmltag($langs->trans('KREAPRODUCTS_INVENTORY_SAVING')).'">'.$langs->trans('Save').'</button>';
}
if (!empty($inventory['can_close'])) {
	print '<button type="submit" form="kps-inventory-count-form" name="action" value="save_and_close" class="butAction kps-inventory-action">'.$langs->trans('MakeMovementsAndClose').'</button>';
}
if (!empty($inventory['can_reverse'])) {
	print '<a class="butActionDelete kps-inventory-action" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=reverse_inventory&token='.newToken().'">'.$langs->trans('KREAPRODUCTS_INVENTORY_REVERSE_ACTION').'</a>';
}
if (!empty($inventory['can_delete'])) {
	print '<a class="butActionDelete kps-inventory-action" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete_inventory&token='.newToken().'">'.$langs->trans('Delete').'</a>';
}
print '</div>';

llxFooter();
$db->close();
