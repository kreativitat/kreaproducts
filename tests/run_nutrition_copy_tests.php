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



// Run the real controller action with isolated persistence and calculator failures.
if (isset($argv[1])) {
    $case = $argv[1];
    define('MAIN_DB_PREFIX', 'test_');
    function getEntity($element) { return '1,8'; }
    function GETPOSTINT($key) { return 10812; }
    function dol_include_once($path) {}
    function dol_syslog($message, $level = 0) {}
    function kreaproducts_debug_log($message) {}
    function setEventMessages($message, $messages, $type) { $GLOBALS['outcome'] = $type; }
    class CopyResult {
        public $rows;
        public function __construct($rows) { $this->rows = $rows; }
    }
    class CopyDb {
        public $state = array('source_nutrition' => 10, 'source_allergens' => array(2), 'target_nutrition' => 20, 'target_allergens' => array(3));
        public $before;
        public $writes = 0;
        public $depth = 0;
        public function begin() {
            if ($GLOBALS['case'] === 'begin-failure') { return -1; }
            $this->before = $this->state; $this->depth = 1; return 1;
        }
        public function commit() {
            if ($GLOBALS['case'] === 'commit-failure') { return -1; }
            $this->depth = 0; return 1;
        }
        public function rollback() { if ($this->before) { $this->state = $this->before; } $this->depth = 0; return 1; }
        public function query($sql) {
            if (strpos($sql, 'SELECT rowid FROM test_product') === 0) {
                if (strpos($sql, 'entity IN (1,8)') === false) { throw new Exception('Missing scope'); }
                return new CopyResult($GLOBALS['case'] === 'inaccessible' ? array() : array(array('rowid' => 10812)));
            }
            if (strpos($sql, 'SELECT pa.fk_allergen') === 0) {
                if ($GLOBALS['case'] === 'source-read-failure') { return false; }
                return new CopyResult(array_map(function ($id) { return array('fk_allergen' => $id, 'traces' => 0); }, $this->state['source_allergens']));
            }
            if (strpos($sql, 'DELETE FROM test_kreaproducts_productallergens') === 0) {
                if (!$this->depth) { throw new Exception('Write outside transaction'); }
                $this->writes++; $this->state['target_allergens'] = array(); return true;
            }
            throw new Exception('Unexpected SQL: '.$sql);
        }
        public function fetch_object($result) { return $result->rows ? (object) array_shift($result->rows) : false; }
        public function num_rows($result) { return count($result->rows); }
        public function free($result) {}
        public function lasterror() { return 'injected failure'; }
    }
    class KreaProductsNutritionalCalculator {
        public static function clearCache() {}
        public static function hasErrors() { return false; }
        public static function saveCalculation($id, $user) {
            $GLOBALS['db']->state['source_nutrition'] = 99;
            return $GLOBALS['case'] === 'nutrition-failure' ? -1 : 1;
        }
    }
    class KreaProductsNutritionEditPolicy { public static function canEdit($db, $id) { return $GLOBALS['case'] !== 'calculated-target'; } }
    class KreaProductsAllergenUpdater {
        public static function getScopeWarning($langs, $user) { return ""; }
        public static function clearCache() {}
        public static function hasErrors() { return $GLOBALS['case'] === 'allergen-reported-error'; }
        public static function updateAllergenAttributes($id, $user, $traces, $options) {
            if (empty($options['root_only'])) { throw new Exception('Copy must not rewrite descendants'); }
            $GLOBALS['db']->state['source_allergens'] = array(6, 7);
            return $GLOBALS['case'] !== 'allergen-failure';
        }
    }
    class KreaProductsNutrientUpdater { public static function updateNutrientAttributes($id, $user) { throw new Exception('Copy must not launch a nutrition cascade'); } }
    class ProductAllergens {
        public $fk_product; public $fk_allergen; public $traces; public $error = 'injected failure';
        public function __construct($db) {}
        public function create($user) {
            $GLOBALS['db']->state['target_allergens'][] = $this->fk_allergen;
            return $GLOBALS['case'] === 'insert-failure' ? -1 : 1;
        }
    }
    function kreaproducts_copy_nutritional_values_to_product($db, $source, $target, $user) {
        $db->state['target_nutrition'] = $db->state['source_nutrition'];
        return $GLOBALS['case'] === 'copy-failure' ? -1 : ($GLOBALS['case'] === 'empty-nutrition' ? 0 : 1);
    }
    class CopyLangs { public function trans($key) { return $key; } }
    $db = new CopyDb(); $langs = new CopyLangs(); $user = (object) array('id' => 1);
    $object = (object) array('id' => 11082, 'array_options' => array('options_kreap_calc_nut' => $case === 'manual' ? '0' : '1', 'options_kreap_calc_allergens' => $case === 'manual' ? '0' : '1'));
    $nutritionAllergenMode = $case === 'nonfood' ? 2 : ($case === 'manual' ? 0 : 1);
    $canManageNutritionAllergens = true; $enableCopyAllergensToProduct = true; $enableCopyAvgToProduct = true;
    $action = 'copy_nutrition_allergens_to_product'; $_SERVER['PHP_SELF'] = '/associatedProducts.php';
    register_shutdown_function(function () use ($db) { echo json_encode(array('state' => $db->state, 'outcome' => $GLOBALS['outcome'] ?? '', 'depth' => $db->depth, 'writes' => $db->writes)); });
    $source = file_get_contents(__DIR__.'/../associatedProducts.php');
    $start = strpos($source, "if (\$action === 'copy_nutrition_allergens_to_product' &&");
    $end = strpos($source, "\nif (\$action === 'setweight'", $start);
    eval(substr($source, $start, $end - $start));
    exit;
}
$cases = array('calculated', 'manual', 'empty-nutrition', 'calculated-target', 'nutrition-failure', 'allergen-failure', 'allergen-reported-error', 'source-read-failure', 'insert-failure', 'copy-failure', 'commit-failure', 'begin-failure', 'inaccessible', 'nonfood');
foreach ($cases as $case) {
    $raw = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case));
    $out = json_decode($raw, true);
    $success = in_array($case, array('calculated', 'manual'), true);
    $expected = $case === 'calculated'
        ? array('source_nutrition' => 99, 'source_allergens' => array(6, 7), 'target_nutrition' => 99, 'target_allergens' => array(6, 7))
        : ($case === 'manual' ? array('source_nutrition' => 10, 'source_allergens' => array(2), 'target_nutrition' => 10, 'target_allergens' => array(2))
        : array('source_nutrition' => 10, 'source_allergens' => array(2), 'target_nutrition' => 20, 'target_allergens' => array(3)));
    if (!$out || $out['state'] !== $expected || $out['outcome'] !== ($success ? 'mesgs' : 'errors') || $out['depth'] !== 0) {
        fwrite(STDERR, 'FAIL '.$case.': '.$raw."\n"); exit(1);
    }
    echo 'PASS '.$case."\n";
}
