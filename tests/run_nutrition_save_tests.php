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

// Exercise the controller's actual rights/preflight/save blocks without ERP bootstrap.
if (isset($argv[1])) {
    $case = json_decode(base64_decode($argv[1]), true);
    define('MAIN_DB_PREFIX', 'test_');
    function getEntity($element) { return '2,8'; }
    function currentToken() { return 'validtoken'; }
    function newToken() { return 'nexttoken'; }
    function dol_syslog($message, $level = 0) {}
    function price2num($value, $type) { return str_replace(',', '.', $value); }
    function GETPOST($key, $type) {
        $values = array(
            'token' => $GLOBALS['case']['token'] ?? 'validtoken',
            'nutritional_protein' => '12,50',
            'nutritional_fat' => '0',
            'KREAPRODUCTS_ALLERGENS' => array(2, 2),
            'KREAPRODUCTS_ALLERGENS_TRACES' => array(2, 7, 7),
        );
        return $values[$key] ?? '';
    }
    function dol_include_once($path) {
        if ($path === '/kreaproducts/class/KreaProductsNutritionEditPolicy.class.php') {
            require_once dirname(__DIR__).'/class/KreaProductsNutritionEditPolicy.class.php';
        } elseif (!in_array($path, array('/kreaproducts/class/nutritional.class.php', '/kreaproducts/class/productallergens.class.php'), true)) {
            throw new RuntimeException('Unexpected dependency: '.$path);
        }
    }
    function kreaproducts_get_product_extrafield_value($db, $id, $field, $entity) {
        return $GLOBALS['case'][$field === 'kreap_calc_nut' ? 'nutrition_mode' : 'allergen_mode'];
    }
    function accessforbidden($message = '') { http_response_code(403); exit; }
    function setEventMessages($message, $messages, $type) { $GLOBALS['outcome'] = $type; }
    class Product { const TYPE_PRODUCT = 0; const TYPE_SERVICE = 1; }
    class SaveLangs { public function trans($key) { return $key; } }
    class SaveUser {
        public $id = 10;
        public $rights;
        public function __construct() {
            $this->rights = (object) array('produit' => (object) array('lire' => true, 'creer' => empty($GLOBALS['case']['no_product_right']), 'supprimer' => false));
        }
        public function hasRight($module, $object, $action) {
            return empty($GLOBALS['case']['no_'.$object.'_right']);
        }
    }
    class SaveRows {
        public $rows;
        public function __construct($rows) { $this->rows = $rows; }
    }
    class SaveDb {
        public $state;
        public $before;
        public $writes = 0;
        public $depth = 0;
        public $rollbacks = 0;
        public $native = array();
        public function __construct() {
            $this->state = array('nutrition' => empty($GLOBALS['case']['create']) ? array('protein' => '3.00') : null, 'allergens' => array(array('id' => 9, 'traces' => 0)));
        }
        public function begin() { $this->before = $this->state; $this->depth = 1; return 1; }
        public function commit() { $this->depth = 0; return 1; }
        public function rollback() { $this->state = $this->before; $this->depth = 0; $this->rollbacks++; return 1; }
        public function write() {
            if ($this->depth !== 1) { throw new RuntimeException('Write outside transaction'); }
            $this->writes++;
        }
        public function query($sql) {
            if (strpos($sql, 'SELECT pe.kreap_calc_nut, pe.kreap_calc_allergens, n.is_food') === 0) {
                if (strpos($sql, 'p.rowid = 42') === false || strpos($sql, 'p.entity IN (2,8)') === false) {
                    throw new RuntimeException('Policy omitted parent product/entity scope');
                }
                if (!empty($GLOBALS['case']['db_error'])) { return false; }
                if (!empty($GLOBALS['case']['missing']) || !in_array($GLOBALS['case']['owner'], array(2, 8), true)) { return new SaveRows(array()); }
                $row = array('kreap_calc_nut' => $GLOBALS['case']['nutrition_mode'], 'kreap_calc_allergens' => $GLOBALS['case']['allergen_mode'], 'is_food' => $GLOBALS['case']['food']);
                $rows = array($row);
                if (!empty($GLOBALS['case']['food_conflict'])) { $rows[] = array_merge($row, array('is_food' => 0)); }
                return new SaveRows($rows);
            }
            if (strpos($sql, 'SELECT rowid FROM test_c_kreaproducts') === 0) {
                if (strpos($sql, 'active = 1 AND rowid IN (2,7)') === false) { throw new RuntimeException('Unexpected allergen validation'); }
                return new SaveRows(array(array('rowid' => 2), array('rowid' => 7)));
            }
            if (strpos($sql, 'DELETE pa FROM test_kreaproducts_productallergens AS pa') === 0) {
                if (strpos($sql, 'p.entity IN (2,8)') === false || strpos($sql, 'pa.fk_product = 42') === false) { throw new RuntimeException('Allergen replacement omitted parent scope'); }
                $this->write();
                $this->state['allergens'] = array();
                return true;
            }
            throw new RuntimeException('Unexpected SQL: '.$sql);
        }
        public function fetch_object($result) { return $result->rows ? (object) array_shift($result->rows) : false; }
        public function free($result) {}
        public function lasterror() { return 'injected read failure'; }
    }
    class Nutritional {
        public $id = 0;
        public $fk_product;
        public $is_food;
        public $fk_user_modif;
        public $fk_user_creat;
        public $energy_kcal;
        public $energy_kj;
        public $fat;
        public $saturates;
        public $carbohydrates;
        public $sugars;
        public $protein;
        public $salt;
        public $fiber;
        public $error = 'injected save failure';
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function fetchByProduct($id) {
            if ($id !== 42) { throw new RuntimeException('Wrong nutritional parent'); }
            return $this->db->state['nutrition'] === null ? 0 : 1;
        }
        public function create($user) { return $this->save('create'); }
        public function update($user) { return $this->save('update'); }
        private function save($method) {
            if ($this->fk_product !== 42 || $this->is_food !== 1) { throw new RuntimeException('Wrong nutritional parent or food flag'); }
            $this->db->write();
            $this->db->native[] = $method;
            $this->db->state['nutrition'] = array();
            foreach (array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber') as $field) {
                $this->db->state['nutrition'][$field] = $this->{$field};
            }
            return empty($GLOBALS['case']['save_error']) ? 1 : -1;
        }
    }
    class ProductAllergens {
        public $fk_product;
        public $fk_allergen;
        public $traces;
        public $error = 'injected allergen failure';
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function create($user) {
            if ($this->fk_product !== 42) { throw new RuntimeException('Wrong allergen parent'); }
            $this->db->write();
            $this->db->state['allergens'][] = array('id' => $this->fk_allergen, 'traces' => $this->traces);
            return 1;
        }
    }
    class KreaProductsNutrientUpdater {
        public static function updateNutrientAttributes($id, $user) { $GLOBALS['cascades']++; return true; }
    }
    require_once dirname(__DIR__).'/class/KreaProductsNutritionEditPolicy.class.php';
    $db = new SaveDb(); $user = new SaveUser(); $langs = new SaveLangs();
    $initial = $db->state;
    $object = (object) array('id' => 42, 'entity' => $case['owner'], 'type' => Product::TYPE_PRODUCT);
    $conf = (object) array('entity' => 2);
    $action = 'save_nutrition_allergens';
    $_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'POST';
    $_SERVER['PHP_SELF'] = '/associatedProducts.php';
    $cascades = 0;
    register_shutdown_function(function () use ($db, $initial) {
        echo json_encode(array('http' => http_response_code() ?: 200, 'state' => $db->state, 'unchanged' => $db->state === $initial, 'writes' => $db->writes, 'native' => $db->native, 'depth' => $db->depth, 'rollbacks' => $db->rollbacks, 'cascades' => $GLOBALS['cascades'], 'outcome' => $GLOBALS['outcome'] ?? ''));
    });
    $source = file_get_contents(__DIR__.'/../associatedProducts.php');
    foreach (array(
        array('$usercanread   =', '$sqlFoodFlag ='),
        array('$productWriteActions =', '$llmWriteActions ='),
        array("if (\$action === 'save_nutrition_allergens')", "if (\$action === 'update_nutrition_allergens')"),
    ) as $boundaries) {
        $start = strpos($source, $boundaries[0]);
        $end = $start === false ? false : strpos($source, $boundaries[1], $start);
        if ($start === false || $end === false) { throw new RuntimeException('Controller action boundary missing'); }
        eval(substr($source, $start, $end - $start));
    }
    throw new RuntimeException('Save action did not terminate');
}

$manual = array('nutrition_mode' => '0', 'allergen_mode' => '0', 'food' => 1, 'owner' => 2);
$cases = array(
    'manual-update' => array(),
    'manual-create' => array('create' => true),
    'legacy-null-create' => array('nutrition_mode' => null, 'allergen_mode' => null, 'food' => null, 'create' => true),
    'legacy-empty-update' => array('nutrition_mode' => '', 'allergen_mode' => ''),
    'legacy-partial-nutrition' => array('nutrition_mode' => null),
    'legacy-partial-allergens' => array('allergen_mode' => ''),
    'shared-parent-update' => array('owner' => 8),
    'next-csrf-token' => array('token' => 'nexttoken'),
    'calculated' => array('nutrition_mode' => '1', 'allergen_mode' => '1', 'denied' => true),
    'mixed-nutrition' => array('nutrition_mode' => '1', 'denied' => true),
    'mixed-allergens' => array('allergen_mode' => '1', 'denied' => true),
    'non-food-mode' => array('nutrition_mode' => '2', 'allergen_mode' => '2', 'denied' => true),
    'non-food-flag' => array('food' => 0, 'denied' => true),
    'invalid-mode' => array('nutrition_mode' => 'invalid', 'denied' => true),
    'conflicting-food-rows' => array('food_conflict' => true, 'denied' => true),
    'missing-parent' => array('missing' => true, 'denied' => true),
    'foreign-parent' => array('owner' => 9, 'denied' => true),
    'policy-database-error' => array('db_error' => true, 'denied' => true),
    'no-product-right' => array('no_product_right' => true, 'denied' => true),
    'no-nutrition-right' => array('no_nutritional_right' => true, 'denied' => true),
    'no-allergen-right' => array('no_productallergens_right' => true, 'denied' => true),
    'get-request' => array('method' => 'GET', 'denied' => true),
    'empty-csrf' => array('token' => '', 'denied' => true),
    'invalid-csrf' => array('token' => 'invalid', 'denied' => true),
    'native-save-failure' => array('save_error' => true),
);
foreach ($cases as $name => $overrides) {
    $case = array_merge($manual, $overrides);
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(base64_encode(json_encode($case)));
    $output = array(); $status = 0;
    exec($command, $output, $status);
    $result = json_decode(implode("\n", $output), true);
    $denied = !empty($case['denied']);
    $failed = !empty($case['save_error']);
    $valid = $status === 0 && is_array($result) && $result['depth'] === 0;
    if ($valid && $denied) {
        $valid = $result['http'] === 403 && $result['unchanged'] && $result['writes'] === 0 && $result['native'] === array() && $result['cascades'] === 0;
    } elseif ($valid && $failed) {
        $valid = $result['http'] === 302 && $result['unchanged'] && $result['rollbacks'] === 1 && $result['cascades'] === 0 && $result['outcome'] === 'errors';
    } elseif ($valid) {
        $nutrition = array_fill_keys(array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber'), null);
        $nutrition['fat'] = '0'; $nutrition['protein'] = '12.50';
        $expected = array('nutrition' => $nutrition, 'allergens' => array(array('id' => 2, 'traces' => 0), array('id' => 7, 'traces' => 1)));
        $valid = $result['http'] === 302 && $result['state'] === $expected && $result['native'] === array(empty($case['create']) ? 'update' : 'create') && $result['writes'] === 4 && $result['cascades'] === 1 && $result['outcome'] === 'mesgs';
    }
    if (!$valid) { fwrite(STDERR, 'FAIL '.$name.': '.implode("\n", $output)."\n"); exit(1); }
}
echo 'Nutrition save tests passed ('.count($cases)." cases).\n";
