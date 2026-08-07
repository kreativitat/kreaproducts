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

if (!function_exists('price2num')) {
	function price2num($value, $mode = '')
	{
		return round((float) $value, 8);
	}
}

require_once __DIR__.'/../class/KreaProductsBusinessDayService.class.php';
require_once __DIR__.'/../class/KreaProductsInventoryLedgerCalculator.class.php';
require_once __DIR__.'/../class/KreaProductsBomCostCalculator.class.php';
require_once __DIR__.'/../lib/kreaproducts.lib.php';

/**
 * @param mixed  $expected Expected value
 * @param mixed  $actual   Actual value
 * @param string $message  Failure message
 * @return void
 */
function assertSameValue($expected, $actual, $message)
{
	if ($expected !== $actual) {
		fwrite(STDERR, $message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).PHP_EOL);
		exit(1);
	}
}

assertSameValue(0, kreaproducts_normalize_weight_unit_scale('0'), 'Kilograms must remain a valid weight-unit scale.');
assertSameValue(0, kreaproducts_normalize_weight_unit_scale(''), 'Missing weight units must default to kilograms.');
assertSameValue(0, kreaproducts_normalize_weight_unit_scale('invalid'), 'Invalid weight units must default to kilograms.');
assertSameValue(-3, kreaproducts_normalize_weight_unit_scale('-3'), 'Gram weight-unit scale normalization failed.');
assertSameValue(98, kreaproducts_normalize_weight_unit_scale('98'), 'Exotic weight-unit scale normalization failed.');
assertSameValue(-3, kreaproducts_normalize_weight_unit_scale(null, -3), 'Missing submissions must preserve the current weight unit.');
assertSameValue('', kreaproducts_weight_unit_select_value(0), 'The Dolibarr selector must receive its empty kilogram option value.');
assertSameValue('-3', kreaproducts_weight_unit_select_value(-3), 'Non-kilogram selector values must remain unchanged.');

$timezone = new DateTimeZone('Europe/Lisbon');
$businessDay = new KreaProductsBusinessDayService();

$entry = new DateTimeImmutable('2026-07-12 01:30:00', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '06:00', '20:00');
assertSameValue('2026-07-12 06:01:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Early-morning count window failed.');

$entry = new DateTimeImmutable('2026-07-12 19:59:59', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '06:00', '20:00');
assertSameValue('2026-07-12 06:01:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Pre-cutoff count window failed.');

$entry = new DateTimeImmutable('2026-07-12 20:00:00', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '06:00', '20:00');
assertSameValue('2026-07-13 06:01:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Cutoff boundary failed.');

$supplierTimestamp = $businessDay->resolveDateTimestamp('2026-07-12', $timezone, '10:00');
assertSameValue('2026-07-12 10:00:00', (new DateTimeImmutable('@'.$supplierTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Supplier time normalization failed.');
$editableValueTimestamp = $businessDay->resolveDateTimestamp('2026-07-11', $timezone, '06:00') + 60;
assertSameValue('2026-07-11 06:01:00', (new DateTimeImmutable('@'.$editableValueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Editable value-date anchor failed.');
$autoCloseTimestamp = $businessDay->resolveInventoryAutoCloseTimestamp($editableValueTimestamp, $timezone, '20:00', 15);
assertSameValue('2026-07-11 19:45:00', (new DateTimeImmutable('@'.$autoCloseTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Automatic inventory closure threshold failed.');

$customerTimestamp = $businessDay->resolveInvoiceDateTimeTimestamp('2026-07-11 23:45:12', $timezone);
assertSameValue('2026-07-11 23:45:12', (new DateTimeImmutable('@'.$customerTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Customer invoice datetime normalization failed.');

$expected = KreaProductsInventoryLedgerCalculator::expectedQuantityAtValueDate(37, -27);
assertSameValue(64.0, $expected, 'Inventory reconstruction failed.');
$adjustment = KreaProductsInventoryLedgerCalculator::adjustmentQuantity(64, $expected);
assertSameValue(0.0, $adjustment, 'Zero inventory correction failed.');

$expected = KreaProductsInventoryLedgerCalculator::expectedQuantityAtValueDate(50, -20);
$adjustment = KreaProductsInventoryLedgerCalculator::adjustmentQuantity(64, $expected);
assertSameValue(44.0, 50.0 + $adjustment, 'Delayed count correction failed.');

$origins = KreaProductsInventoryLedgerCalculator::excludedMovementOrigins();
assertSameValue(true, in_array('inventory', $origins, true), 'Inventory correction origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_inventory_reversal', $origins, true), 'Inventory reversal origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_inventory_rebase', $origins, true), 'Inventory rebase origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_inventory_rebase_reversal', $origins, true), 'Inventory rebase reversal origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_count_correction', $origins, true), 'Count correction origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_count_correction_reversal', $origins, true), 'Count correction reversal origin must be excluded.');
assertSameValue(false, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, false, 1), 'Kit parent without operational parent movements must not be counted independently.');
assertSameValue(true, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, true, 1), 'Kit parent with operational parent movements may be counted independently.');
assertSameValue(true, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, false, 0), 'Simple product must remain countable.');

assertSameValue(null, KreaProductsInventoryLedgerCalculator::normalizePhysicalCount(null), 'Null must remain uncounted.');
assertSameValue(null, KreaProductsInventoryLedgerCalculator::normalizePhysicalCount(''), 'Blank text must remain uncounted.');
assertSameValue(0.0, KreaProductsInventoryLedgerCalculator::normalizePhysicalCount('0'), 'Explicit zero must remain a counted zero.');
assertSameValue(2.5, KreaProductsInventoryLedgerCalculator::normalizePhysicalCount('2.5'), 'Numeric count normalization failed.');

assertSameValue(5.0, KreaProductsBomCostCalculator::normalizeLineQuantity(10, 0.5, 4), 'BOM line efficiency and header quantity normalization failed.');
assertSameValue(array(), KreaProductsBomCostCalculator::findCycle(array(10 => array(20), 20 => array(30), 30 => array())), 'Acyclic BOM graph validation failed.');
assertSameValue(array(10, 20, 30, 10), KreaProductsBomCostCalculator::findCycle(array(10 => array(20), 20 => array(30), 30 => array(10))), 'Cyclic BOM graph detection failed.');
$bomCandidates = array(
	array('id' => 10, 'date_valid' => '2026-01-01 09:00:00', 'date_creation' => '2026-01-01 08:00:00'),
	array('id' => 20, 'date_valid' => '2026-02-01 09:00:00', 'date_creation' => '2026-02-01 08:00:00'),
);
assertSameValue(20, KreaProductsBomCostCalculator::selectPreferredBomId($bomCandidates, array()), 'A product without BOM production history must use its newest validated active BOM automatically.');
assertSameValue(10, KreaProductsBomCostCalculator::selectPreferredBomId($bomCandidates, array(10 => array('date' => '2026-03-01 10:00:00', 'rowid' => 100))), 'A BOM with completed production must take precedence over a never-produced BOM.');
assertSameValue(20, KreaProductsBomCostCalculator::selectPreferredBomId($bomCandidates, array(10 => array('date' => '2026-03-01 10:00:00', 'rowid' => 100), 20 => array('date' => '2026-03-02 10:00:00', 'rowid' => 101))), 'The BOM with the latest completed production must be selected automatically.');

$expectedBeforeEarlierCount = KreaProductsInventoryLedgerCalculator::expectedQuantityAtValueDate(80, -20);
$earlierAdjustment = KreaProductsInventoryLedgerCalculator::adjustmentQuantity(90, $expectedBeforeEarlierCount);
assertSameValue(70.0, 80.0 + $earlierAdjustment, 'Reverse-then-close-older scenario failed.');

$stockMovementSource = file_get_contents(__DIR__.'/../class/KreaProductsStockMovementService.class.php');
assertSameValue(false, strpos((string) $stockMovementSource, 'stock_mouvement SET value'), 'Stock reconciliation must not rewrite historical movement values.');
assertSameValue(true, strpos((string) $stockMovementSource, "origintype === 'facture'") !== false, 'Customer invoice movements must be value-dated.');
assertSameValue(true, strpos((string) $stockMovementSource, 'shiftCustomerInvoiceMoveToInvoiceDateTime') !== false, 'Customer movements must use the authoritative invoice datetime.');
assertSameValue(true, strpos((string) $stockMovementSource, 'zs.datahora_zs') !== false, 'DoliZSynch invoice datetime must be preferred when available.');
assertSameValue(false, strpos((string) $stockMovementSource, 'shiftCustomerInvoiceMoveToBusinessClose') !== false, 'Customer movements must not be shifted to the following business close.');
assertSameValue(true, substr_count((string) $stockMovementSource, 'OR sm.datem >') >= 2, 'Delayed customer and supplier movements must also be selected by actual movement position.');
assertSameValue(true, strpos((string) $stockMovementSource, 'ProductUpdater::getLastErrors()') !== false, 'Supplier stock processing must abort on cost-cascade errors.');
assertSameValue(true, strpos((string) $stockMovementSource, "\t\treturn 0;\n\t}") !== false, 'Successful stock movement processing must use Dolibarr trigger success code zero.');
assertSameValue(true, strpos((string) $stockMovementSource, "'corrected_counted_qty' => \$row->corrected_counted_qty") !== false, 'Inventory reconstruction must carry the latest active corrected count into its anchor.');
assertSameValue(true, strpos((string) $stockMovementSource, "array_key_exists('corrected_counted_qty', \$anchor)") !== false, 'Corrected counts must take precedence over the original inventory quantity.');
assertSameValue(true, strpos((string) $stockMovementSource, '$db->jdate($move->datem)') !== false, 'Dismantling must parse movement SQL datetimes in the server timezone.');
assertSameValue(false, strpos((string) $stockMovementSource, 'dol_stringtotime($move->datem)') !== false, 'Dismantling must not reinterpret server SQL datetimes as GMT.');
assertSameValue(true, strpos((string) $stockMovementSource, "getDolGlobalInt('KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES', 30)") !== false, 'Customer invoice future tolerance must be configurable with a 30-minute fallback.');
assertSameValue(true, strpos((string) $stockMovementSource, 'min(1440, max(0, getDolGlobalInt(') !== false, 'Customer invoice future tolerance must remain within the setup safety bounds.');

$moduleSource = file_get_contents(__DIR__.'/../core/modules/modKreaProducts.class.php');
assertSameValue(true, strpos((string) $moduleSource, "\$this->version = '4.7.0'") !== false, 'The module descriptor must use the audited release version.');
assertSameValue(true, strpos((string) $moduleSource, "'KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES', 'integer', '30'") !== false, 'Invoice datetime future tolerance must default to 30 minutes.');
assertSameValue(true, strpos((string) $moduleSource, "'inventory';\n        \$this->rights[6][5] = 'expected'") !== false, 'Inventory analysis must use the dedicated expected-stock permission.');
assertSameValue(true, strpos((string) $moduleSource, "\$this->rights[6][3] = 0") !== false, 'Inventory analysis permission must remain disabled by default.');
assertSameValue(true, strpos((string) $moduleSource, '/kreaproducts/inventory_stock_overview.php?leftmenu=stock_inventories') !== false, 'The inventory stock overview must be registered in the stock inventory left menu.');
assertSameValue(true, strpos((string) $moduleSource, '$user->hasRight("kreaproducts", "inventory", "expected")') !== false, 'The inventory stock overview menu must require the analysis permission.');
assertSameValue(false, strpos((string) $moduleSource, 'dolibarr_set_const($db, "PRODUIT_SOUSPRODUITS"'), 'Module activation must not force global composed-product stock behavior.');

$associatedProductsSource = file_get_contents(__DIR__.'/../associatedProducts.php');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $object->weight_units)') !== false, 'Parent-product weight updates must preserve the current unit when the submission is missing.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $childProduct->weight_units)') !== false, 'Component weight updates must preserve the current unit when the submission is missing.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_weight_unit_select_value') !== false, 'The KreaProducts product form must use Dolibarr\'s empty kilogram selector value.');

$actionsSource = file_get_contents(__DIR__.'/../class/actions_kreaproducts.class.php');
assertSameValue(true, strpos((string) $actionsSource, 'buildProductCardKilogramSelectionScriptRow') !== false, 'Native product forms must apply the kilogram selector workaround.');
assertSameValue(true, strpos((string) $actionsSource, 'option.value==="0"||label==="kg"') !== false, 'The kilogram selector workaround must support current and corrected Dolibarr unit values.');

$mobileInventorySource = file_get_contents(__DIR__.'/../class/KreaProductsMobileInventoryService.class.php');
assertSameValue(true, strpos((string) $mobileInventorySource, "i.date_inventory >= '") !== false, 'Equal-time inventory anchors must be rejected.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'isInventoryInCurrentCountingWindow') !== false, 'Recorded correction windows must remain enforced.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'isAuditedLegacyKitParentLine') !== false, 'Historical audited kit-parent corrections must retain narrow compatibility.');
assertSameValue(true, strpos((string) $mobileInventorySource, "\$inventory->import_key = 'KPS'") !== false, 'New managed inventories must store a hidden ownership marker.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'dol_print_date((int) $valueTimestamp, \'%Y%m%d\', \'tzuserrel\').\'_\'.$referenceLabel') !== false, 'New inventory references must use the value-day and category format.');
assertSameValue(false, strpos((string) $mobileInventorySource, "return 'KPS-'.((int) \$this->conf->entity)") !== false, 'New inventory references must not expose technical entity, category, timestamp, or random tokens.');
assertSameValue(true, strpos((string) $mobileInventorySource, "i.import_key = 'KPS' OR i.ref LIKE 'KPS-%'") !== false, 'Managed inventory queries must support new ownership markers and legacy references.');
assertSameValue(true, strpos((string) $mobileInventorySource, "return '(PROV'.str_pad((string) ((int) \$inventoryId), 6, '0', STR_PAD_LEFT).')'") !== false, 'Initiated inventories must use the padded Dolibarr provisional reference.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'normalizeInitiatedTechnicalReference($inventory)') !== false, 'Existing initiated KPS technical references must normalize to provisional references when reopened.');
assertSameValue(true, strpos((string) $mobileInventorySource, '$finalReference = $this->resolveAvailableInventoryReference(') !== false, 'Inventory closure must resolve its final business reference before creating movements.');
assertSameValue(true, strpos((string) $mobileInventorySource, '$inventoryCode = \'INV-\'.$inventory->ref') !== false, 'Inventory movements must use the final recorded reference.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'can_edit_value_date') !== false, 'Initiated inventories must expose editable value-date capability.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'findRecordedInventoryOnCalendarDate(') !== false, 'Inventory closure must check for a recorded inventory on the selected calendar date.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_INVENTORY_RECORDED_DATE_EXISTS') !== false, 'Duplicate recorded value dates must return a clear refusal message.');
assertSameValue(false, strpos((string) $mobileInventorySource, 'counting window has closed and it can no longer be recorded') !== false, 'Initiated inventories must remain closable after an explicit value-date change.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'findAnyOpenManagedInventory()') !== false, 'Starting a new category must detect an existing initiated managed inventory.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'closeDueInventories($now = 0)') !== false, 'Managed inventories must expose automatic due closure.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'closeDueInventoriesAsScheduler($now = 0)') !== false, 'Scheduled closure must use a dedicated administrator-only entry point.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_INVENTORY_FUTURE_CLOSE_BLOCKED') !== false, 'Future inventory anchors must not post stock immediately.');
assertSameValue(true, strpos((string) $mobileInventorySource, '$canDelete = $isKreaProductsStockInventory && $isOpen && $this->canCount();') !== false, 'Authorized counters must be allowed to delete initiated inventories.');
$deleteInventoryStart = strpos((string) $mobileInventorySource, 'public function deleteInventory');
$deleteInventoryEnd = strpos((string) $mobileInventorySource, 'public function saveCounts', $deleteInventoryStart);
assertSameValue(true, $deleteInventoryStart !== false && $deleteInventoryEnd !== false, 'Inventory deletion service scope could not be resolved.');
$deleteInventorySource = substr((string) $mobileInventorySource, $deleteInventoryStart, $deleteInventoryEnd - $deleteInventoryStart);
assertSameValue(true, strpos((string) $deleteInventorySource, '$this->requireCountAccess();') !== false, 'Initiated inventory deletion must require count permission.');
assertSameValue(false, strpos((string) $deleteInventorySource, '$this->requireCloseAccess();') !== false, 'Initiated inventory deletion must not require close or reversal permission.');
assertSameValue(true, strpos((string) $deleteInventorySource, 'Inventory::STATUS_VALIDATED') !== false, 'Deletion must remain restricted to initiated inventories.');

$mobileAppSource = file_get_contents(__DIR__.'/../stockapp/src/App.tsx');
assertSameValue(true, strpos((string) $mobileAppSource, 'Guardar correções') !== false, 'Mobile correction mode must include a bottom save action.');

$inventoryPageSource = file_get_contents(__DIR__.'/../inventory.php');
assertSameValue(false, strpos((string) $inventoryPageSource, 'new MouvementStock'), 'The dedicated inventory page must not calculate stock movements directly.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'stock_mouvement'), 'The dedicated inventory page must not query the stock movement ledger directly.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'restrictedArea($user') !== false, 'Custom inventory actions must not inherit core action-name write checks from restrictedArea.');
assertSameValue(true, strpos((string) $inventoryPageSource, "\$user->hasRight('stock', 'lire')") !== false, 'The custom inventory page must retain an explicit stock-read gate.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$service->saveCounts') !== false, 'The dedicated inventory page must delegate count saving to the shared service.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$service->closeInventory') !== false, 'The dedicated inventory page must delegate inventory closure to the shared service.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$service->startInventory') !== false, 'The unified page must start inventories from configured categories.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'inventoryPrepareHead'), 'The unified inventory page must not render separate card and inventory tabs.');
assertSameValue(true, strpos((string) $inventoryPageSource, "dol_get_fiche_head(\$head, \$tab") !== false, 'The unified inventory detail must use the native Dolibarr fiche tab bar.');
assertSameValue(true, strpos((string) $inventoryPageSource, "&tab=statistics") !== false, 'The inventory detail must expose the statistics tab.');
assertSameValue(true, strpos((string) $inventoryPageSource, "if (\$tab === 'statistics' && !\$canViewInventoryAnalysis)") !== false, 'Direct statistics-tab access must require the inventory analysis permission.');
assertSameValue(true, substr_count((string) $inventoryPageSource, 'if ($canViewInventoryAnalysis)') >= 6, 'Expected stock, deviations, and statistics must be conditionally rendered by permission.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$service->getInventoryStatistics($id, 15)') !== false, 'The statistics tab must delegate flow calculations to the shared inventory service.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'new DolGraph()') !== false, 'The statistics tab must render native Dolibarr graphs.');
assertSameValue(false, strpos((string) $inventoryPageSource, "array('horizontalbars', 'horizontalbars')") !== false, 'Inventory statistics must not compare totals across products with incompatible units.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'data-kps-statistics-product') !== false, 'Inventory statistics must provide a product selector.');
assertSameValue(true, strpos((string) $inventoryPageSource, "foreach (\$selectedProductStatistics['daily'] as \$dayStatistics)") !== false, 'Each selected product graph must display its own daily intake and consumption.');
assertSameValue(false, strpos((string) $inventoryPageSource, "\$langs->trans('Status')") !== false, 'The inventory detail must not repeat the initiated status in its summary table.');
assertSameValue(false, strpos((string) $inventoryPageSource, "'dayhour'") !== false, 'The inventory value date must be displayed without its ordering time.');
assertSameValue(true, strpos((string) $inventoryPageSource, "\$langs->trans('KREAPRODUCTS_VALUE_DATE')") !== false, 'The inventory summary must use the concise module value-date label.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'data-kps-value-date') !== false, 'Initiated Dolibarr inventories must render an editable value-date field.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_CORRECTION_DATE_WARNING') !== false, 'Recorded same-day corrections must display their allowed correction date.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'kps-category-grid') !== false, 'Category selection must use the native Dolibarr table instead of custom card buttons.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$showBatchColumn') !== false, 'Lot and serial columns must be hidden when no inventory line uses them.');
assertSameValue(true, strpos((string) $inventoryPageSource, "dol_buildpath('/product/card.php', 1).'?id='") !== false, 'Inventory references must link to their Dolibarr product cards.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'target="_blank" rel="noopener noreferrer"') !== false, 'Product card links must open safely in a new browser tab.');
assertSameValue(true, strpos((string) $inventoryPageSource, "print '<th>'.\$langs->trans('Ref').'</th>';\nprint '<th>'.\$langs->trans('Product').'</th>';") !== false, 'The product reference must be the first inventory table column.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'stock_mobile.php') !== false, 'The Dolibarr inventory page must not show the mobile-counting shortcut.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'kps-uniform-action') !== false, 'Inventory detail actions must not override native Dolibarr sizing.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'class="butAction kps-inventory-action" id="kps-save-counts"') !== false, 'The Save action must use the native Dolibarr action class and shared height class.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'name="action" value="save_and_close" class="butAction kps-inventory-action"') !== false, 'The Close action must submit current count values before confirmation.');
assertSameValue(true, strpos((string) $inventoryPageSource, "header('Location: '.\$_SERVER['PHP_SELF'].'?id='.\$id.'&action=close_inventory')") !== false, 'Save-and-close must redirect to confirmation only after counts are persisted.');
assertSameValue(true, strpos((string) $inventoryPageSource, "'save_and_close'") !== false, 'Save-and-close must be protected as a POST write action.');
assertSameValue(true, strpos((string) $inventoryPageSource, '<a class="butActionDelete kps-inventory-action" href=') !== false, 'Destructive actions must use the native Dolibarr delete class and shared height class.');
assertSameValue(true, strpos((string) $inventoryPageSource, '$confirmationUseAjax = 0') !== false, 'Inventory write confirmations must submit through POST forms, not AJAX GET requests.');
assertSameValue(true, strpos((string) $inventoryPageSource, "'confirm_close',\n\t\t'',\n\t\t'yes',") !== false, 'Inventory closure confirmation must select Yes by default.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'kps-category-new') !== false, 'New category actions must use the light green state class.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'kps-category-existing') !== false, 'Started category actions must use the light yellow state class.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'restore_lastsearch_values=1') !== false, 'Inventory banner navigation must preserve the previous list search.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'kps-save-state') !== false, 'The inventory page must not render a separate saved-status block.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'width:160px!important') !== false, 'Category actions must have one exact shared width.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'height:38px!important') !== false, 'Category and detail actions must use an exact shared height.');
assertSameValue(false, strpos((string) $inventoryPageSource, '.kps-inventory-action{width:') !== false, 'Inventory detail action widths must remain automatic.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'font-family:inherit!important;font-size:inherit!important;font-weight:bold!important') !== false, 'Inventory detail actions must share the exact same typography.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'margin-bottom:24px') !== false, 'Product search must be visually separated from the inventory table.');
assertSameValue(true, strpos((string) $inventoryPageSource, '<div class="pagination paginationref">') !== false, 'The category selector must use the native Dolibarr pagination return control.');
assertSameValue(true, strpos((string) $inventoryPageSource, "dol_get_fiche_head(\$categoryHead, 'categories'") !== false, 'The category selector must use the native one-tab Dolibarr fiche bar.');
assertSameValue(true, strpos((string) $inventoryPageSource, "KREAPRODUCTS_INVENTORY_VIRTUAL_STOCK") !== false, 'Inventory lines must display their virtual stock snapshot.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'data-kps-absolute-deviation') !== false, 'Inventory lines must display absolute deviation.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'data-kps-relative-deviation') !== false, 'Inventory lines must display relative deviation.');
assertSameValue(true, strpos((string) $mobileInventorySource, "if (\$canViewInventoryAnalysis) {\n\t\t\t\t\$line['expected_quantity']") !== false, 'Inventory responses must expose expected stock only to authorized analysis users.');
assertSameValue(true, strpos((string) $mobileInventorySource, "'can_view_analysis' => \$canViewInventoryAnalysis ? 1 : 0") !== false, 'Inventory responses must state whether analysis data is authorized.');
$statisticsMethodStart = strpos((string) $mobileInventorySource, 'public function getInventoryStatistics');
$statisticsMethodEnd = strpos((string) $mobileInventorySource, 'public function listInventories', $statisticsMethodStart);
assertSameValue(true, $statisticsMethodStart !== false && $statisticsMethodEnd !== false, 'Inventory statistics service scope could not be resolved.');
$statisticsMethodSource = substr((string) $mobileInventorySource, $statisticsMethodStart, $statisticsMethodEnd - $statisticsMethodStart);
assertSameValue(true, strpos((string) $statisticsMethodSource, 'if (!$this->canViewInventoryAnalysis())') !== false, 'The statistics service must reject unauthorized direct calls.');
assertSameValue(true, strpos((string) $statisticsMethodSource, 'KreaProductsInventoryLedgerCalculator::excludedMovementOrigins()') !== false, 'Inventory statistics must exclude correction and reversal movements.');
assertSameValue(true, strpos((string) $statisticsMethodSource, 'EXISTS (SELECT 1 FROM') !== false, 'Inventory statistics must restrict movement aggregation to products in the selected inventory.');
assertSameValue(true, strpos((string) $statisticsMethodSource, "if (\$value < 0)") !== false, 'Negative operational movements must be reported as consumption.');
assertSameValue(true, strpos((string) $statisticsMethodSource, "elseif (\$value > 0)") !== false, 'Positive operational movements must be reported as intake.');
assertSameValue(true, strpos((string) $statisticsMethodSource, "\$products[\$productId]['daily'][\$dayKey]['consumption']") !== false, 'Daily consumption must be accumulated separately for each product.');
assertSameValue(true, strpos((string) $statisticsMethodSource, "\$products[\$productId]['daily'][\$dayKey]['intake']") !== false, 'Daily intake must be accumulated separately for each product.');

$inventoryJavascriptSource = file_get_contents(__DIR__.'/../js/kreaproducts_inventory.js');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, 'beforeunload') !== false, 'The inventory page must warn before abandoning unsaved counts.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, 'kps-product-search') !== false, 'The inventory page must dynamically filter products.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, "document.querySelector('[data-kps-value-date]')") !== false, 'Value-date changes must enable the shared Save action.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, 'absolute / Math.abs(expected)') !== false, 'Relative deviation must use the absolute virtual-stock denominator.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, "Math.abs(expected) < 0.0001") !== false, 'Relative deviation must remain undefined when virtual stock is zero.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, 'progress && inputs.length > 0') !== false, 'Read-only recorded inventories must preserve their server-rendered count progress.');
assertSameValue(true, strpos((string) $inventoryJavascriptSource, '[data-kps-statistics-product]') !== false && strpos((string) $inventoryJavascriptSource, 'statisticsProduct.form.submit()') !== false, 'The product selector must load the selected product graph.');

$inventoryStockOverviewSource = file_get_contents(__DIR__.'/../inventory_stock_overview.php');
assertSameValue(true, strpos((string) $inventoryStockOverviewSource, '$service->getInventoryStockOverview()') !== false, 'The inventory stock overview page must delegate calculations to the shared service.');
assertSameValue(true, strpos((string) $inventoryStockOverviewSource, "foreach (\$overview['categories'] as \$category)") !== false, 'The inventory stock overview must render one table for each category.');
assertSameValue(true, strpos((string) $inventoryStockOverviewSource, "dol_buildpath('/product/card.php', 1)") !== false, 'Inventory stock overview references must link to product cards.');
assertSameValue(false, strpos((string) $inventoryStockOverviewSource, 'stock_mouvement') !== false, 'The inventory stock overview page must not implement a parallel stock calculation.');
assertSameValue(true, strpos((string) $mobileInventorySource, "public function getInventoryStockOverview()") !== false, 'The shared service must expose the inventory stock overview.');
assertSameValue(true, strpos((string) $mobileInventorySource, "\$product->load_stock('nobatch')") !== false, 'The stock overview must use Dolibarr native virtual-stock calculation.');
assertSameValue(true, strpos((string) $mobileInventorySource, "c.entity IN ('.getEntity('category').')") !== false, 'Inventory stock categories must respect the Dolibarr category entity scope.');

$inventoryListSource = file_get_contents(__DIR__.'/../inventory_list.php');
assertSameValue(true, strpos((string) $inventoryListSource, "\$object->fields['date_inventory']['type'] = 'date'") !== false, 'The Dolibarr inventory list must display the value date without time.');
assertSameValue(false, strpos((string) $mobileAppSource, 'formatDateTime(item.date_inventory') !== false, 'The mobile inventory list must not display the value-date ordering time.');
assertSameValue(true, strpos((string) $mobileAppSource, 'formatDate(item.date_inventory || item.date_creation)') !== false, 'The mobile inventory list must display the value date as a calendar date.');
assertSameValue(true, strpos((string) $mobileAppSource, 'Data valor') !== false, 'The mobile initiated inventory must expose the concise value-date label.');
assertSameValue(true, strpos((string) $mobileAppSource, 'inventory.can_edit_value_date === 1 ? valueDate : undefined') !== false, 'The mobile app must submit editable value dates with counts.');
assertSameValue(true, strpos((string) $mobileAppSource, 'JSON.stringify({ counts, valueDate })') !== false, 'Offline drafts must preserve the selected value date with counts.');

$productionApiSource = file_get_contents(__DIR__.'/../class/api_kreaproducts.class.php');
$supplierValidationStart = strpos((string) $productionApiSource, 'public function postSupplierInvoiceValidate');
$supplierValidationEnd = strpos((string) $productionApiSource, 'public function getPurchasePrices', $supplierValidationStart);
assertSameValue(true, $supplierValidationStart !== false && $supplierValidationEnd !== false, 'Supplier invoice validation API test scope could not be resolved.');
$supplierValidationSource = substr((string) $productionApiSource, $supplierValidationStart, $supplierValidationEnd - $supplierValidationStart);
assertSameValue(true, strpos((string) $productionApiSource, '@url POST supplier-invoices/{id}/validate') !== false, 'Supplier invoice validation must expose the dedicated KreaProducts endpoint.');
assertSameValue(true, strpos((string) $supplierValidationSource, "array_key_exists('notrigger', \$request_data)") !== false, 'Supplier invoice validation must reject trigger suppression.');
assertSameValue(true, strpos((string) $supplierValidationSource, "\$invoice->validate(DolibarrApiAccess::\$user, '', \$warehouseId, 0)") !== false, 'Supplier invoice validation must call the native lifecycle with triggers enabled.');
assertSameValue(true, strpos((string) $supplierValidationSource, '$this->db->begin()') < strpos((string) $supplierValidationSource, '$invoice->validate('), 'Supplier invoice validation must open an outer transaction before the native lifecycle call.');
assertSameValue(true, strpos((string) $supplierValidationSource, "if (!\$this->db->commit())") > strpos((string) $supplierValidationSource, '$invoice->validate('), 'Supplier invoice validation must verify the outer transaction commit.');
assertSameValue(true, strpos((string) $supplierValidationSource, 'lockSupplierInvoiceForValidation($invoiceId)') < strpos((string) $supplierValidationSource, '$invoice->validate('), 'Supplier invoice validation must lock the invoice before running the native lifecycle.');
assertSameValue(true, strpos((string) $supplierValidationSource, 'supplierInvoiceRequiresStockWarehouse($invoice)') !== false, 'Supplier invoice validation must enforce the native web-interface warehouse requirement.');
assertSameValue(true, strpos((string) $productionApiSource, "getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE')") !== false, 'Supplier invoice validation must fall back to the current entity default warehouse.');
assertSameValue(true, strpos((string) $supplierValidationSource, 'resolveSupplierInvoiceWarehouseId($requestedWarehouseId, $requiresStockWarehouse)') !== false, 'Supplier invoice validation must resolve its warehouse before calling the native lifecycle.');
assertSameValue(true, strpos((string) $productionApiSource, "hasRight('fournisseur', 'supplier_invoice_advance', 'validate')") !== false, 'Supplier invoice validation must enforce Dolibarr advanced validation rights.');
assertSameValue(true, strpos((string) $productionApiSource, "getEntity('supplier_invoice')") !== false, 'Supplier invoice validation locking must respect the invoice entity scope.');
$supplierBatchStart = strpos((string) $productionApiSource, 'public function postSupplierInvoicesValidateBySupplier');
$supplierBatchEnd = strpos((string) $productionApiSource, 'public function getPurchasePrices', $supplierBatchStart);
assertSameValue(true, $supplierBatchStart !== false && $supplierBatchEnd !== false, 'Supplier-wide invoice validation API test scope could not be resolved.');
$supplierBatchSource = substr((string) $productionApiSource, $supplierBatchStart, $supplierBatchEnd - $supplierBatchStart);
assertSameValue(true, strpos((string) $productionApiSource, '@url POST suppliers/{supplier_id}/invoices/validate') !== false, 'Supplier-wide validation must expose the dedicated batch endpoint.');
assertSameValue(true, strpos((string) $supplierBatchSource, '$this->postSupplierInvoiceValidate($invoiceId, $request_data)') !== false, 'Supplier-wide validation must reuse the trigger-safe single-invoice lifecycle.');
assertSameValue(true, strpos((string) $supplierBatchSource, "'validated_count' => \$validatedCount") !== false, 'Supplier-wide validation must report validated invoices.');
assertSameValue(true, strpos((string) $supplierBatchSource, "'failed_count' => \$failedCount") !== false, 'Supplier-wide validation must report failed invoices.');
assertSameValue(true, strpos((string) $productionApiSource, "f.fk_statut = ' . FactureFournisseur::STATUS_DRAFT") !== false, 'Supplier-wide validation must select draft invoices only.');
assertSameValue(true, strpos((string) $productionApiSource, "f.entity IN (' . getEntity('supplier_invoice')") !== false, 'Supplier-wide validation must isolate invoice selection by entity scope.');
$productionRunStart = strpos((string) $productionApiSource, 'public function postProductionRun');
$productionRunEnd = strpos((string) $productionApiSource, 'public function getPurchasePrices', $productionRunStart);
assertSameValue(true, $productionRunStart !== false && $productionRunEnd !== false, 'Production API test scope could not be resolved.');
$productionRunSource = substr((string) $productionApiSource, $productionRunStart, $productionRunEnd - $productionRunStart);
assertSameValue(true, strpos((string) $productionApiSource, 'lockMoForProduction((int) $mo->id)') !== false, 'Production posting must serialize concurrent requests for one MO.');
assertSameValue(true, strpos((string) $productionApiSource, 'hasMoExecutionMovements((int) $mo->id)') !== false, 'Production posting must reject an MO with existing execution movements.');
assertSameValue(true, strpos((string) $productionApiSource, 'assertWarehouseAvailableForProduction($warehouseId)') !== false, 'Production warehouses must be validated in the active entity scope.');
assertSameValue(true, strpos((string) $productionApiSource, 'assertProductionThirdpartyAvailable($requestedThirdpartyId)') !== false, 'Production third parties must be validated in the active entity and user scope.');
assertSameValue(true, strpos((string) $productionApiSource, 'assertProductionProjectAvailable($requestedProjectId)') !== false, 'Production projects must be validated in the active entity and user scope.');
assertSameValue(true, strpos((string) $productionRunSource, '$this->db->begin();') < strpos((string) $productionRunSource, '$mo->update(DolibarrApiAccess::$user)'), 'The production transaction must start before an existing MO is mutated.');
assertSameValue(false, strpos((string) $productionApiSource, 'CREATE TABLE IF NOT EXISTS'), 'Production requests must not create database tables.');
assertSameValue(false, strpos((string) $productionApiSource, 'ALTER TABLE '), 'Production requests must not alter database tables.');
assertSameValue(false, strpos((string) $productionApiSource, 'ensureProductionTraceTables'), 'Production API paths must use read-only schema readiness checks.');
assertSameValue(false, strpos((string) $productionApiSource, 'Mo::STATUS_VALIDATED && (int) $mo->status !== Mo::STATUS_INPROGRESS') !== false, 'In-progress MOs must not be reposted by the production endpoint.');

$dismantleSource = file_get_contents(__DIR__.'/../class/productDismantle.class.php');
assertSameValue(true, strpos((string) $dismantleSource, '$movementPrice = (float) $baseCostPrice / $totalProducedQtyPerPackage;') !== false, 'Dismantling must preserve total source valuation across multiple outputs.');
assertSameValue(true, strpos((string) $dismantleSource, '$hasExecutionLines === null') !== false, 'Dismantling must fail closed when execution idempotency cannot be verified.');
assertSameValue(true, strpos((string) $dismantleSource, 'failed to validate existing MO #') !== false && strpos((string) $dismantleSource, 'return -1;') !== false, 'Dismantling must abort when its MO cannot be validated.');
assertSameValue(true, strpos((string) $dismantleSource, 'refreshDismantleOutputCostPrices') !== false, 'Manual cost changes must use the shared dismantling valuation service.');
assertSameValue(true, strpos((string) $dismantleSource, '$unitCost = $baseCostPrice / $totalProducedQty;') !== false, 'Manual dismantling valuation must assign one common unit cost across every output.');
assertSameValue(true, strpos((string) $dismantleSource, "'qty'         => (float) \$line->qty") !== false, 'Dismantling movements must use the BOM output quantities for one consumed package.');
assertSameValue(false, strpos((string) $dismantleSource, '((float) $line->qty) / $headerQty') !== false, 'Dismantling must not normalize package outputs back to a total quantity of one.');
assertSameValue(true, strpos((string) $dismantleSource, 'isProductAvailable((int) $productId)') !== false, 'Dismantling cost updates must validate every output in the active product entity scope.');
assertSameValue(false, strpos((string) $dismantleSource, 'forcing SQL') !== false, 'Dismantling cost updates must not fall back to direct SQL persistence.');
$stockServiceSource = file_get_contents(__DIR__.'/../class/KreaProductsStockMovementService.class.php');
assertSameValue(true, strpos((string) $stockServiceSource, '$this->dismantleIfNeeded($move, $db, $user) < 0') !== false, 'Dismantling failures must abort the source stock transaction.');

$traceMigrationSource = file_get_contents(__DIR__.'/../sql/llx_kreaproducts_mo_batchtrace_upgrade.sql');
assertSameValue(true, strpos((string) $traceMigrationSource, 'information_schema.COLUMNS') !== false, 'Production trace upgrades must run through an idempotent activation migration.');

assertSameValue(true, strpos((string) $mobileInventorySource, '$this->canCount() && $this->isCurrentBusinessDayInventory($closedRecord)') !== false, 'Post-close correction flags must remain limited to the current business day.');

$moduleDescriptorSource = file_get_contents(__DIR__.'/../core/modules/modKreaProducts.class.php');
assertSameValue(true, strpos((string) $moduleDescriptorSource, "'method' => 'closeDueInventories'") !== false, 'The automatic inventory closure cron job must be registered.');
$inventoryCronSource = file_get_contents(__DIR__.'/../class/KreaProductsInventoryCron.class.php');
assertSameValue(true, strpos((string) $inventoryCronSource, 'closeDueInventoriesAsScheduler(dol_now())') !== false, 'The inventory cron wrapper must use the administrator-only scheduler entry point.');
assertSameValue(true, strpos((string) $inventoryCronSource, 'resolveSchedulerUser()') !== false, 'The inventory cron wrapper must resolve an audited administrator instead of relying on the unavailable global web user.');
assertSameValue(true, strpos((string) $inventoryCronSource, "u.entity IN (0, '") !== false, 'The scheduled audit user must be limited to the current or shared entity.');

$productUpdaterSource = file_get_contents(__DIR__.'/../class/ProductUpdater.class.php');
assertSameValue(true, strpos((string) $productUpdaterSource, 'WHERE b.bomtype = 0 AND b.status = 1') !== false, 'Cost cascades must use manufacturing BOMs only.');
assertSameValue(false, strpos((string) $productUpdaterSource, 'WHERE b.bomtype IN (0,1)') !== false, 'Cost cascades must not merge manufacturing and dismantling BOMs.');
assertSameValue(false, strpos((string) $productUpdaterSource, 'b3.rowid <> b.rowid') !== false, 'Multiple active manufacturing BOMs must not stop automatic cost cascades.');
assertSameValue(true, strpos((string) $productUpdaterSource, "mp.role = 'produced'") !== false, 'Multiple active manufacturing BOMs must be ranked by completed production history.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'Mo::STATUS_CANCELED') !== false, 'Cancelled manufacturing orders must not select a cost-cascade BOM.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'selectPreferredBomId($candidates, $latestProductionByBomId)') !== false, 'Manufacturing BOM selection must remain fully automatic.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'normalizeLineQuantity(') !== false, 'Cost cascades must normalize BOM line efficiency and header quantity.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'public static function prepareProductCostUpdate(Product $product): void') !== false, 'Product cost writers must share one Dolibarr oldcopy preparation method.');
assertSameValue(true, strpos((string) $productUpdaterSource, '$product->oldcopy = dol_clone($product, 1);') !== false, 'Product cost updates must preserve the database-backed product snapshot.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'self::prepareProductCostUpdate($product);') !== false, 'Manufacturing BOM cost persistence must preserve oldcopy before changing cost_price.');
$impactedMapStart = strpos((string) $productUpdaterSource, 'private static function loadImpactedProductMap');
$impactedMapEnd = strpos((string) $productUpdaterSource, 'private static function validateProductMapIsAcyclic', $impactedMapStart);
$impactedMapSource = substr((string) $productUpdaterSource, $impactedMapStart, $impactedMapEnd - $impactedMapStart);
assertSameValue(true, strpos((string) $impactedMapSource, 'loadProductAssociations(null, $lookupIds, true)') !== false, 'Cost cascades must include association recipes as dependent-product fallbacks.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'active_bom.fk_product = pa.fk_product_pere') !== false, 'Active manufacturing BOMs must take precedence over association recipes.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'if (!empty($originalProductIds[$currentProductId]))') !== false, 'Changed source products must not be recalculated over their persisted costs.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'self::$sourceProductIds[(int) $child[\'id\']]') !== false, 'Changed source costs must remain authoritative during recursive dependent calculations.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'validateProductMapIsAcyclic()') !== false, 'Cost cascades must validate the full product graph before product writes.');
assertSameValue(true, strpos((string) $productUpdaterSource, "throw new RuntimeException('Cyclic product cost graph detected") !== false, 'Recursive cost calculation must fail closed on an association or BOM cycle.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'if (!empty(self::getLastErrors()))') !== false, 'The legacy ProductHierarchy entry point must propagate cascade failures.');
assertSameValue(true, strpos((string) $productUpdaterSource, 'catch (Throwable $exception)') !== false, 'Cost calculation exceptions must be converted into deterministic cascade errors.');

$purchasePriceSource = file_get_contents(__DIR__.'/../purchasePrice.php');
assertSameValue(false, strpos((string) $purchasePriceSource, 'function kreaUpdateDismantleBomChildren') !== false, 'The unsafe standalone dismantling helper must remain removed.');
assertSameValue(true, substr_count((string) $purchasePriceSource, 'refreshDismantleOutputCostPrices') >= 2, 'Manual parent-cost actions must use the shared dismantling valuation service.');
assertSameValue(true, substr_count((string) $purchasePriceSource, '$db->begin();') >= 3, 'Manual product and supplier-price writes must retain transaction boundaries.');
assertSameValue(true, strpos((string) $purchasePriceSource, "trans('KreapCostPriceUpdateFailed')") !== false, 'Manual cost failures must expose a generic translated error.');
assertSameValue(true, strpos((string) $purchasePriceSource, 'ProductUpdater::prepareProductCostUpdate($object);') !== false, 'Manual cost changes must preserve oldcopy before changing cost_price.');

assertSameValue(true, strpos((string) $dismantleSource, 'ProductUpdater::prepareProductCostUpdate($product);') !== false, 'Dismantling cost persistence must preserve oldcopy before changing cost_price.');

$productViewerSource = file_get_contents(__DIR__.'/../class/ProductViewer.class.php');
assertSameValue(true, strpos((string) $productViewerSource, 'ProductUpdater::prepareProductCostUpdate($prod);') !== false, 'Legacy product hierarchy cost persistence must preserve oldcopy before changing cost_price.');

$triggerSource = file_get_contents(__DIR__.'/../core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php');
assertSameValue(true, strpos((string) $triggerSource, 'ProductUpdater::prepareProductCostUpdate($product);') !== false, 'Supplier-invoice cost persistence must preserve oldcopy before changing cost_price.');

$reverseStart = strpos((string) $mobileInventorySource, 'public function reverseInventory');
$reverseEnd = strpos((string) $mobileInventorySource, 'private function requireInventoryValueDatingEnabled', $reverseStart);
$reverseSource = substr((string) $mobileInventorySource, $reverseStart, $reverseEnd - $reverseStart);
assertSameValue(true, strpos((string) $reverseSource, "inventory as i") < strpos((string) $reverseSource, 'kreaproducts_inventory_adjustment as a'), 'Inventory reversal must lock the header before adjustment rows.');
assertSameValue(true, strpos((string) $reverseSource, 'FOR UPDATE') !== false, 'Inventory reversal must lock its header row.');

$bomDismantleSource = file_get_contents(__DIR__.'/../ajax/bom_dismantle.php');
assertSameValue(true, strpos((string) $bomDismantleSource, 'if (empty($token) ||') !== false, 'The BOM helper must reject a missing CSRF token.');
$associationsToBomSource = file_get_contents(__DIR__.'/../associations_to_bom.php');
assertSameValue(true, strpos((string) $associationsToBomSource, "define('CSRFCHECK_WITH_TOKEN', 1)") !== false, 'Association-to-BOM writes must force Dolibarr CSRF validation.');
assertSameValue(true, strpos((string) $associationsToBomSource, "REQUEST_METHOD'] ?? 'GET'") !== false, 'Association-to-BOM writes must enforce POST.');
assertSameValue(true, strpos((string) $associationsToBomSource, 'hash_equals((string) currentToken()') !== false, 'Association-to-BOM writes must validate the submitted token explicitly.');

$mobileControllerSource = file_get_contents(__DIR__.'/../stock_mobile.php');
assertSameValue(true, strpos((string) $mobileControllerSource, '$mobileMutationActions = array(') !== false, 'Mobile mutations must be centrally enumerated.');
assertSameValue(true, strpos((string) $mobileControllerSource, 'in_array($requestedAction, $mobileMutationActions, true)') !== false, 'Every mobile mutation must pass the central CSRF gate.');
assertSameValue(true, substr_count((string) $mobileControllerSource, 'catch (Throwable $e)') >= 3, 'Mobile and OAuth boundaries must catch every throwable.');
assertSameValue(false, strpos((string) $mobileControllerSource, "catch (Throwable \$e) {\n\t\t\$_SESSION['dol_loginmesg'] = \$e->getMessage()") !== false, 'OAuth failures must not expose raw exception details.');
assertSameValue(true, substr_count((string) $mobileControllerSource, 'if ($e->httpCode >= 500)') >= 4, 'Mobile business exceptions must sanitize every internal server failure boundary.');
assertSameValue(true, strpos((string) $mobileControllerSource, "respondError(\$langs->trans('KreaProductsStockUnexpectedError'), \$e->httpCode)") !== false, 'Mobile API server errors must expose only the generic translated response.');

$productLabelsSource = file_get_contents(__DIR__.'/../product_labels.php');
assertSameValue(false, strpos((string) $productLabelsSource, 'ALTER TABLE') !== false, 'Product label requests must never alter the database schema.');
$restApiSource = file_get_contents(__DIR__.'/../class/api_kreaproducts.class.php');
assertSameValue(true, strpos((string) $restApiSource, 'protected function failInternalRequest') !== false, 'Internal REST failures must use the centralized logging boundary.');
assertSameValue(false, preg_match('/throw new RestException\\([^;\\n]*(?:lasterror|getMessage|->error)/', (string) $restApiSource) === 1, 'REST responses must not interpolate raw database, object, or exception errors.');
$labelPdfStart = strpos((string) $restApiSource, 'public function postProductionLabelPdf');
$labelPdfEnd = strpos((string) $restApiSource, 'public function postProductionLabelTspl', $labelPdfStart);
$labelPdfSource = substr((string) $restApiSource, $labelPdfStart, $labelPdfEnd - $labelPdfStart);
$labelTsplStart = $labelPdfEnd;
$labelTsplEnd = strpos((string) $restApiSource, 'public function getProductionLabelTspl', $labelTsplStart);
$labelTsplSource = substr((string) $restApiSource, $labelTsplStart, $labelTsplEnd - $labelTsplStart);
assertSameValue(false, strpos((string) $labelPdfSource, "Failed to generate labels PDF: ' . \$ex->getMessage()") !== false, 'Label PDF REST errors must not expose exception details.');
assertSameValue(false, strpos((string) $labelTsplSource, "Failed to generate labels TSPL: ' . \$ex->getMessage()") !== false, 'Label TSPL REST errors must not expose exception details.');

$setupSyncPath = __DIR__.'/../setup_sync_columns.php';
assertSameValue(false, file_exists($setupSyncPath), 'The obsolete core product-association schema mutator must not be shipped.');
$releaseBuilderSource = file_get_contents(__DIR__.'/../build/build-release.sh');
assertSameValue(true, strpos((string) $releaseBuilderSource, 'done < "$allowlist"') !== false, 'The release builder must consume the production allowlist.');
assertSameValue(false, strpos((string) $releaseBuilderSource, 'zip -q -r "$temporary" kreaproducts') !== false, 'The release builder must not package the whole repository with exclusions.');
assertSameValue(true, strpos((string) $moduleDescriptorSource, '$this->phpmin = array(7, 3)') !== false, 'The descriptor must declare the real PHP 7.3 minimum.');

$allergenNumberingSource = file_get_contents(__DIR__.'/../class/productallergens.class.php');
$nutritionalNumberingSource = file_get_contents(__DIR__.'/../class/nutritional.class.php');
assertSameValue(true, strpos((string) $allergenNumberingSource, 'KREAPRODUCTS_PRODUCTALLERGENS_ADDON') !== false, 'Allergen numbering must use its dedicated module constant.');
assertSameValue(true, strpos((string) $nutritionalNumberingSource, 'KREAPRODUCTS_NUTRITIONAL_ADDON') !== false, 'Nutritional numbering must use its dedicated module constant.');

$allergenIndexMigration = file_get_contents(__DIR__.'/../sql/llx_kreaproducts_productallergens_upgrade.sql');
assertSameValue(true, strpos((string) $allergenIndexMigration, 'idx_kreaproducts_productallergens_fk_product') !== false, 'The allergen product lookup index migration must be installed.');

$inventoryRunnerSource = file_get_contents(__DIR__.'/../scripts/run_inventory_auto_close.php');
assertSameValue(true, strpos((string) $inventoryRunnerSource, "c.objectname = 'KreaProductsInventoryCron'") !== false, 'The isolated runner must select only KreaProducts inventory jobs.');

$mobilePackage = json_decode((string) file_get_contents(__DIR__.'/../stockapp/package.json'), true);
$mobilePackageLock = json_decode((string) file_get_contents(__DIR__.'/../stockapp/package-lock.json'), true);
assertSameValue('4.7.0', $mobilePackage['version'] ?? '', 'The mobile package version must match the module release.');
assertSameValue('4.7.0', $mobilePackageLock['version'] ?? '', 'The mobile lockfile version must match the module release.');
assertSameValue('4.7.0', $mobilePackageLock['packages']['']['version'] ?? '', 'The mobile lockfile root package must match the module release.');

$dismantleSource = file_get_contents(__DIR__.'/../class/productDismantle.class.php');
assertSameValue(true, strpos((string) $dismantleSource, 'createDismantleStockMovement') !== false, 'Dismantling must use its dedicated stock movement boundary.');
assertSameValue(true, strpos((string) $dismantleSource, 'PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE') !== false, 'Kit-parent dismantling outputs must use the scoped parent-movement compatibility boundary.');
assertSameValue(true, strpos((string) $dismantleSource, 'ensureProductStockManagedForMo') !== false, 'Dismantling must enable stock management for every MO product.');
assertSameValue(true, strpos((string) $dismantleSource, 'Product::ENABLED_STOCK') !== false, 'MO product stock management must use the native Dolibarr product constant.');
assertSameValue(false, strpos((string) $dismantleSource, 'mp.fk_stock_movement IS NULL AND mp.fk_warehouse IS NULL') !== false, 'MO execution lines must not bypass stock movements.');

print "Stock logic tests passed.\n";
