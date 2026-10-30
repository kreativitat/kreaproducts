<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Isolated SQL transactions and lifecycle doubles; no business database or network.
define('MAIN_DB_PREFIX', 'test_');
define('DOL_DATA_ROOT', sys_get_temp_dir().'/kreaproducts-label-tests-'.getmypid());
function getEntity($element) { return '2,8'; }
function isModEnabled($module) { return !empty($GLOBALS['modulesEnabled']); }
function dol_syslog($text, $level = 0) {}
function dol_strlen($value) { return mb_strlen($value, 'UTF-8'); }
function dol_now() { return time(); }
function price($value, ...$args) { return (string) $value; }
function dol_string_nohtmltag($value, ...$args) { return strip_tags($value); }
function dol_html_entity_decode($value, ...$args) { return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function dol_print_date($time, $format, ...$args) { return date($format === '%Y%m%d' ? 'Ymd' : 'd/m/Y', $time); }
function dol_include_once($path) { return true; }
function setEventMessages($text, $errors = null, $style = '') { $GLOBALS['messages'][] = $text; }
function check($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}

class LabelTestDb
{
    public $pdo;
    public $transaction_opened = 0;
    public $failBegin = false;
    public $failCommit = false;
    public $locked = false;
    public $failLock = false;
    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE test_product (rowid INTEGER PRIMARY KEY, entity INT, fk_product_type INT, ref TEXT, label TEXT, barcode TEXT, lifetime INT)');
        $this->pdo->exec('CREATE TABLE test_product_extrafields (fk_object INTEGER PRIMARY KEY, kreap_default_label_layout TEXT, zs_synch INT DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE test_dolizsynch_zsproduct (rowid INTEGER PRIMARY KEY, fk_product INT, entity INT, loja_zs INT, restricted INT, codigo TEXT, codbarras TEXT, produtos_codigosbarras TEXT, date_creation TEXT, fk_user_creat INT, fk_user_modif INT)');
        $this->pdo->exec("INSERT INTO test_product VALUES (10,2,0,'P10','Product 10','001234',10),(20,8,1,'P20','Service 20','EXISTING',10),(30,9,0,'P30','Hidden','HIDDEN',10)");
        $this->pdo->exec("INSERT INTO test_product_extrafields VALUES (10,'',1)");
        $this->pdo->exec("INSERT INTO test_dolizsynch_zsproduct (fk_product,entity,restricted,codigo,codbarras,produtos_codigosbarras) VALUES (10,2,0,'10','MAIN','[]'),(20,8,0,'20','','[]'),(30,9,0,'30','','[]')");
    }
    public function query($sql)
    {
        if (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            $this->locked = !$this->failLock;
            return $this->pdo->query('SELECT '.($this->locked ? '1' : '0').' AS acquired');
        }
        if (strpos($sql, 'SELECT RELEASE_LOCK(') === 0) { $this->locked = false; return true; }
        return $this->pdo->query(str_replace(' FOR UPDATE', '', $sql));
    }
    public function fetch_object($result) { return $result->fetchObject(); }
    public function free($result) { $result->closeCursor(); }
    public function escape($text) { return substr($this->pdo->quote($text), 1, -1); }
    public function escapeforlike($text) { return addcslashes($text, '%_\\'); }
    public function sanitize($text) { return $text; }
    public function idate($time) { return date('Y-m-d H:i:s', $time); }
    public function DDLDescTable($table, $column) { return $this->pdo->query("SELECT 'text' AS Type"); }
    public function begin()
    {
        if ($this->failBegin) { return -1; }
        $this->pdo->beginTransaction(); $this->transaction_opened = 1; return 1;
    }
    public function commit()
    {
        if ($this->failCommit) { return -1; }
        $this->pdo->commit(); $this->transaction_opened = 0; return 1;
    }
    public function rollback() { $this->pdo->rollBack(); $this->transaction_opened = 0; return 1; }
}
class LabelTestUser
{
    public $id = 7;
    public $socid = 0;
    public $canWrite = true;
    public function hasRight(...$args) { return $this->canWrite; }
}
class LabelTestLangs { public function trans($key, ...$args) { return $key; } }
class Product
{
    public $db;
    public $id = 10;
    public $entity = 2;
    public $type = 0;
    public $ref = 'P10';
    public $label = 'Product 10';
    public $barcode = '001234';
    public $barcode_type = 0;
    public $lifetime = 10;
    public $price = 2;
    public $price_ttc = 2;
    public $array_options = array();
    public $failWrite = false;
    public function __construct($db) { $this->db = $db; }
    public function fetch($id) { $this->id = $id; return 1; }
    public function fetch_optionals()
    {
        $row = $this->db->pdo->query('SELECT * FROM test_product_extrafields WHERE fk_object='.$this->id)->fetch(PDO::FETCH_ASSOC);
        $this->array_options = array('options_kreap_default_label_layout' => $row['kreap_default_label_layout'], 'options_zs_synch' => $row['zs_synch']);
        return 1;
    }
    public function insertExtraFields()
    {
        if ($this->failWrite) { return -1; }
        $stmt = $this->db->pdo->prepare('UPDATE test_product_extrafields SET kreap_default_label_layout=? WHERE fk_object=?');
        $stmt->execute(array($this->array_options['options_kreap_default_label_layout'], $this->id));
        return 1;
    }
}
class ZSProductSynch
{
    public static $calls = 0;
    public static $fail = false;
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function updateZsbmsProduct($product)
    {
        check(!$this->db->transaction_opened && $this->db->locked, 'remote call follows commit while barcode lock is held');
        self::$calls++;
        return !self::$fail;
    }
}
require_once __DIR__.'/../class/KreaProductsLabelService.class.php';
require_once __DIR__.'/../class/KreaProductsLabelBarcodeService.class.php';
$page = file_get_contents(__DIR__.'/../product_labels.php');
$start = strpos($page, 'function kreaProductsSanitizeTemplateSource(');
$end = strpos($page, "$".'id = GETPOSTINT(', $start);
eval(substr($page, $start, $end - $start));
$user = new LabelTestUser();
$conf = (object) array('entity' => 2, 'global' => (object) array('ZS_API_STORE' => 4));
$langs = new LabelTestLangs();
$mysoc = (object) array();
$modulesEnabled = true;
// No framework DB context is needed to resolve these isolated templates.
$db = null;
$testDb = new LabelTestDb();
$product = new Product($testDb);
$templateDir = DOL_DATA_ROOT.'/kreaproducts/2/labels/templates';
mkdir($templateDir, 0700, true);
$template = array('schema_version' => 1, 'template_code' => 'test_label', 'label' => 'Test',
    'label_size_mm' => array('width' => 76, 'height' => 50),
    'inputs' => array(array('source' => 'product.barcode', 'type' => 'text', 'editable' => true)),
    'pages' => array(array('code' => 'front', 'blocks' => array(
        array('id' => 'barcode', 'type' => 'barcode', 'content_mode' => 'dynamic', 'source' => 'product.barcode', 'w_mm' => 40, 'h_mm' => 10)))));
$templatePath = $templateDir.'/test_label.json';
file_put_contents($templatePath, json_encode($template));
function storedPayload() { return kreaProductsParseLabelStoragePayload($GLOBALS['testDb']->pdo->query('SELECT kreap_default_label_layout FROM test_product_extrafields WHERE fk_object=10')->fetchColumn()); }
function storedCodes() { return json_decode($GLOBALS['testDb']->pdo->query('SELECT produtos_codigosbarras FROM test_dolizsynch_zsproduct WHERE fk_product=10')->fetchColumn(), true); }
function saveLabel($code, $single = '')
{
    return kreaProductsPersistLabelStoragePayload($GLOBALS['product'], array(), $GLOBALS['langs'], 'test_label', array('product.barcode' => $code), $single);
}
try {
    $weighted = $template;
    $weighted['inputs'][] = array('source' => 'label.weight_value', 'type' => 'number', 'editable' => true);
    $weighted['inputs'][] = array('source' => 'label.weight_unit', 'type' => 'text', 'editable' => true);
    $weighted['computed_fields'] = array(array('operation' => 'weight_ean13', 'target_source' => 'label.weight_barcode'));
    $weighted['pages'][0]['blocks'][0]['source'] = 'label.weight_barcode';
    file_put_contents($templatePath, json_encode($weighted));
    $weightedCodes = KreaProductsLabelService::getLabelBarcodes($product, $langs, 2, 'test_label', array('label.weight_value' => '0.250', 'label.weight_unit' => 'kg'));
    check(count($weightedCodes) === 1 && strlen($weightedCodes[0]) === 13 && substr($weightedCodes[0], 0, 12) === '270001000250', 'computed weight barcode uses printing context');
    $weighted['pages'][0]['blocks'][0]['source'] = 'label.missing';
    $weighted['pages'][0]['blocks'][0]['placeholder'] = 'PLACEHOLDER';
    file_put_contents($templatePath, json_encode($weighted));
    check(KreaProductsLabelService::getLabelBarcodes($product, $langs, 2, 'test_label') === array(), 'unresolved barcode does not register placeholder');
    file_put_contents($templatePath, json_encode($template));
    check(KreaProductsLabelService::getLabelBarcodes($product, $langs, 2, 'test_label', array('product.barcode' => '000007')) === array('000007'), 'printing resolver preserves leading zeros');
    check(!empty(saveLabel('000007')['success']), 'save registers label and barcode');
    check(count(storedCodes()) === 1 && storedCodes()[0]['codbarras'] === '000007', 'one KreaFlow barcode is stored');
    saveLabel('000007');
    check(count(storedCodes()) === 1 && ZSProductSynch::$calls === 2, 'retry resends without duplicate');
    saveLabel('000008', 'product.barcode');
    check(array_column(storedCodes(), 'codbarras') === array('000008'), 'inline barcode edit replaces prior label-owned code');
    $before = storedPayload();
    check(!empty(saveLabel('EXISTING')['error']) && storedPayload() === $before, 'shared-entity service primary barcode collision rolls back label');
    check(!empty(saveLabel('HIDDEN')['success']), 'unshared entity barcode does not block save');
    $testDb->pdo->exec("UPDATE test_dolizsynch_zsproduct SET produtos_codigosbarras='[{\"codbarras\":\"JSONCODE\"}]' WHERE fk_product=20");
    check(!empty(saveLabel('JSONCODE')['error']), 'shared-entity JSON barcode collision is refused');
    $before = storedPayload(); $beforeCodes = storedCodes();
    $product->failWrite = true;
    check(!empty(saveLabel('FAILWRITE')['error']) && storedPayload() === $before && storedCodes() === $beforeCodes, 'extrafield failure rolls back barcode write');
    $product->failWrite = false; $testDb->failCommit = true;
    $calls = ZSProductSynch::$calls;
    check(!empty(saveLabel('FAILCOMMIT')['error']) && storedCodes() === $beforeCodes && ZSProductSynch::$calls === $calls, 'commit failure rolls back and never calls remote');
    $testDb->failCommit = false; $testDb->failBegin = true;
    check(!empty(saveLabel('FAILBEGIN')['error']) && storedCodes() === $beforeCodes, 'begin failure writes nothing');
    $testDb->failBegin = false; $user->canWrite = false;
    check(!empty(saveLabel('DENIED')['error']) && storedCodes() === $beforeCodes, 'write permission denial writes nothing');
    $user->canWrite = true; $product->id = 30;
    check(!empty(saveLabel('CROSSENTITY')['error']), 'unshared product cannot be changed');
    $product->id = 10; $testDb->failLock = true;
    check(!empty(saveLabel('BUSY')['error']), 'lock contention refuses save');
    $testDb->failLock = false;
    check(!empty(saveLabel('HAS SPACE')['error']) && !empty(saveLabel(str_repeat('x', 21))['error']), 'invalid identifiers are rejected');
    ZSProductSynch::$fail = true;
    $result = saveLabel('REMOTEFAIL');
    check(!empty($result['success']) && isset($result['warning']) && storedCodes()[0]['codbarras'] === 'REMOTEFAIL', 'remote failure keeps committed local state with warning');
    ZSProductSynch::$fail = false;
    check(!empty(saveLabel('REMOTEFAIL')['success']) && count(storedCodes()) === 1, 'remote retry is idempotent');
    $payload = storedPayload();
    $payload['barcode_links']['other_label'] = array('REMOTEFAIL');
    $payload['template_values']['other_label'] = array('product.label' => 'Keep me');
    $stmt = $testDb->pdo->prepare('UPDATE test_product_extrafields SET kreap_default_label_layout=? WHERE fk_object=10');
    $stmt->execute(array(kreaProductsEncodeLabelStoragePayload($payload)));
    saveLabel('NEWCODE');
    check(array_column(storedCodes(), 'codbarras') === array('REMOTEFAIL', 'NEWCODE')
        && storedPayload()['template_values']['other_label']['product.label'] === 'Keep me', 'another label keeps its barcode and saved values');
    $rows = storedCodes();
    $rows[] = array('codbarras' => 'MANUAL', 'descricao' => 'Manual package', 'qtd' => 12, 'total' => 4.5);
    $stmt = $testDb->pdo->prepare('UPDATE test_dolizsynch_zsproduct SET produtos_codigosbarras=? WHERE fk_product=10');
    $stmt->execute(array(json_encode($rows)));
    saveLabel('MANUAL');
    $manual = array_values(array_filter(storedCodes(), function ($row) { return $row['codbarras'] === 'MANUAL'; }));
    check(count($manual) === 1 && $manual[0]['qtd'] === 12 && $manual[0]['total'] === 4.5, 'existing barcode is reused without changing quantity or total');
    saveLabel('AFTERMANUAL');
    check(in_array('MANUAL', array_column(storedCodes(), 'codbarras'), true), 'later changes preserve manually entered barcode');
    $product->failWrite = true;
    $result = kreaProductsPersistLabelStoragePayload($product, array(), $langs, 'test_label', array('batch.validity_days' => '55'));
    check(!empty($result['error']) && (int) $testDb->pdo->query('SELECT lifetime FROM test_product WHERE rowid=10')->fetchColumn() === 10, 'label failure also rolls back validity-day change');
    $product->failWrite = false;
    $testDb->pdo->exec("UPDATE test_dolizsynch_zsproduct SET produtos_codigosbarras='[]' WHERE fk_product=10");
    $testDb->pdo->exec('UPDATE test_dolizsynch_zsproduct SET restricted=1 WHERE fk_product=10');
    $calls = ZSProductSynch::$calls; $result = saveLabel('RESTRICTED');
    check(!empty($result['success']) && isset($result['warning']) && ZSProductSynch::$calls === $calls, 'restricted product stays local and warns');
    $testDb->pdo->exec("UPDATE test_dolizsynch_zsproduct SET produtos_codigosbarras='invalid' WHERE fk_product=10");
    check(!empty(saveLabel('CORRUPT')['error']), 'corrupt barcode metadata is never overwritten');
    check(!$testDb->locked && !$testDb->transaction_opened, 'all failure paths release lock and transaction');
    $testDb->pdo->exec('DELETE FROM test_dolizsynch_zsproduct WHERE fk_product=10');
    $result = saveLabel('NEW_SHADOW');
    check(!empty($result['success']) && isset($result['warning'])
        && (int) $testDb->pdo->query('SELECT restricted FROM test_dolizsynch_zsproduct WHERE fk_product=10')->fetchColumn() === 1, 'missing shadow is created restricted without enabling POS sync');
    $modulesEnabled = false;
    $result = saveLabel('LOCALONLY');
    check(!empty($result['success']) && isset($result['warning'])
        && storedCodes()[0]['codbarras'] === 'NEW_SHADOW', 'missing integration saves label with warning and leaves barcode metadata intact');
} finally {
    unlink($templatePath);
    rmdir($templateDir); rmdir(dirname($templateDir)); rmdir(dirname($templateDir, 2)); rmdir(dirname($templateDir, 3)); rmdir(DOL_DATA_ROOT);
}
