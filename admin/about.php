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
 * \file    kreaproducts/admin/about.php
 * \ingroup kreaproducts
 * \brief   About page of module KreaProducts.
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

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../lib/kreaproducts.lib.php';

// Translations
$langs->loadLangs(array("errors", "admin", "kreaproducts@kreaproducts"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

/**
 * Print a compact two-column About section.
 *
 * @param string $title Section title
 * @param array<int,array{0:string,1:string}> $rows Section rows
 * @return void
 */
function kreaproductsPrintAboutSection($title, array $rows)
{
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield" colspan="2">' . dol_escape_htmltag($title) . '</td></tr>';
	foreach ($rows as $row) {
		print '<tr><td class="titlefield">' . dol_escape_htmltag($row[0]) . '</td><td>' . dol_escape_htmltag($row[1]) . '</td></tr>';
	}
	print '</table>';
}


/*
 * Actions
 */

// None


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "KreaProductsAbout";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-kreaproducts page-admin_about');

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = kreaproductsAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, 'kreaproducts@kreaproducts');

dol_include_once('/kreaproducts/core/modules/modKreaProducts.class.php');
$tmpmodule = new modKreaProducts($db);
print $tmpmodule->getDescLong();

// Custom about block with branding and key facts
$logoUrl = dol_buildpath('/custom/kreaproducts/img/logo.png', 1);
$moduleName = $tmpmodule->getName();
$moduleVersion = $tmpmodule->getVersion();
$editorName = $tmpmodule->editor_name;
$editorUrl = $tmpmodule->editor_url;
$supportEmail = 'mail@kreativitat.com';

$featureRows = array(
	array('Product card workspace', 'Replaces the standard price, supplier, and subproduct tabs with KreaProducts selling prices, buying prices, technical sheet, nutrition, product tree, and labels tabs.'),
	array('Product structure and recipes', 'Manages associations, BOM/MRP technical sheets, component quantities, source packages, nested BOMs, reverse where-used analysis, and recipe unit display scaling.'),
	array('Prices updater', 'ProductUpdater recalculates cost prices from product associations and BOM technical sheets, loads only the impacted graph, batches parent cascades, and avoids repeated full-catalog recalculation.'),
	array('Supplier invoice cost sync', 'Supplier invoice validation can compute weighted HT purchase cost per product, update purchase cost data, then run one final cost cascade after all invoice lines are processed.'),
	array('Automatic sell-price sync', 'Optional global and per-product controls update selling prices from the final cost using kreap_updatesellprice and kreap_updatesellpricepct markup.'),
	array('Nutrition and allergens', 'Automatic nutrition and allergen calculation, parent/child and BOM propagation, threshold-based trace marking, copy tools, non-food exclusion, and user-facing compliance disclaimers.'),
	array('Stock and inventory dates', 'Supplier stock movements can use invoice/receipt dates, inventory can use value dates, and counted stock anchors protect backdated corrections from drift.'),
	array('Dismantling and packaging', 'Per-product dismantle BOM support converts purchased package products into unit products, creates stock/MO history, keeps proportional unit cost, and preserves origin traceability.'),
	array('Labels and product content', 'Product labels tab supports template-based PDF generation, uploaded label assets, front/back layouts, default per-product layout, ingredients, allergens, nutrition, lot, expiry, storage, brand, recipe, description, alias, and video fields.'),
	array('Lists and productivity', 'Simplified product list replacement, hidden product flag, configurable suffix filters, optional margin column, service category shortcut, price simulator, and product stock movement totals.'),
	array('Permissions and dependencies', 'Requires Dolibarr Products, Stock, Suppliers, BOM/MRP modules and exposes dedicated rights for nutrition, allergens, labels, and inventory expected values.'),
);

$controlRows = array(
	array('KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE', 'Enables automatic cost-price propagation through recipes, associations, and BOM parents.'),
	array('KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST', 'Enables automatic selling-price recalculation when final product cost changes and the product-level flag is active.'),
	array('kreap_updatebuyprice', 'Product-level flag that controls whether cost price is automatically recalculated for that product.'),
	array('kreap_updatesellprice', 'Product-level flag that controls whether selling price follows cost changes.'),
	array('kreap_updatesellpricepct', 'Product-level markup percentage used by the selling-price updater.'),
	array('KREAPRODUCTS_AUTO_SYNC_SUPPLIER_PRICE_FROM_PURCHASE', 'Enables supplier invoice purchase-cost synchronization.'),
	array('KREAPRODUCTS_NUTRITIONAL_TABLE_TAB', 'Controls whether the nutrition table is shown in the technical sheet tab.'),
	array('KREAPRODUCTS_DEFAULT_WEIGHT_LABEL', 'Defines the Dolibarr unit class used as the default weight reference.'),
	array('KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT', 'Enables copying calculated average nutrition values back to selected products.'),
	array('KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT', 'Enables copying calculated allergen values back to selected products.'),
	array('KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT', 'Defines the percentage threshold for allergens considered present.'),
	array('KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT', 'Defines the percentage threshold for allergens shown as traces.'),
	array('KREAPRODUCTS_STOCK_MOVEMENT_DATA', 'Uses supplier invoice dates for supplier stock movement valuation dates.'),
	array('KREAPRODUCTS_SUPPLIER_MOVE_TIME', 'Defines the time applied to supplier invoice stock movements.'),
	array('KREAPRODUCTS_AUTO_SCALE_RECIPE_UNITS', 'Auto-scales displayed recipe quantities between kg/l and g/ml without changing production quantities.'),
	array('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', 'Defines the default time used when creating inventory value dates.'),
	array('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT', 'Limits inventory selection to a configured product category root.'),
	array('KREAPRODUCTS_DISMANTLE_BOMTYPE', 'Defines which BOM type is treated as a dismantling BOM.'),
	array('KREAPRODUCTS_DISMANTLE_WAREHOUSE', 'Defines the warehouse used for dismantle movements, falling back to Dolibarr default warehouse when empty.'),
	array('KREAPRODUCTS_PRODUCT_REF_SUFFIXES', 'Configures product-list suffix filters.'),
	array('KREAPRODUCTS_PRODUCT_LIST_MARGIN_ENABLED', 'Shows or hides the product-list margin percentage column.'),
	array('KREAPRODUCTS_REPLACE_PRODUCT_LIST', 'Controls replacement of the standard Dolibarr product list.'),
	array('KREAPRODUCTS_SERVICE_CATEGORIES_LINK_ENABLED', 'Enables the service categories shortcut from the module menu.'),
	array('KREAPRODUCTS_SIM_ENABLE', 'Enables the price simulator and price update workflow.'),
	array('KREAPRODUCTS_SIM_DEFAULT_MARKUP', 'Defines the default markup used by the price simulator.'),
	array('KREAPRODUCTS_DEBUG_LOG', 'Enables module debug logging for operational diagnostics.'),
);

print '<div class="fichecenter" style="margin-top: 18px; display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';

print '<div style="flex:1 1 260px; max-width:320px; text-align:center;">';
print '<img src="' . $logoUrl . '" alt="' . dol_escape_htmltag($moduleName) . '" style="max-width: 260px; height: auto;">';
print '</div>';

print '<div style="flex:2 1 340px; min-width:320px;">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutModuleLabel') . '</td><td>' . dol_escape_htmltag($moduleName) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutVersionLabel') . '</td><td>' . dol_escape_htmltag($moduleVersion) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutEditorLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorName) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutLicenseLabel') . '</td><td>GPL-3.0-or-later</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutSupportLabel') . '</td><td><a href="mailto:' . dol_escape_htmltag($supportEmail) . '">' . dol_escape_htmltag($supportEmail) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutWebsiteLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorUrl) . '</a></td></tr>';
print '</table>';

kreaproductsPrintAboutSection('Feature coverage', $featureRows);
kreaproductsPrintAboutSection('Main setup and product controls', $controlRows);


print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
