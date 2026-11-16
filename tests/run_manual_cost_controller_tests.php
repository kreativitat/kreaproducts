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

// Execute the real purchase-price action against isolated transaction doubles.
// Subprocesses also exercise its successful redirect/exit. No ERP bootstrap or data.
if (isset($argv[1])) {
    $case = json_decode(base64_decode($argv[1]), true);
    function currentToken() { return 'validtoken'; }
    function newToken() { return 'nexttoken'; }
    function GETPOST($key, $type) { return $GLOBALS['case']['token'] ?? 'validtoken'; }
    function dol_syslog($message, $level = 0) {}
    function setEventMessages($message, $details, $type) {
        $GLOBALS['event'] = array($message, $type);
    }
    function accessforbidden() { $GLOBALS['denied'] = true; exit; }
    class ManualCostDb
    {
        public $value = 0;
        public $before = 0;
        public $depth = 0;
        public $calls = array();
        public function begin() {
            $this->calls[] = 'begin';
            if (!empty($GLOBALS['case']['begin_error'])) { return 0; }
            $this->before = $this->value;
            $this->depth = 1;
            return 1;
        }
        public function commit() {
            $this->calls[] = 'commit';
            if (!empty($GLOBALS['case']['commit_error'])) { return 0; }
            $this->depth = 0;
            return 1;
        }
        public function rollback() {
            $this->calls[] = 'rollback';
            $this->value = $this->before;
            $this->depth = 0;
            return 1;
        }
        public function write($label) {
            if ($this->depth !== 1) { throw new RuntimeException('Write outside transaction'); }
            $this->calls[] = $label;
            $this->value++;
        }
    }
    class ProductUpdater
    {
        public static function recalculateProductCostTree($id) {
            if ($id !== 42) { throw new RuntimeException('Wrong selected product'); }
            $GLOBALS['db']->write('tree');
            if (!empty($GLOBALS['case']['tree_throw'])) { throw new RuntimeException('Injected native lifecycle exception'); }
            return array();
        }
        public static function getLastErrors() {
            return !empty($GLOBALS['case']['tree_error']) ? array('Injected cycle or persistence failure') : array();
        }
    }
    class ProductDismantleController
    {
        public function __construct($db) {}
        public function refreshDismantleOutputCostPrices($id, $user, $force = false) {
            if ($id !== 42 || $force !== true) { throw new RuntimeException('Missing manual dismantle override'); }
            $GLOBALS['db']->write('dismantle');
            return !empty($GLOBALS['case']['dismantle_error']) ? -1 : 1;
        }
    }
    $db = new ManualCostDb();
    $langs = new class { public function trans($key) { return $key; } };
    $object = (object) array('id' => 42);
    $user = new stdClass();
    $usercancreate = empty($case['no_right']);
    $action = 'updateProductAttributes';
    $event = null;
    $denied = false;
    $_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'POST';
    $_SERVER['PHP_SELF'] = '/custom/kreaproducts/purchasePrice.php';
    register_shutdown_function(function () use ($db) {
        echo json_encode(array('value' => $db->value, 'depth' => $db->depth, 'calls' => $db->calls,
            'event' => $GLOBALS['event'], 'denied' => $GLOBALS['denied']));
    });
    $source = file_get_contents(__DIR__.'/../purchasePrice.php');
    $start = strpos($source, '$createActions = array(');
    $end = strpos($source, "if (\$action == 'setcost_price'", $start);
    eval(substr($source, $start, $end - $start));
    $start = strpos($source, "if (\$action === 'updateProductAttributes' && \$usercancreate)");
    $end = strpos($source, '/*', $start);
    eval(substr($source, $start, $end - $start));
    exit;
}

function runCase(array $case)
{
    $output = array();
    $status = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(base64_encode(json_encode($case))), $output, $status);
    $result = json_decode(implode("\n", $output), true);
    if ($status !== 0 || !is_array($result)) { throw new RuntimeException('Controller subprocess failed: '.implode("\n", $output)); }
    return $result;
}
function check($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
$result = runCase(array());
check($result['calls'] === array('begin', 'tree', 'dismantle', 'commit') && $result['value'] === 2 && $result['depth'] === 0,
    'button recalculates its tree and forces dismantle propagation in one committed transaction');
check($result['event'] === array('KreapCostPricesUpdated', 'mesgs'), 'button confirms success only after commit');
foreach (array('tree_error', 'tree_throw', 'dismantle_error', 'commit_error', 'begin_error') as $failure) {
    $result = runCase(array($failure => true));
    check($result['value'] === 0 && $result['depth'] === 0 && end($result['calls']) === 'rollback'
        && $result['event'] === array('KreapCostPriceUpdateFailed', 'errors'), $failure.' rolls back the whole button action and reports failure');
    if ($failure === 'begin_error') { check(!in_array('tree', $result['calls'], true), 'failed transaction start prevents all writes'); }
}
foreach (array(array('method' => 'GET'), array('token' => ''), array('token' => 'invalid'), array('no_right' => true)) as $case) {
    $result = runCase($case);
    check($result['calls'] === array() && $result['value'] === 0, 'unauthorized method, token or permission cannot start the manual action: '.json_encode($case));
}
