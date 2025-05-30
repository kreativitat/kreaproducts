<?php

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * KreaProductsAllergenUpdater.class.php
 * 
 * Handles allergen propagation in product hierarchies with BFS and bottom-up processing.
 * Provides comprehensive allergen management with proper validation, error handling,
 * and performance optimization for complex product hierarchies.
 */
class KreaProductsAllergenUpdater
{
    // Constants for allergen management
    public const ALERT_ALLERGEN_ID = 999; // Must exist in your allergens table!
    public const CALC_OPTION_MANUAL = 0;
    public const CALC_OPTION_AUTO = 1;
    
    // Constants for trace modes
    public const TRACES_ALLERGEN = 0;
    public const TRACES_POSSIBLE = 1;
    
    // Constants for processing limits
    private const MAX_HIERARCHY_DEPTH = 50;
    private const MAX_PRODUCTS_PER_LEVEL = 1000;
    private const BATCH_SIZE = 100;
    
    // Error handling
    private static array $errors = [];
    private static ?string $lastError = null;
    
    // Performance tracking
    private static array $processStats = [];
    private static array $productCache = [];
    private static array $allergenCache = [];

    /**
     * Update allergen attributes for a product hierarchy
     *
     * @param int $rootProductId Root product ID to start processing
     * @param User $user User performing the update
     * @param int $forceTraces Force all allergens to trace mode (0=no, 1=yes)
     * @param array $options Additional processing options
     * @return bool True on success, false on failure
     */
    public static function updateAllergenAttributes(
        int $rootProductId, 
        User $user, 
        int $forceTraces = 0,
        array $options = []
    ): bool {
        global $db;

        try {
            self::initializeProcessing($rootProductId);
            
            // Validate inputs
            if (!self::validateInputs($rootProductId, $user, $forceTraces)) {
                return false;
            }

            // Build product hierarchy map
            $hierarchyMap = self::buildProductHierarchy($rootProductId);
            if (empty($hierarchyMap)) {
                self::addError("No products found in hierarchy for root product $rootProductId");
                return false;
            }

            // Validate hierarchy constraints
            if (!self::validateHierarchy($hierarchyMap)) {
                return false;
            }

            // Process hierarchy in transaction
            $db->begin();
            
            try {
                // Clear existing auto-calculated allergens
                self::clearAutoCalculatedAllergens($hierarchyMap);
                
                // Calculate processing order (bottom-up)
                $processingOrder = self::calculateProcessingOrder($hierarchyMap);
                
                // Process each product in order
                $processedCount = self::processProductsInOrder($processingOrder, $hierarchyMap, $user, $forceTraces);
                
                $db->commit();
                
                self::logProcessingStats($rootProductId, $processedCount, count($hierarchyMap));
                return true;
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            self::addError("Processing failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Get allergen information for a specific product
     *
     * @param int $productId Product ID
     * @param bool $includeInherited Include inherited allergens from children
     * @return array|null Allergen data or null on error
     */
    public static function getProductAllergens(int $productId, bool $includeInherited = false): ?array
    {
        global $db;
        
        try {
            if ($productId <= 0) {
                self::addError("Invalid product ID");
                return null;
            }

            // Check cache first
            $cacheKey = "allergens_{$productId}_" . ($includeInherited ? '1' : '0');
            if (isset(self::$allergenCache[$cacheKey])) {
                return self::$allergenCache[$cacheKey];
            }

            $allergens = self::fetchProductAllergens($productId);
            
            if ($includeInherited) {
                $inheritedAllergens = self::getInheritedAllergens($productId);
                $allergens = self::mergeAllergens($allergens, $inheritedAllergens);
            }

            // Cache result
            self::$allergenCache[$cacheKey] = $allergens;
            
            return $allergens;
            
        } catch (Exception $e) {
            self::addError("Failed to get allergens for product $productId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate allergen configuration for a product
     *
     * @param int $productId Product ID to validate
     * @return array Validation results with warnings and errors
     */
    public static function validateProductAllergens(int $productId): array
    {
        $results = [
            'valid' => true,
            'warnings' => [],
            'errors' => [],
            'suggestions' => []
        ];

        try {
            // Check if product exists
            if (!self::productExists($productId)) {
                $results['errors'][] = "Product $productId does not exist";
                $results['valid'] = false;
                return $results;
            }

            // Get allergen data
            $allergens = self::getProductAllergens($productId, true);
            if ($allergens === null) {
                $results['errors'][] = "Failed to retrieve allergen data";
                $results['valid'] = false;
                return $results;
            }

            // Check for alert allergen
            if (isset($allergens[self::ALERT_ALLERGEN_ID])) {
                $results['warnings'][] = "Product contains manual child alert allergen";
            }

            // Check for conflicting allergens
            $conflicts = self::detectAllergenConflicts($allergens);
            if (!empty($conflicts)) {
                $results['warnings'] = array_merge($results['warnings'], $conflicts);
            }

            // Check calculation settings
            $calcOption = self::getCalculationOption($productId);
            if ($calcOption === self::CALC_OPTION_AUTO) {
                $hierarchy = self::buildProductHierarchy($productId);
                if (count($hierarchy) === 1) {
                    $results['suggestions'][] = "Product has auto-calculation enabled but no children";
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = "Validation failed: " . $e->getMessage();
            $results['valid'] = false;
        }

        return $results;
    }

    /**
     * Initialize processing state
     */
    private static function initializeProcessing(int $rootProductId): void
    {
        self::clearErrors();
        self::$processStats = [
            'start_time' => microtime(true),
            'root_product' => $rootProductId,
            'products_processed' => 0,
            'allergens_updated' => 0,
            'database_operations' => 0
        ];
        
        dol_syslog(__METHOD__ . " START (root=$rootProductId)", LOG_DEBUG);
    }

    /**
     * Validate inputs for allergen update
     */
    private static function validateInputs(int $rootProductId, User $user, int $forceTraces): bool
    {
        if ($rootProductId <= 0) {
            self::addError("Invalid root product ID: must be positive integer");
            return false;
        }

        if (!self::productExists($rootProductId)) {
            self::addError("Root product $rootProductId does not exist");
            return false;
        }

        if (!is_object($user) || empty($user->id)) {
            self::addError("Invalid user object provided");
            return false;
        }

        if (!in_array($forceTraces, [0, 1])) {
            self::addError("Force traces must be 0 or 1");
            return false;
        }

        return true;
    }

    /**
     * Build comprehensive product hierarchy map
     */
    private static function buildProductHierarchy(int $rootId): array
    {
        global $db;
        
        $hierarchyMap = [];
        $queue = [$rootId];
        $visited = [];
        $depth = 0;

        while (!empty($queue) && $depth < self::MAX_HIERARCHY_DEPTH) {
            $currentLevel = $queue;
            $queue = [];
            
            if (count($currentLevel) > self::MAX_PRODUCTS_PER_LEVEL) {
                throw new Exception("Hierarchy level exceeds maximum product limit");
            }

            foreach ($currentLevel as $currentId) {
                if (isset($visited[$currentId])) {
                    continue; // Prevent infinite loops
                }
                
                $visited[$currentId] = true;
                
                if (!isset($hierarchyMap[$currentId])) {
                    $hierarchyMap[$currentId] = new ProductHierarchyNode($currentId);
                }

                // Fetch children with proper error handling
                $children = self::fetchProductChildren($currentId);
                foreach ($children as $childId => $quantity) {
                    if (!isset($hierarchyMap[$childId])) {
                        $hierarchyMap[$childId] = new ProductHierarchyNode($childId);
                    }

                    $hierarchyMap[$currentId]->addChild($childId, $quantity);
                    
                    if (!isset($visited[$childId])) {
                        $queue[] = $childId;
                    }
                }
            }
            
            $depth++;
        }

        if ($depth >= self::MAX_HIERARCHY_DEPTH) {
            throw new Exception("Hierarchy depth exceeds maximum limit");
        }

        return $hierarchyMap;
    }

    /**
     * Fetch product children with enhanced error handling
     */
    private static function fetchProductChildren(int $productId): array
    {
        global $db;
        
        $children = [];
        
        $sql = "SELECT fk_product_fils, qty 
                FROM " . MAIN_DB_PREFIX . "product_association 
                WHERE fk_product_pere = %d";
        
        $sql = sprintf($sql, $productId);
        $resql = $db->query($sql);

        if (!$resql) {
            throw new Exception("Failed to fetch children for product $productId: " . $db->lasterror());
        }

        while ($row = $db->fetch_object($resql)) {
            $childId = (int)$row->fk_product_fils;
            $quantity = (float)$row->qty;
            
            if ($childId > 0) {
                $children[$childId] = $quantity;
            }
        }
        
        $db->free($resql);
        self::$processStats['database_operations']++;
        
        return $children;
    }

    /**
     * Validate hierarchy for processing constraints
     */
    private static function validateHierarchy(array $hierarchyMap): bool
    {
        // Check for circular dependencies
        if (self::hasCircularDependencies($hierarchyMap)) {
            self::addError("Circular dependency detected in product hierarchy");
            return false;
        }

        // Check hierarchy depth
        $maxDepth = self::calculateMaxDepth($hierarchyMap);
        if ($maxDepth > self::MAX_HIERARCHY_DEPTH) {
            self::addError("Hierarchy depth ($maxDepth) exceeds maximum allowed (" . self::MAX_HIERARCHY_DEPTH . ")");
            return false;
        }

        return true;
    }

    /**
     * Detect circular dependencies in hierarchy
     */
    private static function hasCircularDependencies(array $hierarchyMap): bool
    {
        $visited = [];
        $recursionStack = [];

        foreach ($hierarchyMap as $nodeId => $node) {
            if (!isset($visited[$nodeId])) {
                if (self::hasCycleDFS($nodeId, $hierarchyMap, $visited, $recursionStack)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * DFS-based cycle detection
     */
    private static function hasCycleDFS(int $nodeId, array $hierarchyMap, array &$visited, array &$recursionStack): bool
    {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;

        $node = $hierarchyMap[$nodeId] ?? null;
        if ($node && !empty($node->children)) {
            foreach ($node->children as $childId => $quantity) {
                if (!isset($visited[$childId])) {
                    if (self::hasCycleDFS($childId, $hierarchyMap, $visited, $recursionStack)) {
                        return true;
                    }
                } elseif (isset($recursionStack[$childId])) {
                    return true; // Back edge found - cycle detected
                }
            }
        }

        unset($recursionStack[$nodeId]);
        return false;
    }

    /**
     * Calculate processing order using topological sort
     */
    private static function calculateProcessingOrder(array $hierarchyMap): array
    {
        $heights = [];
        $processed = [];

        foreach ($hierarchyMap as $nodeId => $node) {
            if (!isset($processed[$nodeId])) {
                self::calculateNodeHeight($nodeId, $hierarchyMap, $heights, $processed);
            }
        }

        // Sort by height (leaves first)
        asort($heights);
        
        return array_keys($heights);
    }

    /**
     * Calculate node height with memoization
     */
    private static function calculateNodeHeight(int $nodeId, array $hierarchyMap, array &$heights, array &$processed): int
    {
        if (isset($heights[$nodeId])) {
            return $heights[$nodeId];
        }

        $node = $hierarchyMap[$nodeId] ?? null;
        if (!$node || empty($node->children)) {
            $heights[$nodeId] = 0;
            $processed[$nodeId] = true;
            return 0;
        }

        $maxHeight = 0;
        foreach ($node->children as $childId => $quantity) {
            $childHeight = self::calculateNodeHeight($childId, $hierarchyMap, $heights, $processed);
            $maxHeight = max($maxHeight, $childHeight);
        }

        $heights[$nodeId] = $maxHeight + 1;
        $processed[$nodeId] = true;
        
        return $heights[$nodeId];
    }

    /**
     * Clear auto-calculated allergens for products in hierarchy
     */
    private static function clearAutoCalculatedAllergens(array $hierarchyMap): void
    {
        global $db;
        
        $productsToClean = [];
        
        foreach ($hierarchyMap as $productId => $node) {
            if (self::getCalculationOption($productId) === self::CALC_OPTION_AUTO) {
                $productsToClean[] = $productId;
            }
        }

        if (empty($productsToClean)) {
            return;
        }

        // Process in batches
        $batches = array_chunk($productsToClean, self::BATCH_SIZE);
        
        foreach ($batches as $batch) {
            $placeholders = str_repeat('?,', count($batch) - 1) . '?';
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                    WHERE fk_product IN ($placeholders)";
            
            $stmt = $db->prepare($sql);
            if (!$stmt || !$stmt->execute($batch)) {
                throw new Exception("Failed to clear allergens: " . $db->lasterror());
            }
            
            self::$processStats['database_operations']++;
        }
    }

    /**
     * Process products in calculated order
     */
    private static function processProductsInOrder(array $processingOrder, array $hierarchyMap, User $user, int $forceTraces): int
    {
        $processedCount = 0;
        
        foreach ($processingOrder as $productId) {
            if (self::getCalculationOption($productId) === self::CALC_OPTION_AUTO) {
                if (self::processProductAllergens($productId, $hierarchyMap, $user, $forceTraces)) {
                    $processedCount++;
                }
            }
        }
        
        return $processedCount;
    }

    /**
     * Process allergens for a single product
     */
    private static function processProductAllergens(int $productId, array $hierarchyMap, User $user, int $forceTraces): bool
    {
        try {
            $node = $hierarchyMap[$productId] ?? null;
            if (!$node || empty($node->children)) {
                dol_syslog("Leaf node $productId - no allergens calculated", LOG_DEBUG);
                return true;
            }

            // Aggregate allergens from children
            $aggregatedAllergens = self::aggregateChildAllergens($node, $forceTraces);
            
            // Update database with aggregated allergens
            self::updateProductAllergens($productId, $aggregatedAllergens, $user);
            
            self::$processStats['products_processed']++;
            self::$processStats['allergens_updated'] += count($aggregatedAllergens);
            
            dol_syslog("Updated product $productId with " . count($aggregatedAllergens) . " allergens", LOG_DEBUG);
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process allergens for product $productId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate allergens from child products
     */
    private static function aggregateChildAllergens(ProductHierarchyNode $node, int $forceTraces): array
    {
        $aggregated = [];
        $hasManualChildren = false;

        foreach ($node->children as $childId => $quantity) {
            $childCalc = self::getCalculationOption($childId);
            $childAllergens = self::fetchProductAllergens($childId);

            // Track manual children
            if ($childCalc === self::CALC_OPTION_MANUAL) {
                $hasManualChildren = true;
            }

            // Merge allergens with trace level logic
            foreach ($childAllergens as $allergenId => $traces) {
                if (!isset($aggregated[$allergenId])) {
                    $aggregated[$allergenId] = $traces;
                } else {
                    // Take the more restrictive trace level (0 = allergen, 1 = trace)
                    $aggregated[$allergenId] = min($aggregated[$allergenId], $traces);
                }
            }
        }

        // Add alert allergen if manual children exist
        if ($hasManualChildren) {
            $aggregated[self::ALERT_ALLERGEN_ID] = self::TRACES_POSSIBLE;
        }

        // Apply force traces mode
        if ($forceTraces) {
            $aggregated = array_map(function () {
                return self::TRACES_POSSIBLE;
            }, $aggregated);
        }

        return $aggregated;
    }

    /**
     * Fetch allergens for a specific product
     */
    private static function fetchProductAllergens(int $productId): array
    {
        global $db;
        
        $allergens = [];
        
        $sql = "SELECT fk_allergen, traces 
                FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                WHERE fk_product = %d";
        
        $sql = sprintf($sql, $productId);
        $resql = $db->query($sql);

        if (!$resql) {
            throw new Exception("Failed to fetch allergens for product $productId: " . $db->lasterror());
        }

        while ($row = $db->fetch_object($resql)) {
            $allergenId = (int)$row->fk_allergen;
            $traces = (int)$row->traces;
            $allergens[$allergenId] = $traces;
        }
        
        $db->free($resql);
        self::$processStats['database_operations']++;
        
        return $allergens;
    }

    /**
     * Update product allergens in database
     */
    private static function updateProductAllergens(int $productId, array $allergens, User $user): void
    {
        global $db;

        if (empty($allergens)) {
            return;
        }

        // Prepare batch insert
        $values = [];
        $now = $db->idate(dol_now());
        
        foreach ($allergens as $allergenId => $traces) {
            $values[] = sprintf(
                "(%d, %d, %d, %d, '%s')",
                $productId,
                $allergenId,
                $traces,
                $user->id,
                $now
            );
        }

        if (!empty($values)) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_productallergens
                    (fk_product, fk_allergen, traces, fk_user_creat, date_creation)
                    VALUES " . implode(', ', $values);
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to insert allergens for product $productId: " . $db->lasterror());
            }
            
            self::$processStats['database_operations']++;
        }
    }

    /**
     * Get calculation option for a product with caching
     */
    private static function getCalculationOption(int $productId): int
    {
        if (isset(self::$productCache[$productId])) {
            return self::$productCache[$productId]['calc_option'];
        }

        global $db;
        
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            dol_syslog("Product $productId not found", LOG_WARNING);
            return self::CALC_OPTION_AUTO; // Default to auto
        }

        $product->fetch_optionals();
        $calcOption = (int)($product->array_options['options_kreap_calc_allergens'] ?? self::CALC_OPTION_AUTO);
        
        // Cache the result
        self::$productCache[$productId] = [
            'calc_option' => $calcOption,
            'product' => $product
        ];

        return $calcOption;
    }

    /**
     * Check if product exists
     */
    private static function productExists(int $productId): bool
    {
        global $db;
        
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE rowid = %d";
        $sql = sprintf($sql, $productId);
        
        $resql = $db->query($sql);
        if (!$resql) {
            return false;
        }

        $exists = $db->num_rows($resql) > 0;
        $db->free($resql);
        
        return $exists;
    }

    /**
     * Calculate maximum depth of hierarchy
     */
    private static function calculateMaxDepth(array $hierarchyMap): int
    {
        $maxDepth = 0;
        $processed = [];
        $heights = [];

        foreach ($hierarchyMap as $nodeId => $node) {
            if (!isset($processed[$nodeId])) {
                $depth = self::calculateNodeHeight($nodeId, $hierarchyMap, $heights, $processed);
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
    }

    /**
     * Get inherited allergens from children
     */
    private static function getInheritedAllergens(int $productId): array
    {
        $hierarchy = self::buildProductHierarchy($productId);
        $node = $hierarchy[$productId] ?? null;
        
        if (!$node || empty($node->children)) {
            return [];
        }

        return self::aggregateChildAllergens($node, 0);
    }

    /**
     * Merge two allergen arrays
     */
    private static function mergeAllergens(array $allergens1, array $allergens2): array
    {
        $merged = $allergens1;
        
        foreach ($allergens2 as $allergenId => $traces) {
            if (!isset($merged[$allergenId])) {
                $merged[$allergenId] = $traces;
            } else {
                $merged[$allergenId] = min($merged[$allergenId], $traces);
            }
        }
        
        return $merged;
    }

    /**
     * Detect allergen conflicts
     */
    private static function detectAllergenConflicts(array $allergens): array
    {
        $conflicts = [];
        
        // Add custom conflict detection logic here
        // For example, detecting incompatible allergen combinations
        
        return $conflicts;
    }

    /**
     * Log processing statistics
     */
    private static function logProcessingStats(int $rootProductId, int $processedCount, int $totalProducts): void
    {
        $endTime = microtime(true);
        $duration = $endTime - self::$processStats['start_time'];
        
        $stats = [
            'root_product' => $rootProductId,
            'total_products' => $totalProducts,
            'processed_count' => $processedCount,
            'duration_seconds' => round($duration, 3),
            'allergens_updated' => self::$processStats['allergens_updated'],
            'database_operations' => self::$processStats['database_operations']
        ];
        
        dol_syslog(__METHOD__ . " COMPLETED: " . json_encode($stats), LOG_INFO);
    }

    /**
     * Error handling methods
     */
    private static function addError(string $error): void
    {
        self::$errors[] = $error;
        self::$lastError = $error;
        dol_syslog("KreaProductsAllergenUpdater Error: $error", LOG_ERR);
    }

    private static function clearErrors(): void
    {
        self::$errors = [];
        self::$lastError = null;
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function getAllErrors(): array
    {
        return self::$errors;
    }

    public static function hasErrors(): bool
    {
        return !empty(self::$errors);
    }

    /**
     * Clear all caches
     */
    public static function clearCache(): void
    {
        self::$productCache = [];
        self::$allergenCache = [];
    }

    /**
     * Get processing statistics
     */
    public static function getProcessingStats(): array
    {
        return self::$processStats;
    }
}

/**
 * Enhanced ProductHierarchyNode class for better hierarchy management
 */
class ProductHierarchyNode
{
    public int $id;
    public array $children = [];
    public array $parents = [];
    private float $totalQuantity = 0.0;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    /**
     * Add a child product with quantity
     */
    public function addChild(int $childId, float $quantity): void
    {
        $this->children[$childId] = $quantity;
        $this->totalQuantity += $quantity;
    }

    /**
     * Add a parent product
     */
    public function addParent(int $parentId): void
    {
        $this->parents[$parentId] = true;
    }

    /**
     * Check if node has children
     */
    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    /**
     * Check if node has parents
     */
    public function hasParents(): bool
    {
        return !empty($this->parents);
    }

    /**
     * Get total quantity of all children
     */
    public function getTotalQuantity(): float
    {
        return $this->totalQuantity;
    }

    /**
     * Get number of children
     */
    public function getChildCount(): int
    {
        return count($this->children);
    }
}

// Maintain backward compatibility
class LocalProductAllergen extends ProductHierarchyNode
{
    public function __construct($id)
    {
        parent::__construct((int)$id);
    }
}
