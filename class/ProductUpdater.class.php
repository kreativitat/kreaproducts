<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
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
     * @var array List of products to update
     */
    private static $updateList = [];

    /**
     * @var array Reverse list for bottom-up updates
     */
    private static $reverseList = [];

    /**
     * @var bool Map loaded flag
     */
    private static $mapLoaded = false;

    /**
     * @var bool Debug mode
     */
    private static $debug = false;

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

        // Validate input
        if ($productId <= 0) {
            self::debug("Invalid product ID: " . $productId);
            return [];
        }

        // Reset and load product map
        self::resetMap();
        self::loadProductMap();

        if (empty(self::$productMap)) {
            self::debug("Product map is empty - no product associations found");
            return [];
        }

        // Clear lists
        self::$updateList = [];
        self::$reverseList = [];

        // Add product to update list (the one that was modified)
        self::addToUpdateList($productId);

        // Create reverse list (builds parent hierarchy)
        self::createReverseList();

        $results = [];

        // Process all products in reverse list
        // This includes the original product AND all its parents that have children
        while (!empty(self::$reverseList)) {
            $currentProductId = array_shift(self::$reverseList);
            $mapProduct = self::getProductFromMap($currentProductId);

            // Skip products that don't exist in map or don't have children
            // (matches original logic: only virtual products with children get updated)
            if (!$mapProduct || !self::hasChildren($currentProductId)) {
                continue;
            }

            self::debug("Processing product ID: " . $currentProductId . " (ref: " . $mapProduct['ref'] . ")");

            // Check if children are still in reverse list (dependency check)
            $hasUnprocessedChildren = false;
            foreach (self::getChildren($currentProductId) as $child) {
                if (in_array($child['id'], self::$reverseList)) {
                    $hasUnprocessedChildren = true;
                    break;
                }
            }

            // If has unprocessed children, postpone this product
            if ($hasUnprocessedChildren) {
                self::$reverseList[] = $currentProductId;
                continue;
            }

            // Update cost price if sync is enabled via product extrafield (kreap_updatebuyprice/kreap_syncprice)
            if (self::isCostPriceSyncEnabled($currentProductId) && $useWholeSalePriceSync) {
                $updated = self::updateCostPriceFromChildren($currentProductId, $productId);
                $results[$currentProductId] = [
                    'updated' => $updated,
                    'ref' => $mapProduct['ref'] ?? 'Unknown',
                    'is_original' => ($currentProductId == $productId)
                ];

                if ($updated) {
                    self::debug("Cost price updated for product: " . $mapProduct['ref'] .
                              ($currentProductId == $productId ? " (this is the original modified product)" : " (parent product)"));
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
    private static function loadProductAssociations(): void
    {
        global $db;

        self::debug("Loading product associations");

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
    private static function loadBOMRelationships(): void
    {
        global $db, $conf;

        // Only load BOM data if BOM module is enabled
        if (empty($conf->bom->enabled)) {
            self::debug("BOM module not enabled, skipping BOM relationships");
            return;
        }

        self::debug("Loading BOM relationships");

        // Query to get BOM relationships (manufacturing type only)
        $sql = "SELECT b.fk_product as parent, bl.fk_product as child, bl.qty as qty, ";
        // Parent product info
        $sql .= "p.label as p_label, p.ref as p_ref, p.cost_price as p_cost_price, ";
        // Child product info
        $sql .= "f.label as f_label, f.ref as f_ref, f.cost_price as f_cost_price, ";
        // BOM info
        $sql .= "b.rowid as bom_id, b.ref as bom_ref ";
        $sql .= "FROM ".MAIN_DB_PREFIX."bom_bom as b ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."bom_bomline as bl ON b.rowid = bl.fk_bom ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = b.fk_product ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."product as f ON f.rowid = bl.fk_product ";
        $sql .= "WHERE b.bomtype IN (0,1) AND b.status = 1"; // Include manufacturing and dismantle BOMs

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
     * Reset product map
     */
    private static function resetMap(): void
    {
        self::$productMap = [];
        self::$mapLoaded = false;
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

        // Load product and its extrafields
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);
        $product->fetch_optionals($productId, $extrafields);

        // Primary flag: kreap_updatebuyprice
        $syncFields = ['options_kreap_updatebuyprice', 'options_kreap_syncprice'];
        foreach ($syncFields as $fieldName) {
            if (!empty($product->array_options[$fieldName])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add product to update list
     *
     * @param int $productId
     */
    private static function addToUpdateList(int $productId): void
    {
        if (!in_array($productId, self::$updateList)) {
            self::$updateList[] = $productId;
            self::debug("Added product " . $productId . " to update list");
        }
    }

    /**
     * Create reverse list for bottom-up processing
     */
    private static function createReverseList(): void
    {
        self::debug("Creating reverse list");
        self::$reverseList = [];

        foreach (self::$updateList as $productId) {
            self::addParentsToReverseList($productId);
        }

        self::debug("Reverse list created with " . count(self::$reverseList) . " products");
    }

    /**
     * Recursively add product and its parents to reverse list
     *
     * @param int $productId
     */
    private static function addParentsToReverseList(int $productId): void
    {
        if (!in_array($productId, self::$reverseList)) {
            self::$reverseList[] = $productId;
        }

        $product = self::getProductFromMap($productId);
        if ($product && !empty($product['parents'])) {
            foreach ($product['parents'] as $parent) {
                self::addParentsToReverseList($parent['id']);
            }
        }
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
    private static function calculateCostPriceFromChildren(int $productId): float
    {
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
                $childCostPrice = self::calculateCostPriceFromChildren($child['id']);
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
     * Batch update cost prices for multiple products
     *
     * @param array $productIds Array of product IDs
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array Results array
     */
    public static function batchUpdateCostPrices(array $productIds, bool $useWholeSalePriceSync = true): array
    {
        $allResults = [];

        foreach ($productIds as $productId) {
            $results = self::updateProductCostPrice($productId, $useWholeSalePriceSync);
            $allResults = array_merge($allResults, $results);
        }

        return $allResults;
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
