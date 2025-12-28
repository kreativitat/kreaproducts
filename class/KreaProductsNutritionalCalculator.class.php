<?php

/**
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 * Enhanced KreaProductsNutritionalCalculator Class
 *
 * This class builds a father→child map for a given product using BFS,
 * then computes and displays nutritional values (kcal, kJ, fat, etc.) for all
 * subproducts (including nested levels) using each ingredient's actual weight.
 * 
 * Enhanced with comprehensive error handling, input validation, performance optimization,
 * security improvements, and proper transaction management for enterprise-level use.
 */
class KreaProductsNutritionalCalculator
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
    
    // Processing limits
    const MAX_HIERARCHY_DEPTH = 50;
    const MAX_PRODUCTS_PER_BATCH = 5000;
    const BATCH_SIZE = 50;
    
    // Precision for nutritional values
    const NUTRITION_PRECISION = 4;
    const DISPLAY_PRECISION = 2;
    
    // Default values
    const DEFAULT_WEIGHT = 1.0;
    const DEFAULT_UNIT = 'g';

    // Nutrition calculation options
    const CALC_OPTION_MANUAL = 0;
    const CALC_OPTION_AUTO = 1;
    const CALC_OPTION_NOT_FOOD = 2;
    
    // Cache and state management
    private static $productMap = array();
    private static $productCache = array();
    private static $nutritionCache = array();
    private static $unitMappingCache = array();
    private static $weightConversionCache = array();
    private static $calculatedNutritionCache = array();
    private static $calcOptionCache = array();
    private static $associationCache = array();
    
    // Error handling
    private static $errors = array();
    private static $warnings = array();
    private static $lastError = null;
    
    // Performance tracking
    private static $processStats = array();

    /**
     * Main entry point for computing and displaying nutritional information
     *
     * @param int $productId The parent product rowid
     * @return bool True on success, false on failure
     */
    public static function computeAndDisplayNutritional($productId)
    {
        global $langs, $conf, $db;

        try {
            self::initializeProcessing($productId);
            
            // Enhanced input validation
            if (!self::validateInputs($productId)) {
                return false;
            }

            // Build enhanced BFS map with error handling
            if (!self::buildEnhancedMapBFS($productId)) {
                return false;
            }

            // Get the parent's local product object with validation
            $lp = self::getLocalProduct($productId);
            if (!$lp) {
                self::addError("Could not find local product map for product #$productId");
                self::displayError("Error: Could not find local product map for #$productId");
                return false;
            }

            // Gather all subproducts with enhanced error handling
            $subList = array();
            if (!self::gatherSubProductsEnhanced($productId, $subList)) {
                return false;
            }

            // Display the nutritional table
            self::displayNutritionalTable($productId, $subList, $langs);
            
            self::logProcessingStats($productId, count($subList));
            return true;
            
        } catch (Exception $e) {
            self::addError("Processing failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            self::displayError("An error occurred while processing nutritional data.");
            return false;
        }
    }

    /**
     * Enhanced method to calculate and save nutritional values
     *
     * @param int $productId The product ID
     * @param User $user The current user object
     * @return int Result of the update/create operation (<0 on error, >0 on success)
     */
    public static function saveCalculation($productId, $user)
    {
        global $db, $conf;

        try {
            self::initializeProcessing($productId);
            
            // Enhanced input validation
            if (!self::validateInputs($productId) || !self::validateUser($user)) {
                return -1;
            }

            // Start database transaction
            $db->begin();
            
            try {
                // Build the product association map
                if (!self::buildEnhancedMapBFS($productId)) {
                    throw new Exception("Failed to build product hierarchy");
                }

                // Ensure the product exists in the map
                $lp = self::getLocalProduct($productId);
                if (!$lp) {
                    throw new Exception("Product #$productId not found in hierarchy map");
                }

                // Gather all subproducts with enhanced validation
                $subList = array();
                if (!self::gatherSubProductsEnhanced($productId, $subList)) {
                    throw new Exception("Failed to gather subproducts");
                }

                // Calculate nutritional totals
                $calculationResult = self::calculateNutritionalTotals($subList);
                if (!$calculationResult['success']) {
                    throw new Exception("Failed to calculate nutritional totals: " . $calculationResult['error']);
                }

                // Save the normalized nutritional values
                $result = self::saveNutritionalRecord($productId, $calculationResult['normalized'], $user);
                
                if ($result <= 0) {
                    throw new Exception("Failed to save nutritional record");
                }

                $db->commit();
                
                self::logProcessingStats($productId, count($subList));
                return $result;
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            self::addError("Save calculation failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Initialize processing state
     */
    private static function initializeProcessing($productId)
    {
        self::clearErrors();
        self::$calculatedNutritionCache = array();
        self::$calcOptionCache = array();
        self::$associationCache = array();
        self::$processStats = array(
            'start_time' => microtime(true),
            'root_product' => $productId,
            'database_operations' => 0,
            'cache_hits' => 0,
            'products_processed' => 0
        );
        
        dol_syslog(__METHOD__ . " Starting nutritional calculation for product: $productId", LOG_DEBUG);
    }

    /**
     * Enhanced input validation
     */
    private static function validateInputs($productId)
    {
        if (!is_numeric($productId) || $productId <= 0) {
            self::addError("Invalid product ID: must be positive integer");
            return false;
        }

        if (!self::productExists($productId)) {
            self::addError("Product $productId does not exist");
            return false;
        }

        return true;
    }

    /**
     * Validate user object
     */
    private static function validateUser($user)
    {
        if (!is_object($user) || empty($user->id)) {
            self::addError("Invalid user object provided");
            return false;
        }

        return true;
    }

    /**
     * Enhanced BFS map building with comprehensive error handling
     */
    private static function buildEnhancedMapBFS($startId)
    {
        global $db, $conf;
        
        try {
            self::$productMap = array();
            self::$associationCache = array();
            $queue = array($startId);
            $seen = array($startId => true);
            $depth = 0;

            // Enhanced SQL with better error handling
            $sqlAssociation = "SELECT pa.fk_product_pere as father,
                                      pa.fk_product_fils as child,
                                      pa.qty as qty,
                                      pf.label as fatherLabel,
                                      pf.weight as fatherWeight,
                                      pf.weight_units as fatherWeightUnits,
                                      pc.label as childLabel,
                                      pc.weight as childWeight,
                                      pc.weight_units as childWeightUnits,
                                      'association' as source
                               FROM " . MAIN_DB_PREFIX . "product_association pa
                               JOIN " . MAIN_DB_PREFIX . "product pf ON (pf.rowid = pa.fk_product_pere)
                               JOIN " . MAIN_DB_PREFIX . "product pc ON (pc.rowid = pa.fk_product_fils)
                               WHERE pa.fk_product_pere = %d OR pa.fk_product_fils = %d";

            $sqlBom = "SELECT b.fk_product as father,
                              bl.fk_product as child,
                              bl.qty as qty,
                              pf.label as fatherLabel,
                              pf.weight as fatherWeight,
                              pf.weight_units as fatherWeightUnits,
                              pc.label as childLabel,
                              pc.weight as childWeight,
                              pc.weight_units as childWeightUnits,
                              'bom' as source
                       FROM " . MAIN_DB_PREFIX . "bom_bom b
                       JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                       JOIN " . MAIN_DB_PREFIX . "product pf ON (pf.rowid = b.fk_product)
                       JOIN " . MAIN_DB_PREFIX . "product pc ON (pc.rowid = bl.fk_product)
                       WHERE b.bomtype IN (0,1)
                           AND b.status = 1
                           AND (b.fk_product = %d OR bl.fk_product = %d)";

            while (!empty($queue) && $depth < self::MAX_HIERARCHY_DEPTH) {
                $currentLevel = $queue;
                $queue = array();
                
                if (count($currentLevel) > self::MAX_PRODUCTS_PER_BATCH) {
                    throw new Exception("Hierarchy level exceeds maximum product limit");
                }

                foreach ($currentLevel as $current) {
                    $queries = array();
                    if (!empty($conf->bom->enabled)) {
                        $queries[] = $sqlBom;
                    }
                    $queries[] = $sqlAssociation;

                    foreach ($queries as $sqlTemplate) {
                        $sql = sprintf($sqlTemplate, (int)$current, (int)$current);
                        $resql = $db->query($sql);
                        
                        if (!$resql) {
                            throw new Exception("Database error: " . $db->lasterror());
                        }

                        while ($obj = $db->fetch_object($resql)) {
                            // Enhanced validation
                            if (!self::validateAssociationData($obj)) {
                                continue;
                            }

                            // Create father product node
                            if (!isset(self::$productMap[$obj->father])) {
                                self::$productMap[$obj->father] = new EnhancedLocalProduct(
                                    $obj->father,
                                    $obj->fatherLabel,
                                    $obj->fatherWeight,
                                    $obj->fatherWeightUnits
                                );
                            }
                            
                            // Create child product node
                            if (!isset(self::$productMap[$obj->child])) {
                                self::$productMap[$obj->child] = new EnhancedLocalProduct(
                                    $obj->child,
                                    $obj->childLabel,
                                    $obj->childWeight,
                                    $obj->childWeightUnits
                                );
                            }
                            
                            // Set the father→child relationship with quantity validation
                            $associationKey = $obj->father . ':' . $obj->child;
                            $quantity = (float)$obj->qty;
                            $source = isset($obj->source) ? $obj->source : 'association';
                            if ($quantity > 0) {
                                if (!isset(self::$associationCache[$associationKey])) {
                                    self::$productMap[$obj->father]->setChildQuantity($obj->child, $quantity);
                                    self::$associationCache[$associationKey] = array('source' => $source);
                                } elseif ($source === 'bom' && self::$associationCache[$associationKey]['source'] !== 'bom') {
                                    self::$productMap[$obj->father]->setChildQuantity($obj->child, $quantity);
                                    self::$associationCache[$associationKey]['source'] = 'bom';
                                }
                            } else {
                                self::addWarning("Invalid quantity ($quantity) for association {$obj->father} → {$obj->child}");
                            }

                            // Queue management with cycle prevention
                            if (!isset($seen[$obj->father])) {
                                $seen[$obj->father] = true;
                                $queue[] = $obj->father;
                            }
                            if (!isset($seen[$obj->child])) {
                                $seen[$obj->child] = true;
                                $queue[] = $obj->child;
                            }
                        }
                        
                        $db->free($resql);
                        self::$processStats['database_operations']++;
                    }
                }
                
                $depth++;
            }

            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                throw new Exception("Hierarchy depth exceeds maximum limit");
            }

            // Ensure the root product is present in the map even if it has no associations.
            if (!isset(self::$productMap[$startId])) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $prod = new Product($db);
                if ($prod->fetch($startId) > 0) {
                    self::$productMap[$startId] = new EnhancedLocalProduct(
                        $startId,
                        $prod->label,
                        $prod->weight,
                        $prod->weight_units
                    );
                }
            }

            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to build product map: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate association data
     */
    private static function validateAssociationData($obj)
    {
        if (empty($obj->father) || empty($obj->child)) {
            self::addWarning("Invalid association data: missing father or child ID");
            return false;
        }

        if ($obj->qty <= 0) {
            self::addWarning("Invalid quantity in association: " . $obj->qty);
            return false;
        }

        return true;
    }

    /**
     * Enhanced subproduct gathering with validation
     */
    private static function gatherSubProductsEnhanced($parentId, &$results)
    {
        global $db, $conf;

        try {
            $source = array();

            if (!empty($conf->bom->enabled)) {
                $sql = "SELECT bl.fk_product AS childId, 
                               bl.qty,
                               p.label,
                               p.weight,
                               p.weight_units
                        FROM " . MAIN_DB_PREFIX . "bom_bom b
                        JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                        LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe 
                            ON bl.fk_product = pe.fk_object
                        LEFT JOIN " . MAIN_DB_PREFIX . "product p
                            ON bl.fk_product = p.rowid
                        WHERE b.fk_product = " . (int)$parentId . " 
                            AND b.bomtype IN (0,1)
                            AND b.status = 1
                            AND (pe.kreap_calc_nut IS NULL OR pe.kreap_calc_nut <> '2')
                            AND p.rowid IS NOT NULL";

                $resql = $db->query($sql);
                
                if (!$resql) {
                    throw new Exception("Database error: " . $db->lasterror());
                }

                while ($obj = $db->fetch_object($resql)) {
                    $childId = (int)$obj->childId;
                    $quantity = (float)$obj->qty;
                    
                    // Enhanced validation
                    if ($childId <= 0 || $quantity <= 0) {
                        self::addWarning("Invalid child data: ID=$childId, Qty=$quantity");
                        continue;
                    }

                    // BOM overrides associations
                    $results[$childId] = $quantity;
                    $source[$childId] = 'bom';
                }
                
                $db->free($resql);
                self::$processStats['database_operations']++;
            }

            $sql = "SELECT pa.fk_product_fils AS childId, 
                           pa.qty,
                           p.label,
                           p.weight,
                           p.weight_units
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                    LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe 
                        ON pa.fk_product_fils = pe.fk_object
                    LEFT JOIN " . MAIN_DB_PREFIX . "product p
                        ON pa.fk_product_fils = p.rowid
                    WHERE pa.fk_product_pere = " . (int)$parentId . " 
                        AND (pe.kreap_calc_nut IS NULL OR pe.kreap_calc_nut <> '2')
                        AND p.rowid IS NOT NULL
                    ORDER BY pa.rang ASC";

            $resql = $db->query($sql);
            
            if (!$resql) {
                throw new Exception("Database error: " . $db->lasterror());
            }

            while ($obj = $db->fetch_object($resql)) {
                $childId = (int)$obj->childId;
                $quantity = (float)$obj->qty;
                
                // Enhanced validation
                if ($childId <= 0 || $quantity <= 0) {
                    self::addWarning("Invalid child data: ID=$childId, Qty=$quantity");
                    continue;
                }

                if (isset($source[$childId]) && $source[$childId] === 'bom') {
                    continue;
                }

                // Aggregate quantities if multiple entries exist
                if (!isset($results[$childId])) {
                    $results[$childId] = 0;
                }
                $results[$childId] += $quantity;
                $source[$childId] = 'association';
            }
            
            $db->free($resql);
            self::$processStats['database_operations']++;
            
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to gather subproducts: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Display enhanced nutritional table
     */
    private static function displayNutritionalTable($productId, $subList, $langs)
    {
        global $db;

        // Display table header and keep an empty row if there are no subproducts
        self::displayTableHeader($langs);

        // Calculate and display nutritional data
        $calculationResult = self::calculateNutritionalTotals($subList);
        
        if (!$calculationResult['success']) {
            self::displayError("Error calculating nutritional totals: " . $calculationResult['error']);
            return;
        }

        if (empty($subList)) {
            print '<tr><td colspan="14" class="opacitymedium">' . $langs->trans("NoSubproductsFound") . '</td></tr>';
        } else {
            // Display individual subproduct rows
            self::displaySubproductRows($subList, $calculationResult['details']);
        }

        // Display totals and normalized values
        self::displayTotalRows($calculationResult, $langs);

        print '</table>';
    }

    /**
     * Display table header
     */
    private static function displayTableHeader($langs)
    {
        print '<table class="noborder" width="100%">';
        print '<tr class="liste_titre">';
        print '<td width="5%">' . $langs->trans("KreaProductsTableProductRef") . '</td>';
        print '<td width="25%">' . $langs->trans("KreaProductsTableProductName") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableProductWeight") . '</td>';
        print '<td width="10%" style="text-align: right;">' . $langs->trans("KreaProductsTableProductQuantity") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableQuantityWeight") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableEnergy_kcal") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableEnergy_kj") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableFat") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSaturates") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableCarbohydrates") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSugars") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableProtein") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSalt") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableFiber") . '</td>';
        print '</tr>';
    }

    /**
     * Calculate comprehensive nutritional totals
     */
    private static function calculateNutritionalTotals($subList)
    {
        global $db, $conf;

        try {
            // Initialize totals
            $totalWeightInGrams = 0;
            $totals = array(
                'energy_kcal' => 0, 'energy_kj' => 0, 'fat' => 0, 'saturates' => 0,
                'carbohydrates' => 0, 'sugars' => 0, 'protein' => 0, 'salt' => 0, 'fiber' => 0
            );
            $details = array();

            // Get unit mapping with caching
            $unitMapping = self::getUnitMappingCached($conf);

            // Process each subproduct
            foreach ($subList as $childId => $finalQty) {
                $childLp = self::getLocalProduct($childId);
                if (!$childLp) {
                    self::addWarning("Local product not found for child ID: $childId");
                    continue;
                }

                // Get weight data with validation
                $rawWeight = $childLp->weight ? $childLp->weight : self::DEFAULT_WEIGHT;
                $weightUnit = $childLp->weight_units !== null ? $childLp->weight_units : 0;
                
                // Get unit label and scale for display/conversion
                $unitInfo = self::resolveUnitInfo($weightUnit, $unitMapping);
                $weightUnitShortLabel = $unitInfo['label'];

                // Convert weight to grams
                $baseWeightInGrams = self::convertToGramsEnhanced($rawWeight, $weightUnit, $unitInfo['scale']);
                $subWeightInGrams = $finalQty * $baseWeightInGrams;

                // Resolve nutritional data (recursive if child has its own children)
                $nutritionData = self::resolveNutritionData($childId);

                // Calculate nutritional contributions
                $contributions = self::calculateNutritionalContributions(
                    $nutritionData, 
                    $subWeightInGrams
                );

                // Store details for display
                $details[$childId] = array(
                    'product' => $childLp,
                    'quantity' => $finalQty,
                    'raw_weight' => $rawWeight,
                    'weight_unit' => $weightUnitShortLabel,
                    'total_weight' => $subWeightInGrams,
                    'contributions' => $contributions
                );

                // Accumulate totals
                $totalWeightInGrams += $subWeightInGrams;
                foreach ($totals as $nutrient => $value) {
                    $totals[$nutrient] += $contributions[$nutrient];
                }
            }

            // Normalize to per 100g basis
            $normalized = array();
            if ($totalWeightInGrams > 0) {
                foreach ($totals as $nutrient => $absoluteValue) {
                    $normalized[$nutrient] = round(
                        ($absoluteValue / $totalWeightInGrams) * 100, 
                        self::DISPLAY_PRECISION
                    );
                }
            } else {
                foreach ($totals as $nutrient => $value) {
                    $normalized[$nutrient] = 0;
                }
            }

            return array(
                'success' => true,
                'totals' => $totals,
                'normalized' => $normalized,
                'total_weight' => $totalWeightInGrams,
                'details' => $details
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Calculate nutritional contributions for a subproduct
     */
    private static function calculateNutritionalContributions($nutritionData, $weightInGrams)
    {
        $contributions = array(
            'energy_kcal' => 0, 'energy_kj' => 0, 'fat' => 0, 'saturates' => 0,
            'carbohydrates' => 0, 'sugars' => 0, 'protein' => 0, 'salt' => 0, 'fiber' => 0
        );

        if ($nutritionData) {
            foreach ($contributions as $nutrient => $value) {
                if (isset($nutritionData[$nutrient])) {
                    $contributions[$nutrient] = ($nutritionData[$nutrient] / 100) * $weightInGrams;
                }
            }
        }

        return $contributions;
    }

    /**
     * Resolve nutritional data for a product, computing from children when needed
     */
    private static function resolveNutritionData($productId, $depth = 0, $path = array())
    {
        if (isset(self::$calculatedNutritionCache[$productId])) {
            self::$processStats['cache_hits']++;
            return self::$calculatedNutritionCache[$productId];
        }

        if ($depth > self::MAX_HIERARCHY_DEPTH) {
            self::addWarning("Max hierarchy depth exceeded while resolving nutrition for product $productId");
            return null;
        }

        if (isset($path[$productId])) {
            self::addWarning("Circular dependency detected while resolving nutrition for product $productId");
            return null;
        }

        if (self::isNotFoodProduct($productId)) {
            self::$calculatedNutritionCache[$productId] = null;
            return null;
        }

        $path[$productId] = true;

        $product = self::getLocalProduct($productId);
        if ($product && $product->hasChildren()) {
            $calculated = self::calculateNutritionFromChildren($product, $depth + 1, $path);
            self::$calculatedNutritionCache[$productId] = $calculated;
            return $calculated;
        }

        $nutrition = self::fetchNutritionalDataCached($productId);
        self::$calculatedNutritionCache[$productId] = $nutrition;
        return $nutrition;
    }

    /**
     * Calculate nutrition from a product's children (recursive)
     */
    private static function calculateNutritionFromChildren($product, $depth, $path)
    {
        global $conf;

        $totals = array(
            'energy_kcal' => 0, 'energy_kj' => 0, 'fat' => 0, 'saturates' => 0,
            'carbohydrates' => 0, 'sugars' => 0, 'protein' => 0, 'salt' => 0, 'fiber' => 0
        );
        $totalWeightInGrams = 0;
        $unitMapping = self::getUnitMappingCached($conf);

        foreach ($product->children as $childId => $qty) {
            if (self::isNotFoodProduct($childId)) {
                continue;
            }

            $childProduct = self::getLocalProduct($childId);
            if (!$childProduct) {
                self::addWarning("Local product not found for child ID: $childId");
                continue;
            }

            $rawWeight = $childProduct->weight ? $childProduct->weight : self::DEFAULT_WEIGHT;
            $weightUnit = $childProduct->weight_units !== null ? $childProduct->weight_units : 0;
            $unitInfo = self::resolveUnitInfo($weightUnit, $unitMapping);
            $baseWeightInGrams = self::convertToGramsEnhanced($rawWeight, $weightUnit, $unitInfo['scale']);
            $childTotalWeight = $qty * $baseWeightInGrams;
            $totalWeightInGrams += $childTotalWeight;

            $childNutrition = self::resolveNutritionData($childId, $depth, $path);
            if ($childNutrition) {
                foreach ($totals as $nutrient => $value) {
                    if (isset($childNutrition[$nutrient])) {
                        $totals[$nutrient] += ($childNutrition[$nutrient] / 100) * $childTotalWeight;
                    }
                }
            }
        }

        if ($totalWeightInGrams <= 0) {
            return null;
        }

        $normalized = array();
        foreach ($totals as $nutrient => $absoluteValue) {
            $normalized[$nutrient] = round(
                ($absoluteValue / $totalWeightInGrams) * 100,
                self::NUTRITION_PRECISION
            );
        }

        return $normalized;
    }

    /**
     * Display subproduct rows
     */
    private static function displaySubproductRows($subList, $details)
    {
        global $db;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        foreach ($subList as $childId => $finalQty) {
            if (!isset($details[$childId])) {
                continue;
            }

            $detail = $details[$childId];
            $childLp = $detail['product'];

            // Get clickable reference link
            $subProdObj = new Product($db);
            $res = $subProdObj->fetch($childId);
            $linkRef = ($res > 0) ? $subProdObj->getNomUrl(1) : 'N/A';
            $nameStr = htmlspecialchars($childLp->label, ENT_QUOTES);

            // Display weight information
            $displayWeight = htmlspecialchars(
                $detail['raw_weight'] . ' ' . $detail['weight_unit'], 
                ENT_QUOTES
            );

            // Display the row
            print '<tr style="font-style: italic;">';
            print '<td>' . $linkRef . '</td>';
            print '<td>' . $nameStr . '</td>';
            print '<td style="text-align: right;">' . $displayWeight . '</td>';
            print '<td style="text-align: right;">x ' . number_format($detail['quantity'], 3, '.', '') . '</td>';
            print '<td style="text-align: right;">' . round($detail['total_weight'], self::DISPLAY_PRECISION) . '</td>';
            
            // Display nutritional contributions
            $contrib = $detail['contributions'];
            print '<td style="text-align: right;">' . round($contrib['energy_kcal'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['energy_kj'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['fat'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['saturates'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['carbohydrates'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['sugars'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['protein'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['salt'], self::DISPLAY_PRECISION) . '</td>';
            print '<td style="text-align: right;">' . round($contrib['fiber'], self::DISPLAY_PRECISION) . '</td>';
            print '</tr>';
        }
    }

    /**
     * Display total rows
     */
    private static function displayTotalRows($calculationResult, $langs)
    {
        $totals = $calculationResult['totals'];
        $normalized = $calculationResult['normalized'];
        $totalWeight = $calculationResult['total_weight'];

        // Display absolute totals
        print '<tr style="font-style: italic;">';
        print '<td colspan="4">' . $langs->trans("EstimatedTotalsForTheProduct") . '</td>';
        print '<td style="text-align: right;">' . round($totalWeight, self::DISPLAY_PRECISION) . '</td>';
        foreach ($totals as $nutrient => $value) {
            print '<td style="text-align: right;">' . round($value, self::DISPLAY_PRECISION) . '</td>';
        }
        print '</tr>';

        // Display normalized values (per 100g)
        if ($totalWeight > 0) {
            print '<tr style="font-weight:bold;">';
            print '<td colspan="4">' . $langs->trans("AverageValuesPer100g") . '</td>';
            print '<td>&nbsp;</td>';
            foreach ($normalized as $nutrient => $value) {
                print '<td style="text-align: right;">' . $value . '</td>';
            }
            print '</tr>';
        }
    }

    /**
     * Save nutritional record with enhanced error handling
     */
    private static function saveNutritionalRecord($productId, $normalized, $user)
    {
        global $db;

        try {
            require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/nutritional.class.php';

            // Check if record exists
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional 
                    WHERE fk_product = " . (int)$productId;
            
            $resql = $db->query($sql);
            if (!$resql) {
                throw new Exception("Database error checking existing record: " . $db->lasterror());
            }

            $recordExists = $db->num_rows($resql) > 0;
            $nutritional = new Nutritional($db);

            if ($recordExists) {
                // Update existing record
                $obj = $db->fetch_object($resql);
                $nutritional->fetch($obj->rowid);
            } else {
                // Create new record
                $nutritional->fk_product = $productId;
            }
            
            $db->free($resql);

            // Set normalized nutritional values
            foreach ($normalized as $field => $value) {
                $nutritional->$field = $value;
            }

            // Save the record
            if (!empty($nutritional->id)) {
                $result = $nutritional->update($user, 0);
                if ($result > 0) {
                    self::$processStats['records_updated']++;
                }
            } else {
                $result = $nutritional->create($user, 0);
                if ($result > 0) {
                    self::$processStats['records_inserted']++;
                }
            }

            return $result;
            
        } catch (Exception $e) {
            throw new Exception("Failed to save nutritional record: " . $e->getMessage());
        }
    }

    /**
     * Enhanced weight conversion with caching
     */
    private static function convertToGramsEnhanced($weight, $unit, $resolvedScale = null)
    {
        $cacheKey = $weight . '_' . $unit . '_' . ($resolvedScale === null ? 'n' : $resolvedScale);
        
        if (isset(self::$weightConversionCache[$cacheKey])) {
            self::$processStats['cache_hits']++;
            return self::$weightConversionCache[$cacheKey];
        }

        $result = $weight; // Default fallback

        if (is_numeric($unit)) {
            $unitNum = ($resolvedScale !== null) ? (int)$resolvedScale : (int)$unit;
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
            $unit = strtolower(trim($unit));
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
                    self::addWarning("Unknown weight unit '$unit', assuming grams");
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
     * Resolve unit label and scale for conversion/display
     */
    private static function resolveUnitInfo($unit, $unitMapping)
    {
        $info = array(
            'label' => self::DEFAULT_UNIT,
            'scale' => null
        );

        if (is_numeric($unit)) {
            $unitNum = (int)$unit;

            if (self::isUnitStoredAsId() && isset($unitMapping['id'][$unitNum])) {
                $info['label'] = $unitMapping['id'][$unitNum]['label'];
                $info['scale'] = $unitMapping['id'][$unitNum]['scale'];
                return $info;
            }

            if (isset($unitMapping['scale'][$unitNum])) {
                $info['label'] = $unitMapping['scale'][$unitNum];
                $info['scale'] = $unitNum;
                return $info;
            }

            if (isset($unitMapping['id'][$unitNum])) {
                $info['label'] = $unitMapping['id'][$unitNum]['label'];
                $info['scale'] = $unitMapping['id'][$unitNum]['scale'];
                return $info;
            }
        } else {
            $unitStr = strtolower(trim($unit));
            if ($unitStr !== '') {
                $info['label'] = $unitStr;
            }
        }

        return $info;
    }

    /**
     * Get unit mapping with caching
     */
    private static function getUnitMappingCached($conf)
    {
        if (!empty(self::$unitMappingCache)) {
            self::$processStats['cache_hits']++;
            return self::$unitMappingCache;
        }

        global $db;
        
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
        } else {
            self::addWarning("Failed to fetch unit mapping: " . $db->lasterror());
        }

        self::$unitMappingCache = $unitMapping;
        self::$processStats['database_operations']++;
        
        return $unitMapping;
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
                WHERE pe.fk_object = " . (int)$productId;
        $resql = $db->query($sql);

        if ($resql) {
            if ($obj = $db->fetch_object($resql)) {
                $calcOption = $obj->kreap_calc_nut !== null ? (int)$obj->kreap_calc_nut : null;
            }
            $db->free($resql);
        } else {
            self::addWarning("Failed to fetch calc option for product $productId: " . $db->lasterror());
        }

        self::$calcOptionCache[$productId] = $calcOption;
        self::$processStats['database_operations']++;

        return $calcOption;
    }

    /**
     * Check if product is marked as not food
     */
    private static function isNotFoodProduct($productId)
    {
        return self::getCalcOptionCached($productId) === self::CALC_OPTION_NOT_FOOD;
    }

    /**
     * Fetch nutritional data with caching
     */
    private static function fetchNutritionalDataCached($productId)
    {
        if (isset(self::$nutritionCache[$productId])) {
            self::$processStats['cache_hits']++;
            return self::$nutritionCache[$productId];
        }

        global $db;
        
        $sql = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, 
                       sugars, protein, salt, fiber
                FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional
                WHERE fk_product = " . (int)$productId . " LIMIT 1";
        
        $resql = $db->query($sql);
        $nutritionData = null;
        
        if ($resql) {
            if ($obj = $db->fetch_object($resql)) {
                $nutritionData = array(
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
        } else {
            self::addWarning("Failed to fetch nutrition for product $productId: " . $db->lasterror());
        }

        // Cache the result (even if null)
        self::$nutritionCache[$productId] = $nutritionData;
        self::$processStats['database_operations']++;
        
        return $nutritionData;
    }

    /**
     * Get local product with validation
     */
    private static function getLocalProduct($id)
    {
        return isset(self::$productMap[$id]) ? self::$productMap[$id] : null;
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
     * Display error message
     */
    private static function displayError($message)
    {
        print '<p style="color:red; font-weight:bold;">' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    }

    /**
     * Log processing statistics
     */
    private static function logProcessingStats($productId, $subproductCount)
    {
        $endTime = microtime(true);
        $duration = $endTime - self::$processStats['start_time'];
        
        $stats = array(
            'product_id' => $productId,
            'subproduct_count' => $subproductCount,
            'duration_seconds' => round($duration, 3),
            'database_operations' => self::$processStats['database_operations'],
            'cache_hits' => self::$processStats['cache_hits']
        );
        
        dol_syslog(__METHOD__ . " COMPLETED: " . json_encode($stats), LOG_INFO);
    }

    /**
     * Error handling methods
     */
    private static function addError($error)
    {
        self::$errors[] = $error;
        self::$lastError = $error;
        dol_syslog("KreaProductsNutritionalCalculator Error: $error", LOG_ERR);
    }

    private static function addWarning($warning)
    {
        self::$warnings[] = $warning;
        dol_syslog("KreaProductsNutritionalCalculator Warning: $warning", LOG_WARNING);
    }

    private static function clearErrors()
    {
        self::$errors = array();
        self::$warnings = array();
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

    public static function getWarnings()
    {
        return self::$warnings;
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
        self::$unitMappingCache = array();
        self::$weightConversionCache = array();
        self::$calculatedNutritionCache = array();
        self::$calcOptionCache = array();
        self::$associationCache = array();
    }

    /**
     * Get processing statistics
     */
    public static function getProcessingStats()
    {
        return self::$processStats;
    }

    // Backward compatibility methods
    public static function triggerSaveNutritional()
    {
        $productId = GETPOST('id', 'int');
        if (!$productId) {
            return -1;
        }

        global $user;
        return self::saveCalculation($productId, $user);
    }
}

/**
 * Enhanced LocalProduct class with better data management
 */
class EnhancedLocalProduct
{
    public $id;
    public $label;
    public $weight;
    public $weight_units;
    public $children = array();
    private $totalQuantity = 0.0;

    /**
     * Constructor with validation
     */
    public function __construct($id, $label, $weight = 0, $weight_units = 'g')
    {
        $this->id = (int)$id;
        $this->label = $label ? $label : '';
        $this->weight = $weight ? (float)$weight : 0;
        $this->weight_units = ($weight_units === null || $weight_units === '') ? 0 : $weight_units;
    }

    /**
     * Add child with quantity validation
     */
    public function addChild($childId, $quantity)
    {
        if ($quantity > 0) {
            if (!isset($this->children[$childId])) {
                $this->children[$childId] = 0;
            }
            $this->children[$childId] += (float)$quantity;
            $this->totalQuantity += (float)$quantity;
        }
    }

    /**
     * Set child quantity (overrides existing value)
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
     * Get total quantity
     */
    public function getTotalQuantity()
    {
        return $this->totalQuantity;
    }

    /**
     * Check if has children
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }
}

// Backward compatibility
class LocalProduct extends EnhancedLocalProduct
{
    public function __construct($id, $label, $weight = 0, $weight_units = 'g')
    {
        parent::__construct($id, $label, $weight, $weight_units);
    }
}
