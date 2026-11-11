<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Luracast\Restler\RestException;

/** Bound-ID native supplier prices; never use the destructive legacy upsert. */
class KreaProductsPurchasePriceService
{
    private $db;
    private $user;
    private $entityId;
    const COLUMNS = 'rowid, entity, fk_product, fk_soc, ref_fourn, desc_fourn, fk_availability, price, quantity, unitprice, charges, remise_percent, remise, default_vat_code, tva_tx, localtax1_tx, localtax1_type, localtax2_tx, localtax2_type, info_bits, fk_supplier_price_expression, delivery_time_days, supplier_reputation, packaging, fk_multicurrency, multicurrency_code, multicurrency_tx, multicurrency_unitprice, multicurrency_price, barcode, fk_barcode_type, import_key, status';
    const DECIMALS = array('price', 'quantity', 'unitprice', 'charges', 'remise_percent', 'remise', 'tva_tx', 'localtax1_tx', 'localtax2_tx', 'packaging', 'multicurrency_tx', 'multicurrency_unitprice', 'multicurrency_price');
    const INTEGERS = array('rowid', 'entity', 'fk_product', 'fk_soc', 'fk_availability', 'info_bits', 'fk_supplier_price_expression', 'delivery_time_days', 'fk_multicurrency', 'fk_barcode_type', 'status');

    public function __construct($db, $user, $entityId)
    {
        $this->db = $db;
        $this->user = $user;
        $this->entityId = $this->id($entityId);
    }

    /** Stable saved row, including fields omitted by the core collection API. */
    public function read($productId, $priceId, $lock = false, $supplierHint = null)
    {
        $productId = $this->id($productId);
        $priceId = $this->id($priceId);
        if ($lock) {
            $this->lockProduct($productId);
            $this->lockSupplier($this->id($supplierHint));
            // Price/supplier locks precede Product/DAO plain reads in RR transactions.
            $rows = $this->rows('SELECT '.self::COLUMNS.' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE rowid = '.$priceId.' AND fk_product = '.$productId.' AND entity = '.$this->entityId.' FOR UPDATE');
            if (count($rows) !== 1) {
                throw new RestException(404, 'Owned supplier price not found for this product');
            }
            if ((int) $rows[0]->fk_soc !== (int) $supplierHint) {
                throw new RestException(409, 'Supplier-price identity changed while acquiring its owner locks');
            }
        }
        $this->product($productId, false);
        if (!$lock) {
            $rows = $this->rows('SELECT '.self::COLUMNS.' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE rowid = '.$priceId.' AND fk_product = '.$productId.' AND entity = '.$this->entityId);
            if (count($rows) !== 1) {
                throw new RestException(404, 'Owned supplier price not found for this product');
            }
        }
        $this->supplier((int) $rows[0]->fk_soc);
        $record = new ProductFournisseur($this->db);
        if ($record->fetch_product_fournisseur_price($priceId, 1) <= 0 || (int) $record->id !== $productId) {
            throw new RestException(500, 'Cannot verify the native supplier price identity');
        }
        return $this->state($rows[0]);
    }

    /** Existing identity is immutable; only explicit HT price/availability/VAT fields change. */
    public function update($productId, $priceId, $request)
    {
        $this->controls($request);
        $dryRun = !isset($request['dry_run']) || $request['dry_run'];
        if ($dryRun) {
            $before = $this->read($productId, $priceId);
            $this->product($productId, true);
            return $this->result($before, $this->plan($before, $request), true);
        }
        if (!$this->user->hasRight('produit', 'creer') || !$this->user->hasRight('produit', 'lire')) {
            throw new RestException(403, 'Native product read/write rights are required');
        }
        // This immutable hint is deliberately read before beginning the RR
        // transaction. All writers then lock product -> supplier -> price.
        $hints = $this->rows('SELECT fk_soc FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE rowid = '.$this->id($priceId)
            .' AND fk_product = '.$this->id($productId).' AND entity = '.$this->entityId);
        if (count($hints) !== 1) {
            throw new RestException(404, 'Owned supplier price not found for this product');
        }
        $supplierHint = $this->id($hints[0]->fk_soc);
        $this->begin();
        try {
            $before = $this->read($productId, $priceId, true, $supplierHint);
            $this->product($productId, true);
            $after = $this->plan($before, $request);
            if ($before['revision'] !== $after['revision']) {
                if (empty($request['expected_revision'])) {
                    throw new RestException(400, 'A reviewed expected_revision is required when applying');
                }
                $raw = $before['preserved'];
                $supplier = $this->supplier($before['fourn_id']);
                $record = new ProductFournisseur($this->db);
                if ($record->fetch_product_fournisseur_price($priceId, 1) <= 0 || (int) $record->id !== (int) $productId) {
                    throw new RestException(409, 'Native supplier price changed identity');
                }
                // Binding the verified ID prevents update_buyprice's fallback DELETE.
                $record->product_fourn_price_id = (int) $priceId;
                $record->product_fourn_packaging = $raw['packaging'];
                $saved = $record->update_buyprice(
                    $after['qty'], $after['buyprice'], $this->user, 'HT', $supplier,
                    $after['availability'], $after['ref_fourn'], $after['tva_tx'],
                    $raw['charges'], $raw['remise_percent'], $raw['remise'], $raw['info_bits'],
                    $raw['delivery_time_days'] === null ? '' : $raw['delivery_time_days'],
                    $raw['supplier_reputation'], array($raw['localtax1_type'], $raw['localtax1_tx'], $raw['localtax2_type'], $raw['localtax2_tx']),
                    $raw['default_vat_code'], $raw['multicurrency_price'], 'HT', $raw['multicurrency_tx'],
                    $raw['multicurrency_code'], $raw['desc_fourn'], $raw['barcode'], $raw['fk_barcode_type'], array()
                );
                if ($saved <= 0 || (int) $record->product_fourn_price_id !== (int) $priceId) {
                    throw new RestException(500, 'Native supplier-price update failed; transaction rolled back');
                }
                $observed = $this->read($productId, $priceId);
                if (!hash_equals($after['revision'], $observed['revision'])) {
                    throw new RestException(409, 'Native price update did not preserve the reviewed price and ancillary fields; transaction rolled back');
                }
                $after = $observed;
            }
            $this->commit();
            return $this->result($before, $after, false);
        } catch (Throwable $exception) {
            $this->db->rollback();
            $this->rethrow($exception);
        }
    }

    /** Create only after globally checking the supplier reference namespace. */
    public function create($productId, $request)
    {
        $this->controls($request);
        $this->createFields($request);
        $dryRun = !isset($request['dry_run']) || $request['dry_run'];
        if (!$dryRun) {
            $this->begin();
        }
        try {
            $productId = $this->id($productId);
            if (!$dryRun) {
                $this->lockProduct($productId);
                $this->lockSupplier($request['fourn_id']);
            }
            // Current collision reads must precede the first plain DAO read.
            $collisions = $this->collisions($request['fourn_id'], $request['ref_fourn'], !$dryRun);
            $product = $this->product($productId, true);
            $supplier = $this->supplier($request['fourn_id']);
            $existing = null;
            foreach ($collisions as $row) {
                if ((int) $row->fk_product !== $productId) {
                    throw new RestException(409, 'Supplier reference is already linked to a different product');
                }
                if ((int) $row->entity === $this->entityId && self::number($row->quantity) === self::number($request['qty'])) {
                    if ($existing !== null) {
                        throw new RestException(409, 'Supplier price identity is ambiguous');
                    }
                    $existing = (int) $row->rowid;
                }
            }
            if ($existing !== null) {
                $before = $this->read($productId, $existing);
                $after = $this->plan($before, $request);
                if ($before['revision'] !== $after['revision']) {
                    throw new RestException(409, 'This supplier price already exists with different values; use its update endpoint');
                }
                if (!$dryRun) {
                    $this->commit();
                }
                return $this->result($before, $before, $dryRun);
            }
            if (isModEnabled('multicurrency')) {
                throw new RestException(409, 'Supplier-price creation requires a dedicated multicurrency contract when that module is enabled');
            }
            $after = $this->newState($productId, $request);
            if ($dryRun) {
                return $this->result(null, $after, true);
            }
            $record = new ProductFournisseur($this->db);
            if ($record->fetch($productId) <= 0 || (int) $record->id !== $productId) {
                throw new RestException(404, 'Native product lookup failed');
            }
            // This native helper inserts a scoped stub and binds its ID. A
            // concurrent unique-key conflict fails, never deletes another row.
            $saved = $record->add_fournisseur($this->user, $supplier->id, $request['ref_fourn'], $after['qty']);
            $priceId = (int) $record->product_fourn_price_id;
            if ($saved <= 0 || $priceId <= 0) {
                throw new RestException(409, 'Supplier price appeared concurrently or native creation failed; inspect before retrying');
            }
            $stub = $this->read($productId, $priceId);
            if ($stub['fourn_id'] !== (int) $supplier->id || $stub['ref_fourn'] !== $request['ref_fourn'] || $stub['qty'] !== $after['qty']) {
                throw new RestException(409, 'Native supplier stub identity did not match the requested price');
            }
            $record->product_fourn_price_id = $priceId;
            $record->product_fourn_packaging = null;
            $saved = $record->update_buyprice($after['qty'], $after['buyprice'], $this->user, 'HT', $supplier,
                $after['availability'], $after['ref_fourn'], $after['tva_tx'], 0, 0, 0, 0, '', '', array(), '', null, 'HT', 1, null, '', null, null, array());
            if ($saved <= 0 || (int) $record->product_fourn_price_id !== $priceId) {
                throw new RestException(500, 'Native supplier-price persistence failed; transaction rolled back');
            }
            $observed = $this->read($productId, $priceId);
            $expected = $after;
            $expected['price_id'] = $priceId;
            $expected['revision'] = self::revision($expected);
            if (!hash_equals($expected['revision'], $observed['revision'])) {
                throw new RestException(409, 'Native supplier-price creation differs from its preview; transaction rolled back');
            }
            $this->commit();
            return $this->result(null, $observed, false);
        } catch (Throwable $exception) {
            if (!$dryRun) {
                $this->db->rollback();
            }
            $this->rethrow($exception);
        }
    }

    private function plan($before, $request)
    {
        self::nativeNumber($before['qty'], 'MS', 'Saved minimum quantity');
        foreach (array('fourn_id', 'ref_fourn', 'qty') as $field) {
            if (array_key_exists($field, $request) && (string) $request[$field] !== (string) $before[$field]
                && !($field === 'qty' && self::nativeNumber($request[$field], 'MS', 'Minimum quantity') === $before[$field])) {
                throw new RestException(409, 'Supplier/reference/quantity identity cannot be changed by this endpoint');
            }
        }
        if (!array_key_exists('buyprice', $request) && !array_key_exists('availability', $request) && !array_key_exists('tva_tx', $request)) {
            throw new RestException(400, 'Supply buyprice, availability and/or tva_tx');
        }
        $after = $before;
        foreach (array('buyprice', 'tva_tx') as $field) {
            if (array_key_exists($field, $request)) {
                $after[$field] = $field === 'buyprice' ? self::nativeNumber($request[$field], 'MU', 'Purchase price') : self::vatRate($request[$field]);
            }
        }
        if (array_key_exists('availability', $request)) {
            if (!is_int($request['availability']) || $request['availability'] < 0) {
                throw new RestException(400, 'availability must be a non-negative dictionary ID');
            }
            $after['availability'] = $request['availability'];
        }
        if ($after['buyprice'] !== $before['buyprice'] && !empty($before['preserved']['fk_supplier_price_expression'])) {
            throw new RestException(409, 'Dynamic supplier-price expressions require their dedicated native workflow');
        }
        self::nativeNumber($after['buyprice'], 'MU', 'Saved/planned purchase price');
        $after['unitprice'] = self::number(price2num(((float) $after['buyprice']) / ((float) $after['qty']), 'MU'));
        $after['revision'] = self::revision($after);
        if ($after['revision'] !== $before['revision']) {
            $this->assertNativePreserves($before, $after);
        }
        if (isset($request['expected_revision']) && !hash_equals($before['revision'], $request['expected_revision']) && $after['revision'] !== $before['revision']) {
            throw new RestException(409, 'Supplier price changed; read and review the new state');
        }
        return $after;
    }

    /** Refuse known native coercions before preview succeeds or triggers run. */
    private function assertNativePreserves($before, $after)
    {
        $raw = $before['preserved'];
        if (isModEnabled('multicurrency') || $raw['fk_multicurrency'] !== null || $raw['multicurrency_unitprice'] !== null
            || !in_array($raw['multicurrency_price'], array(null, '0'), true)
            || !in_array($raw['multicurrency_code'], array(null, ''), true) || $raw['multicurrency_tx'] !== '1') {
            throw new RestException(409, 'Changed supplier prices with active or historical multicurrency metadata require a dedicated currency contract');
        }
        $normalized = array();
        $normalized['desc_fourn'] = (string) $raw['desc_fourn'];
        $normalized['default_vat_code'] = $raw['default_vat_code'] ? (string) $raw['default_vat_code'] : null;
        $reputation = $raw['supplier_reputation'];
        if (empty($reputation) || $reputation == -1) {
            $reputation = '';
        }
        $normalized['supplier_reputation'] = empty($reputation) ? null : (string) $reputation;
        $normalized['barcode'] = empty($raw['barcode']) ? null : (string) $raw['barcode'];
        $normalized['fk_barcode_type'] = empty($raw['fk_barcode_type']) ? null : (int) $raw['fk_barcode_type'];
        $normalized['charges'] = self::number(price2num(empty($raw['charges']) ? 0 : $raw['charges'], 'MU'));
        $normalized['remise_percent'] = self::number(empty($raw['remise_percent']) ? 0 : $raw['remise_percent']);
        $normalized['remise'] = self::number(empty($raw['remise']) ? 0 : $raw['remise']);
        $normalized['info_bits'] = (int) $raw['info_bits'];
        $delivery = $raw['delivery_time_days'] === null ? '' : $raw['delivery_time_days'];
        $normalized['delivery_time_days'] = $delivery != '' ? (int) $delivery : null;
        if (getDolGlobalString('PRODUCT_USE_SUPPLIER_PACKAGING')) {
            $packaging = price2num(empty($raw['packaging']) ? $after['qty'] : $raw['packaging'], 'MS');
            $normalized['packaging'] = empty($packaging) ? '1' : self::number($packaging);
        }
        foreach ($normalized as $field => $value) {
            if ($value !== $raw[$field]) {
                throw new RestException(409, 'Saved supplier-price '.$field.' cannot be preserved by the native lifecycle; use its dedicated native workflow');
            }
        }
        if (self::number(price2num($after['tva_tx'])) !== $after['tva_tx']) {
            throw new RestException(409, 'Saved VAT cannot be preserved by the configured native precision');
        }
    }

    private function newState($productId, $request)
    {
        $preserved = array('desc_fourn' => '', 'charges' => '0', 'remise_percent' => '0', 'remise' => '0',
            'default_vat_code' => null, 'localtax1_tx' => '0', 'localtax1_type' => '0', 'localtax2_tx' => '0', 'localtax2_type' => '0',
            'info_bits' => 0, 'fk_supplier_price_expression' => null, 'delivery_time_days' => null, 'supplier_reputation' => null,
            'packaging' => getDolGlobalString('PRODUCT_USE_SUPPLIER_PACKAGING') ? self::nativeNumber($request['qty'], 'MS', 'Minimum quantity') : null,
            'fk_multicurrency' => null, 'multicurrency_code' => null, 'multicurrency_tx' => '1', 'multicurrency_unitprice' => null,
            'multicurrency_price' => null, 'barcode' => null, 'fk_barcode_type' => null, 'import_key' => null, 'status' => 1);
        $state = array('version' => 1, 'price_id' => null, 'product_id' => $productId, 'entity' => $this->entityId,
            'fourn_id' => $request['fourn_id'], 'ref_fourn' => $request['ref_fourn'], 'qty' => self::nativeNumber($request['qty'], 'MS', 'Minimum quantity'),
            'buyprice' => self::nativeNumber($request['buyprice'], 'MU', 'Purchase price'), 'tva_tx' => self::vatRate($request['tva_tx']),
            'availability' => $request['availability'], 'price_base_type' => 'HT', 'preserved' => $preserved);
        $state['unitprice'] = self::number(price2num(((float) $state['buyprice']) / ((float) $state['qty']), 'MU'));
        $state['revision'] = self::revision($state);
        return $state;
    }

    private function state($row)
    {
        $values = get_object_vars($row);
        foreach ($values as $field => $value) {
            if (in_array($field, self::DECIMALS, true)) {
                $values[$field] = $value === null ? null : self::number($value);
            } elseif (in_array($field, self::INTEGERS, true)) {
                $values[$field] = $value === null ? null : (int) $value;
            }
        }
        if ($values['quantity'] === null || (float) $values['quantity'] <= 0) {
            throw new RestException(409, 'Saved supplier quantity requires manual review');
        }
        $state = array('version' => 1, 'price_id' => $values['rowid'], 'product_id' => $values['fk_product'], 'entity' => $values['entity'],
            'fourn_id' => $values['fk_soc'], 'ref_fourn' => $values['ref_fourn'], 'qty' => $values['quantity'], 'buyprice' => $values['price'],
            'tva_tx' => $values['tva_tx'], 'availability' => (int) $values['fk_availability'], 'price_base_type' => 'HT');
        foreach (array('rowid', 'entity', 'fk_product', 'fk_soc', 'ref_fourn', 'quantity', 'price', 'tva_tx', 'fk_availability', 'unitprice') as $field) {
            unset($values[$field]);
        }
        $state['preserved'] = $values;
        $state['unitprice'] = self::number($row->unitprice);
        $state['revision'] = self::revision($state);
        return $state;
    }

    private function controls($request)
    {
        if (!is_array($request) || array_diff(array_keys($request), array('version', 'entity_id', 'confirm', 'dry_run', 'expected_revision', 'fourn_id', 'ref_fourn', 'qty', 'buyprice', 'availability', 'tva_tx', 'price_base_type'))) {
            throw new RestException(400, 'Unknown supplier-price fields');
        }
        if (!isset($request['entity_id']) || !is_int($request['entity_id']) || $request['entity_id'] !== $this->entityId) {
            throw new RestException(403, 'Explicit entity_id must match the selected company');
        }
        if (!isset($request['confirm']) || $request['confirm'] !== true
            || (array_key_exists('dry_run', $request) && !is_bool($request['dry_run']))) {
            throw new RestException(400, 'confirm=true and Boolean dry_run are required');
        }
        if ((array_key_exists('version', $request) && $request['version'] !== 1)
            || (array_key_exists('price_base_type', $request) && $request['price_base_type'] !== 'HT')) {
            throw new RestException(400, 'Only version 1 HT purchase prices are supported');
        }
        if (array_key_exists('expected_revision', $request) && (!is_string($request['expected_revision']) || !preg_match('/\A[a-f0-9]{64}\z/', $request['expected_revision']))) {
            throw new RestException(400, 'expected_revision must be the saved revision hash');
        }
    }

    private function createFields($request)
    {
        foreach (array('fourn_id', 'ref_fourn', 'qty', 'buyprice', 'availability', 'tva_tx') as $field) {
            if (!array_key_exists($field, $request)) {
                throw new RestException(400, 'Missing required supplier-price creation fields');
            }
        }
        if (!is_int($request['fourn_id'])) {
            throw new RestException(400, 'fourn_id must be a positive integer');
        }
        $this->id($request['fourn_id']);
        if (!is_string($request['ref_fourn']) || trim($request['ref_fourn']) === '' || strlen($request['ref_fourn']) > 128
            || preg_match('/[<>\x00-\x1f\x7f]/', $request['ref_fourn'])) {
            throw new RestException(400, 'Supplier reference must be nonempty plain text of at most 128 bytes');
        }
        foreach (array('qty', 'buyprice', 'tva_tx') as $field) {
            self::number($request[$field]);
        }
        self::nativeNumber($request['qty'], 'MS', 'Minimum quantity');
        self::nativeNumber($request['buyprice'], 'MU', 'Purchase price');
        self::vatRate($request['tva_tx']);
        if ((float) $request['qty'] <= 0 || !is_int($request['availability']) || $request['availability'] < 0) {
            throw new RestException(400, 'Quantity must be positive and availability a non-negative ID');
        }
    }

    private function product($productId, $write)
    {
        if (!isModEnabled('kreaproducts')) {
            throw new RestException(503, 'KreaProducts is not enabled');
        }
        $productId = $this->id($productId);
        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            throw new RestException(404, 'Product not found');
        }
        if ((int) $product->entity !== $this->entityId || !DolibarrApi::_checkAccessToResource('product', $productId)
            || !$this->user->hasRight('produit', $write ? 'creer' : 'lire')) {
            throw new RestException(403, 'Owned product and native product rights are required');
        }
        return $product;
    }

    private function supplier($supplierId)
    {
        $supplierId = $this->id($supplierId);
        $supplier = new Fournisseur($this->db);
        if ($supplier->fetch($supplierId) <= 0) {
            throw new RestException(404, 'Supplier not found');
        }
        if ((int) $supplier->entity !== $this->entityId || empty($supplier->fournisseur)
            || !DolibarrApi::_checkAccessToResource('societe', $supplierId)
            || (!empty($this->user->socid) && (int) $this->user->socid !== $supplierId)) {
            throw new RestException(403, 'Owned supplier access is required');
        }
        return $supplier;
    }

    private function lockProduct($productId)
    {
        if (!$this->user->hasRight('produit', 'creer') || !$this->user->hasRight('produit', 'lire')) {
            throw new RestException(403, 'Native product read/write rights are required');
        }
        $rows = $this->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE rowid = '.$this->id($productId).' AND entity = '.$this->entityId.' FOR UPDATE');
        if (count($rows) !== 1) {
            throw new RestException(403, 'Cannot lock the owned product');
        }
    }

    private function lockSupplier($supplierId)
    {
        $rows = $this->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$this->id($supplierId).' AND entity = '.$this->entityId.' FOR UPDATE');
        if (count($rows) !== 1) {
            throw new RestException(403, 'Cannot lock the owned supplier');
        }
    }

    private function collisions($supplierId, $reference, $lock)
    {
        $entities = (string) getEntity('productsupplierprice');
        if (!preg_match('/\A[0-9]+(?:\s*,\s*[0-9]+)*\z/', $entities)) {
            throw new RestException(500, 'Supplier-price entity scope is unavailable');
        }
        return $this->rows('SELECT rowid, fk_product, entity, quantity FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE fk_soc = '.$this->id($supplierId)
            ." AND ref_fourn = '".$this->db->escape($reference)."' AND entity IN (".$entities.') ORDER BY rowid'.($lock ? ' FOR UPDATE' : ''));
    }

    public static function number($value)
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RestException(400, 'Purchase-price numbers must be finite');
            }
            $decimal = number_format($value, 8, '.', '');
            if ((float) $decimal !== $value) {
                throw new RestException(400, 'Purchase-price numeric input exceeds eight fractional places; use exact decimal text');
            }
            $value = $decimal;
        }
        if (is_bool($value) || $value === null || !is_scalar($value) || !preg_match('/\A[0-9]+(?:\.[0-9]{1,8})?\z/', (string) $value)) {
            throw new RestException(400, 'Purchase-price numbers must be non-negative decimals with at most eight places');
        }
        $parts = explode('.', (string) $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 15) {
            throw new RestException(400, 'Purchase-price number exceeds the safe native range');
        }
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private static function nativeNumber($value, $mode, $field)
    {
        $canonical = self::number($value);
        $native = self::number(price2num($canonical, $mode));
        if ($native !== $canonical) {
            throw new RestException(400, $field.' cannot be represented by the configured native '.$mode.' precision; no values were rounded or remapped');
        }
        return $native;
    }

    private static function vatRate($value)
    {
        $canonical = self::number($value);
        $parts = explode('.', $canonical, 2);
        if (strlen($parts[0]) > 3 || (isset($parts[1]) && strlen($parts[1]) > 4)) {
            throw new RestException(400, 'VAT must fit the native 0..999.9999 rate range with at most four fractional places');
        }
        if (self::number(price2num($canonical)) !== $canonical) {
            throw new RestException(400, 'VAT cannot be represented by the configured native precision');
        }
        return $canonical;
    }

    private static function revision($state)
    {
        unset($state['revision']);
        // Recursively ordered keys make raw DB column order irrelevant.
        $sort = function (&$value) use (&$sort) {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$item) {
                    $sort($item);
                }
            }
        };
        $sort($state);
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RestException(500, 'Cannot encode supplier-price revision');
        }
        return hash('sha256', $encoded);
    }

    private function id($value)
    {
        if (is_bool($value) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value) || strlen((string) $value) > 15) {
            throw new RestException(400, 'A positive object ID is required');
        }
        return (int) $value;
    }

    private function result($before, $after, $dryRun)
    {
        return array('version' => 1, 'product_id' => $after['product_id'], 'entity' => $after['entity'], 'price_id' => $after['price_id'],
            'status' => ($before !== null && $before['revision'] === $after['revision'] ? 'unchanged' : ($dryRun ? 'dry_run' : 'confirmed')),
            'dry_run' => $dryRun, 'before' => $before, 'after' => $after);
    }

    private function rows($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RestException(500, 'Supplier-price database lookup failed');
        }
        $rows = array();
        while ($row = $this->db->fetch_object($result)) {
            $rows[] = $row;
            if (count($rows) > 500) {
                throw new RestException(409, 'Supplier-price lookup exceeds the supported bound');
            }
        }
        $this->db->free($result);
        return $rows;
    }

    private function begin()
    {
        if ($this->db->begin() <= 0) {
            throw new RestException(500, 'Cannot begin the supplier-price transaction');
        }
    }

    private function commit()
    {
        if ($this->db->commit() <= 0) {
            throw new RestException(500, 'Supplier-price commit could not be confirmed; inspect before retrying');
        }
    }

    private function rethrow($exception)
    {
        if ($exception instanceof RestException) {
            throw $exception;
        }
        dol_syslog(__METHOD__.' failed; transaction rolled back', LOG_ERR);
        throw new RestException(500, 'Supplier-price persistence failed; inspect before retrying');
    }
}
