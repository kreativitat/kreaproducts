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



define('MAIN_DB_PREFIX', 'test_');
function getEntity($element) { return '1'; }
function dol_syslog($message, $level = 0) {}
class ValueRows { public $rows; public function __construct($rows) { $this->rows = $rows; } }
class ValueDb {
    public $values; public $writes = 0;
    public function query($sql) {
        if (strpos($sql, 'SELECT rowid FROM test_product') === 0) { return new ValueRows(array((object) array('rowid' => 2))); }
        if (strpos($sql, 'SELECT n.energy_kcal') === 0) { return new ValueRows($this->values === null ? array() : array((object) $this->values)); }
        if (strpos($sql, 'SELECT rowid FROM test_kreaproducts_nutritional') === 0) { return new ValueRows(array((object) array('rowid' => 10))); }
        if (strpos($sql, 'UPDATE test_kreaproducts_nutritional') === 0) { $this->writes++; return true; }
        throw new Exception('Unexpected SQL');
    }
    public function num_rows($r) { return count($r->rows); }
    public function fetch_object($r) { return array_shift($r->rows); }
    public function free($r) {}
}
$source = file_get_contents(__DIR__.'/../associatedProducts.php');
$start = strpos($source, "if (!function_exists('kreaproducts_copy_nutritional_values_to_product'))");
$end = strpos($source, "\n}\n", $start) + 3;
eval(substr($source, $start, $end - $start));
$fields = array('energy_kcal','energy_kj','fat','saturates','carbohydrates','sugars','protein','salt','fiber');
foreach (array('missing' => null, 'blank' => array_fill_keys($fields, null), 'zero' => array_fill_keys($fields, '0.0000')) as $case => $values) {
    $db = new ValueDb(); $db->values = $values;
    $result = kreaproducts_copy_nutritional_values_to_product($db, 1, 2, (object) array('id' => 1));
    $expected = $case === 'zero' ? 1 : 0;
    if ($result !== $expected || $db->writes !== $expected) { throw new Exception('Failed '.$case); }
    echo 'PASS '.$case.PHP_EOL;
}
