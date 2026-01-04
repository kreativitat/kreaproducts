<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * KreaProductsNutrientUpdater.class.php
 *
 * Enhanced class for updating nutritional records for all non-leaf products
 * in the product tree by traversing the association map and normalizing nutrient values.
 * Provides comprehensive validation, error handling, and performance optimization.
 *
 * Disclaimer:
 * Nutrition data is entered by users or derived from their inputs and is not verified.
 * It is provided for informational purposes only and is not medical, dietary, or regulatory advice.
 * Users are solely responsible for accuracy, labeling, and compliance with applicable laws and regulations.
 * This software is provided as is, without warranties of any kind, express or implied, including
 * but not limited to merchantability and fitness for a particular purpose. To the maximum extent
 * permitted by law, the authors and distributors disclaim all liability for damages arising from its use.
 */
class KreaProductsNutrientUpdater
{
    // Constants for weight units
    const UNIT_GRAMS = 'g';
    const UNIT_KILOGRAMS = 'kg';
    const UNIT_MILLIGRAMS = 'mg';
    const UNIT_POUNDS = 'lb';
    const UNIT_OUNCES = 'oz';
    
    // Constants for numeric units (Dolibarr standard)
    const UNIT_NUMERIC_OUNCES = 98;
    const UNIT_NUMERIC_POUNDS = 99;

    // Nutrition calculation options
    const CALC_OPTION_MANUAL = 0;
    const CALC_OPTION_AUTO = 1;
    const CALC_OPTION_NOT_FOOD = 2;
    
    // Processing limits
    const MAX_HIERARCHY_DEPTH = 50;
    const MAX_PRODUCTS_PER_BATCH = 5000;
    const BATCH_SIZE = 50;
    
    // Precision for nutritional values
    const NUTRITION_PRECISION = 4;
    const DISPLAY_PRECISION = 2;
    
    // Error handling
    private static $errors = array();
    private static $lastError = null;
    
    // Performance tracking and caching
    private static $processStats = array();
    private static $productCache = array();
    private static $nutritionCache = array();
    private static $weightConversionCache = array();
    private static $unitMappingCache = array();
    private static $calcOptionCache = array();

    /**
     * Updates the nutritional records for all non-leaf products in the tree.
     *
     * @param int $productId Starting (root) product ID
     * @param User $user The current user performing the update
     * @param array $options Additional processing options
     * @return bool True on success, false on failure
     */
    public static function updateNutrientAttributes($productId, $user, $options = array())
    {
        global $db;

        try {
            self::initializeProcessing($productId);
            
            // Validate inputs
            if (!self::validateInputs($productId, $user)) {
                return false;
            }

            // Build the product hierarchy map
            $productMap = self::buildProductHierarchy($productId);
            if (empty($productMap)) {
                self::addError("No products found in hierarchy for product $productId");
                return false;
            }

            // Validate hierarchy constraints
            if (!self::validateHierarchy($productMap)) {
                return false;
            }

            // Start database transaction
            $db->begin();
            
            try {
                // Compute processing order (bottom-up)
                $processingOrder = self::calculateProcessingOrder($productMap);
                
                // Process non-leaf products
                $processedCount = self::processProductsInOrder($processingOrder, $productMap, $user, $options);
                
                $db->commit();
                
                self::logProcessingStats($productId, $processedCount, count($productMap));
                return true;
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            self::addError("Nutrient update failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Get nutritional data for a specific product
     *
     * @param int $productId Product ID
     * @param bool $includeInherited Include inherited allergens from children
     * @return array|null Nutritional data or null on error
     */
    public static function getProductNutrition($productId, $includeInherited = false)
    {
        try {
            if ($productId <= 0) {
                self::addError("Invalid product ID");
                return null;
            }

            // Check cache first
            $cacheKey = "nutrition_{$productId}_" . ($includeInherited ? '1' : '0');
            if (isset(self::$nutritionCache[$cacheKey])) {
                return self::$nutritionCache[$cacheKey];
            }

            $nutrition = self::fetchNutritionalData($productId);
            
            if ($includeInherited) {
                $inheritedNutrition = self::getInheritedNutrition($productId);
                $nutrition = self::mergeNutrition($nutrition, $inheritedNutrition);
            }

            // Cache result
            if ($nutrition !== null) {
                self::$nutritionCache[$cacheKey] = $nutrition;
            }
            
            return $nutrition;
            
        } catch (Exception $e) {
            self::addError("Failed to get nutrition for product $productId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate nutritional values for a product based on its children
     *
     * @param int $productId Product ID to calculate for
     * @param bool $updateDatabase Whether to update the database
     * @return array|null Calculated nutrition or null on error
     */
    public static function calculateProductNutrition($productId, $updateDatabase = false)
    {
        try {
            // Build hierarchy starting from this product
            $hierarchy = self::buildProductHierarchy($productId);
            $node = isset($hierarchy[$productId]) ? $hierarchy[$productId] : null;
            
            if (!$node || empty($node->children)) {
                self::addError("Product $productId has no children for calculation");
                return null;
            }

            // Calculate nutrition from children
            $calculatedNutrition = self::calculateNodeNutrition($node, $hierarchy);
            
            if ($updateDatabase && $calculatedNutrition !== null) {
                global $user;
                self::updateNutritionalRecord($productId, $calculatedNutrition, $user);
            }
            
            return $calculatedNutrition;
            
        } catch (Exception $e) {
            self::addError("Failed to calculate nutrition for product $productId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Initialize processing state
     */
    private static function initializeProcessing($productId)
    {
        self::clearErrors();
        self::$processStats = array(
            'start_time' => microtime(true),
            'root_product' => $productId,
            'products_processed' => 0,
            'records_updated' => 0,
            'records_inserted' => 0,
            'database_operations' => 0,
            'cache_hits' => 0
        );

        self::logDebug(__METHOD__ . " Starting updateNutrientAttributes for productId: $productId");
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
     * Validate inputs
     */
    private static function validateInputs($productId, $user)
    {
        if (!is_numeric($productId) || $productId <= 0) {
            self::addError("Invalid product ID: must be positive integer");
            return false;
        }

        if (!self::productExists($productId)) {
            self::addError("Product $productId does not exist");
            return false;
        }

        if (!is_object($user) || empty($user->id)) {
            self::addError("Invalid user object provided");
            return false;
        }

        return true;
    }

    /**
     * Build comprehensive product hierarchy
     */
    private static function buildProductHierarchy($startId)
    {
        global $db;
        
        $map = array();
        $associationCache = array();
        $queue = array($startId);
        $seen = array($startId => true);
        $depth = 0;

        while (!empty($queue) && $depth < self::MAX_HIERARCHY_DEPTH) {
            $currentLevel = $queue;
            $queue = array();
            
            if (count($currentLevel) > self::MAX_PRODUCTS_PER_BATCH) {
                throw new Exception("Hierarchy level exceeds maximum product limit");
            }

            foreach ($currentLevel as $current) {
                $associations = self::fetchProductAssociations($current);
                
                foreach ($associations as $assoc) {
                    // Create/update father node
                    if (!isset($map[$assoc['father']])) {
                        $map[$assoc['father']] = new ProductNutritionNode(
                            $assoc['father'],
                            $assoc['father_label'],
                            $assoc['father_weight'],
                            $assoc['father_weight_units']
                        );
                    }
                    
                    // Create/update child node
                    if (!isset($map[$assoc['child']])) {
                        $map[$assoc['child']] = new ProductNutritionNode(
                            $assoc['child'],
                            $assoc['child_label'],
                            $assoc['child_weight'],
                            $assoc['child_weight_units']
                        );
                    }
                    
                    // Record association with BOM precedence
                    $associationKey = $assoc['father'] . ':' . $assoc['child'];
                    $quantity = (float)$assoc['qty'];
                    $source = isset($assoc['source']) ? $assoc['source'] : 'association';
                    if ($quantity > 0) {
                        if (!isset($associationCache[$associationKey])) {
                            $map[$assoc['father']]->setChildQuantity($assoc['child'], $quantity);
                            $associationCache[$associationKey] = $source;
                        } elseif ($source === 'bom' && $associationCache[$associationKey] !== 'bom') {
                            $map[$assoc['father']]->setChildQuantity($assoc['child'], $quantity);
                            $associationCache[$associationKey] = 'bom';
                        }
                    }
                    
                    // Queue unseen nodes
                    if (!isset($seen[$assoc['father']])) {
                        $seen[$assoc['father']] = true;
                        $queue[] = $assoc['father'];
                    }
                    if (!isset($seen[$assoc['child']])) {
                        $seen[$assoc['child']] = true;
                        $queue[] = $assoc['child'];
                    }
                }
            }
            
            $depth++;
        }

        if ($depth >= self::MAX_HIERARCHY_DEPTH) {
            throw new Exception("Hierarchy depth exceeds maximum limit");
        }

        return $map;
    }

    /**
     * Fetch product associations with enhanced error handling
     */
    private static function fetchProductAssociations($productId)
    {
        global $db, $conf;
        
        $associations = array();

        if (!empty($conf->bom->enabled)) {
            $sql = "SELECT b.fk_product AS father,
                           COALESCE(bl.fk_product, cb.fk_product) AS child,
                           bl.qty AS qty,
                           pf.label AS father_label,
                           pf.weight AS father_weight,
                           pf.weight_units AS father_weight_units,
                           COALESCE(pc.label, cprod.label) AS child_label,
                           COALESCE(pc.weight, cprod.weight) AS child_weight,
                           COALESCE(pc.weight_units, cprod.weight_units) AS child_weight_units
                    FROM " . MAIN_DB_PREFIX . "bom_bom b
                    JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                    JOIN " . MAIN_DB_PREFIX . "product pf ON (pf.rowid = b.fk_product)
                    LEFT JOIN " . MAIN_DB_PREFIX . "product pc ON (pc.rowid = bl.fk_product)
                    LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                    LEFT JOIN " . MAIN_DB_PREFIX . "product cprod ON cprod.rowid = cb.fk_product
                    WHERE b.bomtype IN (0,1)
                        AND b.status = 1
                        AND (b.fk_product = " . (int)$productId . " OR COALESCE(bl.fk_product, cb.fk_product) = " . (int)$productId . ")
                        AND b.entity IN (0," . getEntity('bom') . ")
                        AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (
                            SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom b2
                            WHERE b2.fk_product = b.fk_product
                              AND b2.entity = " . ((int) $conf->entity) . "
                              AND b2.bomtype = b.bomtype AND b2.status = 1
                        )))
                        AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                        AND pf.entity IN (" . getEntity('product') . ")
                        AND (pc.rowid IS NULL OR pc.entity IN (" . getEntity('product') . "))
                        AND (cprod.rowid IS NULL OR cprod.entity IN (" . getEntity('product') . "))";

            $resql = $db->query($sql);
            
            if (!$resql) {
                throw new Exception("Failed to fetch BOM associations for product $productId: " . $db->lasterror());
            }

            while ($obj = $db->fetch_object($resql)) {
                $associations[] = array(
                    'father' => (int)$obj->father,
                    'child' => (int)$obj->child,
                    'qty' => (float)$obj->qty,
                    'father_label' => $obj->father_label,
                    'father_weight' => (float)$obj->father_weight,
                    'father_weight_units' => $obj->father_weight_units,
                    'child_label' => $obj->child_label,
                    'child_weight' => (float)$obj->child_weight,
                    'child_weight_units' => $obj->child_weight_units,
                    'source' => 'bom'
                );
            }
            
            $db->free($resql);
            self::$processStats['database_operations']++;
        }
        
        $sql = "SELECT pa.fk_product_pere AS father,
                       pa.fk_product_fils AS child,
                       pa.qty AS qty,
                       pf.label AS father_label,
                       pf.weight AS father_weight,
                       pf.weight_units AS father_weight_units,
                       pc.label AS child_label,
                       pc.weight AS child_weight,
                       pc.weight_units AS child_weight_units
                FROM " . MAIN_DB_PREFIX . "product_association pa
                JOIN " . MAIN_DB_PREFIX . "product pf ON (pf.rowid = pa.fk_product_pere)
                JOIN " . MAIN_DB_PREFIX . "product pc ON (pc.rowid = pa.fk_product_fils)
                WHERE (pa.fk_product_pere = " . (int)$productId . " OR pa.fk_product_fils = " . (int)$productId . ")
                AND pf.entity IN (" . getEntity('product') . ")
                AND pc.entity IN (" . getEntity('product') . ")";
        
        $resql = $db->query($sql);
        
        if (!$resql) {
            throw new Exception("Failed to fetch associations for product $productId: " . $db->lasterror());
        }

        while ($obj = $db->fetch_object($resql)) {
            $associations[] = array(
                'father' => (int)$obj->father,
                'child' => (int)$obj->child,
                'qty' => (float)$obj->qty,
                'father_label' => $obj->father_label,
                'father_weight' => (float)$obj->father_weight,
                'father_weight_units' => $obj->father_weight_units,
                'child_label' => $obj->child_label,
                'child_weight' => (float)$obj->child_weight,
                'child_weight_units' => $obj->child_weight_units,
                'source' => 'association'
            );
        }
        
        $db->free($resql);
        self::$processStats['database_operations']++;
        
        return $associations;
    }

    /**
     * Validate hierarchy constraints
     */
    private static function validateHierarchy($productMap)
    {
        // Check for circular dependencies
        if (self::hasCircularDependencies($productMap)) {
            self::addError("Circular dependency detected in product hierarchy");
            return false;
        }

        return true;
    }

    /**
     * Check for circular dependencies
     */
    private static function hasCircularDependencies($productMap)
    {
        $visited = array();
        $recursionStack = array();

        foreach ($productMap as $nodeId => $node) {
            if (!isset($visited[$nodeId])) {
                if (self::hasCycleDFS($nodeId, $productMap, $visited, $recursionStack)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * DFS cycle detection
     */
    private static function hasCycleDFS($nodeId, $productMap, &$visited, &$recursionStack)
    {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;

        $node = isset($productMap[$nodeId]) ? $productMap[$nodeId] : null;
        if ($node && !empty($node->children)) {
            foreach ($node->children as $childId => $quantity) {
                if (!isset($visited[$childId])) {
                    if (self::hasCycleDFS($childId, $productMap, $visited, $recursionStack)) {
                        return true;
                    }
                } elseif (isset($recursionStack[$childId])) {
                    return true;
                }
            }
        }

        unset($recursionStack[$nodeId]);
        return false;
    }

    /**
     * Calculate processing order (bottom-up)
     */
    private static function calculateProcessingOrder($productMap)
    {
        $heights = array();
        $processed = array();

        foreach ($productMap as $nodeId => $node) {
            if (!isset($processed[$nodeId])) {
                self::calculateNodeHeight($nodeId, $productMap, $heights, $processed);
            }
        }

        // Sort by height (leaves first, then parents)
        asort($heights);
        
        self::logDebug("Computed node heights: " . json_encode($heights));
        return $heights;
    }

    /**
     * Calculate node height recursively
     */
    private static function calculateNodeHeight($nodeId, $productMap, &$heights, &$processed)
    {
        if (isset($heights[$nodeId])) {
            return $heights[$nodeId];
        }

        $node = isset($productMap[$nodeId]) ? $productMap[$nodeId] : null;
        if (!$node || empty($node->children)) {
            $heights[$nodeId] = 0;
            $processed[$nodeId] = true;
            return 0;
        }

        $maxHeight = 0;
        foreach ($node->children as $childId => $quantity) {
            $childHeight = self::calculateNodeHeight($childId, $productMap, $heights, $processed);
            $maxHeight = max($maxHeight, $childHeight);
        }

        $heights[$nodeId] = $maxHeight + 1;
        $processed[$nodeId] = true;
        
        return $heights[$nodeId];
    }

    /**
     * Process products in calculated order
     */
    private static function processProductsInOrder($processingOrder, $productMap, $user, $options)
    {
        $processedCount = 0;

        foreach ($processingOrder as $nodeId => $height) {
            // Skip leaf nodes (height 0)
            if ($height == 0) {
                self::logDebug("Skipping leaf node: $nodeId");
                continue;
            }

            if (!isset($productMap[$nodeId])) {
                dol_syslog("Node $nodeId not found in productMap", LOG_WARNING);
                continue;
            }

            $node = $productMap[$nodeId];
            if (empty($node->children)) {
                self::logDebug("No children for node $nodeId");
                continue;
            }

            $calcOption = self::getCalcOptionCached($nodeId);
            if ($calcOption === self::CALC_OPTION_MANUAL || $calcOption === self::CALC_OPTION_NOT_FOOD) {
                self::logDebug("Skipping node $nodeId due to calc option: " . $calcOption);
                continue;
            }

            self::logDebug("Processing node $nodeId with height $height");

            // Calculate and update nutrition for this node
            if (self::processNodeNutrition($node, $productMap, $user)) {
                $processedCount++;
            }
        }

        return $processedCount;
    }

    /**
     * Process nutrition for a single node
     */
    private static function processNodeNutrition($node, $productMap, $user)
    {
        try {
            // Calculate nutrition from children
            $calculatedNutrition = self::calculateNodeNutrition($node, $productMap);
            
            if ($calculatedNutrition === null) {
                return false;
            }

            // Update database record
            self::updateNutritionalRecord($node->id, $calculatedNutrition, $user);
            
            self::$processStats['products_processed']++;
            
            self::logDebug("Updated nutritional record for node " . $node->id);
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process nutrition for node " . $node->id . ": " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate nutrition values for a node based on children
     */
    private static function calculateNodeNutrition($node, $productMap)
    {
        // Initialize nutrient totals
        $totals = array(
            'energy_kcal' => 0,
            'energy_kj' => 0,
            'fat' => 0,
            'saturates' => 0,
            'carbohydrates' => 0,
            'sugars' => 0,
            'protein' => 0,
            'salt' => 0,
            'fiber' => 0
        );
        $totalWeightInGrams = 0;

        // Process each child
        foreach ($node->children as $childId => $qty) {
            if (!isset($productMap[$childId])) {
                dol_syslog("Child node $childId not found in productMap", LOG_WARNING);
                continue;
            }
            
            $child = $productMap[$childId];

            // Get child weight and convert to grams
            $baseWeight = $child->weight ? $child->weight : 1;
            $baseWeightInGrams = self::convertToGrams($baseWeight, $child->weight_units);
            $childTotalWeight = $qty * $baseWeightInGrams;
            $totalWeightInGrams += $childTotalWeight;

            self::logDebug("Child $childId: weight={$baseWeight}{$child->weight_units}, converted={$baseWeightInGrams}g, total={$childTotalWeight}g");

            // Get child nutritional data
            $childNutrition = self::fetchNutritionalData($childId);
            
            if ($childNutrition) {
                // Add weighted contributions
                foreach ($totals as $nutrient => $value) {
                    if (isset($childNutrition[$nutrient])) {
                        $totals[$nutrient] += ($childNutrition[$nutrient] / 100) * $childTotalWeight;
                    }
                }
                
                self::logDebug("Updated totals for node " . $node->id . " after child $childId");
            } else {
                self::logDebug("No nutritional data found for child $childId");
            }
        }

        // Normalize to per 100g basis
        if ($totalWeightInGrams > 0) {
            $normalized = array();
            foreach ($totals as $nutrient => $absoluteValue) {
                $normalized[$nutrient] = ($absoluteValue / $totalWeightInGrams) * 100;
            }
            
            self::logDebug("Normalized values for node " . $node->id . ": " . json_encode($normalized));
            return $normalized;
        } else {
            dol_syslog("Total weight is zero for node " . $node->id, LOG_WARNING);
            return null;
        }
    }

    /**
     * Fetch nutritional data for a product
     */
    private static function fetchNutritionalData($productId)
    {
        global $db;
        
        // Check cache first
        if (isset(self::$nutritionCache[$productId])) {
            self::$processStats['cache_hits']++;
            return self::$nutritionCache[$productId];
        }

        $sql = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, 
                       sugars, protein, salt, fiber
                FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional
                WHERE fk_product = " . (int)$productId . " LIMIT 1";
        
        $resql = $db->query($sql);
        
        if (!$resql) {
            throw new Exception("Failed to fetch nutrition for product $productId: " . $db->lasterror());
        }

        $nutrition = null;
        if ($obj = $db->fetch_object($resql)) {
            $nutrition = array(
                'energy_kcal' => (float)$obj->energy_kcal,
                'energy_kj' => (float)$obj->energy_kj,
                'fat' => (float)$obj->fat,
                'saturates' => (float)$obj->saturates,
                'carbohydrates' => (float)$obj->carbohydrates,
                'sugars' => (float)$obj->sugars,
                'protein' => (float)$obj->protein,
                'salt' => (float)$obj->salt,
                'fiber' => (float)$obj->fiber
            );
        }
        
        $db->free($resql);
        self::$processStats['database_operations']++;
        
        // Cache result
        if ($nutrition !== null) {
            self::$nutritionCache[$productId] = $nutrition;
        }
        
        return $nutrition;
    }

    /**
     * Get nutrition calculation option with caching
     */
    private static function getCalcOptionCached($productId)
    {
        if (isset(self::$calcOptionCache[$productId])) {
            self::$processStats['cache_hits']++;
            return self::$calcOptionCache[$productId];
        }

        global $db;

        $calcOption = null;
        $sql = "SELECT pe.kreap_calc_nut
                FROM " . MAIN_DB_PREFIX . "product_extrafields pe
                WHERE pe.fk_object = " . (int) $productId;
        $resql = $db->query($sql);

        if ($resql) {
            if ($obj = $db->fetch_object($resql)) {
                $calcOption = $obj->kreap_calc_nut !== null ? (int) $obj->kreap_calc_nut : null;
            }
            $db->free($resql);
        } else {
            self::addError("Failed to fetch calc option for product $productId: " . $db->lasterror());
        }

        self::$calcOptionCache[$productId] = $calcOption;
        self::$processStats['database_operations']++;

        return $calcOption;
    }

    /**
     * Update nutritional record in database
     */
    private static function updateNutritionalRecord($productId, $nutrition, $user)
    {
        global $db;

        // Check if record exists
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional 
                WHERE fk_product = " . (int)$productId;
        
        $resql = $db->query($sql);
        
        if (!$resql) {
            throw new Exception("Failed to check existing record for product $productId: " . $db->lasterror());
        }

        $recordExists = $db->num_rows($resql) > 0;
        $db->free($resql);

        // Prepare values with proper rounding
        $values = array();
        foreach ($nutrition as $field => $value) {
            $values[$field] = round($value, self::DISPLAY_PRECISION);
        }

        if ($recordExists) {
            // Update existing record
            $updateParts = array();
            foreach ($values as $field => $value) {
                $updateParts[] = "$field = " . $value;
            }
            
            $sql = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET " . 
                   implode(', ', $updateParts) . 
                   " WHERE fk_product = " . (int)$productId;
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to update nutrition for product $productId: " . $db->lasterror());
            }
            
            self::$processStats['records_updated']++;
            self::logDebug("Updated nutritional record for product $productId");
            
        } else {
            // Insert new record
            $fields = array_keys($values);
            $valuesList = array_values($values);
            
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional 
                    (fk_product, " . implode(', ', $fields) . ")
                    VALUES (" . (int)$productId . ", " . implode(', ', $valuesList) . ")";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to insert nutrition for product $productId: " . $db->lasterror());
            }
            
            self::$processStats['records_inserted']++;
            self::logDebug("Inserted nutritional record for product $productId");
        }
        
        self::$processStats['database_operations']++;
    }

    /**
     * Convert weight to grams with enhanced unit support and caching
     */
    private static function convertToGrams($weight, $unit)
    {
        global $conf;

        $cacheKey = $weight . '_' . $unit;
        if (isset(self::$weightConversionCache[$cacheKey])) {
            return self::$weightConversionCache[$cacheKey];
        }

        $unit = is_string($unit) ? strtolower(trim($unit)) : $unit;
        $result = $weight; // Default fallback

        if (is_numeric($unit)) {
            // Handle Dolibarr's numeric units
            $unitNum = (int)$unit;
            $shouldResolveId = self::isUnitStoredAsId();
            if (!$shouldResolveId && !in_array($unitNum, array(-9, -6, -3, 0, 3, 98, 99), true)) {
                $shouldResolveId = true;
            }
            if ($shouldResolveId) {
                $unitMapping = self::getUnitMappingCached($conf);
                if (isset($unitMapping[$unitNum])) {
                    $unitNum = (int)$unitMapping[$unitNum];
                }
            }
            switch ($unitNum) {
                case self::UNIT_NUMERIC_OUNCES:
                    $result = $weight / 35.274;
                    break;
                case self::UNIT_NUMERIC_POUNDS:
                    $result = $weight / 2.20462;
                    break;
                default:
                    // Dolibarr power-of-10 units
                    $result = $weight * pow(10, $unitNum) * 1000;
                    break;
            }
        } else {
            // Handle string units
            switch ($unit) {
                case self::UNIT_KILOGRAMS:
                case 'kg':
                    $result = $weight * 1000;
                    break;
                case self::UNIT_GRAMS:
                case 'g':
                    $result = $weight;
                    break;
                case self::UNIT_MILLIGRAMS:
                case 'mg':
                    $result = $weight / 1000;
                    break;
                case self::UNIT_POUNDS:
                case 'lbs':
                case 'lb':
                    $result = $weight / 2.20462;
                    break;
                case self::UNIT_OUNCES:
                case 'oz':
                    $result = $weight * 28.3495;
                    break;
                default:
                    // Unknown unit, log warning and assume grams
                    dol_syslog("Unknown weight unit '$unit', assuming grams", LOG_WARNING);
                    $result = $weight;
                    break;
            }
        }

        // Cache the result
        self::$weightConversionCache[$cacheKey] = $result;
        
        return $result;
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
     * Get unit mapping (id => scale) with caching
     */
    private static function getUnitMappingCached($conf)
    {
        if (!empty(self::$unitMappingCache)) {
            return self::$unitMappingCache;
        }

        global $db;
        $unitMapping = array();

        $weightLabel = !empty($conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL) 
            ? $conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL 
            : 'weight';

        $sql = "SELECT rowid, scale 
                FROM " . MAIN_DB_PREFIX . "c_units 
                WHERE unit_type = '" . $db->escape($weightLabel) . "' 
                    AND active = 1";

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $unitMapping[(int)$obj->rowid] = is_numeric($obj->scale) ? (int)$obj->scale : $obj->scale;
            }
            $db->free($resql);
        }

        self::$unitMappingCache = $unitMapping;
        self::$processStats['database_operations']++;

        return $unitMapping;
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
     * Get inherited nutrition from children
     */
    private static function getInheritedNutrition($productId)
    {
        $hierarchy = self::buildProductHierarchy($productId);
        $node = isset($hierarchy[$productId]) ? $hierarchy[$productId] : null;
        
        if (!$node || empty($node->children)) {
            return array();
        }

        return self::calculateNodeNutrition($node, $hierarchy);
    }

    /**
     * Merge two nutrition arrays
     */
    private static function mergeNutrition($nutrition1, $nutrition2)
    {
        if ($nutrition1 === null) {
            return $nutrition2;
        }
        if ($nutrition2 === null) {
            return $nutrition1;
        }
        
        $merged = $nutrition1;
        foreach ($nutrition2 as $nutrient => $value) {
            if (!isset($merged[$nutrient])) {
                $merged[$nutrient] = $value;
            }
        }
        
        return $merged;
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
            'records_updated' => self::$processStats['records_updated'],
            'records_inserted' => self::$processStats['records_inserted'],
            'database_operations' => self::$processStats['database_operations'],
            'cache_hits' => self::$processStats['cache_hits']
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
        dol_syslog("KreaProductsNutrientUpdater Error: $error", LOG_ERR);
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
        self::$nutritionCache = array();
        self::$weightConversionCache = array();
        self::$unitMappingCache = array();
    }

    /**
     * Get processing statistics
     */
    public static function getProcessingStats()
    {
        return self::$processStats;
    }

    // Backward compatibility methods
    private static function buildProductMap($startId)
    {
        return self::buildProductHierarchy($startId);
    }

    private static function computeHeights($map)
    {
        return self::calculateProcessingOrder($map);
    }

    private static function getHeight($nodeId, $map, &$heights)
    {
        $processed = array();
        return self::calculateNodeHeight($nodeId, $map, $heights, $processed);
    }
}

/**
 * Enhanced ProductNutritionNode class for better nutrition management
 */
class ProductNutritionNode
{
    public $id;
    public $label;
    public $weight;
    public $weight_units;
    public $children = array();
    private $totalQuantity = 0.0;

    public function __construct($id, $label = '', $weight = 0, $weight_units = 'g')
    {
        $this->id = (int)$id;
        $this->label = $label;
        $this->weight = (float)$weight;
        $this->weight_units = ($weight_units === null || $weight_units === '') ? 0 : $weight_units;
    }

    /**
     * Add a child product with quantity
     */
    public function addChild($childId, $quantity)
    {
        if (!isset($this->children[$childId])) {
            $this->children[$childId] = 0;
        }
        $this->children[$childId] += (float)$quantity;
        $this->totalQuantity += (float)$quantity;
    }

    /**
     * Set a child product quantity (override)
     */
    public function setChildQuantity($childId, $quantity)
    {
        $quantity = (float)$quantity;
        if ($quantity <= 0) {
            return;
        }

        if (isset($this->children[$childId])) {
            $this->totalQuantity -= $this->children[$childId];
        }

        $this->children[$childId] = $quantity;
        $this->totalQuantity += $quantity;
    }

    /**
     * Check if node has children
     */
    public function hasChildren()
    {
        return !empty($this->children);
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
class LocalProductNut extends ProductNutritionNode
{
    public function __construct($id, $label, $weight = 0, $weight_units = 'g')
    {
        parent::__construct($id, $label, $weight, $weight_units);
    }
}
