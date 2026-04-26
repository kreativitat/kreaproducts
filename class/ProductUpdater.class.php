<?php
/*
 * Copyright (C) 2024-2026       Kreativität Works       <mail@kreativitat.com>
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Cost Price Updater - Standalone Class
 *
 * Extracted from ProductMixer Dolibarr module to handle product cost price updates
 * Independent implementation using native Dolibarr framework
 *
 * ENHANCED WITH BOM SUPPORT:
 * - Supports both product associations (llx_product_association) and BOM compositions (llx_bom_bom/llx_bom_bomline)
 * - When BOM module is enabled, automatically includes BOM-based parent-child relationships
 * - BOM quantities take precedence over association quantities when both exist
 * - Supports Manufacturing BOMs (bomtype = 0) that are validated (status = 1)
 * - Cost/buy price calculations work with any combination of associations and BOMs
 * - Buy price updates are controlled by the product extrafield kreap_updatebuyprice (fallback to kreap_syncprice for legacy installs)
 * - Debug output shows the source of each relationship (association, bom, or both)
 */
class ProductUpdater
{
    /**
     * @var array Product hierarchy map
     */
    private static $productMap = [];

    /**
     * @var array Cached cost calculations per product id
     */
    private static $costCache = [];

    /**
     * @var bool Map loaded flag
     */
    private static $mapLoaded = false;

    /**
     * @var bool Debug mode
     */
    private static $debug = false;

    /**
     * @var array Cached sync flags per product id
     */
    private static $syncFlagsCache = [];

    /**
     * Set debug mode
     *
     * @param bool $debug
     */
    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    /**
     * Debug log function
     *
     * @param string $message
     */
    private static function debug(string $message): void
    {
        if (self::$debug) {
            error_log("[ProductUpdater] " . $message);
            dol_syslog("[ProductUpdater] " . $message, LOG_DEBUG);
        }
    }

    /**
     * Update cost price for a single product and all its parents (matches original ProductMixer behavior)
     * This mimics the exact flow from ProductMixer::updateProductAttributes()
     *
     * @param int $productId Product ID that was modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array Results array
     */
    public static function updateProductCostPrice(int $productId, bool $useWholeSalePriceSync = true): array
    {
        self::debug("Starting cost price update for product ID: " . $productId);

        if ($productId <= 0) {
            self::debug("Invalid product ID: " . $productId);
            return [];
        }

        return self::batchUpdateCostPrices([$productId], $useWholeSalePriceSync);
    }

    /**
     * Update cost prices for multiple products with one hierarchy load.
     *
     * @param array<int, int> $productIds Product IDs that changed
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array<int, array{updated: bool, ref: string, is_original: bool}>
     */
    public static function batchUpdateCostPrices(array $productIds, bool $useWholeSalePriceSync = true): array
    {
        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return [];
        }

        self::debug("Starting batch cost price update for product IDs: " . implode(',', $productIds));

        self::resetMap();
        self::loadImpactedProductMap($productIds);

        if (empty(self::$productMap)) {
            self::debug("Product map is empty - no product associations found");
            return [];
        }

        $processingOrder = self::createProcessingOrder($productIds);
        self::preloadSyncFlagsForProductIds($processingOrder);

        $results = [];
        $originalProductIds = array_fill_keys($productIds, true);

        foreach ($processingOrder as $currentProductId) {
            $mapProduct = self::getProductFromMap($currentProductId);

            // Skip products that don't exist in map or don't have children
            // (matches original logic: only virtual products with children get updated)
            if (!$mapProduct || !self::hasChildren($currentProductId)) {
                continue;
            }

            self::debug("Processing product ID: " . $currentProductId . " (ref: " . $mapProduct['ref'] . ")");

            // Update cost price if sync is enabled via product extrafield (kreap_updatebuyprice/kreap_syncprice)
            if (self::isCostPriceSyncEnabled($currentProductId) && $useWholeSalePriceSync) {
                $updated = self::updateCostPriceFromChildren($currentProductId, null);
                $results[$currentProductId] = [
                    'updated' => $updated,
                    'ref' => $mapProduct['ref'] ?? 'Unknown',
                    'is_original' => !empty($originalProductIds[$currentProductId])
                ];

                if ($updated) {
                    self::debug("Cost price updated for product: " . $mapProduct['ref'] .
                              (!empty($originalProductIds[$currentProductId]) ? " (this is an original modified product)" : " (parent product)"));
                }
            }
        }

        return $results;
    }

    /**
     * Load product hierarchy map from database
     */
    private static function loadProductMap(): void
    {
        global $db;

        if (self::$mapLoaded) {
            return;
        }

        // Validate database connection
        if (!$db) {
            self::debug("Error: Database connection not available");
            return;
        }

        self::debug("Loading product map from database");

        // First load product associations
        self::loadProductAssociations();

        // Then load BOM-based relationships (if BOM module is enabled)
        self::loadBOMRelationships();

        self::$mapLoaded = true;
        self::debug("Product map loaded with " . count(self::$productMap) . " products");
    }

    /**
     * Load product associations from llx_product_association table
     */
    private static function loadProductAssociations(?array $parentIds = null, ?array $childIds = null): void
    {
        global $db;

        self::debug("Loading product associations");

        $parentIds = ($parentIds === null ? null : self::normalizeProductIds($parentIds));
        $childIds = ($childIds === null ? null : self::normalizeProductIds($childIds));

        // Query to get product associations (no sync flags stored here)
        $sql = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child, pa.qty as qty, ";
        // Parent product info
        $sql .= "p.label as p_label, p.ref as p_ref, p.cost_price as p_cost_price, ";
        // Child product info
        $sql .= "f.label as f_label, f.ref as f_ref, f.cost_price as f_cost_price ";
        $sql .= "FROM ".MAIN_DB_PREFIX."product_association as pa, ";
        $sql .= MAIN_DB_PREFIX."product as p, ";
        $sql .= MAIN_DB_PREFIX."product as f ";
        $sql .= "WHERE p.rowid = pa.fk_product_pere AND f.rowid = pa.fk_product_fils";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " AND f.entity IN (".getEntity('product').")";
        if ($parentIds !== null) {
            if (empty($parentIds)) {
                return;
            }
            $sql .= " AND pa.fk_product_pere IN (" . implode(',', $parentIds) . ")";
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return;
            }
            $sql .= " AND pa.fk_product_fils IN (" . implode(',', $childIds) . ")";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            self::debug("Error loading product associations: " . $db->lasterror());
            return;
        }

        // Initialize product map if not already done
        if (empty(self::$productMap)) {
            self::$productMap = [];
        }

        $associationCount = 0;
        while ($obj = $db->fetch_object($resql)) {
            // Add parent to map
            if (!isset(self::$productMap[$obj->parent])) {
                self::$productMap[$obj->parent] = [
                    'id' => $obj->parent,
                    'ref' => $obj->p_ref,
                    'label' => $obj->p_label,
                    'cost_price' => $obj->p_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to map
            if (!isset(self::$productMap[$obj->child])) {
                self::$productMap[$obj->child] = [
                    'id' => $obj->child,
                    'ref' => $obj->f_ref,
                    'label' => $obj->f_label,
                    'cost_price' => $obj->f_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to parent's children (with source info)
            self::$productMap[$obj->parent]['children'][$obj->child] = [
                'id' => $obj->child,
                'qty' => $obj->qty,
                'source' => 'association'
            ];

            // Add parent to child's parents
            self::$productMap[$obj->child]['parents'][$obj->parent] = [
                'id' => $obj->parent,
                'source' => 'association'
            ];

            $associationCount++;
        }

        $db->free($resql);
        self::debug("Loaded " . $associationCount . " product associations");
    }

    /**
     * Load BOM-based relationships from bom_bom and bom_bomline tables
     */
    private static function loadBOMRelationships(?array $parentIds = null, ?array $childIds = null): void
    {
        global $db, $conf;

        // Only load BOM data if BOM module is enabled
        if (empty($conf->bom->enabled)) {
            self::debug("BOM module not enabled, skipping BOM relationships");
            return;
        }

        self::debug("Loading BOM relationships");

        $parentIds = ($parentIds === null ? null : self::normalizeProductIds($parentIds));
        $childIds = ($childIds === null ? null : self::normalizeProductIds($childIds));

        // Query to get BOM relationships (manufacturing type only)
        $sql = "SELECT b.fk_product as parent, COALESCE(bl.fk_product, cb.fk_product) as child, bl.qty as qty, ";
        // Parent product info
        $sql .= "p.label as p_label, p.ref as p_ref, p.cost_price as p_cost_price, ";
        // Child product info
        $sql .= "COALESCE(f.label, cprod.label) as f_label, COALESCE(f.ref, cprod.ref) as f_ref, COALESCE(f.cost_price, cprod.cost_price) as f_cost_price, ";
        // BOM info
        $sql .= "b.rowid as bom_id, b.ref as bom_ref ";
        $sql .= "FROM ".MAIN_DB_PREFIX."bom_bom as b ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."bom_bomline as bl ON b.rowid = bl.fk_bom ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = b.fk_product ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."product as f ON f.rowid = bl.fk_product ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."bom_bom as cb ON cb.rowid = bl.fk_bom_child ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."product as cprod ON cprod.rowid = cb.fk_product ";
        $sql .= "WHERE b.bomtype IN (0,1) AND b.status = 1"; // Include manufacturing and dismantle BOMs
        $sql .= " AND b.entity IN (0,".getEntity('bom').")";
        $sql .= " AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (";
        $sql .= "SELECT 1 FROM ".MAIN_DB_PREFIX."bom_bom b2";
        $sql .= " WHERE b2.fk_product = b.fk_product";
        $sql .= " AND b2.entity = " . ((int) $conf->entity);
        $sql .= " AND b2.bomtype = b.bomtype AND b2.status = 1";
        $sql .= ")))";
        $sql .= " AND (cb.rowid IS NULL OR cb.entity IN (0,".getEntity('bom')."))";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " AND (f.rowid IS NULL OR f.entity IN (".getEntity('product')."))";
        $sql .= " AND (cprod.rowid IS NULL OR cprod.entity IN (".getEntity('product')."))";
        $sql .= " AND COALESCE(bl.fk_product, cb.fk_product) IS NOT NULL";
        if ($parentIds !== null) {
            if (empty($parentIds)) {
                return;
            }
            $sql .= " AND b.fk_product IN (" . implode(',', $parentIds) . ")";
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return;
            }
            $sql .= " AND (bl.fk_product IN (" . implode(',', $childIds) . ") OR cb.fk_product IN (" . implode(',', $childIds) . "))";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            self::debug("Error loading BOM relationships: " . $db->lasterror());
            return;
        }

        $bomCount = 0;
        while ($obj = $db->fetch_object($resql)) {
            // Add parent to map if not exists
            if (!isset(self::$productMap[$obj->parent])) {
                self::$productMap[$obj->parent] = [
                    'id' => $obj->parent,
                    'ref' => $obj->p_ref,
                    'label' => $obj->p_label,
                    'cost_price' => $obj->p_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to map if not exists
            if (!isset(self::$productMap[$obj->child])) {
                self::$productMap[$obj->child] = [
                    'id' => $obj->child,
                    'ref' => $obj->f_ref,
                    'label' => $obj->f_label,
                    'cost_price' => $obj->f_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to parent's children (BOM-based relationship)
            $childKey = $obj->child;
            if (!isset(self::$productMap[$obj->parent]['children'][$childKey])) {
                self::$productMap[$obj->parent]['children'][$childKey] = [
                    'id' => $obj->child,
                    'qty' => $obj->qty,
                    'source' => 'bom',
                    'bom_id' => $obj->bom_id,
                    'bom_ref' => $obj->bom_ref
                ];
            } else {
                // If relationship already exists from associations, mark it as having BOM too
                self::$productMap[$obj->parent]['children'][$childKey]['source'] = 'both';
                self::$productMap[$obj->parent]['children'][$childKey]['bom_id'] = $obj->bom_id;
                self::$productMap[$obj->parent]['children'][$childKey]['bom_ref'] = $obj->bom_ref;
                // Use BOM quantity as it's likely more accurate
                self::$productMap[$obj->parent]['children'][$childKey]['qty'] = $obj->qty;
            }

            // Add parent to child's parents
            $parentKey = $obj->parent;
            if (!isset(self::$productMap[$obj->child]['parents'][$parentKey])) {
                self::$productMap[$obj->child]['parents'][$parentKey] = [
                    'id' => $obj->parent,
                    'source' => 'bom',
                    'bom_id' => $obj->bom_id,
                    'bom_ref' => $obj->bom_ref
                ];
            } else {
                // Mark existing relationship as having BOM too
                self::$productMap[$obj->child]['parents'][$parentKey]['source'] = 'both';
                self::$productMap[$obj->child]['parents'][$parentKey]['bom_id'] = $obj->bom_id;
                self::$productMap[$obj->child]['parents'][$parentKey]['bom_ref'] = $obj->bom_ref;
            }

            $bomCount++;
        }

        $db->free($resql);
        self::debug("Loaded " . $bomCount . " BOM relationships");
    }

    /**
     * Load only the hierarchy needed to recalculate changed products and their parents.
     *
     * @param array<int, int> $productIds
     */
    private static function loadImpactedProductMap(array $productIds): void
    {
        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return;
        }

        self::debug("Loading impacted product map for product IDs: " . implode(',', $productIds));

        $candidateIds = array_fill_keys($productIds, true);

        $frontier = $productIds;
        $visitedChildLookups = [];
        while (!empty($frontier)) {
            $lookupIds = [];
            foreach ($frontier as $productId) {
                $productId = (int) $productId;
                if ($productId > 0 && empty($visitedChildLookups[$productId])) {
                    $visitedChildLookups[$productId] = true;
                    $lookupIds[] = $productId;
                }
            }
            if (empty($lookupIds)) {
                break;
            }

            self::loadProductAssociations(null, $lookupIds);
            self::loadBOMRelationships(null, $lookupIds);

            $frontier = [];
            foreach ($lookupIds as $childId) {
                $child = self::getProductFromMap((int) $childId);
                if (!$child || empty($child['parents'])) {
                    continue;
                }
                foreach ($child['parents'] as $parent) {
                    $parentId = (int) $parent['id'];
                    if ($parentId > 0 && empty($candidateIds[$parentId])) {
                        $candidateIds[$parentId] = true;
                        $frontier[] = $parentId;
                    }
                }
            }
        }

        $frontier = array_map('intval', array_keys($candidateIds));
        $visitedParentLookups = [];
        while (!empty($frontier)) {
            $lookupIds = [];
            foreach ($frontier as $productId) {
                $productId = (int) $productId;
                if ($productId > 0 && empty($visitedParentLookups[$productId])) {
                    $visitedParentLookups[$productId] = true;
                    $lookupIds[] = $productId;
                }
            }
            if (empty($lookupIds)) {
                break;
            }

            self::loadProductAssociations($lookupIds, null);
            self::loadBOMRelationships($lookupIds, null);

            $frontier = [];
            foreach ($lookupIds as $parentId) {
                foreach (self::getChildren((int) $parentId) as $child) {
                    $childId = (int) $child['id'];
                    if ($childId > 0 && empty($visitedParentLookups[$childId])) {
                        $frontier[] = $childId;
                    }
                }
            }
        }

        self::$mapLoaded = true;
        self::debug("Impacted product map loaded with " . count(self::$productMap) . " products");
    }

    /**
     * Reset product map
     */
    private static function resetMap(): void
    {
        self::$productMap = [];
        self::$mapLoaded = false;
        self::$costCache = [];
        self::$syncFlagsCache = [];
    }

    /**
     * Get product from map
     *
     * @param int $productId
     * @return array|null
     */
    private static function getProductFromMap(int $productId): ?array
    {
        return self::$productMap[$productId] ?? null;
    }

    /**
     * Check if product has children
     *
     * @param int $productId
     * @return bool
     */
    private static function hasChildren(int $productId): bool
    {
        $product = self::getProductFromMap($productId);
        return $product && !empty($product['children']);
    }

    /**
     * Get product children
     *
     * @param int $productId
     * @return array
     */
    private static function getChildren(int $productId): array
    {
        $product = self::getProductFromMap($productId);
        return $product['children'] ?? [];
    }

    /**
     * Check if cost price sync is enabled for product
     * Uses product extrafield kreap_updatebuyprice (with legacy kreap_syncprice fallback)
     *
     * @param int $productId
     * @return bool
     */
    private static function isCostPriceSyncEnabled(int $productId): bool
    {
        global $db;

        if (isset(self::$syncFlagsCache[$productId])) {
            return self::$syncFlagsCache[$productId];
        }

        // Load product and its extrafields
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            self::$syncFlagsCache[$productId] = false;
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);
        $product->fetch_optionals($productId, $extrafields);

        // Primary flag: kreap_updatebuyprice
        $syncFields = ['options_kreap_updatebuyprice', 'options_kreap_syncprice'];
        foreach ($syncFields as $fieldName) {
            if (!empty($product->array_options[$fieldName])) {
                self::$syncFlagsCache[$productId] = true;
                return true;
            }
        }

        self::$syncFlagsCache[$productId] = false;
        return false;
    }

    /**
     * Normalize and deduplicate product ids.
     *
     * @param array<int, mixed> $productIds
     * @return array<int, int>
     */
    private static function normalizeProductIds(array $productIds): array
    {
        $normalized = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0) {
                $normalized[$productId] = $productId;
            }
        }

        return array_values($normalized);
    }

    /**
     * Preload per-product cost sync flags for the products that may be updated.
     *
     * @param array<int, int> $productIds
     */
    private static function preloadSyncFlagsForProductIds(array $productIds): void
    {
        global $db;

        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return;
        }

        foreach ($productIds as $productId) {
            self::$syncFlagsCache[$productId] = false;
        }

        $hasUpdateBuyPrice = self::productExtrafieldColumnExists('kreap_updatebuyprice');
        $hasLegacySyncPrice = self::productExtrafieldColumnExists('kreap_syncprice');
        if (!$hasUpdateBuyPrice && !$hasLegacySyncPrice) {
            return;
        }

        $select = ["fk_object"];
        if ($hasUpdateBuyPrice) {
            $select[] = "kreap_updatebuyprice";
        }
        if ($hasLegacySyncPrice) {
            $select[] = "kreap_syncprice";
        }

        $sql = "SELECT " . implode(', ', $select);
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_extrafields";
        $sql .= " WHERE fk_object IN (" . implode(',', $productIds) . ")";

        $resql = $db->query($sql);
        if (!$resql) {
            self::debug("Error preloading cost sync flags: " . $db->lasterror());
            return;
        }

        while ($obj = $db->fetch_object($resql)) {
            $productId = (int) $obj->fk_object;
            self::$syncFlagsCache[$productId] = (
                ($hasUpdateBuyPrice && !empty($obj->kreap_updatebuyprice))
                || ($hasLegacySyncPrice && !empty($obj->kreap_syncprice))
            );
        }

        $db->free($resql);
    }

    private static function productExtrafieldColumnExists(string $columnName): bool
    {
        global $db;

        static $cache = [];

        if (array_key_exists($columnName, $cache)) {
            return $cache[$columnName];
        }

        $exists = false;
        $resql = $db->DDLDescTable(MAIN_DB_PREFIX . 'product_extrafields', $columnName);
        if ($resql) {
            $exists = ($db->num_rows($resql) > 0);
            $db->free($resql);
        }

        $cache[$columnName] = $exists;
        return $exists;
    }

    /**
     * Build a deterministic bottom-up processing order for changed products and their parents.
     *
     * @param array<int, int> $productIds
     * @return array<int, int>
     */
    private static function createProcessingOrder(array $productIds): array
    {
        $candidates = [];
        foreach ($productIds as $productId) {
            self::collectProductAndParents((int) $productId, $candidates);
        }

        $depthCache = [];
        $order = array_keys($candidates);
        usort($order, function ($left, $right) use ($candidates, &$depthCache) {
            $leftDepth = self::calculateCandidateDepth((int) $left, $candidates, $depthCache);
            $rightDepth = self::calculateCandidateDepth((int) $right, $candidates, $depthCache);
            if ($leftDepth === $rightDepth) {
                return ((int) $left <=> (int) $right);
            }

            return ($leftDepth <=> $rightDepth);
        });

        self::debug("Processing order created with " . count($order) . " products");
        return array_map('intval', $order);
    }

    /**
     * @param array<int, bool> $candidates
     */
    private static function collectProductAndParents(int $productId, array &$candidates): void
    {
        if ($productId <= 0 || !empty($candidates[$productId])) {
            return;
        }

        $candidates[$productId] = true;
        $product = self::getProductFromMap($productId);
        if (!$product || empty($product['parents'])) {
            return;
        }

        foreach ($product['parents'] as $parent) {
            self::collectProductAndParents((int) $parent['id'], $candidates);
        }
    }

    /**
     * @param array<int, bool> $candidates
     * @param array<int, int> $depthCache
     * @param array<int, bool> $path
     */
    private static function calculateCandidateDepth(int $productId, array $candidates, array &$depthCache, array $path = []): int
    {
        if (isset($depthCache[$productId])) {
            return $depthCache[$productId];
        }
        if (!empty($path[$productId])) {
            self::debug("Cycle detected while ordering product hierarchy at product ID: " . $productId);
            return 0;
        }

        $path[$productId] = true;
        $maxChildDepth = -1;
        foreach (self::getChildren($productId) as $child) {
            $childId = (int) $child['id'];
            if (empty($candidates[$childId])) {
                continue;
            }
            $maxChildDepth = max($maxChildDepth, self::calculateCandidateDepth($childId, $candidates, $depthCache, $path));
        }

        $depthCache[$productId] = ($maxChildDepth < 0 ? 0 : $maxChildDepth + 1);
        return $depthCache[$productId];
    }

    /**
     * Update cost price from children calculation
     * This matches the original updateBuyprice() method behavior
     *
     * @param int $productId Product to update
     * @param int $originalProductId The original product that was modified (for warning messages)
     * @return bool True if updated, false otherwise
     */
    private static function updateCostPriceFromChildren(int $productId, ?int $originalProductId = null): bool
    {
        global $db, $user;

        // Load product from database
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            self::debug("Failed to load product: " . $productId);
            return false;
        }

        // Calculate new cost price from children
        $newCostPrice = self::calculateCostPriceFromChildren($productId);

        // Compare with current cost price (tolerance of 0.001 - matches original)
        if (abs($product->cost_price - $newCostPrice) < 0.001) {
            self::debug("No cost price change needed for product " . $product->ref .
                       " (current: " . $product->cost_price . ", calculated: " . $newCostPrice . ")");
            return false;
        }

        $oldCostPrice = $product->cost_price;

        // Update product cost price (matches original method)
        $product->cost_price = $newCostPrice;
        $result = $product->update($productId, $user);

        if ($result > 0) {
            self::debug("Updated cost price for product " . $product->ref . " from " .
                       $oldCostPrice . " to " . $newCostPrice);

            // If this is the original product that was modified, show a warning
            // (matches original behavior: line 215-217 in MapProduct.php)
            if ($productId == $originalProductId) {
                self::debug("WARNING: Cost price for " . $product->ref .
                           " was recalculated from children and overrode your manual entry!");
            }

            return true;
        }

        self::debug("Failed to update product " . $product->ref . " - update() returned: " . $result);
        return false;
    }

    /**
     * Calculate cost price from children
     *
     * @param int $productId
     * @return float
     */
    private static function calculateCostPriceFromChildren(int $productId, array $path = []): float
    {
        if (isset(self::$costCache[$productId])) {
            return self::$costCache[$productId];
        }
        if (in_array($productId, $path, true)) {
            self::debug("Cycle detected in product hierarchy at product ID: " . $productId);
            return 0.0;
        }
        $path[] = $productId;

        $children = self::getChildren($productId);
        if (empty($children)) {
            return 0.0;
        }

        $totalCostPrice = 0.0;
        $parentProduct = self::getProductFromMap($productId);
        $parentRef = $parentProduct ? $parentProduct['ref'] : $productId;

        self::debug("Calculating cost for {$parentRef} with " . count($children) . " children");

        foreach ($children as $child) {
            $childProduct = self::getProductFromMap($child['id']);
            if (!$childProduct) {
                continue;
            }

            $childCostPrice = 0.0;
            $source = isset($child['source']) ? $child['source'] : 'unknown';
            $bomInfo = '';

            if ($source === 'bom' || $source === 'both') {
                $bomInfo = " (BOM: " . (isset($child['bom_ref']) ? $child['bom_ref'] : $child['bom_id']) . ")";
            }

            // If child has its own children, calculate recursively
            if (!empty($childProduct['children'])) {
                $childCostPrice = self::calculateCostPriceFromChildren($child['id'], $path);
                self::debug("  Child {$childProduct['ref']}: calculated cost {$childCostPrice} * qty {$child['qty']} = " .
                          ($childCostPrice * $child['qty']) . " [source: {$source}]{$bomInfo}");
            } else {
                // Use child's current cost price
                $childCostPrice = (float)$childProduct['cost_price'];
                self::debug("  Child {$childProduct['ref']}: direct cost {$childCostPrice} * qty {$child['qty']} = " .
                          ($childCostPrice * $child['qty']) . " [source: {$source}]{$bomInfo}");
            }

            $totalCostPrice += $childCostPrice * $child['qty'];
        }

        self::debug("Total calculated cost for {$parentRef}: {$totalCostPrice}");
        self::$costCache[$productId] = $totalCostPrice;
        return $totalCostPrice;
    }

    /**
     * Get all products in hierarchy for a given product
     *
     * @param int $productId
     * @return array
     */
    public static function getProductHierarchy(int $productId): array
    {
        self::loadProductMap();

        $hierarchy = [
            'product' => self::getProductFromMap($productId),
            'children' => [],
            'parents' => []
        ];

        if ($hierarchy['product']) {
            // Get children recursively
            $hierarchy['children'] = self::getChildrenHierarchy($productId);
            // Get parents recursively
            $hierarchy['parents'] = self::getParentsHierarchy($productId);
        }

        return $hierarchy;
    }

    /**
     * Get children hierarchy recursively
     *
     * @param int $productId
     * @return array
     */
    private static function getChildrenHierarchy(int $productId): array
    {
        $children = [];
        $productChildren = self::getChildren($productId);

        foreach ($productChildren as $child) {
            $childData = self::getProductFromMap($child['id']);
            if ($childData) {
                $children[] = [
                    'product' => $childData,
                    'qty' => $child['qty'],
                    'children' => self::getChildrenHierarchy($child['id'])
                ];
            }
        }

        return $children;
    }

    /**
     * Get parents hierarchy recursively
     *
     * @param int $productId
     * @return array
     */
    private static function getParentsHierarchy(int $productId): array
    {
        $parents = [];
        $product = self::getProductFromMap($productId);

        if ($product && !empty($product['parents'])) {
            foreach ($product['parents'] as $parent) {
                $parentData = self::getProductFromMap($parent['id']);
                if ($parentData) {
                    $parents[] = [
                        'product' => $parentData,
                        'parents' => self::getParentsHierarchy($parent['id'])
                    ];
                }
            }
        }

        return $parents;
    }

    /**
     * Simulate the exact behavior when a product is saved in Dolibarr
     * This is what gets called when you modify a product and save it
     * (equivalent to the trigger PRODUCT_MODIFY calling ProductMixer::updateProductAttributes)
     *
     * @param int $productId The product that was just saved/modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting (default true)
     * @return array Results array showing what was updated
     */
    public static function onProductModified(int $productId, bool $useWholeSalePriceSync = true): array
    {
        self::debug("=== Product Modified Event for Product ID: " . $productId . " ===");

        // This exactly matches the trigger behavior:
        // 1. Product A is saved
        // 2. Trigger calls ProductMixer::updateProductAttributes(A)
        // 3. This recalculates cost prices for A (if A has children) and all its parents

        $results = self::updateProductCostPrice($productId, $useWholeSalePriceSync);

        self::debug("=== End Product Modified Event ===");

        return $results;
    }

    /**
     * Main entry point for updating product attributes (backward compatibility)
     * This maintains compatibility with existing code that calls ProductHierarchy::updateProductAttributes
     *
     * @param int $productId Starting product ID
     * @param mixed $user User performing the update
     * @return int 1 on success, 0 on failure or skip
     */
    public static function updateProductAttributes($productId, $user)
    {
        // Call the new method and return simplified result
        $results = self::updateProductCostPrice($productId, true);

        // Return 1 if any products were updated, 0 otherwise
        foreach ($results as $result) {
            if ($result['updated']) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Test method to verify the class is working correctly
     *
     * @return array Test results
     */
    public static function runSelfTest(): array
    {
        global $db;

        $results = [
            'database_connection' => false,
            'product_associations_table' => false,
            'product_table' => false,
            'test_query' => false,
            'errors' => []
        ];

        // Test database connection
        if ($db) {
            $results['database_connection'] = true;
        } else {
            $results['errors'][] = 'No database connection available';
            return $results;
        }

        // Test if product_association table exists
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "product_association'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $results['product_associations_table'] = true;
        } else {
            $results['errors'][] = 'product_association table not found';
        }
        if ($resql) $db->free($resql);

        // Test if product table exists
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "product'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $results['product_table'] = true;
        } else {
            $results['errors'][] = 'product table not found';
        }
        if ($resql) $db->free($resql);

        // Test a simple query
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "product_association";
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $results['test_query'] = true;
            $results['association_count'] = $obj->count;
            $db->free($resql);
        } else {
            $results['errors'][] = 'Failed to query product_association table: ' . $db->lasterror();
        }

        return $results;
    }
}

/**
 * Backward compatibility alias
 * Maintains compatibility with existing code that references ProductHierarchy
 */
class ProductHierarchy extends ProductUpdater
{
    // All functionality is inherited from ProductUpdater
}
