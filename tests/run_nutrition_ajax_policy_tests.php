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


// Exercise the real AJAX action after Dolibarr bootstrap with isolated dependencies.
if (isset($argv[1])) {
    $case = json_decode(base64_decode($argv[1]), true);
    define('MAIN_DB_PREFIX', 'test_');
    function getEntity($element) { return '2,8'; }
    function dol_syslog($message, $level = 0) {}
    function currentToken() { return 'validtoken'; }
    function GETPOST($key, $type) {
        global $case;
        $values = array('token' => 'validtoken', 'field' => $case['endpoint'] === 'nutritional' ? 'protein' : 'traces', 'value' => '1');
        return $values[$key] ?? '';
    }
    function GETPOSTINT($key) { return 12; }
    function price2num($value, $type) { return $value; }
    function top_httphead($type) {}
    function dol_include_once($path) { require_once dirname(__DIR__).substr($path, strlen('/kreaproducts')); }
    function accessforbidden() { http_response_code(403); exit; }
    class TestUser { public function hasRight(...$args) { return true; } }
    class TestLangs { public function trans($key, ...$args) { return $key; } }
    class TestDB {
        public $rows;
        public function query($sql) {
            global $case;
            if (strpos($sql, 'p.entity IN (2,8)') === false || strpos($sql, 'p.rowid = 42') === false) {
                throw new RuntimeException('Missing parent product/entity scope');
            }
            $this->rows = $case['rows'];
            return empty($case['db_error']);
        }
        public function fetch_object($result) { return $this->rows ? (object) array_shift($this->rows) : false; }
        public function free($result) {}
        public function lasterror() { return 'simulated failure'; }
        public function close() {}
    }
    class Nutritional {
        public $id = 0;
        public $fk_product = 42;
        public $protein;
        public $traces;
        public function fetch($id) { $this->id = $id; return 1; }
        public function update($user) { $GLOBALS['updated'] = true; return 1; }
    }
    class ProductAllergens extends Nutritional {}
    $db = new TestDB(); $user = new TestUser(); $langs = new TestLangs();
    register_shutdown_function(function () {
        echo "\n".json_encode(array('http' => http_response_code() ?: 200, 'updated' => !empty($GLOBALS['updated'])));
    });
    $source = file_get_contents(__DIR__.'/../ajax/'.$case['endpoint'].'.php');
    $start = strpos($source, '$token = GETPOST(');
    if ($start === false) { throw new RuntimeException('AJAX action boundary missing'); }
    eval(substr($source, $start));
    exit;
}

$cases = array(
    'manual' => array('0', '0', 1, true),
    'legacy-null' => array(null, null, null, true),
    'legacy-empty' => array('', '', 1, true),
    'calculated' => array('1', '1', 1, false),
    'mixed-nutrition' => array('1', '0', 1, false),
    'mixed-allergens' => array('0', '1', 1, false),
    'invalid' => array('invalid', '0', 1, false),
    'non-food-mode' => array('2', '2', 1, false),
    'non-food-flag' => array('0', '0', 0, false),
    'missing-or-foreign-product' => array('0', '0', 1, false),
    'database-failure' => array('0', '0', 1, false),
    'conflicting-food-rows' => array('0', '0', 1, false),
);
$count = 0;
foreach (array('nutritional', 'productallergens') as $endpoint) {
    foreach ($cases as $name => $values) {
        $row = array('kreap_calc_nut' => $values[0], 'kreap_calc_allergens' => $values[1], 'is_food' => $values[2]);
        $rows = $name === 'missing-or-foreign-product' ? array() : array($row);
        if ($name === 'conflicting-food-rows') { $rows[] = array_merge($row, array('is_food' => 0)); }
        $case = array('endpoint' => $endpoint, 'rows' => $rows, 'db_error' => $name === 'database-failure');
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(base64_encode(json_encode($case)));
        $output = array(); $status = 0;
        exec($command, $output, $status);
        $result = json_decode(end($output), true);
        $expected = array('http' => $values[3] ? 200 : 403, 'updated' => $values[3]);
        if ($status !== 0 || $result !== $expected) {
            fwrite(STDERR, $endpoint.'/'.$name.' failed: '.json_encode($output)."\n");
            exit(1);
        }
        $count++;
    }
}
echo 'Nutrition AJAX policy tests passed ('.$count." cases).\n";
