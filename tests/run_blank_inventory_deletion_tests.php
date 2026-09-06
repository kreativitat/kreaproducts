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


require_once __DIR__.'/../class/KreaProductsInventoryLedgerCalculator.class.php';
function dol_syslog($message, $level = 0) {}
class KreaProductsStockApiException extends Exception {}
class BlankTestLangs { public function trans($key) { return $key; } }
class Inventory {
    public $context = array();
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function fetch($id) { return $this->db->failure === 'fetch' ? -1 : 1; }
    public function setDraft($user) { $this->db->calls[] = 'draft'; return $this->db->failure === 'draft' ? -1 : 1; }
    public function delete($user) { $this->db->calls[] = 'delete'; return $this->db->failure === 'delete' ? -1 : 1; }
}
class BlankTestDB {
    public $results;
    public $failure;
    public $calls = array();
    public $queries = array();
    private $rows;
    public function __construct($results, $failure) { $this->results = $results; $this->failure = $failure; }
    public function prefix() { return 'test_'; }
    public function escape($value) { return $value; }
    public function query($sql) {
        if (strpos($sql, 'FOR UPDATE') === false || strpos($sql, 'entity = 8') === false) {
            throw new RuntimeException('Missing locked entity scope');
        }
        $this->queries[] = $sql;
        $this->rows = array_shift($this->results);
        return $this->rows !== false;
    }
    public function fetch_object($result) { return $this->rows ? (object) array_shift($this->rows) : false; }
    public function free($result) {}
    public function lasterror() { return 'simulated query failure'; }
    public function rollback() { $this->calls[] = 'rollback'; }
    public function commit() { $this->calls[] = 'commit'; return $this->failure !== 'commit'; }
}
// Execute the production methods with isolated database and native-object dependencies.
$source = file_get_contents(__DIR__.'/../class/KreaProductsMobileInventoryService.class.php');
$methods = '';
foreach (array('deleteBlankRecordedInventory', 'commitStockTransaction') as $name) {
    $start = strpos($source, "\tprivate function ".$name.'(');
    $end = strpos($source, "\n\t/**", $start);
    $methods .= str_replace('private function '.$name, 'public function '.$name, substr($source, $start, $end - $start));
}
eval('class BlankInventoryProbe { public $db; public $conf; public $user; public $langs; public function getObjectError($object, $fallback) { return $fallback; } '.$methods.'}');
$blank = array('rowid' => 1, 'qty_view' => null, 'fk_movement' => null);
$base = array(array($blank), array(), array(), array());
$cases = array('blank' => array($base, '', true));
foreach (array('zero' => 0, 'positive' => 3, 'negative' => -2) as $name => $qty) {
    $rows = $base; $rows[0][] = array_merge($blank, array('qty_view' => $qty));
    $cases[$name] = array($rows, '', false);
}
$rows = $base; $rows[0][0]['fk_movement'] = 100; $cases['movement-link'] = array($rows, '', false);
$rows = $base; $rows[0] = array(); $cases['missing-lines'] = array($rows, '', false);
foreach (array(1 => 'adjustment-history', 2 => 'correction-history', 3 => 'origin-movement') as $index => $name) {
    $rows = $base; $rows[$index] = array(array('rowid' => 100)); $cases[$name] = array($rows, '', false);
}
foreach (range(0, 3) as $index) {
    $rows = $base; $rows[$index] = false; $cases['query-failure-'.$index] = array($rows, '', false);
}
foreach (array('fetch', 'draft', 'delete', 'commit') as $failure) {
    $cases[$failure.'-failure'] = array($base, $failure, false);
}
foreach ($cases as $name => $case) {
    $probe = new BlankInventoryProbe();
    $probe->db = new BlankTestDB($case[0], $case[1]);
    $probe->conf = (object) array('entity' => 8);
    $probe->user = (object) array('id' => 7);
    $probe->langs = new BlankTestLangs();
    $success = false;
    try { $success = $probe->deleteBlankRecordedInventory(42) === array('deleted' => 1, 'inventory_id' => 42); }
    catch (KreaProductsStockApiException $exception) {}
    if ($success !== $case[2]
        || (!$success && !in_array('rollback', $probe->db->calls, true))
        || ($success && $probe->db->calls !== array('draft', 'delete', 'commit'))
        || (!$success && $case[1] === '' && in_array('delete', $probe->db->calls, true))
    ) {
        throw new RuntimeException($name.' failed: '.json_encode($probe->db->calls));
    }
}
echo 'Blank inventory deletion tests passed ('.count($cases)." cases).\n";
