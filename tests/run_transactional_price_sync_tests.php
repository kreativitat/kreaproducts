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
 */

// Execute deployed class bodies against isolated lifecycle doubles; no database or network.
function loadProductionClass($path, $className)
{
    $source = file_get_contents((getenv('KREAPRODUCTS_TEST_ROOT') ?: __DIR__.'/..').'/'.$path);
    $start = strpos($source, "\nclass ".$className);
    if ($start === false) {
        throw new RuntimeException('Class not found: '.$className);
    }
    eval(substr($source, $start));
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message.PHP_EOL;
}
function price2num($value, $mode = '') { return round((float) $value, 8); }
function dol_syslog($message, $level = 0) {}
function getDolGlobalInt($name) { return (int) ($GLOBALS['conf']->global->$name ?? 0); }
function isModEnabled($name) { return $name === 'kreaproducts'; }
function getEntity($element) { return '1,8'; }
class User {}
class Conf { public $global; public $entity = 8; }
class Translate
{
    public function load($file) {}
    public function trans($key, ...$values) { return $key.': '.implode(', ', $values); }
}
class ExtraFields { public function __construct($db) {} }
class DolibarrTriggers
{
    const VERSIONS = array('dev' => 'development');
    public $db;
    public $error = '';
    public $errors = array();
    public $family;
    public $description;
    public $version;
    public $picto;
    public function __construct($db) { $this->db = $db; }
}
class CommonObject { public $error = ''; public $errors = array(); }
class Mo {}
class Product
{
    public static $rows = array();
    public static $remoteCalls = 0;
    public static $priceCalls = 0;
    public static $throwOnPrice = false;
    public $id;
    public $ref;
    public $entity = 8;
    public $cost_price;
    public $price = 10;
    public $price_min = 0;
    public $price_base_type = 'HT';
    public $tva_tx = 23;
    public $context = array();
    public $array_options = array('options_kreap_updatesellprice' => 1, 'options_kreap_updatesellpricepct' => 15, 'options_kreap_updatebuyprice' => 1);
    public $oldcopy;
    public function __construct($db) {}
    public function fetch($id)
    {
        $this->id = $id;
        $this->ref = 'P'.$id;
        $this->cost_price = self::$rows[$id]['cost'];
        return 1;
    }
    public function fetch_optionals($id, $extra) { return 1; }
    public function hasFatherOrChild($direction) { return 0; }
    public function update($id, $user)
    {
        self::$rows[$id]['cost'] = $this->cost_price;
        $this->simulateRemoteSync();
        return 1;
    }
    public function updatePrice($price, ...$args)
    {
        self::$priceCalls++;
        if (self::$throwOnPrice) { throw new RuntimeException('Injected price failure'); }
        $this->simulateRemoteSync();
        self::$rows[$this->id]['price'] = $price;
        return 1;
    }
    private function simulateRemoteSync()
    {
        if (empty($this->context['skip_kreawoo_realtime_sync'])) {
            self::$remoteCalls++;
            // Model the production WooCommerce import that disabled stock on the output.
            self::$rows[$this->id]['stockable'] = 0;
        }
    }
}
class ProductUpdater
{
    public static function prepareProductCostUpdate($product) { $product->oldcopy = clone $product; }
    public static function getLastErrors() { return array(); }
    public static function batchUpdateCostPrices($ids, $sync)
    {
        return array(9000 => array('updated' => true));
    }
}
class ProductHierarchy
{
    public static $cascade = false;
    public static function updateProductAttributes($id, $user)
    {
        if (self::$cascade && $id !== 9000) {
            $dependent = new Product(null);
            $dependent->fetch(9000);
            $dependent->oldcopy = (object) array('cost_price' => 9);
            $dependent->context['skip_kreawoo_realtime_sync'] = true;
            $GLOBALS['trigger']->runTrigger('PRODUCT_MODIFY', $dependent, $user, $GLOBALS['langs'], $GLOBALS['conf']);
        }
        return 1;
    }
}
$conf = new Conf();
$conf->global = (object) array('KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST' => 1, 'KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE' => 1);
$langs = new Translate();
$user = new User();
$db = new stdClass();
$mode = $argv[1] ?? 'sync';

if ($mode === 'movement') {
    loadProductionClass('class/productDismantle.class.php', 'ProductDismantleController');
    $controller = new ProductDismantleController($db);
    $method = new ReflectionMethod($controller, 'createDismantleStockMovement');
    $method->setAccessible(true);
    $product = new Product($db);
    Product::$rows[7087] = array('cost' => 14.715, 'stockable' => 1);
    $product->fetch(7087);
    $stock = new class {
        public $result = 0;
        public function _create(...$args) { return $this->result; }
    };
    foreach (array(0, -1, 1234) as $result) {
        $controller->error = '';
        $stock->result = $result;
        $actual = $method->invoke($controller, $stock, $product, $user, 49, 16, 14.715, 'Test', 1788946200);
        check($actual === $result, 'native movement result '.$result.' retained');
        check($result > 0 ? $controller->error === '' : strpos($controller->error, 'P7087, 49') !== false, 'movement '.$result.' has correct product/warehouse error');
    }
    exit;
}
if ($mode === 'propagation') {
    class ProductDismantleController
    {
        public $error = 'Specific dismantling failure';
        public function __construct($db) {}
        public function productInDismantleCategory($id) { return true; }
        public function findBom($id) { return 214; }
        public function produceAndConsume(...$args) { return -1; }
    }
    loadProductionClass('class/KreaProductsStockMovementService.class.php', 'KreaProductsStockMovementService');
    $service = new KreaProductsStockMovementService();
    $method = new ReflectionMethod($service, 'dismantleIfNeeded');
    $method->setAccessible(true);
    $db = new class { public function jdate($date) { return 1; } };
    $move = (object) array('product_id' => 7599, 'qty' => 4, 'price' => 58.86, 'label' => 'Test', 'origin_id' => 241467, 'origintype' => 'invoice_supplier', 'datem' => '', 'id' => 1, 'errors' => array());
    check($method->invoke($service, $move, $db, $user) === -1, 'stock service preserves failure');
    check($move->error === 'Specific dismantling failure' && $move->errors === array($move->error), 'stock service propagates dismantling detail');
    exit;
}
if ($mode === 'sync') {
    class KreaProductsStockMovementService
    {
        public function handleStockMovement($object, $db, $conf, $user) { return -1; }
    }
}
loadProductionClass('core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php', 'InterfaceKreaProductsTriggers');
$trigger = new InterfaceKreaProductsTriggers($db);
foreach (array('PRODUCT_MODIFY', 'PRODUCT_PRICE_MODIFY') as $event) {
    foreach (array(true, false) as $suppressed) {
        Product::$rows = array(7087 => array('cost' => 14.715, 'stockable' => 1), 9000 => array('cost' => 20, 'stockable' => 1));
        Product::$remoteCalls = 0;
        Product::$priceCalls = 0;
        ProductHierarchy::$cascade = true;
        $source = new Product($db);
        $source->fetch(7087);
        $source->oldcopy = (object) array('cost_price' => 14.71375);
        $source->context = array('skip_kreawoo_realtime_sync' => $suppressed, 'unrelated' => 'keep');
        $before = $source->context;
        $trigger->runTrigger($event, $source, $user, $langs, $conf);
        check(Product::$remoteCalls === ($suppressed ? 0 : 1), $event.' sync guard '.($suppressed ? 'preserved' : 'normal edits preserved'));
        check(Product::$priceCalls === 2 && abs(Product::$rows[7087]['price'] - 16.92225) < 0.00001, 'source and dependent prices calculated');
        check(Product::$rows[9000]['stockable'] === 1 && Product::$rows[7087]['stockable'] === ($suppressed ? 1 : 0), 'transactional source/dependent stock flags protected');
        check($source->context === $before, 'source context unchanged');
    }
}
ProductHierarchy::$cascade = false;
Product::$remoteCalls = 0;
Product::$rows = array(7087 => array('cost' => 10, 'stockable' => 1), 9000 => array('cost' => 20, 'stockable' => 1));
$invoice = (object) array('id' => 241467, 'entity' => 8, 'type' => 0, 'lines' => array((object) array('fk_product' => 7087, 'qty' => 16, 'total_ht' => 235.44)));
$method = new ReflectionMethod($trigger, 'syncCostAndSellPriceFromValidatedSupplierInvoice');
$method->setAccessible(true);
$method->invoke($trigger, $invoice, $user, $conf);
check(Product::$remoteCalls === 0 && Product::$rows[7087]['stockable'] === 1, 'supplier cost and selling-price writes suppress remote sync');
check(isset(Product::$rows[9000]['price']), 'supplier cascade price still calculated');
$invoice->entity = 2;
$before = Product::$rows;
$method->invoke($trigger, $invoice, $user, $conf);
check(Product::$rows === $before, 'foreign-entity invoice makes no product writes');

$method = new ReflectionMethod($trigger, 'syncSellPriceFromCostIfEnabled');
$method->setAccessible(true);
Product::$throwOnPrice = true;
try { $method->invoke($trigger, 7087, $user, $conf, true, true); } catch (RuntimeException $expected) {}
Product::$throwOnPrice = false;
$count = Product::$priceCalls;
$method->invoke($trigger, 7087, $user, $conf, true, true);
check(Product::$priceCalls === $count + 1 && Product::$remoteCalls === 0, 'failure clears recursion guard for safe retry');
foreach (array('', 'Specific dismantling failure') as $detail) {
    $move = (object) array('product_id' => 7599, 'error' => $detail);
    check($trigger->runTrigger('STOCK_MOVEMENT', $move, $user, $langs, $conf) === -1, 'stock trigger refuses failed movement');
    check($trigger->error !== '' && ($detail === '' || $trigger->error === $detail) && $trigger->errors === array($trigger->error), 'stock trigger returns visible failure detail');
}
