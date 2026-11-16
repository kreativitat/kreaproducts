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
class User {
    public $id = 1;
    public $denied = array();
    public function hasRight($module, $permission) { return empty($this->denied[$module]); }
}
class Conf { public $global; public $entity = 8; public $bom; }
class Translate { public function load($file) {} public function trans($key) { return $key; } }
class ExtraFields { public function __construct($db) {} }
class Mo { const STATUS_CANCELED = 9; }
class CommonObject { public $error = ''; public $errors = array(); }
class DolibarrTriggers
{
    const VERSIONS = array('dev' => 'development');
    public $db; public $error = ''; public $errors = array();
    public $family; public $description; public $version; public $picto;
    public function __construct($db) { $this->db = $db; }
}
class CascadeRows extends ArrayObject { public $iterator; }
class FakeDb
{
    public $queries = 0;
    /** @var array<int,array<int,float>> parent => child => qty */
    public $recipes = array();
    public $usedIn = array();
    public $syncEnabled = true;
    public $boms = array();
    public $production = array();
    public $failReads = false;
    public function link($parent, $child, $qty)
    {
        $this->recipes[$parent][$child] = $qty;
        $this->usedIn[$child][$parent] = true;
    }
    public function lasterror() { return ''; }
    public function free($result) {}
    public function num_rows($result) { return count($result); }
    public function DDLDescTable($table, $column) { return new CascadeRows(array(1)); }
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
        if ($this->failReads) { return false; }
        $rows = array();
        if (strpos($sql, 'product_association') !== false) {
            checkScope($sql, array('p.entity IN (1,8)', 'f.entity IN (1,8)'));
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
                $rows[] = (object) array('fk_object' => (int) $id, 'kreap_updatebuyprice' => (int) $this->syncEnabled, 'kreap_syncprice' => 0);
            }
        } elseif (strpos($sql, 'SELECT b.rowid, b.fk_product') === 0) {
            checkScope($sql, array('b.bomtype = 0 AND b.status = 1', 'b.entity IN (0,8)', 'p.entity IN (1,8)'));
            $rows = array_values($this->boms);
        } elseif (strpos($sql, 'SELECT mo.fk_bom') === 0) {
            checkScope($sql, array('mo.entity = 8', 'mo.status <> 9', "mp.role = 'produced'"));
            $rows = $this->production;
        } elseif (strpos($sql, 'SELECT b.fk_product as parent') === 0) {
            checkScope($sql, array('p.entity IN (1,8)', 'f.entity IN (1,8)', 'cprod.entity IN (1,8)'));
            preg_match('/b.rowid IN \(([0-9,]+)\)/', $sql, $selected);
            $ids = array_map('intval', explode(',', $selected[1]));
            foreach ($this->boms as $bom) {
                if (!in_array($bom->rowid, $ids, true)) { continue; }
                if (preg_match('/AND b.fk_product IN \(([0-9,]+)\)/', $sql, $match)
                    && !in_array($bom->fk_product, array_map('intval', explode(',', $match[1])), true)) { continue; }
                if (preg_match('/AND \(bl.fk_product IN \(([0-9,]+)\)/', $sql, $match)
                    && !in_array($bom->child, array_map('intval', explode(',', $match[1])), true)) { continue; }
                $row = $this->association($bom->fk_product, $bom->child);
                $row->line_qty = $bom->line_qty;
                $row->line_efficiency = $bom->line_efficiency;
                $row->header_qty = $bom->header_qty;
                $row->bom_id = $bom->rowid;
                $row->bom_ref = $bom->ref;
                $rows[] = $row;
            }
        } else {
            throw new RuntimeException('Unexpected SQL: '.$sql);
        }
        return new CascadeRows($rows);
    }
    private function association($parent, $child)
    {
        return (object) array('parent' => $parent, 'child' => $child, 'qty' => $this->recipes[$parent][$child] ?? 0,
            'p_label' => 'P'.$parent, 'p_ref' => 'P'.$parent, 'p_cost_price' => Product::$rows[$parent]['cost'],
            'f_label' => 'P'.$child, 'f_ref' => 'P'.$child, 'f_cost_price' => Product::$rows[$child]['cost']);
    }
}
function checkScope($sql, array $clauses)
{
    foreach ($clauses as $clause) {
        if (strpos($sql, $clause) === false) { throw new RuntimeException('Missing scope clause: '.$clause); }
    }
}
// Native lifecycle double: update() and updatePrice() fire the module trigger like Dolibarr does.
class Product
{
    const TYPE_SERVICE = 1;
    public static $rows = array();
    public static $updates = 0;
    public static $priceUpdates = 0;
    public static $writeOrder = array();
    public static $failId = 0;
    public $type = 0;
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
        $this->entity = self::$rows[$id]['entity'] ?? 1;
        $this->type = self::$rows[$id]['type'] ?? 0;
        return 1;
    }
    public function fetch_optionals($id, $extra) { return 1; }
    public function update($id, $user)
    {
        self::$updates++;
        if ($id === self::$failId) { $this->error = 'Injected product update failure'; return -1; }
        self::$writeOrder[] = $id;
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

// 3. Automatic cascade call (stock dismantle) with two changed sources.
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

// 5. Manual tree: leaf -> child recipe -> selected manufacturing product -> parent.
// A second parent also consumes the child; a separate product must remain untouched.
$manualTree = function () use ($conf, $user) {
    $db = new FakeDb();
    $db->syncEnabled = false;
    Product::$updates = 0;
    Product::$priceUpdates = 0;
    Product::$writeOrder = array();
    Product::$failId = 0;
    $user->denied = array();
    Product::$rows = array();
    foreach (array(1 => 2.0, 2 => 80.0, 3 => 0.0, 4 => 90.0, 5 => 70.0, 6 => 444.0) as $id => $cost) {
        Product::$rows[$id] = array('cost' => $cost, 'price' => 0.0);
    }
    $db->link(2, 1, 3);
    $db->link(4, 3, 4);
    $db->link(5, 2, 5);
    $db->boms[100] = (object) array('rowid' => 100, 'fk_product' => 3, 'entity' => 8, 'ref' => 'FM100',
        'date_valid' => '2026-01-01', 'date_creation' => '2026-01-01', 'child' => 2,
        'line_qty' => 8, 'line_efficiency' => 0.5, 'header_qty' => 8);
    $db->boms[101] = (object) array('rowid' => 101, 'fk_product' => 3, 'entity' => 8, 'ref' => 'FM101',
        'date_valid' => '2026-11-01', 'date_creation' => '2026-11-01', 'child' => 1,
        'line_qty' => 50, 'line_efficiency' => 1, 'header_qty' => 1);
    $db->production = array((object) array('fk_bom' => 100, 'production_date' => '2026-10-01', 'rowid' => 42));
    $conf->bom->enabled = 1;
    $conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE = 0;
    return $db;
};
$verifyManualTree = function () {
    foreach (array(1 => 2.0, 2 => 6.0, 3 => 12.0, 4 => 48.0, 5 => 30.0, 6 => 444.0) as $id => $cost) {
        check(abs(Product::$rows[$id]['cost'] - $cost) < 0.0001, 'manual tree: product '.$id.' has expected cost '.$cost);
    }
};
$db = $manualTree();
$results = ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() === array(), 'manual tree: mixed association/BOM/MO chain succeeds with purchase sync disconnected');
$verifyManualTree();
check(Product::$writeOrder === array(2, 3, 5, 4), 'manual tree: descendants, selected product and all consuming parents saved once, bottom-up');
check(!empty($results[3]['is_original']) && count($results) === 4, 'manual tree: selected recipe is included in recalculation results');
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(Product::$rows === $before && Product::$updates === 4, 'manual tree: repeating the command preserves costs without duplicate writes');

// Native triggers can re-enter with automatic sync enabled, without replacing the manual map.
$db = $manualTree();
$db->syncEnabled = true;
$conf->global->KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE = 1;
ProductUpdater::recalculateProductCostTree(3);
$verifyManualTree();
check(Product::$updates === 4, 'manual tree: native cost/price triggers cannot duplicate or truncate recalculation');

// Disabled automatic sync remains disabled; authoritative changed-source costs survive.
$db = $manualTree();
Product::$rows[3]['cost'] = 99.0;
$before = Product::$rows;
ProductUpdater::batchUpdateCostPrices(array(3), true);
check(Product::$rows === $before && Product::$updates === 0, 'automatic cascade still respects disabled per-product buy-sync flags');
$db->syncEnabled = true;
ProductUpdater::batchUpdateCostPrices(array(3), false);
check(Product::$rows === $before, 'automatic cascade still respects its global sync argument');
ProductUpdater::batchUpdateCostPrices(array(3), true);
check(Product::$rows[3]['cost'] === 99.0 && Product::$rows[4]['cost'] == 396.0, 'automatic cascade preserves changed source cost even when it has a manufacturing recipe');

// Dismantling outputs are allocated inputs, even if they also have a recipe.
$db = $manualTree();
Product::$rows[3]['cost'] = 7.0;
ProductUpdater::batchUpdateCostPrices(array(3), true, true);
check(ProductUpdater::getLastErrors() === array() && Product::$rows[3]['cost'] == 7.0 && Product::$rows[4]['cost'] == 28.0,
    'forced dismantle propagation preserves allocated output cost and refreshes parents through disabled switches');

// Invalid graphs and query failures fail before writes; subsequent calls recover cleanly.
$db = $manualTree();
$db->link(1, 4, 1);
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before, 'manual cycle is refused before any product write');
$db = $manualTree();
$db->failReads = true;
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before, 'manual hierarchy read failure cannot produce partial or zero costs');
$db->failReads = false;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() === array() && Product::$updates === 4, 'manual cascade guard and errors reset after failure');

$db = $manualTree();
Product::$failId = 2;
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before && Product::$writeOrder === array(), 'failed child persistence stops the cascade before any parent write');

// Recheck shared ownership and each resource's native write right at persistence.
$db = $manualTree();
Product::$rows[2]['entity'] = 2;
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before, 'product moved outside the visible entity scope is denied');
$db = $manualTree();
Product::$rows[2]['type'] = Product::TYPE_SERVICE;
$user->denied['service'] = true;
$before = Product::$rows;
ProductUpdater::recalculateProductCostTree(3);
check(ProductUpdater::getLastErrors() !== array() && Product::$rows === $before, 'manual cascade cannot edit a component without its service write right');

// Exercise the real manual dismantle refresh, allocation, readback and forced cascade.
class DismantleCostDb extends FakeDb
{
    public function query($sql)
    {
        if (strpos($sql, 'SELECT pe.kreap_dismantle') === 0) {
            return new CascadeRows(array((object) array('kreap_dismantle' => 1)));
        }
        if (strpos($sql, 'SELECT p.rowid FROM llx_product as p') === 0) {
            checkScope($sql, array('p.entity IN (1,8)'));
            preg_match('/p.rowid = ([0-9]+)/', $sql, $match);
            $id = (int) $match[1];
            return new CascadeRows(in_array(Product::$rows[$id]['entity'] ?? 1, array(1, 8), true) ? array((object) array('rowid' => $id)) : array());
        }
        if (strpos($sql, 'SELECT rowid FROM llx_bom_bom') === 0) {
            checkScope($sql, array('bomtype = 1', 'status = 1', 'entity IN (0,1,8)'));
            return new CascadeRows(array((object) array('rowid' => 200)));
        }
        if (strpos($sql, 'SELECT rowid, fk_product, qty, bomtype, status') === 0) {
            return new CascadeRows(array((object) array('fk_product' => 6, 'qty' => 1, 'bomtype' => 1, 'status' => 1)));
        }
        if (strpos($sql, 'SELECT COALESCE(bl.fk_product, cb.fk_product)') === 0) {
            return new CascadeRows(array((object) array('fk_product' => 3, 'qty' => 2)));
        }
        return parent::query($sql);
    }
}
loadProductionClass('class/productDismantle.class.php', 'ProductDismantleController');
$buildDismantle = function () use ($manualTree) {
    $original = $manualTree();
    $db = new DismantleCostDb();
    foreach (array('recipes', 'usedIn', 'syncEnabled', 'boms', 'production') as $field) { $db->$field = $original->$field; }
    return $db;
};
$db = $buildDismantle();
$controller = new ProductDismantleController($db);
check($controller->refreshDismantleOutputCostPrices(6, $user, true) === 1, 'real manual dismantle refresh succeeds without invoice/MO writes');
check(Product::$rows[3]['cost'] == 222.0 && Product::$rows[4]['cost'] == 888.0 && Product::$rows[2]['cost'] == 6.0,
    'real manual dismantle allocation is authoritative and persists disconnected recipe parents');

$db = $buildDismantle();
Product::$rows[3]['type'] = Product::TYPE_SERVICE;
$user->denied['service'] = true;
$before = Product::$rows;
$controller = new ProductDismantleController($db);
check($controller->refreshDismantleOutputCostPrices(6, $user, true) === -1 && Product::$rows === $before,
    'manual dismantle refresh denies service outputs without their native write permission');
