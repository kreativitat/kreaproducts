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
require_once __DIR__.'/../class/KreaProductsProductionQuantityValidator.class.php';
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
assertSameValue(0, kreaproducts_resolve_nutrition_allergen_mode(null, null, 1), 'Empty legacy modes must resolve to the manual mode displayed by the product workspace.');
assertSameValue(0, kreaproducts_resolve_nutrition_allergen_mode('', '0', 1), 'Empty and explicit manual legacy modes must remain manual.');
assertSameValue(1, kreaproducts_resolve_nutrition_allergen_mode('1', '0', 1), 'Mixed calculated/manual modes must fail closed as calculated.');
assertSameValue(2, kreaproducts_resolve_nutrition_allergen_mode('0', '2', 1), 'A non-food mode must take precedence over manual mode.');
assertSameValue(2, kreaproducts_resolve_nutrition_allergen_mode('0', '0', 0), 'A non-food product must never resolve to manual mode.');
assertSameValue(2, kreaproducts_resolve_nutrition_allergen_mode('invalid', '0', 1), 'Invalid stored modes must fail closed.');
assertSameValue(
	"First line\nSecond\n- Third",
	kreaproducts_normalize_plain_text('<p>First&nbsp;line<br>Second</p><ul><li>Third</li></ul><script>ignored()</script>'),
	'Legacy rich text must normalize to predictable plain text while preserving meaningful line breaks.'
);
assertSameValue(
	"## Preparation\n\nMix **well** and *serve*.\n\n- First\n- [Second](https://example.com/item)",
	kreaproducts_normalize_markdown('<h2>Preparation</h2><p>Mix <strong>well</strong> and <em>serve</em>.</p><ul><li>First</li><li><a href="https://example.com/item">Second</a></li></ul><script>ignored()</script>'),
	'Legacy database HTML must be converted into Markdown before it reaches the editor or renderer.'
);
$legacyRecipeHtml = "CHAPA\r\n<ul>\r\n<li>140G PATTY DE CARNE</li>\r\n<li>QJ EDAM - 1 FATIA</li>\r\n</ul>\r\n<br />\r\nMONTAGEM\r\n<ul>\r\n<li>RUCULA</li>\r\n<li>TOMATE - 1 FATIA</li>\r\n</ul>";
$legacyRecipeMarkdown = "CHAPA\n\n- 140G PATTY DE CARNE\n\n- QJ EDAM - 1 FATIA\n\nMONTAGEM\n\n- RUCULA\n\n- TOMATE - 1 FATIA";
assertSameValue($legacyRecipeMarkdown, kreaproducts_normalize_markdown($legacyRecipeHtml), 'Raw recipe HTML must import as editable Markdown.');
assertSameValue($legacyRecipeMarkdown, kreaproducts_normalize_markdown(htmlspecialchars($legacyRecipeHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'Entity-encoded recipe HTML must import as editable Markdown.');
assertSameValue($legacyRecipeMarkdown, kreaproducts_normalize_markdown(htmlspecialchars(htmlspecialchars($legacyRecipeHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'Double-encoded recipe HTML must import as editable Markdown.');
$importedCharacteristics = kreaproducts_import_characteristic_database_values(
	array(
		'options_kreap_brand' => '<strong>Brand</strong>',
		'options_kreap_recipe' => $legacyRecipeHtml,
	),
	array(
		'options_kreap_brand' => array('type' => 'text'),
		'options_kreap_recipe' => array('type' => 'textarea', 'format' => 'markdown'),
	)
);
assertSameValue('Brand', $importedCharacteristics['options_kreap_brand'], 'Plain characteristic database values must not retain HTML.');
assertSameValue($legacyRecipeMarkdown, $importedCharacteristics['options_kreap_recipe'], 'The database import boundary must provide Markdown to the editor.');
assertSameValue(
	'**Already Markdown**',
	kreaproducts_normalize_markdown('**Already Markdown**'),
	'Existing Markdown must remain unchanged.'
);
assertSameValue(
	'Unsupported markup',
	kreaproducts_normalize_markdown('<table><tr><td>Unsupported markup</td></tr></table><img src="javascript:alert(1)">'),
	'Unsupported legacy HTML must be removed instead of reaching Markdown storage.'
);
assertSameValue(
	'<https://example.com>',
	kreaproducts_normalize_markdown('<https://example.com>'),
	'Markdown autolinks must not be mistaken for legacy HTML.'
);
assertSameValue(true, kreaproducts_is_http_url('https://www.example.com/video?id=10'), 'HTTPS video URLs must be accepted.');
assertSameValue(false, kreaproducts_is_http_url('javascript:alert(1)'), 'Non-HTTP video URLs must be rejected.');

$timezone = new DateTimeZone('Europe/Lisbon');
$businessDay = new KreaProductsBusinessDayService();

$entry = new DateTimeImmutable('2026-07-12 01:30:00', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '10:30', '20:00');
assertSameValue('2026-07-12 10:30:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Early-morning count window failed.');
$singleDigitValueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '8:30', '20:00');
assertSameValue('2026-07-12 08:30:00', (new DateTimeImmutable('@'.$singleDigitValueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Single-digit inventory hours must normalize safely.');

$entry = new DateTimeImmutable('2026-07-12 19:59:59', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '10:30', '20:00');
assertSameValue('2026-07-12 10:30:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Pre-cutoff count window failed.');
assertSameValue(0, $businessDay->resolvePostCutoffMinimumValueTimestamp($entry->getTimestamp(), $timezone, '10:30', '20:00'), 'A pre-cutoff inventory must not receive a mandatory next-day minimum.');

$entry = new DateTimeImmutable('2026-07-12 20:00:00', $timezone);
$valueTimestamp = $businessDay->resolveInventoryValueTimestamp($entry->getTimestamp(), $timezone, '10:30', '20:00');
assertSameValue('2026-07-13 10:30:00', (new DateTimeImmutable('@'.$valueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Cutoff boundary failed.');
$minimumPostCutoffTimestamp = $businessDay->resolvePostCutoffMinimumValueTimestamp($entry->getTimestamp(), $timezone, '10:30', '20:00');
assertSameValue('2026-07-13 10:30:00', (new DateTimeImmutable('@'.$minimumPostCutoffTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'A cutoff-time inventory must require the next calendar value date.');

$entry = new DateTimeImmutable('2026-07-12 21:00:00', $timezone);
$minimumPostCutoffTimestamp = $businessDay->resolvePostCutoffMinimumValueTimestamp($entry->getTimestamp(), $timezone, '8:30', '20:00');
assertSameValue('2026-07-13 08:30:00', (new DateTimeImmutable('@'.$minimumPostCutoffTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'A post-cutoff inventory must require the next day at the configured inventory time.');

$supplierTimestamp = $businessDay->resolveDateTimestamp('2026-07-12', $timezone, '10:00');
assertSameValue('2026-07-12 10:00:00', (new DateTimeImmutable('@'.$supplierTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Supplier time normalization failed.');
$singleDigitSupplierTimestamp = $businessDay->resolveDateTimestamp('2026-07-12', $timezone, '9:30');
assertSameValue('2026-07-12 09:30:00', (new DateTimeImmutable('@'.$singleDigitSupplierTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Single-digit supplier invoice hours must normalize safely.');
$editableValueTimestamp = $businessDay->resolveDateTimestamp('2026-07-11', $timezone, '10:30');
assertSameValue('2026-07-11 10:30:00', (new DateTimeImmutable('@'.$editableValueTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Editable value-date anchor failed.');
$billingCloseTimestamp = $businessDay->resolveDateTimestamp('2026-07-11', $timezone, '06:00');
assertSameValue(84.0, KreaProductsInventoryLedgerCalculator::quantityAtTimestampFromAnchor(40, -44, $billingCloseTimestamp, $editableValueTimestamp), 'Billing-close stock before the inventory anchor failed.');
assertSameValue(37.0, KreaProductsInventoryLedgerCalculator::quantityAtTimestampFromAnchor(40, -3, $editableValueTimestamp, $billingCloseTimestamp), 'Billing-close stock after the inventory anchor failed.');
assertSameValue(40.0, KreaProductsInventoryLedgerCalculator::quantityAtTimestampFromAnchor(40, -3, $editableValueTimestamp, $editableValueTimestamp), 'Equal billing-close and inventory anchors must preserve stock.');
$autoCloseTimestamp = $businessDay->resolveInventoryAutoCloseTimestamp($editableValueTimestamp, $timezone, '19:45');
assertSameValue('2026-07-11 19:45:00', (new DateTimeImmutable('@'.$autoCloseTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Automatic inventory closure threshold failed.');
$configuredAutoCloseTimestamp = $businessDay->resolveInventoryAutoCloseTimestamp($editableValueTimestamp, $timezone, '18:35');
assertSameValue('2026-07-11 18:35:00', (new DateTimeImmutable('@'.$configuredAutoCloseTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'), 'Automatic inventory closure must use its independent configured time.');

$lockWindow = $businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 19:44:59', $timezone))->getTimestamp(), $timezone, '19:45', '20:00');
assertSameValue(false, $lockWindow['active'], 'Inventory mutations must remain open immediately before the configured lock start.');
$lockWindow = $businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 19:45:00', $timezone))->getTimestamp(), $timezone, '19:45', '20:00');
assertSameValue(true, $lockWindow['active'], 'The configured automatic closure time must start the read-only interval.');
$lockWindow = $businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 19:59:59', $timezone))->getTimestamp(), $timezone, '19:45', '20:00');
assertSameValue(true, $lockWindow['active'], 'Inventory mutations must remain locked until the configured cutoff.');
$lockWindow = $businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 20:00:00', $timezone))->getTimestamp(), $timezone, '19:45', '20:00');
assertSameValue(false, $lockWindow['active'], 'The configured cutoff must reopen inventory mutations for the next counting window.');
$customLockWindow = $businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 18:40:00', $timezone))->getTimestamp(), $timezone, '18:35', '19:10');
assertSameValue(true, $customLockWindow['active'], 'The read-only interval must use both setup times rather than fixed clock values.');
try {
	$businessDay->resolveInventoryMutationLockWindow((new DateTimeImmutable('2026-07-11 18:40:00', $timezone))->getTimestamp(), $timezone, '20:00', '20:00');
	throw new RuntimeException('Equal automatic closure and cutoff times must be rejected.');
} catch (InvalidArgumentException $exception) {
	assertSameValue(true, strpos($exception->getMessage(), 'earlier than') !== false, 'Invalid configured inventory times must fail closed.');
}

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
assertSameValue(true, in_array('kreaproducts_inventory_reinstatement', $origins, true), 'Inventory reinstatement origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_inventory_rebase', $origins, true), 'Inventory rebase origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_inventory_rebase_reversal', $origins, true), 'Inventory rebase reversal origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_count_correction', $origins, true), 'Count correction origin must be excluded.');
assertSameValue(true, in_array('kreaproducts_count_correction_reversal', $origins, true), 'Count correction reversal origin must be excluded.');
assertSameValue(false, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, false, 1), 'Kit parent without operational parent movements must not be counted independently.');
assertSameValue(true, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, true, 1), 'Kit parent with operational parent movements may be counted independently.');
assertSameValue(true, KreaProductsInventoryLedgerCalculator::isIndependentStockItem(true, false, 0), 'Simple product must remain countable.');
assertSameValue(true, KreaProductsProductionQuantityValidator::matchesRecipeQuantity('12.50000000', 12.5), 'Equivalent normalized recipe quantities must be accepted.');
assertSameValue(false, KreaProductsProductionQuantityValidator::matchesRecipeQuantity(12.5, 0), 'A zero component override must not satisfy a positive recipe quantity.');
assertSameValue(false, KreaProductsProductionQuantityValidator::matchesRecipeQuantity(12.5, 12.499), 'A partial component override must not satisfy the recipe quantity.');
assertSameValue(false, KreaProductsProductionQuantityValidator::matchesRecipeQuantity(12.5, 13), 'An excess component override must not satisfy the recipe quantity.');
assertSameValue(false, KreaProductsProductionQuantityValidator::matchesRecipeQuantity(12.5, 'invalid'), 'Non-numeric component quantities must fail closed.');

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
assertSameValue(true, substr_count((string) $stockMovementSource, 'AND a.rowid IS NOT NULL))') >= 3, 'A reopened edit draft with an active adjustment must remain a reconstruction anchor.');
assertSameValue(true, substr_count((string) $stockMovementSource, 'THEN a.counted_qty ELSE id.qty_view END AS qty_view') >= 3, 'Reconstruction must retain the active generation count while revised counts are staged.');
assertSameValue(true, strpos((string) $stockMovementSource, '$db->jdate($move->datem)') !== false, 'Dismantling must parse movement SQL datetimes in the server timezone.');
assertSameValue(false, strpos((string) $stockMovementSource, 'dol_stringtotime($move->datem)') !== false, 'Dismantling must not reinterpret server SQL datetimes as GMT.');
assertSameValue(true, strpos((string) $stockMovementSource, "getDolGlobalInt('KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES', 30)") !== false, 'Customer invoice future tolerance must be configurable with a 30-minute fallback.');
assertSameValue(true, strpos((string) $stockMovementSource, 'normalizeConfiguredTime(') !== false, 'Supplier invoice movement dating must share configured-time normalization.');
assertSameValue(true, strpos((string) $stockMovementSource, 'min(1440, max(0, getDolGlobalInt(') !== false, 'Customer invoice future tolerance must remain within the setup safety bounds.');
assertSameValue(true, strpos((string) $stockMovementSource, 'shiftCustomerInvoiceMoveToInvoiceDateTime($move, $db, $conf, true)') !== false, 'Inventory reconstruction must retain the persisted movement date when a future source clock exceeds tolerance.');
assertSameValue(true, strpos((string) $stockMovementSource, 'if (!$this->shiftCustomerInvoiceMoveToInvoiceDateTime($move, $db, $conf))') !== false, 'Live customer movement ingestion must continue to reject future source clocks beyond tolerance.');
assertSameValue(true, substr_count((string) $stockMovementSource, 'a_any.fk_inventorydet=id.rowid') >= 3, 'Inventory anchor queries must distinguish reversed audited inventories from legacy inventory movements.');
assertSameValue(true, substr_count((string) $stockMovementSource, '(a.rowid IS NOT NULL OR (a_any.rowid IS NULL AND sm.rowid IS NOT NULL))') >= 3, 'Reversed audited inventories must not remain eligible as stock anchors.');
assertSameValue(true, strpos((string) $stockMovementSource, '$this->getNextInventoryAnchorAfter($db, $productId, $warehouse, $batch, $invDate)') !== false, 'Backdated inventory handling must search for the next active anchor rather than any immutable inventory movement.');
assertSameValue(false, strpos((string) $stockMovementSource, 'getNextInventoryMovementAfter') !== false, 'Backdated inventory handling must not retain a raw movement-only anchor lookup.');

$productListSource = file_get_contents(__DIR__.'/../product_list.php');
assertSameValue(true, strpos((string) $productListSource, "actions_changeselectedfields.inc.php") !== false, 'The product list must persist native Dolibarr column selections.');
assertSameValue(true, strpos((string) $productListSource, "multiSelectArrayWithCheckbox('selectedfields', \$arrayfields") !== false, 'The product list must expose the native column configurator.');
assertSameValue(true, strpos((string) $productListSource, "'zsbms_entities'") !== false, 'The product list must offer the ZSBMS entities column.');
assertSameValue(true, strpos((string) $productListSource, 'COALESCE(pe.zs_synch, 0) as zs_synch') !== false, 'ZSBMS pills must require the product sync flag.');
assertSameValue(true, strpos((string) $productListSource, 'COALESCE(zsp.restricted, 0) as zsbms_restricted') !== false, 'ZSBMS pills must exclude restricted shadow rows.');
assertSameValue(true, strpos((string) $productListSource, "cstore.name = 'ZS_API_STORE'") !== false, 'ZSBMS store ids must resolve through each entity configuration.');
assertSameValue(true, strpos((string) $productListSource, 'font-size: 0.62em') !== false, 'ZSBMS entity pills must use compact text.');
assertSameValue(true, strpos((string) $productListSource, 'justify-content: flex-start') !== false, 'ZSBMS entity pills must be left aligned.');
assertSameValue(true, strpos((string) $productListSource, 'zsp.retalho_by_store as zsbms_retail_by_store') !== false, 'ZSBMS pill colors must use the per-store availability source.');
assertSameValue(true, strpos((string) $productListSource, 'kreaproducts-zsbms-pill-sale') !== false, 'Venda pills must have their own color class.');
assertSameValue(true, strpos((string) $productListSource, 'kreaproducts-zsbms-pill-backoffice') !== false, 'Backoffice pills must have their own color class.');
assertSameValue(true, strpos((string) $productListSource, 'kreaproducts-zsbms-pill-discontinued') !== false, 'Discontinued pills must have a neutral color class.');
assertSameValue(true, strpos((string) $productListSource, "DoliZSynchRetalhoSoBackOffice") !== false, 'Backoffice pills must expose their translated availability label.');

$inventoryServiceSource = file_get_contents(__DIR__.'/../class/KreaProductsInventoryService.class.php');
assertSameValue(true, strpos((string) $inventoryServiceSource, 'e.entity IN (" . getEntity(\'stock\') . ")') !== false, 'Native inventory prefill must limit warehouses to the active Dolibarr stock entity scope.');
assertSameValue(true, strpos((string) $inventoryServiceSource, 'KreaProductsInventoryLedgerCalculator::excludedMovementOrigins()') !== false, 'Native inventory prefill must exclude every module-owned inventory-ledger origin.');
assertSameValue(true, strpos((string) $inventoryServiceSource, 'if ($movedCache === false)') !== false, 'Native inventory prefill must abort when movement reconstruction fails.');
assertSameValue(false, strpos((string) $inventoryServiceSource, "Error loading stock movements: " . '$db->lasterror(), LOG_ERR);\n\t\t\t\tcontinue;') !== false, 'Native inventory prefill must not convert movement-query failures into zero movement.');

$mobileInventoryServiceSource = file_get_contents(__DIR__.'/../class/KreaProductsMobileInventoryService.class.php');
assertSameValue(true, strpos((string) $mobileInventoryServiceSource, '&& $counted > 0') !== false, 'Execute must become available only after at least one count is saved.');
assertSameValue(true, strpos((string) $mobileInventoryServiceSource, "'can_reverse' => 0") !== false, 'Inventory details must never expose a direct reversal action.');

$inventoryListSource = file_get_contents(__DIR__.'/../inventory_list.php');
assertSameValue(false, strpos((string) $inventoryListSource, 'kps_fully_reversed') !== false, 'The Dolibarr inventory list must not expose an intermediate reversed state.');

$inventoryPageSource = file_get_contents(__DIR__.'/../inventory.php');
assertSameValue(false, strpos((string) $inventoryPageSource, 'reverse_inventory') !== false, 'The Dolibarr inventory page must not expose a direct reversal action.');
assertSameValue(true, strpos((string) $inventoryPageSource, "'confirm_edit'") !== false, 'The Dolibarr inventory page must protect inventory reopening as a confirmed POST action.');
assertSameValue(true, strpos((string) $inventoryPageSource, "action=edit_inventory") !== false, 'The Dolibarr recorded inventory page must expose Edit when authorized.');

$mobileInventoryAppSource = file_get_contents(__DIR__.'/../stockapp/src/App.tsx');
assertSameValue(true, strpos((string) $mobileInventoryAppSource, '!inventoryEditable && inventory.can_delete === 1') !== false, 'Mobile inventory detail must expose deletion independently of editability.');
assertSameValue(false, strpos((string) $mobileInventoryAppSource, 'Reverter correção') !== false, 'Mobile inventory detail must not expose a direct reversal action.');
assertSameValue(true, strpos((string) $mobileInventoryAppSource, 'Editar inventário') !== false, 'Mobile recorded inventory detail must expose Edit.');
assertSameValue(false, strpos((string) $mobileInventoryAppSource, 'Boolean(templates.blocking_open_inventory && !open)') !== false, 'Open inventories must not block unrelated category and warehouse scopes in mobile.');
assertSameValue(true, strpos((string) $mobileInventoryAppSource, 'inventory.history_locked === 1') !== false, 'Mobile must explain permanently locked recorded history.');

$moduleSource = file_get_contents(__DIR__.'/../core/modules/modKreaProducts.class.php');
assertSameValue(true, strpos((string) $moduleSource, "\$this->version = '4.20.2'") !== false, 'The module descriptor must use the audited release version.');
assertSameValue(true, strpos((string) $moduleSource, "'KREAPRODUCTS_INVOICE_DATETIME_FUTURE_TOLERANCE_MINUTES', 'integer', '30'") !== false, 'Invoice datetime future tolerance must default to 30 minutes.');
assertSameValue(true, strpos((string) $moduleSource, "'inventory';\n        \$this->rights[6][5] = 'expected'") !== false, 'Inventory analysis must use the dedicated expected-stock permission.');
assertSameValue(true, strpos((string) $moduleSource, "\$this->rights[6][3] = 0") !== false, 'Inventory analysis permission must remain disabled by default.');
assertSameValue(true, strpos((string) $moduleSource, '/kreaproducts/inventory_stock_overview.php?leftmenu=stock_inventories') !== false, 'The inventory stock overview must be registered in the stock inventory left menu.');
assertSameValue(true, strpos((string) $moduleSource, '$user->hasRight("kreaproducts", "inventory", "expected")') !== false, 'The inventory stock overview menu must require the analysis permission.');
assertSameValue(false, strpos((string) $moduleSource, 'dolibarr_set_const($db, "PRODUIT_SOUSPRODUITS"'), 'Module activation must not force global composed-product stock behavior.');
assertSameValue(true, strpos((string) $moduleSource, "updateExtraField('kreap_recipe', \$field_label, 'text'") !== false, 'Preparation Markdown must use a predictable text extrafield definition.');
assertSameValue(true, strpos((string) $moduleSource, "updateExtraField('kreap_description', \$field_label, 'text'") !== false, 'Product description Markdown must use a predictable text extrafield definition.');
assertSameValue(true, strpos((string) $moduleSource, "updateExtraField('kreap_ingredients', \$field_label, 'text'") !== false, 'Ingredients Markdown must use a predictable text extrafield definition.');
assertSameValue(true, strpos((string) $moduleSource, 'product:-stats:NU:$conf->kreaproducts->enabled') !== false, 'KreaProducts must remove the legacy core product statistics tab without changing Dolibarr core.');
assertSameValue(true, strpos((string) $moduleSource, '/kreaproducts/product_statistics.php?id=__ID__') !== false, 'KreaProducts must register its replacement product statistics dashboard.');

$productStatisticsPageSource = file_get_contents(__DIR__.'/../product_statistics.php');
$productStatisticsServiceSource = file_get_contents(__DIR__.'/../class/KreaProductsProductStatistics.class.php');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "restrictedArea(\$user, 'produit|service'") !== false, 'The product statistics dashboard must retain the native product/service access boundary.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "\$user->hasRight('facture', 'lire')") !== false, 'Customer sales statistics must require the native invoice read permission.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "\$user->hasRight('fournisseur', 'facture', 'lire')") !== false, 'Supplier statistics must require the native supplier-invoice read permission.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "\$user->hasRight('margins', 'liretous')") !== false, 'Margin statistics must require the native all-margins read permission.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "\$user->hasRight('stock', 'lire')") !== false, 'Operational product statistics must require the native stock read permission.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, '$showOperations') !== false, 'The statistics dashboard must select an operational view for internal and manufactured products.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "empty(\$object->tosell) && empty(\$object->tobuy)") !== false, 'Products that are neither sold nor purchased must use operational statistics.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "e.entity IN ('.getEntity('stock').')") !== false, 'Operational stock statistics must resolve warehouses through the active stock entity scope.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, 'product_association AS pa') !== false, 'Ingredient statistics must discover composed products through Dolibarr product associations.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "b.status = 1 AND b.bomtype = 0") !== false, 'Ingredient relations must include only active manufacturing BOMs.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "if (\$originType === 'inventory')") !== false, 'Inventory adjustments must remain separate from operational demand and production flow.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "getEntity('invoice')") !== false, 'Customer statistics must use the active customer-invoice entity scope.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "getEntity('facture_fourn')") !== false, 'Supplier statistics must use the active supplier-invoice entity scope.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, 'f.fk_statut IN (1, 2)') !== false, 'Statistics must exclude draft and abandoned invoices.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, 'societe_commerciaux AS sc') !== false, 'Statistics must enforce commercial assignments for restricted users.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, "\$this->db->ifsql('f.type = 2'") !== false, 'Statistics must reverse credit-note quantities.');
assertSameValue(false, strpos((string) $productStatisticsPageSource, 'DolGraph') !== false, 'The replacement dashboard must not generate legacy graph image files.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "'today', 'yesterday', '7d', 'currentmonth', 'lastmonth', '3m', '6m'") !== false, 'Statistics must provide all requested short period presets.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "\$period = '12m';") !== false, 'Statistics must default to the last 12 months.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "dol_time_plus_duree(\$todayStart, -6, 'd')") !== false, 'Last-seven-days statistics must include today and the preceding six days.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "dol_mktime(0, 0, 0, \$today['mon'] - 1, 1, \$today['year'])") !== false, 'Previous-month statistics must start on the exact first calendar day.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "dol_mktime(0, 0, 0, \$today['mon'] - 5, 1, \$today['year'])") !== false, 'Six-month statistics must start on the exact first calendar day.');
assertSameValue(false, strpos((string) $productStatisticsPageSource, '<details class="kps-stat-breakdown"') !== false, 'Monthly detail must not be collapsible.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, 'kps-stat-breakdown-title') !== false, 'Monthly detail must retain a permanently visible heading.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, '<tfoot><tr class="liste_total">') !== false, 'Monthly detail tables must end with period totals.');
assertSameValue(true, strpos((string) $productStatisticsServiceSource, 'array_slice($top, 0, 10)') !== false, 'Customer and supplier rankings must return up to ten entries.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, 'kps-stat-columns kps-stat-customer-columns') !== false, 'Top customers and recent customer invoices must share one two-column row.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, 'kps-stat-columns kps-stat-supplier-columns') !== false, 'Top suppliers and recent supplier invoices must share one two-column row.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "'layer' => 30, 'stroke_width' => 4, 'point_radius' => 4") !== false, 'Net revenue must render as the uninterrupted top chart layer.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, "'layer' => 10, 'stroke_width' => 6, 'point_radius' => 6, 'dasharray' => '9 6'") !== false, 'Gross margin must render as a wider dashed underlay when series coincide.');
assertSameValue(true, strpos((string) $productStatisticsPageSource, 'usort($drawSeries') !== false, 'Chart series must be rendered by explicit layer without changing legend order.');
assertSameValue(true, substr_count((string) $productStatisticsPageSource, 'foreach ($drawSeries as $item)') >= 2, 'Both chart scaling and SVG drawing must use the explicitly layered series order.');

$associatedProductsSource = file_get_contents(__DIR__.'/../associatedProducts.php');
$associatedProductsSortSource = file_get_contents(__DIR__.'/../js/associated-products-sort.js');
assertSameValue(true, strpos((string) $associatedProductsSource, "array('/kreaproducts/js/associated-products-sort.js')") !== false, 'The associated-products page must load its scoped parent-table sorting controller.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'id="krea-parent-products-table"') !== false, 'The parent-kit list must expose one scoped sortable table.');
assertSameValue(3, substr_count((string) $associatedProductsSource, 'class="krea-parent-sort-button"'), 'Every parent-kit table header must be a sort control.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'data-krea-sort-key="qty" data-krea-sort-type="number"') !== false, 'Parent-kit quantities must use numeric sorting.');
assertSameValue(true, strpos((string) $associatedProductsSortSource, "currentDirection === 'ascending' ? 'descending' : 'ascending'") !== false, 'Repeated header selection must toggle ascending and descending order.');
assertSameValue(true, strpos((string) $associatedProductsSortSource, "header.setAttribute('aria-sort', isActive ? direction : 'none')") !== false, 'Sortable parent-kit headers must expose their active direction accessibly.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $object->weight_units)') !== false, 'Parent-product weight updates must preserve the current unit when the submission is missing.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_normalize_weight_unit_scale($submittedWeightUnit, $childProduct->weight_units)') !== false, 'Component weight updates must preserve the current unit when the submission is missing.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_weight_unit_select_value') !== false, 'The KreaProducts product form must use Dolibarr\'s empty kilogram selector value.');
assertSameValue(true, strpos((string) $associatedProductsSource, "\$action === 'setfinished'") !== false, 'Product nature must have a dedicated inline-save action.');
assertSameValue(true, strpos((string) $associatedProductsSource, "selectProductNature('finished', \$selectedNature)") !== false, 'Product nature editing must use Dolibarr\'s native selector.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'editfieldkey("NatureOfProductShort", \'finished\'') !== false, 'Product nature must display the native inline-edit icon.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'editfieldval("NatureOfProductShort", \'finished\'') !== false, 'Product nature must use the native inline-edit value form.');
assertSameValue(true, strpos((string) $associatedProductsSource, "'save_other_characteristics'") !== false, 'Other characteristics must use one dedicated save boundary.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'id="kreaproducts-other-characteristics"') !== false, 'Other characteristics must render in one dedicated workspace.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_normalize_markdown(GETPOST($fieldName, \'none\'))') !== false, 'Markdown submissions must be normalized without retaining raw HTML.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'kreaproducts_import_characteristic_database_values(') !== false, 'Existing database HTML must be converted at one import boundary.');
assertSameValue(true, strpos((string) $associatedProductsSource, '// Convert raw database extra-field values before any action, hook, renderer, or editor consumes them.') !== false, 'The import boundary must run before actions and rendering.');
assertSameValue(true, strpos((string) $associatedProductsSource, '$otherCharacteristicsValues[$fieldName] = $object->array_options[$fieldName] ?? \'\';') !== false, 'The editor must consume only the already-imported characteristic values.');
assertSameValue(true, strpos((string) $associatedProductsSource, '// Final invariant: a Markdown textarea must never receive database HTML.') !== false, 'Markdown textarea rendering must retain a final no-HTML invariant.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'dol_escape_htmltag($value, 0, 1)') !== false, 'Markdown textarea escaping must preserve real newline characters.');
assertSameValue(true, strpos((string) $associatedProductsSource, "dolMd2Html(\$value, 'parsedown')") !== false, 'Markdown display must use Dolibarr safe-mode rendering.');
assertSameValue(false, strpos((string) $associatedProductsSource, "'ckeditor'") !== false, 'Other characteristics must not use unpredictable inline HTML editors.');
assertSameValue(false, strpos((string) $associatedProductsSource, '$hasOptionsPost') !== false, 'The broad options POST persistence path must be removed.');
$ingredientsPosition = strpos((string) $associatedProductsSource, "'options_kreap_ingredients' =>");
$brandPosition = strpos((string) $associatedProductsSource, "'options_kreap_brand' =>");
$videoPosition = strpos((string) $associatedProductsSource, "'options_kreap_video' =>");
$descriptionPosition = strpos((string) $associatedProductsSource, "'options_kreap_description' =>");
$preparationPosition = strpos((string) $associatedProductsSource, "'options_kreap_recipe' =>");
assertSameValue(
	true,
	$ingredientsPosition !== false
		&& $brandPosition !== false
		&& $videoPosition !== false
		&& $descriptionPosition !== false
		&& $preparationPosition !== false
		&& $brandPosition < $videoPosition
		&& $videoPosition < $descriptionPosition
		&& $descriptionPosition < $ingredientsPosition
		&& $ingredientsPosition < $preparationPosition,
	'Ingredients must appear immediately before the final Preparation field.'
);
assertSameValue(false, strpos((string) $associatedProductsSource, 'KREAPRODUCTS_NUTRITION_ALLERGENS_DISCLAIMER') !== false, 'The nutrition verification disclaimer must not render in the product workspace.');
assertSameValue(false, strpos((string) $associatedProductsSource, 'KREAPRODUCTS_OTHER_CHARACTERISTICS_MARKDOWN_HELP') !== false, 'The Markdown helper paragraph must not render in the product workspace.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'name="nutrition_allergen_mode"') !== false, 'Nutrition and allergens must use one shared mode selector.');
assertSameValue(true, strpos((string) $associatedProductsSource, "0 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_ENTERED'") !== false, 'The shared selector must expose entered data mode.');
assertSameValue(true, strpos((string) $associatedProductsSource, "1 => 'KREAPRODUCTS_NUTRITION_ALLERGENS_CALCULATED'") !== false, 'The shared selector must expose calculated data mode.');
assertSameValue(true, strpos((string) $associatedProductsSource, "2 => 'NaoEUmAlimento'") !== false, 'The shared selector must expose non-food mode.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'name="action" value="save_nutrition_allergens"') !== false, 'Manual nutrition and allergens must use one common Save action.');
assertSameValue(true, strpos((string) $associatedProductsSource, "'save_nutrition_allergens_mode'") !== false, 'Shared mode changes must use the unified write boundary.');
assertSameValue(true, strpos((string) $associatedProductsSource, '<div class="tabsAction">') !== false, 'Nutrition and allergen record actions must use the native Dolibarr action bar.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'dolGetButtonAction($editLabel') !== false, 'Nutrition and allergen record actions must use Dolibarr button rendering.');
assertSameValue(true, substr_count((string) $associatedProductsSource, "'title' => ''") >= 5, 'Product workspace action buttons must suppress redundant hover descriptions.');
assertSameValue(true, substr_count((string) $associatedProductsSource, "'aria-label' =>") >= 5, 'Action buttons without hover descriptions must retain accessible labels.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'KreaProductsNutritionalCalculator::computeAndDisplayNutritional($object->id, true)') !== false, 'Calculated mode must render detailed component rows inside the unified table.');
assertSameValue(false, strpos((string) $associatedProductsSource, 'class="nobordernopadding"') !== false, 'Calculated mode must not nest its nutritional table inside a parent cell.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'class="button button-save"') !== false, 'Nutrition and allergen form submissions must use the native Save button class.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'class="button button-cancel"') !== false, 'Nutrition and allergen editing must use the native Cancel button class.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'class="badge badge-pill kreaproducts-allergen-pill marginrightonly"') !== false, 'Displayed allergens must retain compact pill styling.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'background:var(--butactionbg, #555);color:var(--textbutaction, #fff)') !== false, 'Allergen pills must use the active Dolibarr action-button colors.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'class="valignmiddle kreaproducts-allergen-pill-icon" style="filter:brightness(0) invert(1);"') !== false, 'Allergen icons inside grey pills must render white without changing the source assets.');
assertSameValue(false, strpos((string) $associatedProductsSource, 'class="button small" name="action" value="save_nutrition_allergens_mode"') !== false, 'The unified mode control must not use the legacy compact generic action button.');
assertSameValue(false, strpos((string) $associatedProductsSource, "if (\$action == 'saveAllergens'") !== false, 'The legacy standalone allergen save boundary must be removed.');
assertSameValue(false, strpos((string) $associatedProductsSource, "if (\$action == 'save_kreaproducts_nutrition'") !== false, 'The legacy standalone nutrition save boundary must be removed.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'id="kreaproducts-copy-product-data-modal" style="display:none;"') !== false, 'Nutrition and allergen copying must remain hidden in a modal.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'value="copy_nutrition_allergens_to_product"') !== false, 'The copy modal must use the unified nutrition and allergen boundary.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'copyDialog.dialog("open")') !== false, 'The copy action must open the Dolibarr modal.');
assertSameValue(true, strpos((string) $associatedProductsSource, "array('generate_llm_product_data', 'apply_llm_product_data')") !== false, 'LLM generation and application must remain explicit write actions.');
assertSameValue(true, strpos((string) $associatedProductsSource, "REQUEST_METHOD'] ?? 'GET'") !== false, 'LLM product-data actions must remain POST-only.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'hash_equals((string) currentToken()') !== false, 'LLM product-data actions must validate the submitted CSRF token explicitly.');
assertSameValue(true, strpos((string) $associatedProductsSource, '$llmManualDataMode = ($nutritionAllergenMode === 0);') !== false, 'LLM suggestions must use the same resolved manual mode as the product workspace.');
assertSameValue(true, strpos((string) $associatedProductsSource, "'kreaproducts-open-llm-modal'") !== false, 'The LLM workflow must expose a dedicated native action launcher.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'id="kreaproducts-llm-modal" style="display:none;"') !== false, 'The LLM workflow must remain hidden until its modal is opened.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'llmDialog.dialog("open")') !== false, 'The LLM launcher must open the Dolibarr modal.');
assertSameValue(false, strpos((string) $associatedProductsSource, "load_fiche_titre(\$langs->trans('KREAPRODUCTS_LLM_PRODUCT_DATA_TITLE')") !== false, 'The LLM workflow must not render as a standalone product-page section.');
assertSameValue(false, strpos((string) $associatedProductsSource, "print '<script type=\"text/javascript\">\\n';") !== false, 'Inline modal JavaScript must not emit a literal newline escape outside JavaScript strings.');
assertSameValue(true, strpos((string) $associatedProductsSource, "print '<script type=\"text/javascript\">'.\"\\n\";") !== false, 'Inline modal JavaScript must emit a real line break after the script tag.');

$nutritionalCalculatorSource = file_get_contents(__DIR__.'/../class/KreaProductsNutritionalCalculator.class.php');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, "displaySubproductRows(\$productId, \$subList, \$calculationResult['details'])") !== false, 'The calculated nutrition table must render one detail row per component.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'KreaProductsTableProductQuantity') !== false, 'The calculated nutrition table must display component quantities.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, "\$contrib = \$detail['contributions']") !== false, 'The calculated nutrition table must display nutrient contributions per component.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'computeAndDisplayNutritional($productId, $embedded = false)') !== false, 'The nutritional renderer must support backward-compatible embedded-row output.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'self::displayTableHeader($langs, !$embedded)') !== false, 'Embedded calculated mode must reuse the native component header without opening another table.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'border-collapse: separate !important') !== false, 'The unified calculated table must support native Dolibarr rounded corners.');
assertSameValue(false, strpos((string) $nutritionalCalculatorSource, 'border-radius: 0 !important') !== false, 'The calculated table must not override the active Dolibarr corner radius.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'overflow: hidden') !== false, 'The calculated table must clip row backgrounds to its rounded corners.');
assertSameValue(true, strpos((string) $nutritionalCalculatorSource, 'select[name="weight_units"]') !== false, 'Compact selector styling must remain scoped to component weight units.');

$llmServiceSource = file_get_contents(__DIR__.'/../class/KreaProductsLlmProductDataService.class.php');
assertSameValue(true, strpos((string) $llmServiceSource, "'https://api.openai.com/v1/chat/completions'") !== false, 'Hosted OpenAI credentials must use a fixed endpoint.');
assertSameValue(true, strpos((string) $llmServiceSource, "'https://api.anthropic.com/v1/messages'") !== false, 'Hosted Anthropic credentials must use a fixed endpoint.');
assertSameValue(true, strpos((string) $llmServiceSource, "'https://openrouter.ai/api/v1/chat/completions'") !== false, 'Hosted OpenRouter credentials must use a fixed endpoint.');
assertSameValue(true, strpos((string) $llmServiceSource, '$localUrlMode = ($provider === self::PROVIDER_OLLAMA ? 1 : 0)') !== false, 'Ollama must use Dolibarr local-address enforcement.');
assertSameValue(true, strpos((string) $llmServiceSource, 'isAllowedPrivateOllamaHost') !== false, 'Ollama must reject public and metadata-service address ranges.');
assertSameValue(true, strpos((string) $llmServiceSource, '$this->db->begin()') !== false, 'Reviewed nutrition and allergen replacement must be transactional.');
assertSameValue(true, strpos((string) $llmServiceSource, 'every nutrient is null and the allergen list is empty, usable MUST be false') !== false, 'The LLM prompt must explicitly classify empty output as insufficient evidence.');
assertSameValue(true, strpos((string) $llmServiceSource, 'array(CURLINFO_HEADER_OUT => false)') !== false, 'Provider credentials must not be included in Dolibarr outbound-header logs.');
assertSameValue(true, strpos((string) $llmServiceSource, '$localUrlMode, -1, 15, 60, $curlOptions') !== false, 'Provider requests must allow enough time for DNS and connection establishment.');
assertSameValue(true, strpos((string) $llmServiceSource, "array(6, 28)") !== false, 'Provider DNS and timeout failures must have a dedicated user-facing error.');
assertSameValue(true, strpos((string) $llmServiceSource, 'general food-composition knowledge to estimate typical values') !== false, 'LLM nutrition generation must support estimates when exact label values are absent.');

$aboutSource = file_get_contents(__DIR__.'/../admin/about.php');
assertSameValue(true, strpos((string) $aboutSource, "trans('KreapAboutModuleLabel')") !== false, 'The About signature must display the module identity.');
assertSameValue(true, strpos((string) $aboutSource, "trans('KreapAboutVersionLabel')") !== false, 'The About signature must display the current module version.');
assertSameValue(true, strpos((string) $aboutSource, 'print $tmpmodule->getDescLong();') !== false, 'The About page must render the module long description from README.md.');
assertSameValue(false, strpos((string) $aboutSource, "trans('KreapAboutAiCapabilitiesLabel')") !== false, 'The module signature must not contain an AI capabilities row.');
assertSameValue(false, strpos((string) $aboutSource, '$nutritionFeatures') !== false, 'The README description must not be duplicated beside the signature.');
assertSameValue(false, strpos((string) $aboutSource, 'Feature coverage') !== false, 'The About signature must not include the feature catalogue.');
assertSameValue(false, strpos((string) $aboutSource, 'Main setup and product controls') !== false, 'The About signature must not include the setup-control catalogue.');
assertSameValue(false, strpos((string) $aboutSource, '$featureRows') !== false, 'The removed feature catalogue must not remain as unused page data.');
assertSameValue(false, strpos((string) $aboutSource, '$controlRows') !== false, 'The removed control catalogue must not remain as unused page data.');

$readmeSource = file_get_contents(__DIR__.'/../README.md');
assertSameValue(true, strpos((string) $readmeSource, 'KreaProducts also provides optional AI-assisted nutrition and allergen suggestions') !== false, 'The rendered About introduction must describe the module AI capabilities.');
assertSameValue(true, strpos((string) $readmeSource, 'OpenAI, Anthropic, OpenRouter, or private Ollama') !== false, 'The rendered About content must name the supported AI providers.');
assertSameValue(true, strpos((string) $readmeSource, 'nothing is saved without explicit user confirmation') !== false, 'The rendered About introduction must describe the review-first save boundary.');
assertSameValue(true, strpos((string) $readmeSource, '### Nutrition and allergens') !== false, 'The rendered About features must retain the nutrition and allergen section.');
assertSameValue(true, strpos((string) $readmeSource, '- Optional AI-assisted nutrition and allergen suggestions') !== false, 'The rendered nutrition and allergen features must include AI assistance.');
assertSameValue(true, strpos((string) $readmeSource, '## Recent release highlights') !== false, 'The README must summarize changes for the current Dolistore release.');
assertSameValue(true, strpos((string) $readmeSource, 'One coherent Nutrition and allergens workspace') !== false, 'The Dolistore description must explain the unified product-data workflow.');
assertSameValue(true, strpos((string) $readmeSource, 'Markdown-based product description, ingredients, and preparation fields') !== false, 'The Dolistore description must explain Markdown characteristics and legacy conversion.');
assertSameValue(true, strpos((string) $readmeSource, '- Dolibarr >= 19') !== false, 'README compatibility must match the descriptor Dolibarr minimum.');
assertSameValue(true, strpos((string) $readmeSource, '- PHP >= 7.3') !== false, 'README compatibility must match the descriptor PHP minimum.');
$localizedReadmes = array(
	'fr_FR' => '## Nouveautés principales',
	'de_DE' => '## Wichtigste Neuerungen',
	'it_IT' => '## Principali novità',
	'es_ES' => '## Principales novedades',
);
$releaseAllowlistSource = file_get_contents(__DIR__.'/../build/makepack-kreaproducts.conf');
foreach ($localizedReadmes as $locale => $releaseHeading) {
	$localizedReadmePath = __DIR__.'/../README-'.$locale.'.md';
	assertSameValue(true, is_file($localizedReadmePath), 'The '.$locale.' Dolistore README must exist.');
	$localizedReadmeSource = file_get_contents($localizedReadmePath);
	assertSameValue(true, strpos((string) $localizedReadmeSource, $releaseHeading) !== false, 'The '.$locale.' README must contain localized release highlights.');
	assertSameValue(true, strpos((string) $localizedReadmeSource, 'OpenAI, Anthropic, OpenRouter') !== false, 'The '.$locale.' README must describe the supported AI providers.');
	assertSameValue(true, strpos((string) $releaseAllowlistSource, 'kreaproducts/README-'.$locale.'.md') !== false, 'The '.$locale.' README must be packaged for Dolistore.');
}
assertSameValue(true, strpos((string) $llmServiceSource, 'For allergens, use only explicit product evidence') !== false, 'LLM allergen generation must remain evidence-based.');

$setupSource = file_get_contents(__DIR__.'/../admin/setup.php');
assertSameValue(true, strpos((string) $setupSource, "newItem('KREAPRODUCTS_LLM_API_KEY')->setAsSecureKey()") !== false, 'The LLM API key must use Dolibarr encrypted secure-key storage.');
assertSameValue(true, strpos((string) $setupSource, "newItem('KREAPRODUCTS_INVENTORY_AUTO_CLOSE_TIME')") !== false, 'The automatic inventory closure and lock-start time must be configurable in setup.');
assertSameValue(true, strpos((string) $setupSource, '$submittedAutoCloseTime >= $submittedEntryCutoff') !== false, 'Setup must reject an automatic closure time that is not earlier than the entry cutoff.');

$actionsSource = file_get_contents(__DIR__.'/../class/actions_kreaproducts.class.php');
assertSameValue(true, strpos((string) $actionsSource, 'buildProductCardKilogramSelectionScriptRow') !== false, 'Native product forms must apply the kilogram selector workaround.');
assertSameValue(true, strpos((string) $actionsSource, 'option.value==="0"||label==="kg"') !== false, 'The kilogram selector workaround must support current and corrected Dolibarr unit values.');
assertSameValue(false, strpos((string) $actionsSource, 'SET stockable_product =') !== false, 'KreaProducts must not bypass the native Product lifecycle when saving stock management.');
assertSameValue(false, strpos((string) $actionsSource, "GETPOST('stockable_product', 'int')") !== false, 'KreaProducts must not parse the native HTML checkbox value as an integer.');

$mobileInventorySource = file_get_contents(__DIR__.'/../class/KreaProductsMobileInventoryService.class.php');
assertSameValue(true, strpos((string) $mobileInventorySource, 'private function beginStockTransaction()') !== false, 'Stock mutations must share a checked transaction-start boundary.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'private function commitStockTransaction()') !== false, 'Stock mutations must share a checked transaction-commit boundary.');
assertSameValue(true, strpos((string) $mobileInventorySource, "getDolGlobalString('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30')") !== false, 'Inventory value timestamps must use the configured default inventory time.');
assertSameValue(true, strpos((string) $mobileInventorySource, "getDolGlobalString('KREAPRODUCTS_BUSINESS_DAY_CLOSE_TIME', '06:00')") !== false, 'Displayed virtual stock must use the configured billing-day close time.');
assertSameValue(true, strpos((string) $mobileInventorySource, "getDolGlobalString('KREAPRODUCTS_INVENTORY_AUTO_CLOSE_TIME', '19:45')") !== false, 'Automatic closure and the read-only lock must use the configured setup time.');
assertSameValue(true, substr_count((string) $mobileInventorySource, '$this->requireInventoryMutationWindowOpen();') >= 5, 'Every interactive inventory mutation must enforce the configured read-only interval.');
assertSameValue(true, substr_count((string) $mobileInventorySource, '$this->requireInventoryCountsCurrent(') >= 3, 'Saving, editing, and executing must reject counts from an earlier counting window.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'if ($this->schedulerMode)') !== false, 'Scheduled automatic closure must be the only mutation exempt from the read-only interval.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'virtual_stock_at_business_close') !== false, 'Inventory analysis must expose a distinct close-time virtual stock value.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'loadOpenInventoryVirtualStockAtBusinessDayClose') !== false, 'Open inventories must reconstruct close-time stock from the live ledger.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'THEN COALESCE(ps.reel, 0) ELSE COALESCE(pb.qty, 0) END as current_qty') !== false, 'Open close-time reconstruction must support product and batch stock.');
assertSameValue(true, strpos((string) file_get_contents(__DIR__.'/../inventory.php'), "\$line['virtual_stock_at_business_close']") !== false, 'The inventory page must display close-time virtual stock rather than the adjustment anchor.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'getMovementQuantityAfterValueDate(') !== false, 'Inventory reconstruction must retain operational movements posted after the configured inventory time.');
assertSameValue(false, strpos((string) $mobileInventorySource, '$this->db->begin();') !== false, 'Stock mutations must not ignore transaction-start failures.');
assertSameValue(false, strpos((string) $mobileInventorySource, '$this->db->commit();') !== false, 'Stock mutations must not ignore transaction-commit failures.');
assertSameValue(true, strpos((string) $mobileInventorySource, "i.date_inventory >= '") !== false, 'Equal-time inventory anchors must be rejected.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'isInventoryInCurrentCountingWindow') !== false, 'Recorded correction windows must remain enforced.');
assertSameValue(true, substr_count((string) $mobileInventorySource, "->format('Y-m-d')") >= 4, 'Counting-window membership must compare business calendar dates without replacing immutable anchor times.');
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
assertSameValue(false, strpos((string) $mobileInventorySource, 'findAnyOpenManagedInventory()') !== false, 'An open inventory must block only its own category and warehouse, not unrelated scopes.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_ERROR_INVENTORY_SCOPE_OPEN') !== false, 'Starting another inventory in an occupied category and warehouse must return a clear conflict.');
assertSameValue(true, substr_count((string) $mobileInventorySource, 'requireFirstOpenInventoryOfScope(') >= 4, 'Saving, editing, and executing must recheck the first open inventory while holding the scope lock.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'ORDER BY i.date_inventory ASC, i.rowid ASC') !== false, 'The oldest open inventory must own its category and warehouse scope.');
assertSameValue(true, strpos((string) $mobileInventorySource, "'history_locked' => (\$isRecorded && !\$isCurrentCountingWindow) ? 1 : 0") !== false, 'Recorded inventories outside the current counting window must be marked as permanent read-only history.');
assertSameValue(true, strpos((string) $mobileInventorySource, '($isLatestOfKind && $isCurrentCountingWindow && $this->canClose())') !== false, 'Recorded inventory deletion must be exposed only in the current counting window.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'closeDueInventories($now = 0)') !== false, 'Managed inventories must expose automatic due closure.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'closeDueInventoriesAsScheduler($now = 0)') !== false, 'Scheduled closure must use a dedicated administrator-only entry point.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_INVENTORY_FUTURE_CLOSE_BLOCKED') !== false, 'Future inventory anchors must not post stock immediately.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'isFutureCalendarDate($valueTimestamp, $now)') !== false, 'Inventory closure must distinguish a future calendar date from a same-day ordering time.');
assertSameValue(false, strpos((string) $mobileInventorySource, '$valueTimestamp = $now;') !== false, 'Same-day inventory execution must preserve the configured inventory ordering time.');
assertSameValue(true, strpos((string) $mobileInventorySource, "'can_reverse' => 0") !== false, 'Direct inventory reversal must remain disabled.');
assertSameValue(true, strpos((string) $mobileInventorySource, "'can_edit' => \$canEdit ? 1 : 0") !== false, 'The latest recorded inventory must expose edit capability.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'public function editInventory') !== false, 'The service must expose the explicit recorded-inventory edit lifecycle.');
assertSameValue(true, strpos((string) $mobileInventorySource, "\$closedInventory['can_edit'] = !\$mutationLocked && \$closedStillCurrent && \$this->canClose() ? 1 : 0") !== false, 'A successful execution response must expose Edit only in the current counting window and outside the configured read-only interval.');
assertSameValue(true, strpos((string) $mobileInventorySource, '$this->compensateInventoryStockEffects($record, false, true, true);') !== false, 'Inventory re-execution must atomically replace the previous active adjustment generation.');
assertSameValue(true, substr_count((string) $mobileInventorySource, 'assertValueDateAfterLatestInventory(') >= 4, 'Creation, saving, and closure must enforce strictly increasing category and warehouse dates.');
$deleteInventoryStart = strpos((string) $mobileInventorySource, 'public function deleteInventory');
$deleteInventoryEnd = strpos((string) $mobileInventorySource, 'public function saveCounts', $deleteInventoryStart);
assertSameValue(true, $deleteInventoryStart !== false && $deleteInventoryEnd !== false, 'Inventory deletion service scope could not be resolved.');
$deleteInventorySource = substr((string) $mobileInventorySource, $deleteInventoryStart, $deleteInventoryEnd - $deleteInventoryStart);
assertSameValue(true, strpos((string) $deleteInventorySource, '$this->requireCountAccess();') !== false, 'Initiated inventory deletion must require count permission.');
assertSameValue(true, strpos((string) $deleteInventorySource, '$this->requireCloseAccess();') !== false, 'Recorded inventory deletion must require close permission.');
assertSameValue(true, strpos((string) $deleteInventorySource, 'return $this->compensateInventoryStockEffects($record, true);') !== false, 'Recorded inventory deletion must compensate stock before removing the record.');
assertSameValue(true, strpos((string) $deleteInventorySource, '$initiatedHasActiveStockEffects') !== false, 'Deleting an edit draft must compensate its still-active stock generation.');
assertSameValue(true, strpos((string) $deleteInventorySource, 'KREAPRODUCTS_ERROR_INVENTORY_HISTORY_LOCKED') !== false, 'Recorded inventories from an earlier counting window must reject deletion.');
assertSameValue(true, strpos((string) $deleteInventorySource, '$inventory->setDraft($this->user)') !== false, 'Inventory deletion must use the native Dolibarr draft lifecycle before deletion.');

$editInventoryStart = strpos((string) $mobileInventorySource, 'public function editInventory');
$editInventoryEnd = strpos((string) $mobileInventorySource, 'public function saveCounts', $editInventoryStart);
assertSameValue(true, $editInventoryStart !== false && $editInventoryEnd !== false, 'Inventory edit service scope could not be resolved.');
$editInventorySource = substr((string) $mobileInventorySource, $editInventoryStart, $editInventoryEnd - $editInventoryStart);
assertSameValue(false, strpos((string) $editInventorySource, 'compensateInventoryStockEffects(') !== false, 'Opening Edit must not change live stock.');
assertSameValue(true, strpos((string) $editInventorySource, '$inventory->setStatut(Inventory::STATUS_VALIDATED') !== false, 'Edit must reopen the same inventory through the native validated lifecycle.');
assertSameValue(true, strpos((string) $editInventorySource, 'getActiveAdjustmentValueDate($inventoryId, true)') !== false, 'Edit must restore the immutable active adjustment timestamp before reopening the inventory.');
assertSameValue(true, strpos((string) $mobileInventorySource, '($canCount && !$hasActiveAdjustmentGeneration)') !== false, 'A reopened recorded inventory must keep its original value date immutable.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_ERROR_EDIT_VALUE_DATE_IMMUTABLE') !== false, 'The service must reject value-date changes while revised counts are staged.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'if (!$stagesRecordedRevision)') !== false, 'A revision save must never recompute its timestamp from the current configured inventory hour.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_ERROR_ACTIVE_INVENTORY_VALUE_DATE_INCONSISTENT') !== false, 'Revision saves must fail closed when one active adjustment generation has inconsistent value timestamps.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'resolvePostCutoffMinimumValueTimestamp($inventory)') !== false, 'Inventory reads and saves must derive the mandatory post-cutoff value date from the inventory creation time.');
assertSameValue(true, strpos((string) $mobileInventorySource, 'KREAPRODUCTS_INVENTORY_POST_CUTOFF_DATE_REQUIRED') !== false, 'An unconfirmed post-cutoff backdate must fail before counts are persisted.');
assertSameValue(true, strpos((string) $mobileInventorySource, ': (int) $this->db->jdate($inventory->date_inventory)') !== false, 'The post-cutoff safeguard must also validate the stored value date when a client omits the date field.');

$inventoryListSource = file_get_contents(__DIR__.'/../inventory_list.php');
assertSameValue(true, strpos((string) $inventoryListSource, "preg_match('/^\\(PROV\\d+\\)\$/i', \$ref)") !== false, 'The inventory list must preserve Dolibarr provisional references.');
assertSameValue(true, strpos((string) $inventoryListSource, "\$refdisplay = '(PROV'.str_pad") !== false, 'The inventory list must provide a provisional display fallback for initiated managed inventories.');
assertSameValue(true, strpos((string) $inventoryListSource, '$inventoryMutationLocked') !== false, 'The inventory list must suppress creation and mass deletion during the configured read-only interval.');

$mobileAppSource = file_get_contents(__DIR__.'/../stockapp/src/App.tsx');
$mobileApiSource = file_get_contents(__DIR__.'/../stockapp/src/lib/api.ts');
assertSameValue(true, strpos((string) $mobileAppSource, 'Guardar correções') !== false, 'Mobile correction mode must include a bottom save action.');
assertSameValue(true, strpos((string) $mobileAppSource, 'cria os movimentos compensatórios necessários e elimina o inventário fechado') !== false, 'Deleting a recorded inventory must explain its atomic compensation and removal.');
assertSameValue(true, strpos((string) $mobileAppSource, 'templates.mutation_window.active === 1') !== false, 'The mobile category selector must become read-only during the configured lock interval.');
assertSameValue(true, strpos((string) $mobileAppSource, 'inventory.counts_expired === 1') !== false, 'The mobile inventory must explain that stale products need to be counted again.');

$inventoryPageSource = file_get_contents(__DIR__.'/../inventory.php');
assertSameValue(false, strpos((string) $inventoryPageSource, 'new MouvementStock'), 'The dedicated inventory page must not calculate stock movements directly.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'stock_mouvement'), 'The dedicated inventory page must not query the stock movement ledger directly.');
assertSameValue(false, strpos((string) $inventoryPageSource, 'restrictedArea($user') !== false, 'Custom inventory actions must not inherit core action-name write checks from restrictedArea.');
assertSameValue(true, strpos((string) $inventoryPageSource, "\$user->hasRight('stock', 'lire')") !== false, 'The custom inventory page must retain an explicit stock-read gate.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_TITLE') !== false, 'The inventory detail page must explain its stock lifecycle.');
assertSameValue(true, strpos((string) $inventoryPageSource, "getDolGlobalString('KREAPRODUCTS_INVENTORY_DEFAULT_TIME', '10:30')") !== false, 'The inventory explanation must show the configured stock-anchor time.');
assertSameValue(true, strpos((string) $inventoryPageSource, "getDolGlobalString('KREAPRODUCTS_BUSINESS_DAY_CLOSE_TIME', '06:00')") !== false, 'The inventory explanation must distinguish the billing-close display time.');
assertSameValue(true, strpos((string) $inventoryPageSource, "'KREAPRODUCTS_INVENTORY_LOGIC_VALUE_DATE', \$inventoryAnchorLabel, \$inventoryDefaultTime, \$inventoryCutoffTime") !== false, 'The bottom explanation must disclose the configured cutoff and next-day value-date rule.');
assertSameValue(true, strpos((string) $inventoryPageSource, "getDolGlobalString('KREAPRODUCTS_INVENTORY_AUTO_CLOSE_TIME', '19:45')") !== false, 'The inventory explanation must use the configured automatic closure time.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_READ_ONLY_WINDOW') !== false, 'The bottom explanation must document the configured read-only interval.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_EXPIRED_COUNTS') !== false, 'The bottom explanation must require a new count after an earlier window expires.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_OPEN_SCOPE') !== false, 'The bottom explanation must document the category and warehouse open-inventory gate.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_HISTORY_LOCKED') !== false, 'The bottom explanation must document permanent recorded-history locking.');
assertSameValue(true, strpos((string) $inventoryPageSource, "GETPOSTINT('confirm_post_cutoff_date') === 1") !== false, 'The inventory page must pass only an explicit post-cutoff date confirmation to the service.');
assertSameValue(true, strpos((string) $inventoryPageSource, 'data-kps-post-cutoff-min-date') !== false, 'The inventory form must expose its mandatory next-window date to the confirmation UI.');
$inventoryActionsPosition = strrpos((string) $inventoryPageSource, "print '<div class=\"tabsAction\">';");
$inventoryExplanationPosition = strrpos((string) $inventoryPageSource, 'KREAPRODUCTS_INVENTORY_LOGIC_TITLE');
assertSameValue(true, $inventoryActionsPosition !== false && $inventoryExplanationPosition > $inventoryActionsPosition, 'The inventory stock explanation must render below the page actions.');
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
assertSameValue(true, strpos((string) $inventoryPageSource, "print dol_print_date((int) \$inventory['date_inventory'], 'day');") !== false, 'The inventory summary must display the value date without its ordering time.');
assertSameValue(true, strpos((string) $inventoryPageSource, "\$inventoryAnchorLabel = dol_print_date((int) \$inventory['date_inventory'], 'dayhour', 'tzuserrel');") !== false, 'The stock explanation must disclose the exact inventory anchor timestamp.');
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

$inventoryJsSource = file_get_contents(__DIR__.'/../js/kreaproducts_inventory.js');
assertSameValue(true, strpos((string) $inventoryJsSource, 'window.confirm(message)') !== false, 'The inventory page must ask before replacing an invalid post-cutoff value date.');
assertSameValue(true, strpos((string) $inventoryJsSource, 'valueDateInput.value = minimumDate') !== false, 'Accepted confirmation must replace the submitted date with the mandatory next-window date.');
assertSameValue(true, strpos((string) $inventoryJsSource, "postCutoffConfirmed.value = '1'") !== false, 'Accepted confirmation must be explicit in the server request.');
assertSameValue(true, strpos((string) $inventoryJsSource, "postCutoffConfirmed.value = '0'") !== false, 'Changing the value date must invalidate an earlier browser confirmation.');
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
assertSameValue(true, strpos((string) $inventoryListSource, "'t.title',") !== false, 'The inventory list must display the saved label by default.');
assertSameValue(false, strpos((string) $mobileAppSource, 'formatDateTime(item.date_inventory') !== false, 'The mobile inventory list must not display the value-date ordering time.');
assertSameValue(true, strpos((string) $mobileAppSource, 'formatDate(item.date_inventory || item.date_creation)') !== false, 'The mobile inventory list must display the value date as a calendar date.');
assertSameValue(true, strpos((string) $mobileAppSource, 'Data valor') !== false, 'The mobile initiated inventory must expose the concise value-date label.');
assertSameValue(true, strpos((string) $mobileAppSource, 'inventory.can_edit_value_date === 1 ? valueDate : undefined') !== false, 'The mobile app must submit editable value dates with counts.');
assertSameValue(true, strpos((string) $mobileAppSource, 'JSON.stringify({ counts, valueDate })') !== false, 'Offline drafts must preserve the selected value date with counts.');
assertSameValue(true, strpos((string) $mobileApiSource, 'virtual_stock_at_business_close?: number') !== false, 'The mobile API contract must expose the authorized billing-close stock snapshot.');
assertSameValue(true, strpos((string) $mobileApiSource, 'virtual_stock_snapshot_time: string') !== false, 'The mobile API contract must expose the configured snapshot time.');
assertSameValue(true, strpos((string) $mobileAppSource, "inventory.can_view_analysis === 1 && typeof line.virtual_stock_at_business_close === 'number'") !== false, 'The mobile app must render stock snapshots only for authorized analysis users.');
assertSameValue(true, strpos((string) $mobileAppSource, 'Stock virtual às {inventory.virtual_stock_snapshot_time}') !== false, 'The mobile inventory must identify the configured stock snapshot hour.');

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
assertSameValue(true, strpos((string) $productionApiSource, 'protected function supplierInvoiceRequiresStockWarehouse($invoice)') !== false, 'Supplier invoice API helpers must remain compatible with Restler route reflection.');
assertSameValue(false, strpos((string) $productionApiSource, 'supplierInvoiceRequiresStockWarehouse(FactureFournisseur $invoice)') !== false, 'Supplier invoice API helpers must not expose constructor-dependent object type hints to Restler.');
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
assertSameValue(true, strpos((string) $productionRunSource, 'if (!$this->db->begin())') < strpos((string) $productionRunSource, '$mo->update(DolibarrApiAccess::$user)'), 'The checked production transaction must start before an existing MO is mutated.');
assertSameValue(true, strpos((string) $productionRunSource, 'assertMoLinesSupportedByCoreProductionApi($mo->lines)') < strpos((string) $productionRunSource, '$mosApi->produceAndConsume('), 'Unsupported batch-managed MO lines must be rejected before core stock posting.');
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

assertSameValue(true, strpos((string) $mobileInventorySource, "\$closedInventory['editable'] = 0") !== false, 'Recorded inventories must remain read-only after stock execution.');
assertSameValue(true, strpos((string) $mobileInventorySource, "\$closedInventory['can_delete'] = !\$mutationLocked && \$closedStillCurrent && \$this->canClose() ? 1 : 0") !== false, 'Recorded inventories must expose deletion only in the current counting window and outside the configured read-only interval.');

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
assertSameValue(true, strpos((string) $productViewerSource, 'class KreaProductsHierarchyProductNode') !== false, 'The product hierarchy must use a module-specific internal node class.');
preg_match_all('/\\bclass\\s+([A-Za-z_][A-Za-z0-9_]*)/', (string) $productViewerSource, $productViewerClasses);
preg_match_all('/\\bclass\\s+([A-Za-z_][A-Za-z0-9_]*)/', (string) $nutritionalCalculatorSource, $nutritionCalculatorClasses);
assertSameValue(
	array(),
	array_values(array_intersect($productViewerClasses[1], $nutritionCalculatorClasses[1])),
	'The hierarchy and nutrition calculators must be loadable in the same product-page request without duplicate class declarations.'
);
assertSameValue(true, strpos((string) $productViewerSource, 'public static function getRecursiveComponentCost($productId)') !== false, 'The technical-sheet recursive calculation must be reusable by the associated-products page.');
assertSameValue(true, strpos((string) $productViewerSource, 'const PRICE_COMPARISON_DELTA = 0.0001;') !== false, 'Recursive cost comparison must match the four displayed decimal places.');
assertSameValue(false, strpos((string) $productViewerSource, "trans(\"FichaTecnica\")") !== false, 'The redundant technical-sheet table must not render on the hierarchy page.');
assertSameValue(true, strpos((string) $productViewerSource, '/theme/eldy/img/error.png') !== false, 'Recursive cost mismatches must retain the hierarchy warning icon.');
assertSameValue(true, strpos((string) $productViewerSource, 'Cyclic product hierarchy detected while calculating product') !== false, 'Recursive display calculations must fail safely on cyclic hierarchies.');

$associatedProductsSource = file_get_contents(__DIR__.'/../associatedProducts.php');
assertSameValue(true, strpos((string) $associatedProductsSource, 'ProductHierarchyTree::getRecursiveComponentCost($id)') !== false, 'The associated-products table must display the hierarchy recursive cost.');
assertSameValue(true, strpos((string) $associatedProductsSource, "trans('KreapRecursiveCost')") !== false, 'The recursive total must be identified below the direct component total.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'ProductHierarchyTree::getCostDifferenceIcon($recursiveBuyingPrice, $total)') !== false, 'The recursive total must be compared with the direct component total.');
assertSameValue(true, strpos((string) $associatedProductsSource, '$total +=  $totalline;') !== false, 'The direct component total must remain the primary associated-products calculation.');
assertSameValue(true, strpos((string) $associatedProductsSource, "print \$langs->trans(\"TotalBuyingPriceMinShort\");\n\t\t\t\tif (\$recursiveDifferenceIcon !== '')") !== false, 'The recursive total must render below the direct-total label only when the values differ.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'opacitymedium krea-recursive-cost') !== false, 'The recursive amount and mismatch icon must share one aligned inline row.');
assertSameValue(true, strpos((string) $associatedProductsSource, 'print $recursiveDifferenceIcon;') < strpos((string) $associatedProductsSource, "print ')</span>';") , 'The mismatch icon must render inside the recursive-cost parentheses.');

$triggerSource = file_get_contents(__DIR__.'/../core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php');
assertSameValue(true, strpos((string) $triggerSource, 'ProductUpdater::prepareProductCostUpdate($product);') !== false, 'Supplier-invoice cost persistence must preserve oldcopy before changing cost_price.');

$deleteRecordedStart = strpos((string) $mobileInventorySource, 'private function compensateInventoryStockEffects');
$deleteRecordedEnd = strpos((string) $mobileInventorySource, 'private function requireInventoryValueDatingEnabled', $deleteRecordedStart);
$deleteRecordedSource = substr((string) $mobileInventorySource, $deleteRecordedStart, $deleteRecordedEnd - $deleteRecordedStart);
assertSameValue(true, strpos((string) $deleteRecordedSource, "inventory as i") < strpos((string) $deleteRecordedSource, 'kreaproducts_inventory_adjustment as a'), 'Recorded deletion must lock the header before adjustment rows.');
assertSameValue(true, strpos((string) $deleteRecordedSource, 'FOR UPDATE') !== false, 'Recorded deletion must lock inventory and ledger rows.');
assertSameValue(true, strpos((string) $deleteRecordedSource, 'hasLaterActiveInventoryAnchor(') !== false, 'Recorded deletion must reject unsafe removal before a later active anchor.');
assertSameValue(true, strpos((string) $deleteRecordedSource, '(string) $row->value_datetime') !== false, 'Compensation must protect the original active adjustment timestamp even when an edit changes the staged value date.');
assertSameValue(true, strpos((string) $deleteRecordedSource, 'isLatestInventoryOfKind($record, true)') !== false, 'Recorded deletion must enforce the latest template and warehouse boundary under lock.');
assertSameValue(true, strpos((string) $deleteRecordedSource, '$inventory->setDraft($this->user)') !== false, 'Recorded deletion must use the native draft lifecycle after compensating stock.');
assertSameValue(true, strpos((string) $deleteRecordedSource, '$inventory->delete($this->user)') !== false, 'Recorded deletion must remove the record in the compensation transaction.');
assertSameValue(true, strpos((string) $deleteRecordedSource, '$this->commitStockTransaction();') !== false, 'Recorded deletion must verify its final database commit.');
assertSameValue(false, strpos((string) $mobileInventorySource, 'public function reverseInventory') !== false, 'The service must not expose reversal as a public action.');

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
assertSameValue(true, strpos((string) $mobileControllerSource, "'edit_inventory'") !== false, 'Mobile mutations must expose the explicit recorded-inventory edit lifecycle.');

$productLabelsSource = file_get_contents(__DIR__.'/../product_labels.php');
assertSameValue(false, strpos((string) $productLabelsSource, 'ALTER TABLE') !== false, 'Product label requests must never alter the database schema.');
$restApiSource = file_get_contents(__DIR__.'/../class/api_kreaproducts.class.php');
assertSameValue(true, strpos((string) $restApiSource, 'protected function failInternalRequest') !== false, 'Internal REST failures must use the centralized logging boundary.');
assertSameValue(true, strpos((string) $restApiSource, 'Component lot quantity must match the manufacturing-order recipe quantity') !== false, 'Component-lot quantities must not override manufacturing recipe quantities.');
assertSameValue(true, strpos((string) $restApiSource, 'assertMoLinesSupportedByCoreProductionApi') !== false, 'Production must reject batch-managed MO lines before delegating to an incompatible core API.');
assertSameValue(true, strpos((string) $restApiSource, "if (!\$this->db->begin())") !== false, 'Production must fail before MO mutation when its outer transaction cannot start.');
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
assertSameValue(true, strpos((string) $nutritionalNumberingSource, '$this->date_creation = !empty($this->tms) ? $this->tms : dol_now();') !== false, 'Nutritional updates must repair legacy zero creation dates before the native update lifecycle.');

$allergenIndexMigration = file_get_contents(__DIR__.'/../sql/llx_kreaproducts_productallergens_upgrade.sql');
assertSameValue(true, strpos((string) $allergenIndexMigration, 'idx_kreaproducts_productallergens_fk_product') !== false, 'The allergen product lookup index migration must be installed.');

$inventoryAdjustmentSchema = file_get_contents(__DIR__.'/../sql/llx_kreaproducts_inventory_adjustment.sql');
$inventoryAdjustmentHistoryMigration = file_get_contents(__DIR__.'/../sql/llx_kreaproducts_inventory_adjustment_history_upgrade.sql');
assertSameValue(false, strpos((string) $inventoryAdjustmentSchema, 'UNIQUE KEY uk_kreaproducts_inventory_adjustment_line') !== false, 'Fresh installs must allow append-only adjustment generations per inventory line.');
assertSameValue(true, strpos((string) $inventoryAdjustmentSchema, 'KEY idx_kreaproducts_inventory_adjustment_line (entity, fk_inventorydet, status)') !== false, 'Fresh installs must index adjustment history by entity, line, and active status.');
assertSameValue(true, strpos((string) $inventoryAdjustmentHistoryMigration, 'DROP INDEX uk_kreaproducts_inventory_adjustment_line') !== false, 'Upgrades must remove the legacy one-row-per-line unique key.');
assertSameValue(true, strpos((string) $inventoryAdjustmentHistoryMigration, 'CREATE INDEX idx_kreaproducts_inventory_adjustment_line') !== false, 'Upgrades must add the non-unique adjustment history index.');

$inventoryRunnerSource = file_get_contents(__DIR__.'/../scripts/run_inventory_auto_close.php');
assertSameValue(true, strpos((string) $inventoryRunnerSource, "c.objectname = 'KreaProductsInventoryCron'") !== false, 'The isolated runner must select only KreaProducts inventory jobs.');

$mobilePackage = json_decode((string) file_get_contents(__DIR__.'/../stockapp/package.json'), true);
$mobilePackageLock = json_decode((string) file_get_contents(__DIR__.'/../stockapp/package-lock.json'), true);
assertSameValue('4.20.2', $mobilePackage['version'] ?? '', 'The mobile package version must match the module release.');
assertSameValue('4.20.2', $mobilePackageLock['version'] ?? '', 'The mobile lockfile version must match the module release.');
assertSameValue('4.20.2', $mobilePackageLock['packages']['']['version'] ?? '', 'The mobile lockfile root package must match the module release.');

$dismantleSource = file_get_contents(__DIR__.'/../class/productDismantle.class.php');
assertSameValue(true, strpos((string) $dismantleSource, 'createDismantleStockMovement') !== false, 'Dismantling must use its dedicated stock movement boundary.');
assertSameValue(true, strpos((string) $dismantleSource, 'PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE') !== false, 'Kit-parent dismantling outputs must use the scoped parent-movement compatibility boundary.');
assertSameValue(true, strpos((string) $dismantleSource, 'ensureProductStockManagedForMo') !== false, 'Dismantling must enable stock management for every MO product.');
assertSameValue(true, strpos((string) $dismantleSource, 'Product::ENABLED_STOCK') !== false, 'MO product stock management must use the native Dolibarr product constant.');
assertSameValue(false, strpos((string) $dismantleSource, 'mp.fk_stock_movement IS NULL AND mp.fk_warehouse IS NULL') !== false, 'MO execution lines must not bypass stock movements.');

print "Stock logic tests passed.\n";
