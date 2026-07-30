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
 * \file       inventory_stock_overview.php
 * \ingroup    kreaproducts
 * \brief      Current virtual stock grouped by inventory category
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

dol_include_once('/kreaproducts/class/KreaProductsMobileInventoryService.class.php');

/**
 * @var Conf      $conf
 * @var DoliDB    $db
 * @var Translate $langs
 * @var User      $user
 */

$langs->loadLangs(array('products', 'stocks', 'kreaproducts@kreaproducts'));

if ($user->socid > 0) {
	accessforbidden();
}
restrictedArea($user, 'stock');

$service = new KreaProductsMobileInventoryService($db, $user, $langs, $conf);
try {
	$overview = $service->getInventoryStockOverview();
} catch (KreaProductsStockApiException $exception) {
	accessforbidden($exception->getMessage());
}

$title = $langs->trans('KREAPRODUCTS_INVENTORY_STOCK_OVERVIEW');
llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-product page-inventory-stock-overview');

print load_fiche_titre($title, '', 'stock');
print '<p class="opacitymedium">'.$langs->trans('KREAPRODUCTS_INVENTORY_STOCK_OVERVIEW_HELP').'</p>';

if (empty($overview['categories'])) {
	print '<div class="info">'.$langs->trans('KREAPRODUCTS_INVENTORY_STOCK_OVERVIEW_EMPTY').'</div>';
}

foreach ($overview['categories'] as $category) {
	print '<div class="fichecenter">';
	print load_fiche_titre(
		dol_escape_htmltag((string) $category['label']).' <span class="badge">'.count($category['products']).'</span>',
		'',
		'category'
	);
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Ref').'</th>';
	print '<th>'.$langs->trans('Product').'</th>';
	print '<th class="right">'.$langs->trans('KREAPRODUCTS_INVENTORY_REALTIME_VIRTUAL_STOCK').'</th>';
	print '</tr>';
	if (empty($category['products'])) {
		print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
	} else {
		foreach ($category['products'] as $product) {
			$productCardUrl = dol_buildpath('/product/card.php', 1).'?id='.((int) $product['product_id']);
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_escape_htmltag($productCardUrl).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag((string) $product['ref']).'</a></td>';
			print '<td>'.dol_escape_htmltag((string) $product['label']).'</td>';
			print '<td class="right nowraponall">'.dol_escape_htmltag((string) price2num((float) $product['virtual_stock'], 'MS')).'</td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';
	print '</div><br>';
}

llxFooter();
$db->close();
