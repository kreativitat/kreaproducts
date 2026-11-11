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
    function isModEnabled($name) { return $name === 'kreaproducts' || !empty($GLOBALS['modules'][$name]); }
    function getDolGlobalString($name) { return $GLOBALS['settings'][$name] ?? ''; }
    function getEntity($name) { return '1,8'; }
    function dol_syslog($message, $level = 0) {}
    function price2num($value, $mode = '') { return round((float) $value, $GLOBALS['precision'][$mode] ?? 8); }
    class DolibarrApi {
        public static $allowed = true;
        public static function _checkAccessToResource($type, $id) { return self::$allowed; }
    }
    class PriceUser {
        public $socid = 0;
        public $denied = array();
        public function hasRight(...$parts) { return !in_array(implode('.', $parts), $this->denied, true); }
    }
    class PriceResult {
        public $rows;
        public function __construct($rows) { $this->rows = array_values($rows); }
    }
    function defaultPrice($id = 12, $productId = 42) {
        return array('rowid' => $id, 'entity' => 8, 'fk_product' => $productId, 'fk_soc' => 5, 'ref_fourn' => 'SUP-42',
            'desc_fourn' => 'Saved description', 'fk_availability' => 3, 'price' => '10', 'quantity' => '10', 'unitprice' => '1',
            'charges' => '1.25', 'remise_percent' => '2', 'remise' => '0', 'default_vat_code' => 'PT23', 'tva_tx' => '23',
            'localtax1_tx' => '0', 'localtax1_type' => '0', 'localtax2_tx' => '0', 'localtax2_type' => '0', 'info_bits' => 1,
            'fk_supplier_price_expression' => null, 'delivery_time_days' => 5, 'supplier_reputation' => 'FAVORITE', 'packaging' => '5',
            'fk_multicurrency' => null, 'multicurrency_code' => null, 'multicurrency_tx' => '1', 'multicurrency_unitprice' => null,
            'multicurrency_price' => null, 'barcode' => '12345', 'fk_barcode_type' => 4, 'import_key' => 'import', 'status' => 1);
    }
    class PriceDB {
        public $products = array(42 => array('id' => 42, 'entity' => 8), 99 => array('id' => 99, 'entity' => 8));
        public $suppliers = array(5 => array('id' => 5, 'entity' => 8, 'fournisseur' => 1));
        public $prices = array();
        public $queries = array();
        public $snapshots = array();
        public $history = array();
        public $nativeCalls = array();
        public $failNative = false;
        public $driftAncillary = false;
        public $concurrentCreate = false;
        public $flipSupplierAfterHint = false;
        public $rollbacks = 0;
        public function escape($value) { return str_replace("'", "''", (string) $value); }
        public function begin() { $this->queries[] = 'BEGIN'; $this->snapshots[] = serialize(array($this->prices, $this->history)); return 1; }
        public function commit() { $this->queries[] = 'COMMIT'; array_pop($this->snapshots); return 1; }
        public function rollback() {
            $this->rollbacks++;
            if ($this->snapshots) { list($this->prices, $this->history) = unserialize(array_pop($this->snapshots)); }
            return 1;
        }
        public function query($sql) {
            $this->queries[] = $sql;
            if (strpos($sql, 'FROM test_product WHERE') !== false) {
                preg_match('/rowid = ([0-9]+)/', $sql, $match);
                $id = (int) $match[1];
                return new PriceResult(isset($this->products[$id]) && $this->products[$id]['entity'] === 8 ? array(array('rowid' => $id)) : array());
            }
            if (strpos($sql, 'FROM test_societe WHERE') !== false) {
                preg_match('/rowid = ([0-9]+)/', $sql, $match);
                $id = (int) $match[1];
                return new PriceResult(isset($this->suppliers[$id]) && $this->suppliers[$id]['entity'] === 8 ? array(array('rowid' => $id)) : array());
            }
            if (strpos($sql, 'WHERE rowid = ') !== false) {
                preg_match('/rowid = ([0-9]+) AND fk_product = ([0-9]+)/', $sql, $match);
                $row = $this->prices[(int) $match[1]] ?? null;
                if ($this->flipSupplierAfterHint && strpos($sql, 'SELECT fk_soc FROM') === 0) {
                    $this->prices[(int) $match[1]]['fk_soc'] = 6;
                    $this->suppliers[6] = array('id' => 6, 'entity' => 8, 'fournisseur' => 1);
                }
                return new PriceResult($row && $row['fk_product'] === (int) $match[2] && $row['entity'] === 8 ? array($row) : array());
            }
            preg_match('/fk_soc = ([0-9]+).*ref_fourn = \'((?:\'\'|[^\'])*)\'/s', $sql, $match);
            $reference = str_replace("''", "'", $match[2]);
            return new PriceResult(array_filter($this->prices, function ($row) use ($match, $reference) {
                return $row['fk_soc'] === (int) $match[1] && $row['ref_fourn'] === $reference && in_array($row['entity'], array(1, 8), true);
            }));
        }
        public function fetch_object($result) { return $result->rows ? (object) array_shift($result->rows) : false; }
        public function free($result) {}
    }
    class Product {
        protected $db;
        public $id;
        public $entity;
        public function __construct($db) { $this->db = $db; }
        public function fetch($id) {
            $this->db->queries[] = 'DAO_PRODUCT';
            if (!isset($this->db->products[$id])) { return 0; }
            $this->id = $id; $this->entity = $this->db->products[$id]['entity'];
            return 1;
        }
    }
    class Fournisseur {
        private $db;
        public $id;
        public $entity;
        public $fournisseur;
        public function __construct($db) { $this->db = $db; }
        public function fetch($id) {
            $this->db->queries[] = 'DAO_SUPPLIER';
            if (!isset($this->db->suppliers[$id])) { return 0; }
            foreach ($this->db->suppliers[$id] as $key => $value) { $this->$key = $value; }
            return 1;
        }
    }
    class ProductFournisseur extends Product {
        public $product_fourn_price_id;
        public $product_fourn_packaging;
        public function fetch_product_fournisseur_price($id, $ignoreExpression = 0) {
            $this->db->queries[] = 'DAO_PRICE';
            if (!isset($this->db->prices[$id]) || $ignoreExpression !== 1) { return 0; }
            $this->id = $this->db->prices[$id]['fk_product'];
            $this->product_fourn_price_id = $id;
            return 1;
        }
        public function add_fournisseur($user, $supplierId, $reference, $qty) {
            $this->db->nativeCalls[] = array('add_fournisseur', $this->id, $supplierId, $reference, $qty);
            if ($this->db->concurrentCreate) { return -1; }
            $id = 100;
            $row = defaultPrice($id, $this->id);
            foreach (array('desc_fourn', 'default_vat_code', 'delivery_time_days', 'supplier_reputation', 'packaging', 'barcode', 'fk_barcode_type', 'import_key') as $field) { $row[$field] = null; }
            foreach (array('price', 'unitprice', 'charges', 'remise_percent', 'remise', 'tva_tx') as $field) { $row[$field] = '0'; }
            $row['info_bits'] = 0; $row['fk_availability'] = null;
            $row['fk_soc'] = $supplierId; $row['ref_fourn'] = $reference; $row['quantity'] = (string) $qty;
            $this->db->prices[$id] = $row; $this->product_fourn_price_id = $id;
            return 1;
        }
        public function update_buyprice(...$args) {
            $this->db->nativeCalls[] = array('update_buyprice', $this->product_fourn_price_id, $args, $this->product_fourn_packaging);
            if (!$this->product_fourn_price_id || !isset($this->db->prices[$this->product_fourn_price_id])) { throw new \RuntimeException('Dangerous unbound legacy fallback attempted'); }
            if ($this->db->failNative) { return -1; }
            $this->db->begin();
            $row = &$this->db->prices[$this->product_fourn_price_id];
            foreach (array(0 => 'quantity', 1 => 'price', 5 => 'fk_availability', 6 => 'ref_fourn', 7 => 'tva_tx', 8 => 'charges', 9 => 'remise_percent', 10 => 'remise', 11 => 'info_bits', 12 => 'delivery_time_days', 13 => 'supplier_reputation', 15 => 'default_vat_code', 16 => 'multicurrency_price', 18 => 'multicurrency_tx', 19 => 'multicurrency_code', 20 => 'desc_fourn', 21 => 'barcode', 22 => 'fk_barcode_type') as $index => $field) {
                $row[$field] = $args[$index];
            }
            $row['delivery_time_days'] = $row['delivery_time_days'] === '' ? null : $row['delivery_time_days'];
            foreach (array('supplier_reputation', 'default_vat_code', 'barcode', 'fk_barcode_type') as $field) { $row[$field] = empty($row[$field]) ? null : $row[$field]; }
            $row['desc_fourn'] = (string) $row['desc_fourn'];
            $row['unitprice'] = price2num(((float) $row['price']) / ((float) $row['quantity']), 'MU');
            $row['multicurrency_unitprice'] = null; $row['fk_multicurrency'] = null;
            if (getDolGlobalString('PRODUCT_USE_SUPPLIER_PACKAGING')) { $row['packaging'] = empty($this->product_fourn_packaging) ? $row['quantity'] : $this->product_fourn_packaging; }
            if ($this->db->driftAncillary) { $row['barcode'] = 'silently changed'; }
            $this->db->history[] = array('PRODUCT_BUYPRICE_MODIFY', $this->product_fourn_price_id, $row['price']);
            $this->db->commit();
            return $this->product_fourn_price_id;
        }
    }
    require_once __DIR__.'/../class/KreaProductsPurchasePriceService.class.php';
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) { throw new \RuntimeException($message); } $checks++; }
    function expectError($status, $callback) {
        try { $callback(); } catch (\Luracast\Restler\RestException $exception) {
            check($exception->getCode() === $status, 'Expected '.$status.'; got '.$exception->getCode().': '.$exception->getMessage()); return;
        }
        throw new \RuntimeException('Expected failure '.$status);
    }
    function fixture($existing = true) {
        $GLOBALS['modules'] = array(); $GLOBALS['settings'] = array(); $GLOBALS['precision'] = array();
        $db = new PriceDB(); $user = new PriceUser();
        if ($existing) { $db->prices[12] = defaultPrice(); }
        return array($db, $user, new KreaProductsPurchasePriceService($db, $user, 8));
    }
    function controls($extra = array()) { return array_merge(array('entity_id' => 8, 'confirm' => true), $extra); }
    function creation($extra = array()) { return controls(array_merge(array('fourn_id' => 5, 'ref_fourn' => 'NEW-42', 'qty' => '10', 'buyprice' => '20', 'availability' => 0, 'tva_tx' => '23'), $extra)); }

    list($db, $user, $service) = fixture();
    $before = $service->read(42, 12);
    check($before['price_id'] === 12 && $before['product_id'] === 42 && $before['preserved']['default_vat_code'] === 'PT23', 'GET preserves exact price/product identity and hidden VAT code');
    $preview = $service->update(42, 12, controls(array('buyprice' => '15')));
    check($preview['status'] === 'dry_run' && $preview['after']['unitprice'] === '1.5', 'Preview gives exact native HT plan');
    check($db->nativeCalls === array() && $db->snapshots === array(), 'Preview never invokes native mutation');
    $result = $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => $before['revision'])));
    check($result['status'] === 'confirmed' && $result['after']['preserved'] === $before['preserved'], 'Bound native update preserves every ancillary value');
    check($db->history === array(array('PRODUCT_BUYPRICE_MODIFY', 12, '15')), 'Native history/trigger path retained once');
    $native = $db->nativeCalls[0];
    check($native[1] === 12 && $native[2][15] === 'PT23' && $native[2][20] === 'Saved description' && $native[2][21] === '12345' && $native[3] === '5', 'Verified ID, VAT code, description, barcode and packaging passed to native update');
    $begin = array_search('BEGIN', $db->queries, true);
    $sequence = array_slice($db->queries, $begin + 1);
    check(count(array_filter(array_slice($sequence, 0, 3), function ($sql) { return strpos($sql, 'FOR UPDATE') !== false; })) === 3 && $sequence[3] === 'DAO_PRODUCT', 'Update locks product/supplier/price before any plain DAO SELECT');
    check(strpos($sequence[0], 'FROM test_product WHERE') !== false && strpos($sequence[1], 'FROM test_societe WHERE') !== false
        && strpos($sequence[2], 'FROM test_product_fournisseur_price WHERE') !== false, 'Update uses the same product then supplier then price lock order as create');
    $again = $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => $before['revision'])));
    check($again['status'] === 'unchanged' && count($db->history) === 1, 'Idempotent already-saved update needs no replay');

    list($db, $user, $service) = fixture();
    expectError(409, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '15', 'expected_revision' => str_repeat('0', 64)))); });
    expectError(409, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '15', 'qty' => '20'))); });
    $db->prices[12]['entity'] = 1;
    expectError(404, function () use ($service) { $service->read(42, 12); });
    $db->prices[12]['entity'] = 8; $db->products[42]['entity'] = 1;
    expectError(403, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => str_repeat('0', 64)))); });
    check($db->nativeCalls === array(), 'Stale/cross-company/identity changes never call native writes');

    foreach (array('driftAncillary', 'failNative') as $fault) {
        list($db, $user, $service) = fixture();
        $original = $db->prices; $db->$fault = true;
        expectError($fault === 'driftAncillary' ? 409 : 500, function () use ($service) {
            $revision = $service->read(42, 12)['revision'];
            $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => $revision)));
        });
        check($db->prices === $original && $db->history === array(), 'Native failure/ancillary drift rolls back price and history');
    }
    list($db, $user, $service) = fixture();
    $db->prices[12]['fk_supplier_price_expression'] = 4;
    expectError(409, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '15'))); });
    $user->denied = array('produit.creer');
    expectError(403, function () use ($service) { $service->update(42, 12, controls(array('availability' => 0))); });

    list($db, $user, $service) = fixture(false);
    $preview = $service->create(42, creation());
    check($preview['status'] === 'dry_run' && $preview['before'] === null && $db->prices === array(), 'Create previews the complete new state without a stub');
    $result = $service->create(42, creation(array('dry_run' => false)));
    check($result['status'] === 'confirmed' && $result['price_id'] === 100, 'Create confirms a new native bound price row');
    check(array_column($db->nativeCalls, 0) === array('add_fournisseur', 'update_buyprice') && $db->nativeCalls[1][1] === 100, 'Creation uses scoped native stub before bound update, never legacy DELETE fallback');
    check(count($db->history) === 1 && count($db->prices) === 1, 'Creation preserves one native history event and one price');
    $duplicate = $service->create(42, creation(array('dry_run' => false)));
    check($duplicate['status'] === 'unchanged' && count($db->nativeCalls) === 2, 'Existing identical create is unchanged');
    expectError(409, function () use ($service) { $service->create(42, creation(array('buyprice' => '30', 'dry_run' => false))); });

    list($db, $user, $service) = fixture(false);
    $db->prices[12] = defaultPrice(12, 99); $db->prices[12]['ref_fourn'] = 'NEW-42';
    $original = $db->prices;
    expectError(409, function () use ($service) { $service->create(42, creation(array('dry_run' => false))); });
    check($db->prices === $original && $db->nativeCalls === array(), 'Cross-product supplier reference collision cannot delete or reassign another price');
    list($db, $user, $service) = fixture(false);
    $db->concurrentCreate = true;
    expectError(409, function () use ($service) { $service->create(42, creation(array('dry_run' => false))); });
    check(count($db->nativeCalls) === 1 && $db->prices === array(), 'Concurrent create failure never falls into a native upsert');
    list($db, $user, $service) = fixture(false);
    $GLOBALS['modules']['multicurrency'] = true;
    expectError(409, function () use ($service) { $service->create(42, creation()); });
    check($db->nativeCalls === array(), 'Unspecified foreign-currency creation fails closed');
    list($db, $user, $service) = fixture();
    $GLOBALS['modules']['multicurrency'] = true;
    expectError(409, function () use ($service) {
        $revision = $service->read(42, 12)['revision'];
        $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => $revision)));
    });
    check($db->nativeCalls === array(), 'Multicurrency HT price changes are rejected before native triggers or writes');
    expectError(409, function () use ($service) { $service->update(42, 12, controls(array('availability' => 0))); });
    check($db->nativeCalls === array(), 'Availability-only multicurrency updates also fail before native writes');
    foreach (array('desc_fourn' => null, 'barcode' => '', 'fk_barcode_type' => 0, 'default_vat_code' => '', 'supplier_reputation' => '', 'packaging' => null) as $field => $value) {
        list($db, $user, $service) = fixture();
        $db->prices[12][$field] = $value;
        if ($field === 'packaging') { $GLOBALS['settings']['PRODUCT_USE_SUPPLIER_PACKAGING'] = '1'; }
        expectError(409, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '15'))); });
        check($db->nativeCalls === array(), 'Known ancillary coercion is rejected during preview before native side effects');
    }
    foreach (array('fk_multicurrency' => 5, 'multicurrency_unitprice' => '2', 'multicurrency_price' => '20', 'multicurrency_code' => 'USD', 'multicurrency_tx' => '1.2') as $field => $value) {
        list($db, $user, $service) = fixture();
        $db->prices[12][$field] = $value;
        expectError(409, function () use ($service) { $service->update(42, 12, controls(array('availability' => 0))); });
        check($db->nativeCalls === array(), 'Historic currency metadata is rejected before even availability-only updates');
    }
    list($db, $user, $service) = fixture();
    $db->prices[12]['multicurrency_price'] = '0'; $db->prices[12]['multicurrency_code'] = '';
    $revision = $service->read(42, 12)['revision'];
    $result = $service->update(42, 12, controls(array('availability' => 0, 'dry_run' => false, 'expected_revision' => $revision)));
    check($result['status'] === 'confirmed', 'Native disabled-currency zero/empty defaults remain eligible when every value is preserved');
    list($db, $user, $service) = fixture();
    $revision = $service->read(42, 12)['revision']; $db->flipSupplierAfterHint = true;
    expectError(409, function () use ($service, $revision) {
        $service->update(42, 12, controls(array('buyprice' => '15', 'dry_run' => false, 'expected_revision' => $revision)));
    });
    check($db->nativeCalls === array(), 'Supplier hint is revalidated under the current price lock');
    list($db, $user, $service) = fixture(false);
    $GLOBALS['precision']['MS'] = 2;
    expectError(400, function () use ($service) { $service->create(42, creation(array('qty' => '10.001'))); });
    check($db->nativeCalls === array(), 'Minimum-quantity rounding cannot silently remap supplier identity');
    list($db, $user, $service) = fixture();
    $GLOBALS['precision']['MU'] = 2;
    expectError(400, function () use ($service) { $service->update(42, 12, controls(array('buyprice' => '1.001'))); });
    check($db->nativeCalls === array(), 'Purchase price precision mismatch fails before native mutation');
    list($db, $user, $service) = fixture(false);
    $GLOBALS['settings']['PRODUCT_USE_SUPPLIER_PACKAGING'] = '1';
    $result = $service->create(42, creation(array('dry_run' => false)));
    check($result['after']['preserved']['packaging'] === '10', 'New supplier packaging matches the native bound-ID quantity behavior');
    foreach (array('1000', '23.00001', '999.99999') as $rate) {
        list($db, $user, $service) = fixture();
        expectError(400, function () use ($service, $rate) { $service->update(42, 12, controls(array('tva_tx' => $rate))); });
        check($db->nativeCalls === array(), 'Invalid VAT storage precision/range is rejected before native mutation');
    }
    list($db, $user, $service) = fixture(false);
    expectError(400, function () use ($service) { $service->create(42, creation(array('buyprice' => 1.123456789))); });
    check($db->nativeCalls === array(), 'Direct JSON floating inputs never silently lose extra fractional places');
    echo 'PASS '.$checks." purchase-price API assertions\n";
}
