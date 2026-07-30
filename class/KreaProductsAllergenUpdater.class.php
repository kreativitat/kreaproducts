<?php
/*
 * Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * KreaProductsAllergenUpdater.class.php
 * 
 * Handles allergen propagation in product hierarchies with BFS and bottom-up processing.
 * Provides comprehensive allergen management with proper validation, error handling,
 * and performance optimization for complex product hierarchies.
 *
 * Disclaimer:
 * Allergen data is entered by users or derived from their inputs and is not verified.
 * It is provided for informational purposes only and is not medical, dietary, or regulatory advice.
 * Users are solely responsible for accuracy, labeling, and compliance with applicable laws and regulations.
 * This software is provided as is, without warranties of any kind, express or implied, including
 * but not limited to merchantability and fitness for a particular purpose. To the maximum extent
 * permitted by law, the authors and distributors disclaim all liability for damages arising from its use.
 */
class KreaProductsAllergenUpdater
{
    // Constants for allergen management
    const ALERT_ALLERGEN_ID = 999; // Must exist in your allergens table!
    const CALC_OPTION_MANUAL = 0;
    const CALC_OPTION_AUTO = 1;
    const CALC_OPTION_NOT_FOOD = 2;
    
    // Constants for trace modes
    const TRACES_ALLERGEN = 0;
    const TRACES_POSSIBLE = 1;
    
    // Constants for processing limits
    const MAX_HIERARCHY_DEPTH = 50;
    const MAX_PRODUCTS_PER_LEVEL = 1000;
    const BATCH_SIZE = 100;
    
    // Error handling
    private static $errors = array();
    private static $lastError = null;
    
    // Performance tracking
    private static $processStats = array();
    private static $productCache = array();
    private static $allergenCache = array();
    private static $resolvedAllergenCache = array();
    private static $unitMappingCache = array();
    private static $weightConversionCache = array();

    /**
     * Update allergen attributes for a product hierarchy
     *
     * @param int $rootProductId Root product ID to start processing
     * @param User $user User performing the update
     * @param int $forceTraces Force all allergens to trace mode (0=no, 1=yes)
     * @param array $options Additional processing options
     * @return bool True on success, false on failure
     */
    public static function updateAllergenAttributes($rootProductId, $user, $forceTraces = 0, $options = array())
    {
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
    public static function getProductAllergens($productId, $includeInherited = false)
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
    public static function validateProductAllergens($productId)
    {
        $results = array(
            'valid' => true,
            'warnings' => array(),
            'errors' => array(),
            'suggestions' => array()
        );

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
    private static function initializeProcessing($rootProductId)
    {
        self::clearErrors();
        self::$resolvedAllergenCache = array();
        self::$processStats = array(
            'start_time' => microtime(true),
            'root_product' => $rootProductId,
            'products_processed' => 0,
            'allergens_updated' => 0,
            'database_operations' => 0
        );

        self::logDebug(__METHOD__ . " START (root=$rootProductId)");
    }

    private static function isDebugEnabled()
    {
        global $conf;

        return !empty($conf->global->KREAPRODUCTS_DEBUG_LOG);
    }

    private static function logDebug($message)
    {
        if (self::isDebugEnabled()) {
            dol_syslog($message, LOG_DEBUG);
        }
    }

    private static function logInfo($message)
    {
        if (self::isDebugEnabled()) {
            dol_syslog($message, LOG_INFO);
        }
    }

    /**
     * Validate inputs for allergen update
     */
    private static function validateInputs($rootProductId, $user, $forceTraces)
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

        if (!in_array($forceTraces, array(0, 1))) {
            self::addError("Force traces must be 0 or 1");
            return false;
        }

        return true;
    }

    /**
     * Build comprehensive product hierarchy map
     */
    private static function buildProductHierarchy($rootId)
    {
        global $db;
        
        $hierarchyMap = array();
        $queue = array($rootId);
        $visited = array();
        $depth = 0;

        while (!empty($queue) && $depth < self::MAX_HIERARCHY_DEPTH) {
            $currentLevel = $queue;
            $queue = array();
            
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
    private static function fetchProductChildren($productId)
    {
        global $db, $conf;
        
        $children = array();
        $source = array();

        if (!empty($conf->bom->enabled)) {
            $sql = "SELECT COALESCE(bl.fk_product, cb.fk_product) as child_id, bl.qty as qty
                    FROM " . MAIN_DB_PREFIX . "bom_bom b
                    JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                    LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                    WHERE b.fk_product = " . (int)$productId . "
                        AND b.bomtype IN (0,1)
                        AND b.status = 1
                        ";

            $resql = $db->query($sql);
            if (!$resql) {
                throw new Exception("Failed to fetch BOM children for product $productId: " . $db->lasterror());
            }

            while ($row = $db->fetch_object($resql)) {
                $childId = (int)$row->child_id;
                $quantity = (float)$row->qty;
                if ($childId > 0 && $quantity > 0) {
                    $children[$childId] = $quantity;
                    $source[$childId] = 'bom';
                }
            }

            $db->free($resql);
            self::$processStats['database_operations']++;
        }
        
        $sql = "SELECT pa.fk_product_fils, pa.qty
                FROM " . MAIN_DB_PREFIX . "product_association pa
                JOIN " . MAIN_DB_PREFIX . "product pf ON pf.rowid = pa.fk_product_pere
                JOIN " . MAIN_DB_PREFIX . "product pc ON pc.rowid = pa.fk_product_fils
                WHERE pa.fk_product_pere = " . (int)$productId;
        
        $resql = $db->query($sql);

        if (!$resql) {
            throw new Exception("Failed to fetch children for product $productId: " . $db->lasterror());
        }

        while ($row = $db->fetch_object($resql)) {
            $childId = (int)$row->fk_product_fils;
            $quantity = (float)$row->qty;
            
            if ($childId > 0 && $quantity > 0) {
                if (isset($source[$childId]) && $source[$childId] === 'bom') {
                    continue;
                }
                $children[$childId] = $quantity;
                $source[$childId] = 'association';
            }
        }
        
        $db->free($resql);
        self::$processStats['database_operations']++;
        
        return $children;
    }

    /**
     * Validate hierarchy for processing constraints
     */
    private static function validateHierarchy($hierarchyMap)
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
    private static function hasCircularDependencies($hierarchyMap)
    {
        $visited = array();
        $recursionStack = array();

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
    private static function hasCycleDFS($nodeId, $hierarchyMap, &$visited, &$recursionStack)
    {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;

        $node = isset($hierarchyMap[$nodeId]) ? $hierarchyMap[$nodeId] : null;
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
    private static function calculateProcessingOrder($hierarchyMap)
    {
        $heights = array();
        $processed = array();

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
    private static function calculateNodeHeight($nodeId, $hierarchyMap, &$heights, &$processed)
    {
        if (isset($heights[$nodeId])) {
            return $heights[$nodeId];
        }

        $node = isset($hierarchyMap[$nodeId]) ? $hierarchyMap[$nodeId] : null;
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
    private static function clearAutoCalculatedAllergens($hierarchyMap)
    {
        global $db;
        
        $productsToClean = array();
        
        foreach ($hierarchyMap as $productId => $node) {
            if (self::getCalculationOption($productId) !== self::CALC_OPTION_AUTO) {
                continue;
            }
            if (empty($node->children)) {
                // Preserve leaf allergens so they can act as base data for parents.
                continue;
            }
            $productsToClean[] = $productId;
        }

        if (empty($productsToClean)) {
            return;
        }

        // Process in batches for better performance
        $batches = array_chunk($productsToClean, self::BATCH_SIZE);
        
        foreach ($batches as $batch) {
            $ids = implode(',', array_map('intval', $batch));
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                    WHERE fk_product IN ($ids)";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to clear allergens: " . $db->lasterror());
            }
            
            self::$processStats['database_operations']++;
        }
    }

    /**
     * Process products in calculated order
     */
    private static function processProductsInOrder($processingOrder, $hierarchyMap, $user, $forceTraces)
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
    private static function processProductAllergens($productId, $hierarchyMap, $user, $forceTraces)
    {
        try {
            $node = isset($hierarchyMap[$productId]) ? $hierarchyMap[$productId] : null;
            if (!$node || empty($node->children)) {
                self::logDebug("Leaf node $productId - no allergens calculated");
                return true;
            }

            // Aggregate allergens from children (recursive)
            $aggregatedAllergens = self::resolveProductAllergens(
                $productId,
                $hierarchyMap,
                $forceTraces,
                0,
                array()
            );
            
            // Update database with aggregated allergens
            self::updateProductAllergens($productId, $aggregatedAllergens, $user);
            
            self::$processStats['products_processed']++;
            self::$processStats['allergens_updated'] += count($aggregatedAllergens);
            
            self::logDebug("Updated product $productId with " . count($aggregatedAllergens) . " allergens");
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process allergens for product $productId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggregate allergens from child products
     */
    private static function aggregateChildAllergens($node, $hierarchyMap, $forceTraces, $depth = 0, $path = array())
    {
        $aggregated = array();
        $hasManualChildren = false;
        $unitMapping = self::getUnitMappingCached();
        $thresholds = self::getAllergenThresholds();
        $childWeights = array();
        $totalWeightInGrams = 0.0;

        if ($depth > self::MAX_HIERARCHY_DEPTH) {
            dol_syslog("Max hierarchy depth exceeded while aggregating allergens for product " . $node->id, LOG_WARNING);
            return $aggregated;
        }

        if (!isset($path[$node->id])) {
            $path[$node->id] = true;
        }

        // Pre-calculate total recipe weight in grams (exclude not-food children)
        foreach ($node->children as $childId => $quantity) {
            $childCalc = self::getCalculationOption($childId);
            if ($childCalc === self::CALC_OPTION_NOT_FOOD || $quantity <= 0) {
                continue;
            }

            $weightInfo = self::getProductWeightInfo($childId);
            if ($weightInfo['weight'] === null || $weightInfo['weight'] <= 0) {
                $childWeights[$childId] = null;
                continue;
            }

            $perUnitGrams = self::convertToGrams($weightInfo['weight'], $weightInfo['weight_units'], $unitMapping);
            if ($perUnitGrams <= 0) {
                $childWeights[$childId] = null;
                continue;
            }

            $childTotalGrams = $perUnitGrams * $quantity;
            $childWeights[$childId] = $childTotalGrams;
            $totalWeightInGrams += $childTotalGrams;
        }

        foreach ($node->children as $childId => $quantity) {
            $childCalc = self::getCalculationOption($childId);

            if ($childCalc === self::CALC_OPTION_NOT_FOOD) {
                continue;
            }

            // Track manual children
            if ($childCalc === self::CALC_OPTION_MANUAL) {
                $hasManualChildren = true;
                $childAllergens = self::fetchProductAllergens($childId);
            } else {
                $childAllergens = self::resolveProductAllergens(
                    $childId,
                    $hierarchyMap,
                    $forceTraces,
                    $depth + 1,
                    $path
                );
            }

            $thresholdTrace = null;
            if (isset($childWeights[$childId]) && $childWeights[$childId] !== null && $totalWeightInGrams > 0) {
                $childPercent = ($childWeights[$childId] / $totalWeightInGrams) * 100;
                if ($childPercent < $thresholds['trace']) {
                    continue;
                }
                $thresholdTrace = ($childPercent < $thresholds['full']) ? self::TRACES_POSSIBLE : self::TRACES_ALLERGEN;
            }

            // Merge allergens with trace level logic
            foreach ($childAllergens as $allergenId => $traces) {
                $finalTraces = $traces;
                if ($thresholdTrace !== null) {
                    $finalTraces = max($finalTraces, $thresholdTrace);
                }
                if (!isset($aggregated[$allergenId])) {
                    $aggregated[$allergenId] = $finalTraces;
                } else {
                    // Take the more restrictive trace level (0 = allergen, 1 = trace)
                    $aggregated[$allergenId] = min($aggregated[$allergenId], $finalTraces);
                }
            }
        }

        // Add alert allergen if manual children exist
        if ($hasManualChildren) {
            $aggregated[self::ALERT_ALLERGEN_ID] = self::TRACES_POSSIBLE;
        }

        // Apply force traces mode
        if ($forceTraces) {
            foreach ($aggregated as $key => $value) {
                $aggregated[$key] = self::TRACES_POSSIBLE;
            }
        }

        return $aggregated;
    }

    /**
     * Resolve allergens for a product, computing from children when auto
     */
    private static function resolveProductAllergens($productId, $hierarchyMap, $forceTraces, $depth = 0, $path = array())
    {
        $cacheKey = $productId . ':' . (int) $forceTraces;
        if (isset(self::$resolvedAllergenCache[$cacheKey])) {
            return self::$resolvedAllergenCache[$cacheKey];
        }

        if ($depth > self::MAX_HIERARCHY_DEPTH) {
            dol_syslog("Max hierarchy depth exceeded while resolving allergens for product $productId", LOG_WARNING);
            self::$resolvedAllergenCache[$cacheKey] = array();
            return self::$resolvedAllergenCache[$cacheKey];
        }

        if (isset($path[$productId])) {
            dol_syslog("Circular dependency detected while resolving allergens for product $productId", LOG_WARNING);
            self::$resolvedAllergenCache[$cacheKey] = array();
            return self::$resolvedAllergenCache[$cacheKey];
        }

        $calcOption = self::getCalculationOption($productId);
        if ($calcOption === self::CALC_OPTION_NOT_FOOD) {
            self::$resolvedAllergenCache[$cacheKey] = array();
            return self::$resolvedAllergenCache[$cacheKey];
        }

        if ($calcOption === self::CALC_OPTION_MANUAL) {
            $allergens = self::fetchProductAllergens($productId);
            self::$resolvedAllergenCache[$cacheKey] = $allergens;
            return $allergens;
        }

        $node = isset($hierarchyMap[$productId]) ? $hierarchyMap[$productId] : null;
        if (!$node || empty($node->children)) {
            // Auto leafs use any stored allergens as their base data.
            $allergens = self::fetchProductAllergens($productId);
            self::$resolvedAllergenCache[$cacheKey] = $allergens;
            return $allergens;
        }

        $path[$productId] = true;
        $allergens = self::aggregateChildAllergens($node, $hierarchyMap, $forceTraces, $depth + 1, $path);
        self::$resolvedAllergenCache[$cacheKey] = $allergens;

        return $allergens;
    }

    /**
     * Get allergen thresholds from configuration
     */
    private static function getAllergenThresholds()
    {
        global $conf;

        $full = isset($conf->global->KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT)
            ? (float) $conf->global->KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT
            : 1.0;
        $trace = isset($conf->global->KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT)
            ? (float) $conf->global->KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT
            : 0.1;

        if ($full <= 0) {
            $full = 1.0;
        }
        if ($trace < 0) {
            $trace = 0.0;
        }
        if ($trace > $full) {
            $trace = $full;
        }

        return array('full' => $full, 'trace' => $trace);
    }

    /**
     * Get product weight and unit from cache or database
     */
    private static function getProductWeightInfo($productId)
    {
        if (isset(self::$productCache[$productId]['weight'])) {
            return array(
                'weight' => self::$productCache[$productId]['weight'],
                'weight_units' => self::$productCache[$productId]['weight_units']
            );
        }

        global $db;

        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            return array('weight' => null, 'weight_units' => 0);
        }

        $weight = is_numeric($product->weight) ? (float) $product->weight : null;
        $weightUnits = ($product->weight_units === null || $product->weight_units === '') ? 0 : $product->weight_units;

        if (!isset(self::$productCache[$productId])) {
            self::$productCache[$productId] = array();
        }

        self::$productCache[$productId]['product'] = $product;
        self::$productCache[$productId]['weight'] = $weight;
        self::$productCache[$productId]['weight_units'] = $weightUnits;

        return array('weight' => $weight, 'weight_units' => $weightUnits);
    }

    /**
     * Convert weight to grams with unit support and caching
     */
    private static function convertToGrams($weight, $unit, $unitMapping)
    {
        $cacheKey = $weight . '_' . $unit;
        if (isset(self::$weightConversionCache[$cacheKey])) {
            return self::$weightConversionCache[$cacheKey];
        }

        $result = $weight; // Default fallback

        if (is_numeric($unit)) {
            $unitScale = self::resolveUnitScale((int) $unit, $unitMapping);
            switch ($unitScale) {
                case 98:
                    $result = $weight / 35.274;
                    break;
                case 99:
                    $result = $weight / 2.20462;
                    break;
                default:
                    $result = $weight * pow(10, (int) $unitScale) * 1000;
                    break;
            }
        } else {
            $unit = strtolower(trim($unit));
            switch ($unit) {
                case 'kg':
                    $result = $weight * 1000;
                    break;
                case 'g':
                    $result = $weight;
                    break;
                case 'mg':
                    $result = $weight / 1000;
                    break;
                case 'lb':
                case 'lbs':
                    $result = $weight / 2.20462;
                    break;
                case 'oz':
                    $result = $weight * 28.3495;
                    break;
                default:
                    $result = $weight;
                    break;
            }
        }

        self::$weightConversionCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Resolve unit scale from stored unit value
     */
    private static function resolveUnitScale($unit, $unitMapping)
    {
        if (self::isUnitStoredAsId() && isset($unitMapping['id'][$unit])) {
            return $unitMapping['id'][$unit]['scale'];
        }

        if (isset($unitMapping['scale'][$unit])) {
            return $unit;
        }

        if (isset($unitMapping['id'][$unit])) {
            return $unitMapping['id'][$unit]['scale'];
        }

        return $unit;
    }

    /**
     * Detect if weight units are stored as dictionary IDs (Dolibarr 10.0.0-10.0.2)
     */
    private static function isUnitStoredAsId()
    {
        if (!defined('DOL_VERSION')) {
            return false;
        }

        return version_compare(DOL_VERSION, '10.0.0', '>=') && version_compare(DOL_VERSION, '10.0.2', '<=');
    }

    /**
     * Get unit mapping with caching (scale and id)
     */
    private static function getUnitMappingCached()
    {
        if (!empty(self::$unitMappingCache)) {
            return self::$unitMappingCache;
        }

        global $db, $conf;

        $unitMapping = array(
            'scale' => array(),
            'id' => array()
        );

        $weightLabel = !empty($conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL)
            ? $conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL
            : 'weight';

        $sql = "SELECT rowid, scale, short_label 
                FROM " . MAIN_DB_PREFIX . "c_units 
                WHERE unit_type = '" . $db->escape($weightLabel) . "' 
                    AND active = 1";

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $scaleKey = is_numeric($obj->scale) ? (int)$obj->scale : $obj->scale;
                $unitMapping['scale'][$scaleKey] = $obj->short_label;
                $unitMapping['id'][(int)$obj->rowid] = array(
                    'scale' => $scaleKey,
                    'label' => $obj->short_label
                );
            }
            $db->free($resql);
        }

        self::$unitMappingCache = $unitMapping;
        self::$processStats['database_operations']++;

        return $unitMapping;
    }

    /**
     * Fetch allergens for a specific product
     */
    private static function fetchProductAllergens($productId)
    {
        global $db;
        
        $allergens = array();
        
        $sql = "SELECT fk_allergen, traces 
                FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens 
                WHERE fk_product = " . (int)$productId;
        
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
    private static function updateProductAllergens($productId, $allergens, $user)
    {
        global $db;

        if (empty($allergens)) {
            return;
        }

        // Insert allergens one by one for better compatibility
        foreach ($allergens as $allergenId => $traces) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_productallergens
                    (fk_product, fk_allergen, traces, fk_user_creat, date_creation)
                    VALUES (
                        " . (int)$productId . ",
                        " . (int)$allergenId . ",
                        " . (int)$traces . ",
                        " . (int)$user->id . ",
                        NOW()
                    )";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to insert allergen $allergenId for product $productId: " . $db->lasterror());
            }
        }
        
        self::$processStats['database_operations']++;
    }

    /**
     * Get calculation option for a product with caching
     */
    private static function getCalculationOption($productId)
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
        $calcOption = isset($product->array_options['options_kreap_calc_allergens']) 
            ? (int)$product->array_options['options_kreap_calc_allergens'] 
            : self::CALC_OPTION_AUTO;
        
        // Cache the result
        self::$productCache[$productId] = array(
            'calc_option' => $calcOption,
            'product' => $product,
            'weight' => is_numeric($product->weight) ? (float) $product->weight : null,
            'weight_units' => ($product->weight_units === null || $product->weight_units === '') ? 0 : $product->weight_units
        );

        return $calcOption;
    }

    /**
     * Check if product exists
     */
    private static function productExists($productId)
    {
        global $db;
        
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int)$productId;
        
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
    private static function calculateMaxDepth($hierarchyMap)
    {
        $maxDepth = 0;
        $processed = array();
        $heights = array();

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
    private static function getInheritedAllergens($productId)
    {
        self::$resolvedAllergenCache = array();
        $hierarchy = self::buildProductHierarchy($productId);
        $node = isset($hierarchy[$productId]) ? $hierarchy[$productId] : null;
        
        if (!$node || empty($node->children)) {
            return array();
        }

        return self::aggregateChildAllergens($node, $hierarchy, 0, 0, array($productId => true));
    }

    /**
     * Merge two allergen arrays
     */
    private static function mergeAllergens($allergens1, $allergens2)
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
    private static function detectAllergenConflicts($allergens)
    {
        $conflicts = array();
        
        // Add custom conflict detection logic here
        // For example, detecting incompatible allergen combinations
        
        return $conflicts;
    }

    /**
     * Log processing statistics
     */
    private static function logProcessingStats($rootProductId, $processedCount, $totalProducts)
    {
        $endTime = microtime(true);
        $duration = $endTime - self::$processStats['start_time'];
        
        $stats = array(
            'root_product' => $rootProductId,
            'total_products' => $totalProducts,
            'processed_count' => $processedCount,
            'duration_seconds' => round($duration, 3),
            'allergens_updated' => self::$processStats['allergens_updated'],
            'database_operations' => self::$processStats['database_operations']
        );
        
        self::logInfo(__METHOD__ . " COMPLETED: " . json_encode($stats));
    }

    /**
     * Error handling methods
     */
    private static function addError($error)
    {
        self::$errors[] = $error;
        self::$lastError = $error;
        dol_syslog("KreaProductsAllergenUpdater Error: $error", LOG_ERR);
    }

    private static function clearErrors()
    {
        self::$errors = array();
        self::$lastError = null;
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    public static function getAllErrors()
    {
        return self::$errors;
    }

    public static function hasErrors()
    {
        return !empty(self::$errors);
    }

    /**
     * Clear all caches
     */
    public static function clearCache()
    {
        self::$productCache = array();
        self::$allergenCache = array();
        self::$resolvedAllergenCache = array();
        self::$unitMappingCache = array();
        self::$weightConversionCache = array();
    }

    /**
     * Get processing statistics
     */
    public static function getProcessingStats()
    {
        return self::$processStats;
    }

    // Original method for backward compatibility
    private static function rebuildAllergensForNode($nodeId, $map, $user, $forceTraces)
    {
        return self::processProductAllergens($nodeId, $map, $user, $forceTraces);
    }

    private static function buildDownwardMap($rootId)
    {
        return self::buildProductHierarchy($rootId);
    }

    private static function computeHeights($map)
    {
        return self::calculateProcessingOrder($map);
    }

    private static function fetchCalcOption($productId)
    {
        return self::getCalculationOption($productId);
    }
}

/**
 * Enhanced ProductHierarchyNode class for better hierarchy management
 */
class ProductHierarchyNode
{
    public $id;
    public $children = array();
    public $parents = array();
    private $totalQuantity = 0.0;

    public function __construct($id)
    {
        $this->id = (int)$id;
    }

    /**
     * Add a child product with quantity
     */
    public function addChild($childId, $quantity)
    {
        $this->children[$childId] = $quantity;
        $this->totalQuantity += $quantity;
    }

    /**
     * Add a parent product
     */
    public function addParent($parentId)
    {
        $this->parents[$parentId] = true;
    }

    /**
     * Check if node has children
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }

    /**
     * Check if node has parents
     */
    public function hasParents()
    {
        return !empty($this->parents);
    }

    /**
     * Get total quantity of all children
     */
    public function getTotalQuantity()
    {
        return $this->totalQuantity;
    }

    /**
     * Get number of children
     */
    public function getChildCount()
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
