<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Luracast\Restler {
    class RestException extends \RuntimeException {
        public function __construct($status, $message) { parent::__construct($message, $status); }
    }
}

namespace {
    define('MAIN_DB_PREFIX', 'test_');
    function isModEnabled($name) { return $name === 'kreaproducts'; }
    function getEntity($name) { return '1,8'; }
    function dol_syslog($message, $level = 0) {}
    function dol_include_once($path) {}
    class DolibarrApi {
        public static $access = true;
        public static function _checkAccessToResource($kind, $id) { return self::$access; }
    }
    class DolibarrApiAccess { public static $user; }
    class FoodResult {
        public $rows;
        public function __construct($rows) { $this->rows = array_values($rows); }
    }
    class FoodUser {
        public $denied = array();
        public function hasRight(...$parts) { return !in_array(implode('.', $parts), $this->denied, true); }
    }
    class FoodDB {
        public $products;
        public $nutrition = array();
        public $allergens = array();
        public $dictionary;
        public $queries = array();
        public $snapshots = array();
        public $writes = array();
        public $begins = 0;
        public $commits = 0;
        public $rollbacks = 0;
        public $failAllergen = false;
        public $ignoreWrites = false;
        public function __construct() {
            $this->products = array(42 => array('id' => 42, 'entity' => 8, 'type' => 0, 'nut_mode' => '0', 'allergen_mode' => '0'));
            $this->dictionary = array(
                array('rowid' => 1, 'code' => 'NOAL', 'label' => 'No allergens', 'active' => 0),
                array('rowid' => 7, 'code' => 'LEIT', 'label' => 'Milk', 'active' => 1),
                array('rowid' => 10, 'code' => 'EGGS', 'label' => 'Eggs', 'active' => 1),
            );
        }
        public function plimit($limit) { return ' LIMIT '.((int) $limit); }
        public function query($sql) {
            $this->queries[] = $sql;
            if (strpos($sql, 'SELECT pe.kreap_calc_nut') === 0) {
                $product = $this->products[42];
                $food = $this->nutrition ? reset($this->nutrition)['is_food'] : null;
                return new FoodResult(array(array('kreap_calc_nut' => $product['nut_mode'], 'kreap_calc_allergens' => $product['allergen_mode'], 'is_food' => $food)));
            }
            if (strpos($sql, 'SELECT fk_object') === 0) { return new FoodResult(array(array('fk_object' => 42))); }
            if (strpos($sql, 'FROM test_product WHERE') !== false) {
                return new FoodResult($this->products[42]['entity'] === 8 ? array(array('rowid' => 42)) : array());
            }
            if (strpos($sql, 'FROM test_c_kreaproducts ') !== false) { return new FoodResult($this->dictionary); }
            $table = strpos($sql, 'test_kreaproducts_nutritional') !== false ? 'nutrition' : 'allergens';
            $rows = array();
            foreach ($this->$table as $id => $row) {
                if ($row['fk_product'] === 42) { $rows[] = array('rowid' => $id); }
            }
            return new FoodResult($rows);
        }
        public function fetch_object($result) { return $result->rows ? (object) array_shift($result->rows) : false; }
        public function free($result) {}
        public function begin() {
            $this->begins++;
            $this->queries[] = 'BEGIN';
            $this->snapshots[] = serialize(array($this->nutrition, $this->allergens));
            return 1;
        }
        public function commit() { $this->commits++; array_pop($this->snapshots); return 1; }
        public function rollback() {
            $this->rollbacks++;
            if ($this->snapshots) { list($this->nutrition, $this->allergens) = unserialize(array_pop($this->snapshots)); }
            return 1;
        }
    }
    class Product {
        private $db;
        public $id;
        public $entity;
        public $type;
        public function __construct($db) { $this->db = $db; }
        public function fetch($id) {
            $this->db->queries[] = 'DAO_PRODUCT_FETCH';
            if (!isset($this->db->products[$id])) { return 0; }
            foreach (array('id', 'entity', 'type') as $field) { $this->$field = $this->db->products[$id][$field]; }
            return 1;
        }
    }
    class FoodRecord {
        protected $db;
        protected $table;
        public $id;
        public $fk_product;
        public $fk_allergen;
        public $is_food = 1;
        public $traces;
        public $energy_kcal;
        public $energy_kj;
        public $fat;
        public $saturates;
        public $carbohydrates;
        public $sugars;
        public $protein;
        public $salt;
        public $fiber;
        public $unknown = 'preserve';
        public function __construct($db) { $this->db = $db; }
        public function fetch($id) {
            $this->db->queries[] = 'DAO_DECLARATION_FETCH';
            if (!isset($this->db->{$this->table}[$id])) { return 0; }
            $this->id = $id;
            foreach ($this->db->{$this->table}[$id] as $field => $value) { $this->$field = $value; }
            return 1;
        }
        public function create($user) { $this->id = count($this->db->{$this->table}) + 100; return $this->persist('create'); }
        public function update($user) { return $this->persist('update'); }
        private function persist($action) {
            $this->db->writes[] = array($this->table, $action, $this->id);
            if ($this->table === 'allergens' && $this->db->failAllergen) { return -1; }
            if (!$this->db->ignoreWrites) {
                $values = get_object_vars($this);
                unset($values['db'], $values['table']);
                $this->db->{$this->table}[$this->id] = $values;
            }
            return $this->id;
        }
        public function delete($user) {
            $this->db->writes[] = array($this->table, 'delete', $this->id);
            unset($this->db->{$this->table}[$this->id]);
            return 1;
        }
    }
    class Nutritional extends FoodRecord { protected $table = 'nutrition'; }
    class ProductAllergens extends FoodRecord { protected $table = 'allergens'; }

    require_once __DIR__.'/../class/KreaProductsNutritionEditPolicy.class.php';
    require_once __DIR__.'/../class/KreaProductsFoodSetupService.class.php';
    $checks = 0;
    function check($condition, $message) {
        global $checks;
        if (!$condition) { throw new \RuntimeException($message); }
        $checks++;
    }
    function expectError($status, $callable) {
        try { $callable(); } catch (\Luracast\Restler\RestException $exception) {
            check($exception->getCode() === $status, 'Expected HTTP '.$status.'; got '.$exception->getCode().': '.$exception->getMessage());
            return;
        }
        throw new \RuntimeException('Expected failure '.$status);
    }
    function fixture() {
        $db = new FoodDB(); $user = new FoodUser();
        return array($db, $user, new KreaProductsFoodSetupService($db, $user, 8));
    }
    function patch($service, $values) {
        return array_merge(array('entity_id' => 8, 'confirm' => true, 'dry_run' => false, 'expected_revision' => $service->read(42)['revision']), $values);
    }

    list($db, $user, $service) = fixture();
    $read = $service->read(42);
    check($read['nutrition'] === null && $read['allergens'] === array(), 'Missing declarations stay missing');
    $preview = $service->save(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('protein' => '0.0000')));
    check($preview['status'] === 'dry_run' && $preview['after']['nutrition']['protein'] === '0', 'Preview canonicalizes explicit zero');
    check($db->writes === array() && $db->begins === 0, 'Preview performs no native writes or transaction');
    $saved = $service->save(42, patch($service, array('nutrition' => array('protein' => '3.5000', 'salt' => '0'), 'allergens' => array(array('allergen_id' => 7, 'declaration' => 'contains')))));
    check($saved['status'] === 'confirmed' && $saved['after']['nutrition']['protein'] === '3.5', 'Native create readback confirms canonical nutrition');
    check($db->commits === 1 && $db->rollbacks === 0, 'Apply commits one atomic transaction');
    check(count(array_filter($db->queries, function ($sql) { return strpos($sql, 'FOR UPDATE') !== false; })) >= 4, 'Apply locks parent, mode and declaration rows');
    $beginIndex = array_search('BEGIN', $db->queries, true);
    $afterBegin = array_slice($db->queries, $beginIndex + 1);
    check(count($afterBegin) >= 6 && count(array_filter(array_slice($afterBegin, 0, 5), function ($sql) { return strpos($sql, 'FOR UPDATE') !== false; })) === 5
        && $afterBegin[5] === 'DAO_PRODUCT_FETCH', 'All parent/mode/declaration/dictionary locks precede the first plain DAO read');
    $again = $service->save(42, patch($service, array('nutrition' => array('protein' => '3.5'))));
    check($again['status'] === 'unchanged' && count($db->writes) === 2, 'Repeated declaration does not write again');
    $updated = $service->save(42, patch($service, array('nutrition' => array('protein' => '4'), 'allergens' => array(array('allergen_id' => 10, 'declaration' => 'traces')))));
    check($updated['after']['nutrition']['salt'] === '0' && count($updated['after']['allergens']) === 2, 'Patch preserves omitted nutrient and allergen declarations');
    check(reset($db->nutrition)['unknown'] === 'preserve', 'Native object update retains unrelated saved fields');
    $removed = $service->save(42, patch($service, array('allergens' => array(array('allergen_id' => 7, 'declaration' => 'absent')))));
    check($removed['after']['allergens'] === array(array('allergen_id' => 10, 'declaration' => 'traces')), 'Explicit absent only removes its own relationship');

    list($db, $user, $service) = fixture();
    $stale = patch($service, array('nutrition' => array('protein' => '1')));
    $db->products[42]['nut_mode'] = '1';
    expectError(403, function () use ($service, $stale) { $service->save(42, $stale); });
    check($db->writes === array() && $db->rollbacks === 1, 'Calculated mode denies writes and rolls back');
    $db->products[42]['nut_mode'] = '0';
    $stale['expected_revision'] = str_repeat('0', 64);
    expectError(409, function () use ($service, $stale) { $service->save(42, $stale); });
    $db->products[42]['entity'] = 1;
    expectError(403, function () use ($service, $stale) { $service->save(42, $stale); });
    check($db->writes === array(), 'Foreign shared catalog visibility never grants write authority');

    foreach (array('-1', 'NaN', 'INF', '1e3', '0.00001', true, '1000000') as $value) {
        list($db, $user, $service) = fixture();
        expectError(400, function () use ($service, $value) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('protein' => $value))); });
        check($db->writes === array(), 'Invalid nutrition does not write');
    }
    list($db, $user, $service) = fixture();
    expectError(400, function () use ($service) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('energy_kcal' => '100', 'energy_kj' => '1'))); });
    expectError(400, function () use ($service) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('protein' => null))); });
    expectError(400, function () use ($service) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'allergens' => array(array('allergen_id' => 1, 'declaration' => 'contains')))); });
    expectError(400, function () use ($service) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'allergens' => array(array('allergen_id' => 7, 'declaration' => 'contains'), array('allergen_id' => 7, 'declaration' => 'traces')))); });
    $user->denied = array('kreaproducts.nutritional.write');
    expectError(403, function () use ($service) { $service->save(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('protein' => '1'))); });
    $user->denied = array('kreaproducts.nutritional.read');
    expectError(403, function () use ($service) { $service->read(42); });

    list($db, $user, $service) = fixture();
    $db->failAllergen = true;
    expectError(500, function () use ($service) { $service->save(42, patch($service, array('nutrition' => array('protein' => '1'), 'allergens' => array(array('allergen_id' => 7, 'declaration' => 'contains'))))); });
    check($db->nutrition === array() && $db->allergens === array() && $db->rollbacks === 1, 'Second native failure rolls back the entire change');
    $db->failAllergen = false; $db->ignoreWrites = true;
    expectError(409, function () use ($service) { $service->save(42, patch($service, array('nutrition' => array('protein' => '1')))); });
    check($db->nutrition === array(), 'Mismatched readback rolls back');

    list($db, $user, $service) = fixture();
    $service->save(42, patch($service, array('nutrition' => array('protein' => '0'), 'allergens' => array(array('allergen_id' => 7, 'declaration' => 'contains')))));
    $db->nutrition[101] = reset($db->nutrition);
    expectError(409, function () use ($service) { $service->read(42); });
    unset($db->nutrition[101]);
    $db->allergens[101] = reset($db->allergens);
    expectError(409, function () use ($service) { $service->read(42); });
    unset($db->allergens[101]);
    $user->denied = array('kreaproducts.productallergens.delete');
    expectError(403, function () use ($service) { $service->save(42, patch($service, array('allergens' => array(array('allergen_id' => 7, 'declaration' => 'absent'))))); });

    // Exercise actual API entry methods after bootstrap, as other offline module tests do.
    $source = file_get_contents(__DIR__.'/../class/api_kreaproducts.class.php');
    $start = strpos($source, "\nclass KreaProductsApi extends DolibarrApi");
    if ($start === false) { throw new \RuntimeException('API class boundary missing'); }
    eval(substr($source, $start));
    list($db, $user, $service) = fixture();
    $conf = (object) array('entity' => 8); DolibarrApiAccess::$user = $user;
    $api = new KreaProductsApi();
    check($api->getProductionProductFoodSetup(42)['nutrition'] === null, 'API GET delegates to actual food setup service');
    $result = $api->putProductionProductFoodSetup(42, array('entity_id' => 8, 'confirm' => true, 'nutrition' => array('protein' => '1')));
    check($result['status'] === 'dry_run' && $db->writes === array(), 'API PUT defaults to a write-free preview');
    echo 'PASS '.$checks." food-setup API assertions\n";
}
