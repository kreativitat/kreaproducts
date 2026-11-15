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

// Runs the deployed trigger and cost cascade end to end over an in-memory
// product tree. Isolated doubles only: no database or network.
// Usage: php tests/run_price_cascade_chain_tests.php [mids] [tops-per-mid] [chain-depth]
const MAIN_DB_PREFIX = 'llx_';
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
function dol_clone($object, $native = 0) { return clone $object; }
function isModEnabled($name) { return $name === 'kreaproducts'; }
function getEntity($element) { return '1,8'; }
class User { public $id = 1; }
class Conf { public $global; public $entity = 8; public $bom; }
class Translate { public function load($file) {} public function trans($key) { return $key; } }
class ExtraFields { public function __construct($db) {} }
class Mo { const STATUS_CANCELED = 9; }
class DolibarrTriggers
{
    const VERSIONS = array('dev' => 'development');
    public $db; public $error = ''; public $errors = array();
    public $family; public $description; public $version; public $picto;
    public function __construct($db) { $this->db = $db; }
}
class FakeDb
{
    public $queries = 0;
    /** @var array<int,array<int,float>> parent => child => qty */
    public $recipes = array();
    public $usedIn = array();
    public function link($parent, $child, $qty)
    {
        $this->recipes[$parent][$child] = $qty;
        $this->usedIn[$child][$parent] = true;
    }
    public function lasterror() { return ''; }
    public function free($result) {}
    public function num_rows($result) { return count($result); }
    public function DDLDescTable($table, $column) { return new ArrayObject(array(1)); }
    public function fetch_object($result)
    {
        $iterator = $result->iterator ?? ($result->iterator = $result->getIterator());
        if (!$iterator->valid()) { return null; }
        $row = $iterator->current();
        $iterator->next();
        return $row;
    }
    public function query($sql)
    {
        $this->queries++;
        $rows = array();
        if (strpos($sql, 'product_association') !== false) {
            if (preg_match('/fk_product_fils IN \(([0-9,]+)\)/', $sql, $match)) {
                foreach (explode(',', $match[1]) as $child) {
                    foreach (array_keys($this->usedIn[(int) $child] ?? array()) as $parent) {
                        $rows[] = $this->association($parent, (int) $child);
                    }
                }
            } elseif (preg_match('/fk_product_pere IN \(([0-9,]+)\)/', $sql, $match)) {
                foreach (explode(',', $match[1]) as $parent) {
                    foreach (array_keys($this->recipes[(int) $parent] ?? array()) as $child) {
                        $rows[] = $this->association((int) $parent, $child);
                    }
                }
            }
        } elseif (strpos($sql, 'product_extrafields') !== false && preg_match('/fk_object IN \(([0-9,]+)\)/', $sql, $match)) {
            foreach (explode(',', $match[1]) as $id) {
                $rows[] = (object) array('fk_object' => (int) $id, 'kreap_updatebuyprice' => 1, 'kreap_syncprice' => 0);
            }
        } else {
            throw new RuntimeException('Unexpected SQL: '.$sql);
        }
        return new ArrayObject($rows);
    }
    private function association($parent, $child)
    {
        return (object) array('parent' => $parent, 'child' => $child, 'qty' => $this->recipes[$parent][$child],
            'p_label' => 'P'.$parent, 'p_ref' => 'P'.$parent, 'p_cost_price' => Product::$rows[$parent]['cost'],
            'f_label' => 'P'.$child, 'f_ref' => 'P'.$child, 'f_cost_price' => Product::$rows[$child]['cost']);
    }
}
// Native lifecycle double: update() and updatePrice() fire the module trigger like Dolibarr does.
class Product
{
    public static $rows = array();
    public static $updates = 0;
    public static $priceUpdates = 0;
    public $id; public $ref; public $entity = 1; public $cost_price; public $price; public $price_min = 0;
    public $price_base_type = 'HT'; public $tva_tx = 23; public $context = array(); public $oldcopy; public $error = '';
    public $array_options = array('options_kreap_updatesellprice' => 1, 'options_kreap_updatesellpricepct' => 50, 'options_kreap_updatebuyprice' => 1);
    public function __construct($db) {}
    public function fetch($id)
    {
        if (!isset(self::$rows[$id])) { return 0; }
        $this->id = $id;
        $this->ref = 'P'.$id;
        $this->cost_price = self::$rows[$id]['cost'];
        $this->price = self::$rows[$id]['price'];
        return 1;
    }
    public function fetch_optionals($id, $extra) { return 1; }
    public function update($id, $user)
    {
        self::$updates++;
        self::$rows[$id]['cost'] = $this->cost_price;
        if (empty($this->oldcopy)) { $this->oldcopy = clone $this; }
        $result = $GLOBALS['trigger']->runTrigger('PRODUCT_MODIFY', $this, $user, $GLOBALS['langs'], $GLOBALS['conf']);
        $this->oldcopy = null;
        return $result < 0 ? -1 : 1;
    }
    public function updatePrice($price, ...$args)
    {
        self::$priceUpdates++;
        self::$rows[$this->id]['price'] = $price;
        $this->price = $price;
        $result = $GLOBALS['trigger']->runTrigger('PRODUCT_PRICE_MODIFY', $this, $args[1], $GLOBALS['langs'], $GLOBALS['conf']);
        return $result < 0 ? -1 : 1;
    }
}
loadProductionClass('class/KreaProductsBomCostCalculator.class.php', 'KreaProductsBomCostCalculator');
loadProductionClass('class/ProductUpdater.class.php', 'ProductUpdater');
loadProductionClass('core/triggers/interface_99_modKreaProducts_KreaProductsTriggers.class.php', 'InterfaceKreaProductsTriggers');

$mids = max(1, (int) ($argv[1] ?? 40));
$topsPerMid = max(1, (int) ($argv[2] ?? 25));
$chainDepth = max(1, (int) ($argv[3] ?? 60));

$conf = new Conf();
$conf->global = (object) array('KREAPRODUCTS_AUTO_SYNC_SELL_PRICE_FROM_COST' => 1, 'KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE' => 1);
$conf->bom = (object) array('enabled' => 0);
$langs = new Translate();
$user = new User();
$trigger = new InterfaceKreaProductsTriggers(null);

// Tree: one purchased leaf -> $mids preparations -> $topsPerMid dishes each (every
// dish also uses the leaf directly), plus one deep chain and a second leaf.
$build = function () use ($mids, $topsPerMid, $chainDepth) {
    $db = new FakeDb();
    Product::$rows = array();
    Product::$updates = 0;
    Product::$priceUpdates = 0;
    $next = 1;
    $add = function ($cost) use (&$next) { Product::$rows[$next] = array('cost' => $cost, 'price' => 0.0); return $next++; };
    $leaf = $add(1.0);
    $other = $add(2.0);
    $all = array();
    for ($m = 0; $m < $mids; $m++) {
        $mid = $add(0.0);
        $db->link($mid, $leaf, 2);
        $db->link($mid, $other, 1);
        $all[] = $mid;
        for ($t = 0; $t < $topsPerMid; $t++) {
            $top = $add(0.0);
            $db->link($top, $mid, 3);
            $db->link($top, $leaf, 0.5);
            $all[] = $top;
        }
    }
    $previous = $leaf;
    for ($d = 0; $d < $chainDepth; $d++) {
        $node = $add(0.0);
        $db->link($node, $previous, 1);
        $all[] = $node;
        $previous = $node;
    }
    return array($db, $leaf, $other, $all);
};
// Expected cost recomputed independently from the recipes.
$expectedCost = function ($db, $id) use (&$expectedCost) {
    if (empty($db->recipes[$id])) { return (float) Product::$rows[$id]['cost']; }
    $total = 0.0;
    foreach ($db->recipes[$id] as $child => $qty) { $total += $expectedCost($db, $child) * $qty; }
    return $total;
};
$verify = function ($db, $all, $label) use ($expectedCost) {
    $wrongCost = 0;
    $wrongPrice = 0;
    foreach ($all as $id) {
        $cost = $expectedCost($db, $id);
        if (abs(Product::$rows[$id]['cost'] - $cost) > 0.0001) { $wrongCost++; }
        if (abs(Product::$rows[$id]['price'] - round($cost * 1.5, 8)) > 0.0001) { $wrongPrice++; }
    }
    check($wrongCost === 0, $label.': every one of '.count($all).' dependents has the recalculated cost');
    check($wrongPrice === 0, $label.': every dependent has the selling price derived from its new cost');
};

// 1. Validated supplier invoice changes the leaf cost.
list($db, $leaf, $other, $all) = $build();
$GLOBALS['db'] = $db;
$invoice = (object) array('id' => 1, 'entity' => 8, 'type' => 0, 'lines' => array((object) array('fk_product' => $leaf, 'qty' => 10, 'total_ht' => 40)));
$method = new ReflectionMethod($trigger, 'syncCostAndSellPriceFromValidatedSupplierInvoice');
$method->setAccessible(true);
$started = microtime(true);
$method->invoke($trigger, $invoice, $user, $conf);
$elapsed = microtime(true) - $started;
check(Product::$rows[$leaf]['cost'] == 4.0 && Product::$rows[$leaf]['price'] == 6.0, 'invoice: purchased product cost and selling price updated');
$verify($db, $all, 'invoice');
check(Product::$updates === count($all) + 1 && Product::$priceUpdates === count($all) + 1, 'invoice: each product written exactly once (no repeated or recursive passes)');
printf("INFO invoice cascade: %d products, %d hierarchy queries, %.3fs, peak %.1f MB\n", count($all) + 1, $db->queries, $elapsed, memory_get_peak_usage(true) / 1048576);
check($db->queries < 60 + 4 * $chainDepth, 'invoice: hierarchy queries grow with tree depth, not with product count');

// 2. Manual cost edit on the product card (PRODUCT_MODIFY).
list($db, $leaf, $other, $all) = $build();
$GLOBALS['db'] = $db;
$product = new Product(null);
$product->fetch($leaf);
ProductUpdater::prepareProductCostUpdate($product);
$product->cost_price = 7.0;
check($product->update($leaf, $user) > 0, 'manual edit: native save succeeds');
$verify($db, $all, 'manual edit');
check(Product::$updates === count($all) + 1, 'manual edit: each product written exactly once');

// 3. Direct cascade call (stock dismantle, purchase-price page button) with two changed sources.
list($db, $leaf, $other, $all) = $build();
$GLOBALS['db'] = $db;
Product::$rows[$leaf]['cost'] = 3.0;
Product::$rows[$other]['cost'] = 5.0;
ProductUpdater::batchUpdateCostPrices(array($leaf, $other), true);
check(ProductUpdater::getLastErrors() === array(), 'direct cascade: no errors');
$verify($db, $all, 'direct cascade');
check(Product::$updates === count($all), 'direct cascade: each dependent written exactly once');

// 4. A recipe loop is refused before any write.
list($db, $leaf, $other, $all) = $build();
$GLOBALS['db'] = $db;
$db->link($leaf, end($all), 1);
$before = Product::$rows;
ProductUpdater::batchUpdateCostPrices(array($leaf), true);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before, 'cyclic recipe is rejected without writes or endless looping');
