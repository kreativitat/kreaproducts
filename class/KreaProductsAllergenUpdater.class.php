<?php

/**
 * KreaProductsAllergenUpdater.class.php
 * Handles allergen propagation in product hierarchies with BFS and bottom-up processing
 */

class KreaProductsAllergenUpdater
{
    const ALERT_ALLERGEN_ID = 999; // Must exist in your allergens table!

    public static function updateAllergenAttributes($rootProductId, User $user, $forceTraces = 0)
    {
        global $db;

        dol_syslog(__METHOD__ . "::START (root=$rootProductId)", LOG_DEBUG);

        // 1. Build downward map
        $map = self::buildDownwardMap($rootProductId);
        if (empty($map)) {
            dol_syslog("No products found in hierarchy", LOG_WARNING);
            return;
        }

        // 2. Delete existing auto-calculated allergens
        foreach ($map as $productId => $node) {
            if (self::fetchCalcOption($productId) === 1) {
                $sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                        WHERE fk_product = " . (int)$productId;
                if (!$db->query($sql)) {
                    dol_syslog("DELETE FAILED: " . $db->lasterror(), LOG_ERR);
                }
            }
        }

        // 3. Calculate processing order
        $heights = self::computeHeights($map);
        asort($heights);

        // 4. Process from leaves to root
        foreach ($heights as $productId => $height) {
            if (self::fetchCalcOption($productId) === 1) {
                self::rebuildAllergensForNode($productId, $map, $user, $forceTraces);
            }
        }

        dol_syslog(__METHOD__ . "::END (root=$rootProductId)", LOG_DEBUG);
    }

    private static function rebuildAllergensForNode($nodeId, $map, $user, $forceTraces)
    {
        global $db;

        $node = $map[$nodeId] ?? null;
        if (!$node || empty($node->children)) {
            dol_syslog("Leaf node $nodeId - no allergens calculated", LOG_DEBUG);
            return;
        }

        // Aggregate allergens from children
        $aggregated = [];
        $hasManualChildren = false;

        foreach ($node->children as $childId => $qty) {
            $childCalc = self::fetchCalcOption($childId);

            // Fetch child's allergens
            $sql = "SELECT fk_allergen, traces 
                    FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                    WHERE fk_product = " . (int)$childId;
            $res = $db->query($sql);

            if (!$res) {
                dol_syslog("CHILD ALLERGEN FETCH FAILED: " . $db->lasterror(), LOG_ERR);
                continue;
            }

            // Track manual children
            if ($childCalc === 0) $hasManualChildren = true;

            // Merge allergens
            while ($row = $db->fetch_object($res)) {
                $aid = (int)$row->fk_allergen;
                $traces = (int)$row->traces;

                if (!isset($aggregated[$aid])) {
                    $aggregated[$aid] = $traces;
                } else {
                    $aggregated[$aid] = min($aggregated[$aid], $traces);
                }
            }
        }

        // Add manual child alert
        if ($hasManualChildren) {
            $aggregated[self::ALERT_ALLERGEN_ID] = 1;
        }

        // Force traces mode
        if ($forceTraces) {
            $aggregated = array_map(function () {
                return 1;
            }, $aggregated);
        }

        // Update database
        $db->begin();

        // Clear existing
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                WHERE fk_product = " . (int)$nodeId;
        if (!$db->query($sql)) {
            dol_syslog("CLEAR ALLERGENS FAILED: " . $db->lasterror(), LOG_ERR);
            $db->rollback();
            return;
        }

        // Insert new values
        foreach ($aggregated as $allergenId => $traces) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_productallergens
                    (fk_product, fk_allergen, traces, fk_user_creat, date_creation)
                    VALUES (
                        " . (int)$nodeId . ",
                        " . (int)$allergenId . ",
                        " . (int)$traces . ",
                        " . (int)$user->id . ",
                        NOW()
                    )";
            if (!$db->query($sql)) {
                dol_syslog("INSERT ALLERGEN $allergenId FAILED: " . $db->lasterror(), LOG_ERR);
                $db->rollback();
                return;
            }
        }

        $db->commit();
        dol_syslog("Updated $nodeId with " . count($aggregated) . " allergens", LOG_DEBUG);
    }

    private static function buildDownwardMap($rootId)
    {
        global $db;
        $map = [];
        $queue = [$rootId];
        $visited = [$rootId => true];

        while (!empty($queue)) {
            $current = array_shift($queue);

            if (!isset($map[$current])) {
                $map[$current] = new LocalProductAllergen($current);
            }

            // Find children
            $sql = "SELECT fk_product_fils, qty 
                    FROM " . MAIN_DB_PREFIX . "product_association 
                    WHERE fk_product_pere = " . (int)$current;
            $res = $db->query($sql);

            if (!$res) {
                dol_syslog("CHILD FETCH FAILED: " . $db->lasterror(), LOG_ERR);
                continue;
            }

            while ($row = $db->fetch_object($res)) {
                $childId = (int)$row->fk_product_fils;
                $qty = (float)$row->qty;

                if (!isset($map[$childId])) {
                    $map[$childId] = new LocalProductAllergen($childId);
                }

                $map[$current]->children[$childId] = $qty;

                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }

        return $map;
    }

    private static function computeHeights($map)
    {
        $heights = [];

        foreach ($map as $nodeId => $node) {
            self::calculateNodeHeight($nodeId, $map, $heights);
        }

        return $heights;
    }

    private static function calculateNodeHeight($nodeId, $map, &$heights)
    {
        if (isset($heights[$nodeId])) return $heights[$nodeId];

        $node = $map[$nodeId] ?? null;
        if (!$node || empty($node->children)) {
            $heights[$nodeId] = 0;
            return 0;
        }

        $maxHeight = 0;
        foreach ($node->children as $childId => $qty) {
            $maxHeight = max($maxHeight, self::calculateNodeHeight($childId, $map, $heights));
        }

        $heights[$nodeId] = $maxHeight + 1;
        return $heights[$nodeId];
    }

    private static function fetchCalcOption($productId)
    {
        global $db;
        static $cache = [];

        if (isset($cache[$productId])) return $cache[$productId];

        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            dol_syslog("Product $productId not found", LOG_WARNING);
            return 1; // Default to auto
        }

        $product->fetch_optionals();
        $cache[$productId] = (int)($product->array_options['options_kreap_calc_allergens'] ?? 1);

        return $cache[$productId];
    }
}

class LocalProductAllergen
{
    public $id;
    public $children = [];

    public function __construct($id)
    {
        $this->id = (int)$id;
    }
}

// Important Notes:
// 1. Requires allergen with ID 999 to exist in your database
// 2. Verify table names match your schema:
//    - product_association (standard Dolibarr table)
//    - kreaproducts_productallergens (custom table)
// 3. Ensure extrafield 'options_kreap_calc_allergens' exists