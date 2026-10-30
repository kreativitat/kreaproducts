<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Atomic label/barcode storage; remote synchronization follows the local commit. */
class KreaProductsLabelBarcodeService
{
    private $db;
    private $user;
    private $conf;
    private $langs;
    private $syncRequired = false;
    private $warning = '';

    public function __construct($db, $user, $conf, $langs)
    {
        $this->db = $db;
        $this->user = $user;
        $this->conf = $conf;
        $this->langs = $langs;
    }

    private function query($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_SAVE_ERROR');
        }
        return $result;
    }

    private function rows($sql)
    {
        $result = $this->query($sql);
        $rows = array();
        while ($row = $this->db->fetch_object($result)) {
            $rows[] = $row;
        }
        $this->db->free($result);
        return $rows;
    }

    /** Reject corrupt metadata instead of replacing unrelated barcode entries. */
    private function decodeRows($raw)
    {
        if ($raw === null || trim($raw) === '' || trim($raw) === 'null') {
            return array();
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            $rows = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        }
        if (!is_array($rows) || array_values($rows) !== $rows) {
            throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_METADATA_ERROR');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || (isset($row['codbarras']) && !is_scalar($row['codbarras']))) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_METADATA_ERROR');
            }
        }
        return $rows;
    }

    /** The lock is shared with KreaFlow's scanner across overlapping sharing groups. */
    public function save($product, callable $persist)
    {
        $this->syncRequired = false;
        $this->warning = '';
        if (!empty($this->db->transaction_opened)) {
            return array('error' => $this->langs->trans('KREAPRODUCTS_LABELS_BARCODE_BUSY'));
        }
        $lock = 'kf_barcode_'.substr(hash('sha256', MAIN_DB_PREFIX), 0, 40);
        $locked = false;
        $transaction = false;
        try {
            $locks = $this->rows("SELECT GET_LOCK('".$lock."', 5) AS acquired");
            if (!$locks || (int) $locks[0]->acquired !== 1) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_BUSY');
            }
            $locked = true;
            if ($this->db->begin() <= 0) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_SAVE_ERROR');
            }
            $transaction = true;
            $entities = implode(',', array_map('intval', explode(',', getEntity('product'))));
            $rows = $this->rows('SELECT rowid, entity, fk_product_type FROM '.MAIN_DB_PREFIX.'product WHERE rowid='.(int) $product->id
                .' AND entity IN ('.$entities.') FOR UPDATE');
            if (!$rows || empty($this->user->id) || !empty($this->user->socid)
                || !$this->user->hasRight((int) $rows[0]->fk_product_type === 1 ? 'service' : 'produit', 'creer')) {
                throw new RuntimeException('NotEnoughPermissions');
            }
            $product->entity = (int) $rows[0]->entity;
            if ($product->fetch($product->id) <= 0 || $product->fetch_optionals() < 0) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_SAVE_ERROR');
            }
            $result = $persist($this);
            if (!empty($result['error'])) {
                $this->db->rollback();
                $transaction = false;
                return $result;
            }
            if ($this->db->commit() <= 0) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_SAVE_ERROR');
            }
            $transaction = false;
            // Retries re-send the committed list, even when the barcode itself did not change.
            if ($this->syncRequired) {
                $this->synchronize($product);
            }
            if ($this->warning !== '') {
                $result['warning'] = $this->langs->trans($this->warning);
            }
            return $result;
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.': label/barcode save failed for product '.(int) $product->id, LOG_ERR);
            $key = $e->getMessage();
            if (strpos($key, 'KREAPRODUCTS_LABELS_BARCODE_') !== 0 && $key !== 'NotEnoughPermissions') {
                $key = 'KREAPRODUCTS_LABELS_BARCODE_SAVE_ERROR';
            }
            return array('error' => $this->langs->trans($key));
        } finally {
            if ($transaction) {
                $this->db->rollback();
            }
            if ($locked) {
                $this->db->query("SELECT RELEASE_LOCK('".$lock."')");
            }
        }
    }

    /** Update only label-owned codes; manually entered codes retain their quantities and totals. */
    public function update($product, &$payload, $templateCode, array $codes)
    {
        $previous = isset($payload['barcode_links']) ? $payload['barcode_links'] : array();
        if (!$codes && empty($previous[$templateCode])) {
            return;
        }
        if (!isModEnabled('kreaflow') || !isModEnabled('dolizsynch')) {
            $this->warning = 'KREAPRODUCTS_LABELS_BARCODE_MODULE_DISABLED';
            return;
        }
        foreach ($codes as $code) {
            if (!is_string($code) || !preg_match('/^[\x21-\x7E]{1,20}$/D', $code)) {
                throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_INVALID');
            }
        }
        $stored = $this->rows('SELECT rowid, codigo, codbarras, restricted, produtos_codigosbarras FROM '.MAIN_DB_PREFIX
            .'dolizsynch_zsproduct WHERE fk_product='.(int) $product->id.' FOR UPDATE');
        if (count($stored) > 1) {
            throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_METADATA_ERROR');
        }
        $rows = $this->decodeRows($stored ? $stored[0]->produtos_codigosbarras : null);
        // Check all product types in native product-sharing scope, regardless of UI read rights.
        foreach ($codes as $code) {
            $likes = array();
            foreach (array(0, JSON_UNESCAPED_SLASHES) as $flags) {
                $jsonCode = substr(json_encode($code, $flags), 1, -1);
                foreach (array($jsonCode, htmlspecialchars($jsonCode, ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $value) {
                    $like = $this->db->escape('%'.$this->db->escapeforlike($value).'%');
                    $likes[] = "z.produtos_codigosbarras LIKE '".$like."'";
                }
            }
            $quoted = $this->db->escape($code);
            $candidates = $this->rows('SELECT p.barcode, z.codbarras, z.produtos_codigosbarras FROM '.MAIN_DB_PREFIX.'product p'
                .' LEFT JOIN '.MAIN_DB_PREFIX.'dolizsynch_zsproduct z ON z.fk_product=p.rowid'
                .' WHERE p.entity IN ('.implode(',', array_map('intval', explode(',', getEntity('product')))).')'
                .' AND p.rowid<>'.(int) $product->id." AND (p.barcode='".$quoted."' OR z.codbarras='".$quoted
                ."' OR ".implode(' OR ', array_unique($likes)).')');
            foreach ($candidates as $candidate) {
                $used = array((string) $candidate->barcode, (string) $candidate->codbarras);
                foreach ($this->decodeRows($candidate->produtos_codigosbarras) as $row) {
                    $used[] = isset($row['codbarras']) ? (string) $row['codbarras'] : '';
                }
                if (in_array($code, $used, true)) {
                    throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_CONFLICT');
                }
            }
        }
        $previous[$templateCode] = array_values(array_unique($codes));
        $active = array();
        foreach ($previous as $linkedCodes) {
            $active = array_merge($active, $linkedCodes);
        }
        $owned = isset($payload['managed_barcodes']) ? $payload['managed_barcodes'] : array();
        $kept = array();
        $found = array();
        foreach ($rows as $row) {
            $code = isset($row['codbarras']) ? (string) $row['codbarras'] : '';
            if (in_array($code, $owned, true) && !in_array($code, $active, true)
                && $code !== (string) $product->barcode && (!$stored || $code !== (string) $stored[0]->codbarras)) {
                continue;
            }
            if (in_array($code, $codes, true)) {
                if (in_array($code, $found, true)) {
                    continue;
                }
                $row['descricao'] = (string) $product->label;
                $found[] = $code;
            }
            $kept[] = $row;
        }
        $codigo = $stored && $stored[0]->codigo !== null ? $stored[0]->codigo : $product->ref;
        foreach ($codes as $code) {
            if (!in_array($code, $found, true)) {
                $kept[] = array('codbarras' => $code, 'codigo' => ctype_digit((string) $codigo) ? (int) $codigo : (string) $codigo,
                    'descricao' => (string) $product->label, 'qtd' => 1, 'total' => 0, '_errors' => array());
                $owned[] = $code;
                $found[] = $code;
            }
        }
        $json = json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || dol_strlen($json) > 4096) {
            throw new RuntimeException('KREAPRODUCTS_LABELS_BARCODE_LIMIT');
        }
        if ($stored) {
            $this->query('UPDATE '.MAIN_DB_PREFIX."dolizsynch_zsproduct SET produtos_codigosbarras='".$this->db->escape($json)
                ."', fk_user_modif=".(int) $this->user->id.' WHERE rowid='.(int) $stored[0]->rowid.' AND fk_product='.(int) $product->id);
        } else {
            $this->query('INSERT INTO '.MAIN_DB_PREFIX.'dolizsynch_zsproduct (fk_product,entity,loja_zs,restricted,produtos_codigosbarras,date_creation,fk_user_creat,fk_user_modif) VALUES ('
                .(int) $product->id.','.(int) $product->entity.','.(int) ($this->conf->global->ZS_API_STORE ?? 0).",1,'".$this->db->escape($json)
                ."','".$this->db->idate(dol_now())."',".(int) $this->user->id.','.(int) $this->user->id.')');
        }
        $payload['barcode_links'] = $previous;
        $payload['managed_barcodes'] = array_values(array_unique(array_intersect($owned, array_column($kept, 'codbarras'))));
        $this->syncRequired = true;
        if (!$stored || !empty($stored[0]->restricted)) {
            $this->syncRequired = false;
            $this->warning = 'KREAPRODUCTS_LABELS_BARCODE_SYNC_DISABLED';
        }
    }

    private function synchronize($product)
    {
        try {
            $fresh = new Product($this->db);
            if ($fresh->fetch($product->id) <= 0 || $fresh->fetch_optionals() < 0) {
                throw new RuntimeException();
            }
            if (empty($fresh->array_options['options_zs_synch'])) {
                $this->warning = 'KREAPRODUCTS_LABELS_BARCODE_SYNC_DISABLED';
                return;
            }
            dol_include_once('/kreaflow/class/zsprodsynch.class.php');
            $sync = new ZSProductSynch($this->db);
            if (!$sync->updateZsbmsProduct($fresh)) {
                throw new RuntimeException();
            }
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.': remote barcode synchronization failed for product '.(int) $product->id, LOG_ERR);
            $this->warning = 'KREAPRODUCTS_LABELS_BARCODE_SYNC_FAILED';
        }
    }
}
