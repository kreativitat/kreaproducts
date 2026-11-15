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

// Execute the deployed service body against isolated doubles; no database or network.
const MAIN_DB_PREFIX = 'llx_';
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message.PHP_EOL;
}
function price2num($value, $mode = '') { return round((float) $value, 8); }
function dol_syslog($message, $level = 0) { $GLOBALS['logs'][] = $message; }
function isModEnabled($name) { return !empty($GLOBALS['modules'][$name]); }
function getDolGlobalString($name) { return $GLOBALS['settings'][$name] ?? ''; }
class Fournisseur
{
    public $id;
    public function __construct($db) {}
    public function fetch($id) { $this->id = $id; return 1; }
}
class FakeDb
{
    public $rows = array();
    public $log = array();
    public function escape($value) { return addslashes($value); }
    public function lasterror() { return 'db error'; }
    public function query($sql)
    {
        return new ArrayObject($this->result($sql));
    }
    private function result($sql)
    {
        $this->log[] = $sql;
        if (strpos($sql, 'llx_societe') !== false) {
            return array((object) array('rowid' => 55));
        }
        if (preg_match('/WHERE rowid = (\d+)/', $sql, $match)) {
            return isset($this->rows[(int) $match[1]]) ? array((object) $this->rows[(int) $match[1]]) : array();
        }
        if (preg_match("/pfp\.fk_product = (\d+).*pfp\.fk_soc = (\d+).*pfp\.ref_fourn = '([^']*)'/", $sql, $match)) {
            foreach ($this->rows as $row) {
                if ((int) $row['fk_product'] === (int) $match[1] && (int) $row['fk_soc'] === (int) $match[2] && strcasecmp($row['ref_fourn'], $match[3]) === 0) {
                    return array((object) array('rowid' => $row['rowid']));
                }
            }
            return array();
        }
        throw new RuntimeException('Unexpected SQL: '.$sql);
    }
    public function fetch_object($result)
    {
        $rows = $result->getArrayCopy();
        $row = array_shift($rows);
        $result->exchangeArray($rows);
        return $row;
    }
}
class ProductFournisseur
{
    public static $calls = array();
    public static $unbind = false;
    public static $corrupt = '';
    public $db;
    public $id;
    public $error = '';
    public $product_fourn_price_id = 0;
    public $product_fourn_packaging;
    public function __construct($db) { $this->db = $db; }
    public function fetch_product_fournisseur_price($id, $ignore = 0)
    {
        if (!isset($this->db->rows[$id])) { return 0; }
        $this->id = $this->db->rows[$id]['fk_product'];
        $this->product_fourn_price_id = self::$unbind ? 0 : $id;
        return 1;
    }
    public function update_buyprice($qty, $price, $user, $base, $fourn, $availability, $ref, $vat, $charges, $remisePercent, $remise, $npr, $delivery, $reputation, $localTaxes, $vatCode, $mcPrice, $mcBase, $mcTx, $mcCode, $desc, $barcode, $barcodeType)
    {
        self::$calls[] = func_get_args();
        if ($this->product_fourn_price_id <= 0) { throw new RuntimeException('Destructive unbound fallback entered'); }
        $row = &$this->db->rows[$this->product_fourn_price_id];
        $row = array_merge($row, array('price' => $price, 'unitprice' => $price / $qty, 'ref_fourn' => $ref, 'desc_fourn' => $desc,
            'fk_availability' => $availability, 'tva_tx' => $vat, 'charges' => $charges, 'remise_percent' => $remisePercent, 'remise' => $remise,
            'info_bits' => $npr, 'delivery_time_days' => $delivery !== '' ? (int) $delivery : null, 'supplier_reputation' => $reputation ?: null,
            'default_vat_code' => $vatCode ?: null, 'multicurrency_price' => $mcPrice, 'multicurrency_code' => $mcCode,
            'barcode' => $barcode ?: null, 'fk_barcode_type' => $barcodeType ?: null));
        if (self::$corrupt !== '') { $row[self::$corrupt] = 'changed'; }
        return $this->product_fourn_price_id;
    }
}
function baseRow()
{
    return array('rowid' => 900, 'entity' => 8, 'fk_product' => 7087, 'fk_soc' => 55, 'ref_fourn' => 'Ref-A', 'desc_fourn' => null,
        'fk_availability' => 2, 'price' => '20.00000000', 'quantity' => '2.00000000', 'unitprice' => '10.00000000', 'charges' => '0.00000000',
        'remise_percent' => '5', 'remise' => '0', 'default_vat_code' => 'NOR', 'tva_tx' => '23.0000', 'localtax1_tx' => '0', 'localtax1_type' => '0',
        'localtax2_tx' => '0', 'localtax2_type' => '0', 'info_bits' => 1, 'fk_supplier_price_expression' => null, 'delivery_time_days' => 3,
        'supplier_reputation' => 'FAVORITE', 'packaging' => '6.00000000', 'fk_multicurrency' => null, 'multicurrency_code' => null, 'multicurrency_tx' => '1.00000000',
        'multicurrency_unitprice' => null, 'multicurrency_price' => null, 'barcode' => '5601234567890', 'fk_barcode_type' => 2, 'import_key' => 'imp1', 'status' => 1);
}
$source = file_get_contents((getenv('KREAPRODUCTS_TEST_ROOT') ?: __DIR__.'/..').'/class/KreaProductsSupplierPriceSyncService.class.php');
eval(substr($source, strpos($source, "\nclass KreaProductsSupplierPriceSyncService")));

$conf = (object) array('entity' => 8, 'currency' => 'EUR');
$user = (object) array('id' => 1);
$service = new KreaProductsSupplierPriceSyncService();
$invoice = (object) array('id' => 1, 'entity' => 8, 'type' => 0, 'socid' => 55,
    'lines' => array((object) array('fk_product' => 7087, 'ref_supplier' => 'REF-A', 'qty' => 4, 'subprice' => 12.5)));
$run = function (array $row) use ($service, $invoice, $conf, $user) {
    $db = new FakeDb();
    $db->rows[900] = $row;
    ProductFournisseur::$calls = array();
    return array($service->syncFromValidatedSupplierInvoice($invoice, $db, $user, $conf), $db);
};

list($updated, $db) = $run(baseRow());
$expected = array_merge(baseRow(), array('price' => 25.0, 'unitprice' => 12.5));
$actual = $db->rows[900];
check($updated === 1 && (float) $actual['price'] === 25.0, 'matching supplier price row updated from invoice unit price');
foreach (array('ref_fourn', 'fk_availability', 'remise_percent', 'default_vat_code', 'info_bits', 'delivery_time_days', 'supplier_reputation', 'barcode', 'fk_barcode_type', 'multicurrency_code', 'multicurrency_price', 'import_key') as $field) {
    check($actual[$field] == $expected[$field] && ($actual[$field] === null) === ($expected[$field] === null), 'saved '.$field.' preserved');
}
$locks = array_values(array_filter($db->log, function ($sql) { return strpos($sql, 'FOR UPDATE') !== false; }));
check(strpos($db->log[0], 'llx_societe') !== false && count($locks) === 3, 'supplier locked before its price rows');

ProductFournisseur::$unbind = true;
list($updated, $db) = $run(baseRow());
check($updated === 1 && (float) $db->rows[900]['price'] === 25.0, 'verified price ID bound explicitly; unbound fallback never entered');
ProductFournisseur::$unbind = false;

foreach (array('foreign currency' => array('multicurrency_code' => 'USD'), 'foreign rate' => array('multicurrency_tx' => '1.10000000'), 'dynamic expression' => array('fk_supplier_price_expression' => 4)) as $label => $change) {
    list($updated, $db) = $run(array_merge(baseRow(), $change));
    check($updated === 0 && ProductFournisseur::$calls === array() && $db->rows[900]['price'] === '20.00000000', $label.' row left untouched');
}

$GLOBALS['modules']['multicurrency'] = true;
list($updated, $db) = $run(array_merge(baseRow(), array('multicurrency_code' => 'EUR', 'multicurrency_price' => '20.00000000')));
check($updated === 1 && ProductFournisseur::$calls[0][16] === 25.0 && ProductFournisseur::$calls[0][19] === 'EUR', 'same-currency row keeps a coherent currency price');
$GLOBALS['modules'] = array();

ProductFournisseur::$corrupt = 'barcode';
try {
    $run(baseRow());
    check(false, 'ancillary drift must fail');
} catch (RuntimeException $exception) {
    check(strpos($exception->getMessage(), 'barcode') !== false, 'unexpected ancillary drift fails the validation transaction');
}
ProductFournisseur::$corrupt = '';

$other = baseRow();
$other['fk_product'] = 1;
list($updated, $db) = $run($other);
check($updated === 0 && ProductFournisseur::$calls === array(), 'price row of another product is never updated');
