<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Luracast\Restler\RestException;

/**
 * Narrow API workflow for saved, manually declared food information.
 *
 * Nutrition/allergen records inherit ownership from their product parent.
 * Native object methods retain validation, triggers and audit metadata.
 */
class KreaProductsFoodSetupService
{
    const VERSION = 1;
    const NUTRIENTS = array('energy_kcal', 'energy_kj', 'fat', 'saturates', 'carbohydrates', 'sugars', 'protein', 'salt', 'fiber');

    private $db;
    private $user;
    private $entityId;
    private $nutritionObjects = array();
    private $allergenObjects = array();

    public function __construct($db, $user, $entityId)
    {
        $this->db = $db;
        $this->user = $user;
        $this->entityId = (int) $entityId;
        if ($this->entityId < 1) {
            throw new RestException(500, 'A selected company is required for food setup');
        }
    }

    /** Read saved declarations; missing nutrition is null, never a zero declaration. */
    public function read($productId, $lock = false)
    {
        $productId = $this->positiveId($productId);
        if (!isModEnabled('kreaproducts')) {
            throw new RestException(503, 'KreaProducts is not enabled');
        }
        if ((!$this->user->hasRight('produit', 'lire') && !$this->user->hasRight('service', 'lire'))
            || !$this->user->hasRight('kreaproducts', 'nutritional', 'read')
            || !$this->user->hasRight('kreaproducts', 'productallergens', 'read')) {
            throw new RestException(403, 'Missing product or food-information read rights');
        }
        $nutritionSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'kreaproducts_nutritional WHERE fk_product = '.$productId.' ORDER BY rowid'.$this->db->plimit(2);
        $allergenSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'kreaproducts_productallergens WHERE fk_product = '.$productId.' ORDER BY rowid'.$this->db->plimit(101);
        $dictionarySql = 'SELECT rowid, code, label, active FROM '.MAIN_DB_PREFIX.'c_kreaproducts ORDER BY rowid'.$this->db->plimit(501);
        if ($lock) {
            // Every current/locking read precedes the first DAO/plain SELECT.
            // Otherwise REPEATABLE READ could retain a snapshot from before a
            // concurrent owner-lock holder committed, defeating the revision.
            $rows = $this->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE rowid = '.$productId.' AND entity = '.$this->entityId.' FOR UPDATE');
            if (count($rows) !== 1) {
                throw new RestException(403, 'Cannot lock the owned parent product');
            }
            $this->rows('SELECT fk_object FROM '.MAIN_DB_PREFIX.'product_extrafields WHERE fk_object = '.$productId.' FOR UPDATE');
            $nutritionRows = $this->rows($nutritionSql.' FOR UPDATE');
            $allergenRows = $this->rows($allergenSql.' FOR UPDATE');
            $dictionary = $this->rows($dictionarySql.' FOR UPDATE');
        }
        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            throw new RestException(404, 'Product not found');
        }
        if (!DolibarrApi::_checkAccessToResource('product', $productId)) {
            throw new RestException(403, 'Product access denied');
        }
        $family = ((int) $product->type === 1 ? 'service' : 'produit');
        if (!$this->user->hasRight($family, 'lire')
            || !$this->user->hasRight('kreaproducts', 'nutritional', 'read')
            || !$this->user->hasRight('kreaproducts', 'productallergens', 'read')) {
            throw new RestException(403, 'Missing product or food-information read rights');
        }
        if ($lock) {
            if ((int) $product->entity !== $this->entityId) {
                throw new RestException(403, 'Food-information writes require the product owning entity');
            }
        }
        if (!$lock) {
            $nutritionRows = $this->rows($nutritionSql);
            $allergenRows = $this->rows($allergenSql);
            $dictionary = $this->rows($dictionarySql);
        }
        if (count($nutritionRows) > 1) {
            throw new RestException(409, 'Multiple saved nutrition declarations require manual review');
        }
        $nutrition = null;
        $this->nutritionObjects[$productId] = null;
        if ($nutritionRows) {
            $object = new Nutritional($this->db);
            if ($object->fetch((int) $nutritionRows[0]->rowid) <= 0 || (int) $object->fk_product !== $productId) {
                throw new RestException(500, 'Cannot read the saved nutrition declaration');
            }
            if (!in_array((string) $object->is_food, array('0', '1'), true)) {
                throw new RestException(409, 'Saved food status requires manual review');
            }
            $nutrition = array('is_food' => (int) $object->is_food);
            foreach (self::NUTRIENTS as $field) {
                $nutrition[$field] = self::decimal($object->$field);
            }
            $this->nutritionObjects[$productId] = $object;
        }
        if (count($allergenRows) > 100) {
            throw new RestException(409, 'Too many saved allergen declarations require manual review');
        }
        $allergens = array();
        $this->allergenObjects[$productId] = array();
        foreach ($allergenRows as $row) {
            $object = new ProductAllergens($this->db);
            if ($object->fetch((int) $row->rowid) <= 0 || (int) $object->fk_product !== $productId) {
                throw new RestException(500, 'Cannot read a saved allergen declaration');
            }
            $allergenId = $this->positiveId($object->fk_allergen);
            if (isset($this->allergenObjects[$productId][$allergenId]) || !in_array((string) $object->traces, array('0', '1'), true)) {
                throw new RestException(409, 'Duplicate or malformed allergen declarations require manual review');
            }
            $this->allergenObjects[$productId][$allergenId] = $object;
            $allergens[$allergenId] = array('allergen_id' => $allergenId, 'declaration' => ((int) $object->traces === 1 ? 'traces' : 'contains'));
        }
        ksort($allergens, SORT_NUMERIC);
        if (count($dictionary) > 500) {
            throw new RestException(409, 'Allergen dictionary exceeds the supported bound');
        }
        $available = array();
        foreach ($dictionary as $entry) {
            $available[] = array('allergen_id' => (int) $entry->rowid, 'code' => (string) $entry->code,
                'label' => (string) $entry->label, 'active' => (int) $entry->active);
        }
        $state = array('version' => self::VERSION, 'product_id' => $productId, 'entity' => (int) $product->entity,
            'nutrition' => $nutrition, 'allergens' => array_values($allergens),
            'manual_edit_allowed' => KreaProductsNutritionEditPolicy::canEdit($this->db, $productId));
        $state['revision'] = self::revision($state);
        $state['available_allergens'] = $available;
        return $state;
    }

    /** Validate a patch without writing or inventing missing declarations. */
    public function plan($before, $request)
    {
        if (!is_array($request) || array_diff(array_keys($request), array('version', 'nutrition', 'allergens', 'entity_id', 'confirm', 'dry_run', 'expected_revision'))) {
            throw new RestException(400, 'Unknown food-setup fields');
        }
        if (array_key_exists('version', $request) && $request['version'] !== self::VERSION) {
            throw new RestException(400, 'Unsupported food-setup version');
        }
        if (!isset($request['entity_id']) || !is_int($request['entity_id']) || $request['entity_id'] !== $this->entityId
            || (int) $before['entity'] !== $this->entityId) {
            throw new RestException(403, 'Explicit entity_id must match the selected owning entity');
        }
        if (!isset($request['confirm']) || $request['confirm'] !== true) {
            throw new RestException(400, 'confirm=true is required');
        }
        if (array_key_exists('dry_run', $request) && !is_bool($request['dry_run'])) {
            throw new RestException(400, 'dry_run must be a Boolean');
        }
        if (empty($before['manual_edit_allowed'])) {
            throw new RestException(403, 'Manual nutrition/allergen mode is required');
        }
        $dryRun = !isset($request['dry_run']) || $request['dry_run'];
        if (array_key_exists('expected_revision', $request)) {
            if (!is_string($request['expected_revision']) || !preg_match('/\A[a-f0-9]{64}\z/', $request['expected_revision'])) {
                throw new RestException(400, 'expected_revision must be a saved revision hash');
            }
        } elseif (!$dryRun) {
            throw new RestException(400, 'expected_revision from a fresh GET is required when applying');
        }
        $after = $before;
        $changedFields = 0;
        if (array_key_exists('nutrition', $request)) {
            if (!is_array($request['nutrition']) || !$request['nutrition'] || array_diff(array_keys($request['nutrition']), self::NUTRIENTS)) {
                throw new RestException(400, 'nutrition must contain supported per-100-g nutrient fields');
            }
            if (!$this->user->hasRight('kreaproducts', 'nutritional', 'write')) {
                throw new RestException(403, 'Missing nutrition write right');
            }
            $nutrition = $before['nutrition'];
            if ($nutrition === null) {
                $nutrition = array_merge(array('is_food' => 1), array_fill_keys(self::NUTRIENTS, null));
            }
            foreach ($request['nutrition'] as $field => $value) {
                $nutrition[$field] = self::decimal($value);
            }
            if ($before['nutrition'] === null && count(array_filter($nutrition, function ($value) { return $value !== null; })) === 1) {
                throw new RestException(400, 'A missing nutrition declaration needs at least one explicit numeric value');
            }
            self::assertEnergyConsistent($nutrition);
            $after['nutrition'] = $nutrition;
            $changedFields++;
        }
        if (array_key_exists('allergens', $request)) {
            if (!is_array($request['allergens']) || count($request['allergens']) < 1 || count($request['allergens']) > 100
                || array_keys($request['allergens']) !== range(0, count($request['allergens']) - 1)) {
                throw new RestException(400, 'allergens must be an array of 1 to 100 explicit declarations');
            }
            if (!$this->user->hasRight('kreaproducts', 'productallergens', 'write')) {
                throw new RestException(403, 'Missing allergen write right');
            }
            $indexed = array();
            foreach ($after['allergens'] as $entry) {
                $indexed[$entry['allergen_id']] = $entry;
            }
            $dictionary = array();
            foreach ($before['available_allergens'] as $entry) {
                $dictionary[$entry['allergen_id']] = $entry;
            }
            $seen = array();
            foreach ($request['allergens'] as $entry) {
                if (!is_array($entry) || count($entry) !== 2 || array_diff(array_keys($entry), array('allergen_id', 'declaration'))) {
                    throw new RestException(400, 'Each allergen patch requires only allergen_id and declaration');
                }
                if (!is_int($entry['allergen_id'])) {
                    throw new RestException(400, 'allergen_id must be a positive integer');
                }
                $id = $this->positiveId($entry['allergen_id']);
                if (isset($seen[$id]) || !in_array($entry['declaration'], array('contains', 'traces', 'absent'), true)) {
                    throw new RestException(400, 'Duplicate allergen ID or unsupported declaration');
                }
                $seen[$id] = true;
                if (!isset($dictionary[$id]) || ($entry['declaration'] !== 'absent' && $dictionary[$id]['active'] !== 1)) {
                    throw new RestException(400, 'Allergen is missing or inactive in the native dictionary');
                }
                if ($entry['declaration'] === 'absent') {
                    if (isset($indexed[$id]) && !$this->user->hasRight('kreaproducts', 'productallergens', 'delete')) {
                        throw new RestException(403, 'Missing allergen delete right for the explicit removal');
                    }
                    unset($indexed[$id]);
                } else {
                    $indexed[$id] = array('allergen_id' => $id, 'declaration' => $entry['declaration']);
                }
            }
            ksort($indexed, SORT_NUMERIC);
            $after['allergens'] = array_values($indexed);
            $changedFields++;
        }
        if (!$changedFields) {
            throw new RestException(400, 'Supply nutrition and/or explicit allergen changes');
        }
        $after['revision'] = self::revision($after);
        if (isset($request['expected_revision']) && !hash_equals($before['revision'], $request['expected_revision'])
            && $after['revision'] !== $before['revision']) {
            throw new RestException(409, 'Food setup changed; read and review the new state');
        }
        return $after;
    }

    /** Serialize API writes under their parent lock and commit verified state atomically. */
    public function save($productId, $request)
    {
        $dryRun = !is_array($request) || !array_key_exists('dry_run', $request) || $request['dry_run'] !== false;
        if ($dryRun) {
            $before = $this->read($productId);
            $after = $this->plan($before, $request);
            return $this->result($before, $after, true);
        }
        if ($this->db->begin() <= 0) {
            throw new RestException(500, 'Cannot start the food-setup transaction');
        }
        try {
            $before = $this->read($productId, true);
            $after = $this->plan($before, $request);
            if ($before['revision'] !== $after['revision']) {
                if (array_key_exists('nutrition', $request) && $before['nutrition'] !== $after['nutrition']) {
                    $object = $this->nutritionObjects[(int) $productId];
                    $creating = $object === null;
                    if ($creating) {
                        $object = new Nutritional($this->db);
                        $object->fk_product = (int) $productId;
                        $object->is_food = 1;
                    }
                    foreach (self::NUTRIENTS as $field) {
                        $object->$field = $after['nutrition'][$field];
                    }
                    $saved = $creating ? $object->create($this->user) : $object->update($this->user);
                    if ($saved <= 0) {
                        throw new RestException(500, 'Native nutrition persistence failed; transaction rolled back');
                    }
                }
                foreach (isset($request['allergens']) ? $request['allergens'] : array() as $entry) {
                    $id = $entry['allergen_id'];
                    $object = isset($this->allergenObjects[(int) $productId][$id]) ? $this->allergenObjects[(int) $productId][$id] : null;
                    if ($entry['declaration'] === 'absent') {
                        $saved = $object === null ? 1 : $object->delete($this->user);
                    } else {
                        $traces = ($entry['declaration'] === 'traces' ? 1 : 0);
                        if ($object !== null && (int) $object->traces === $traces) {
                            continue;
                        }
                        $creating = $object === null;
                        if ($creating) {
                            $object = new ProductAllergens($this->db);
                            $object->fk_product = (int) $productId;
                            $object->fk_allergen = $id;
                        }
                        $object->traces = $traces;
                        $saved = $creating ? $object->create($this->user) : $object->update($this->user);
                    }
                    if ($saved <= 0) {
                        throw new RestException(500, 'Native allergen persistence failed; transaction rolled back');
                    }
                }
                $observed = $this->read($productId);
                if (!hash_equals($after['revision'], $observed['revision'])) {
                    throw new RestException(409, 'Food-setup readback differs; transaction rolled back');
                }
                $after = $observed;
            }
            if ($this->db->commit() <= 0) {
                throw new RestException(500, 'Food-setup commit could not be confirmed; inspect before retrying');
            }
            return $this->result($before, $after, false);
        } catch (Throwable $exception) {
            $this->db->rollback();
            if ($exception instanceof RestException) {
                throw $exception;
            }
            dol_syslog(__METHOD__.' failed; transaction rolled back', LOG_ERR);
            throw new RestException(500, 'Food-setup transaction failed; inspect before retrying');
        }
    }

    /** Canonical decimal text uses the native 4-place range; null remains missing. */
    public static function decimal($value)
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || !is_scalar($value) || !preg_match('/\A[0-9]+(?:\.[0-9]{1,4})?\z/', (string) $value)) {
            throw new RestException(400, 'Nutrients must be non-negative decimals with at most four fractional places');
        }
        $parts = explode('.', (string) $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = ($whole === '' ? '0' : $whole);
        if (strlen($whole) > 6) {
            throw new RestException(400, 'Nutrient exceeds the native 999999.9999 maximum');
        }
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        return $whole.($fraction !== '' ? '.'.$fraction : '');
    }

    private static function assertEnergyConsistent($nutrition)
    {
        if ($nutrition['energy_kcal'] !== null && $nutrition['energy_kj'] !== null) {
            $expected = ((float) $nutrition['energy_kcal']) * 4.184;
            if (abs(((float) $nutrition['energy_kj']) - $expected) > $expected * 0.1) {
                throw new RestException(400, 'Saved/planned kcal and kJ differ beyond the native 10% tolerance');
            }
        }
    }

    private static function revision($state)
    {
        $data = array();
        foreach (array('version', 'product_id', 'entity', 'nutrition', 'allergens', 'manual_edit_allowed') as $key) {
            $data[$key] = $state[$key];
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RestException(500, 'Cannot encode saved food information');
        }
        return hash('sha256', $encoded);
    }

    private function result($before, $after, $dryRun)
    {
        return array('version' => self::VERSION, 'product_id' => $before['product_id'], 'entity' => $before['entity'],
            'status' => ($before['revision'] === $after['revision'] ? 'unchanged' : ($dryRun ? 'dry_run' : 'confirmed')),
            'dry_run' => $dryRun, 'before' => $before, 'after' => $after);
    }

    private function positiveId($value)
    {
        if (is_bool($value) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)
            || strlen((string) $value) > strlen((string) PHP_INT_MAX)
            || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) {
            throw new RestException(400, 'A positive object ID is required');
        }
        return (int) $value;
    }

    private function rows($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            throw new RestException(500, 'Food-information database lookup failed');
        }
        $rows = array();
        while ($row = $this->db->fetch_object($result)) {
            $rows[] = $row;
        }
        $this->db->free($result);
        return $rows;
    }
}
