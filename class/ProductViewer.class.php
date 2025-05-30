<?php

/**
 * Enhanced ProductHierarchyTree Class
 *
 * Provides methods to build a non-recursive map of product associations, display
 * a fancy ASCII tree of children/parents, and recalculate cost prices.
 * Enhanced with comprehensive error handling, input validation, performance optimization,
 * security improvements, and proper transaction management for enterprise-level use.
 */
class ProductHierarchyTree
{
    // Constants for configuration
    const MAX_HIERARCHY_DEPTH = 50;
    const MAX_PRODUCTS_PER_LEVEL = 1000;
    const BATCH_SIZE = 100;
    const PRICE_COMPARISON_DELTA = 0.01;
    
    // Constants for display modes
    const MODE_CHILD = 'child';
    const MODE_PARENT = 'parent';
    
    // Constants for price fields
    const FIELD_PRICE = 'price';
    const FIELD_BUYPRICE = 'buyprice';
    
    // ASCII tree characters
    const TREE_VERTICAL = '┃';
    const TREE_BRANCH = '┣━━ ';
    const TREE_LAST = '┗━━ ';
    const TREE_SPACE = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    const TREE_FULL_SPACE = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';

    // Class state management
    private static $productMap = array();
    private static $productCache = array();
    private static $priceCache = array();
    
    // Error handling
    private static $errors = array();
    private static $warnings = array();
    private static $lastError = null;
    
    // Performance tracking
    private static $processStats = array();

    /**
     * Generate the complete HTML page with enhanced error handling and validation
     *
     * @param int $productId ID of the product to display
     * @return string HTML output or error message
     */
    public static function getCompletePage($productId)
    {
        global $db, $langs, $conf;

        try {
            self::initializeProcessing($productId);
            
            // Enhanced input validation
            if (!self::validateInputs($productId)) {
                return self::generateErrorOutput("Invalid product ID provided");
            }

            // Build enhanced BFS tree with error handling
            if (!self::buildEnhancedMapBFS($productId)) {
                return self::generateErrorOutput("Failed to build product hierarchy");
            }

            // Load and validate top-level product
            $prod = self::loadAndValidateProduct($productId);
            if (!$prod) {
                return self::generateErrorOutput("Product #$productId not found or invalid");
            }

            // Start output buffering with error handling
            ob_start();
            
            try {
                // Generate page sections
                self::generateHeaderSection($prod, $productId, $langs);
                self::generateChildrenSection($productId, $langs);
                self::generateParentsSection($productId, $langs);
                self::generateTechnicalSheet($productId, $langs, $conf);
                
                $output = ob_get_clean();
                
                self::logProcessingStats($productId);
                return $output;
                
            } catch (Exception $e) {
                ob_end_clean();
                throw $e;
            }
            
        } catch (Exception $e) {
            self::addError("Page generation failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return self::generateErrorOutput("An error occurred while generating the page");
        }
    }

    /**
     * Enhanced updateProductAttributes with transaction management
     *
     * @param int $productId ID of the product to update
     * @param User $user Dolibarr user performing the action
     * @return bool True on success, false on failure
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;

        try {
            self::initializeProcessing($productId);
            
            // Enhanced input validation
            if (!self::validateInputs($productId) || !self::validateUser($user)) {
                return false;
            }

            dol_syslog(__METHOD__ . " Start for productId=$productId", LOG_DEBUG);
            
            // Start database transaction
            $db->begin();
            
            try {
                // Build enhanced BFS map
                if (!self::buildEnhancedMapBFS($productId)) {
                    throw new Exception("Failed to build product hierarchy");
                }

                // Process product updates
                $result = self::processProductUpdates($productId, $user);
                
                if ($result) {
                    $db->commit();
                    self::logProcessingStats($productId);
                    return true;
                } else {
                    throw new Exception("Product update processing failed");
                }
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            self::addError("Product attributes update failed: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return false;
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
            'database_operations' => 0,
            'cache_hits' => 0,
            'products_processed' => 0,
            'updates_performed' => 0
        );
        
        dol_syslog(__METHOD__ . " Starting processing for product: $productId", LOG_DEBUG);
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
        global $db;

        try {
            self::$productMap = array();
            $queue = array($startId);
            $seen = array($startId => true);
            $depth = 0;

            while (!empty($queue) && $depth < self::MAX_HIERARCHY_DEPTH) {
                $currentLevel = $queue;
                $queue = array();
                
                if (count($currentLevel) > self::MAX_PRODUCTS_PER_LEVEL) {
                    throw new Exception("Hierarchy level exceeds maximum product limit");
                }

                foreach ($currentLevel as $current) {
                    if (!self::processProductAssociations($current, $queue, $seen)) {
                        self::addWarning("Failed to process associations for product $current");
                    }
                }
                
                $depth++;
            }

            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                throw new Exception("Hierarchy depth exceeds maximum limit");
            }

            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to build product map: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process product associations for a single product
     */
    private static function processProductAssociations($current, &$queue, &$seen)
    {
        global $db;

        try {
            // Enhanced SQL with better security
            $sql = "SELECT pa.fk_product_pere as father, 
                           pa.fk_product_fils as child, 
                           pa.qty as qty,
                           p.label as fatherLabel, 
                           p.ref as fatherRef, 
                           p.price as fatherPrice, 
                           p.cost_price as fatherBuy,
                           f.label as childLabel, 
                           f.ref as childRef, 
                           f.price as childPrice, 
                           f.cost_price as childBuy
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                    JOIN " . MAIN_DB_PREFIX . "product p ON (p.rowid = pa.fk_product_pere)
                    JOIN " . MAIN_DB_PREFIX . "product f ON (f.rowid = pa.fk_product_fils)
                    WHERE pa.fk_product_pere = " . (int)$current . " 
                       OR pa.fk_product_fils = " . (int)$current;

            $resql = $db->query($sql);
            
            if (!$resql) {
                throw new Exception("Database error: " . $db->lasterror());
            }

            while ($obj = $db->fetch_object($resql)) {
                if (!self::processAssociationObject($obj, $queue, $seen)) {
                    self::addWarning("Failed to process association: {$obj->father} → {$obj->child}");
                }
            }
            
            $db->free($resql);
            self::$processStats['database_operations']++;
            
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process associations for product $current: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process a single association object
     */
    private static function processAssociationObject($obj, &$queue, &$seen)
    {
        try {
            // Validate association data
            if (!self::validateAssociationObject($obj)) {
                return false;
            }

            // Ensure father object exists
            if (!isset(self::$productMap[$obj->father])) {
                self::$productMap[$obj->father] = new EnhancedLocalProduct(
                    $obj->father,
                    $obj->fatherLabel,
                    $obj->fatherRef,
                    (float)$obj->fatherPrice,
                    (float)$obj->fatherBuy
                );
            }
            
            // Ensure child object exists
            if (!isset(self::$productMap[$obj->child])) {
                self::$productMap[$obj->child] = new EnhancedLocalProduct(
                    $obj->child,
                    $obj->childLabel,
                    $obj->childRef,
                    (float)$obj->childPrice,
                    (float)$obj->childBuy
                );
            }
            
            // Add relationships with deduplication
            $quantity = (float)$obj->qty;
            if ($quantity > 0) {
                self::$productMap[$obj->father]->addChild($obj->child, $quantity);
                self::$productMap[$obj->child]->addParent($obj->father);
            }

            // Queue management with cycle prevention
            if (empty($seen[$obj->father])) {
                $queue[] = $obj->father;
                $seen[$obj->father] = true;
            }
            if (empty($seen[$obj->child])) {
                $queue[] = $obj->child;
                $seen[$obj->child] = true;
            }

            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process association object: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate association object data
     */
    private static function validateAssociationObject($obj)
    {
        if (empty($obj->father) || empty($obj->child)) {
            self::addWarning("Invalid association: missing father or child ID");
            return false;
        }

        if ($obj->qty <= 0) {
            self::addWarning("Invalid quantity in association: " . $obj->qty);
            return false;
        }

        if ($obj->father == $obj->child) {
            self::addWarning("Self-referencing association detected: " . $obj->father);
            return false;
        }

        return true;
    }

    /**
     * Load and validate product with caching
     */
    private static function loadAndValidateProduct($productId)
    {
        // Check cache first
        if (isset(self::$productCache[$productId])) {
            self::$processStats['cache_hits']++;
            return self::$productCache[$productId];
        }

        global $db;
        
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new Product($db);
        
        if ($prod->fetch($productId) <= 0) {
            self::addError("Failed to load product #$productId");
            return null;
        }

        // Cache the product
        self::$productCache[$productId] = $prod;
        
        return $prod;
    }

    /**
     * Generate header section with enhanced error handling
     */
    private static function generateHeaderSection($prod, $productId, $langs)
    {
        try {
            print '<div class="fichecenter">';
            print '<div class="underbanner clearboth"></div>';
            print '<table class="border tableforfield centpercent">';
            
            // Product reference with validation
            $linkRef = method_exists($prod, 'getNomUrl') ? $prod->getNomUrl(1) : htmlspecialchars($prod->ref, ENT_QUOTES);
            print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td><td colspan="3">' . $linkRef . '</td></tr>';

            // Product statistics
            $lp = self::getLocalProduct($productId);
            $nbChildren = $lp ? count($lp->children) : 0;
            $nbParents = $lp ? count($lp->parents) : 0;
            
            print '<tr><td class="titlefield">Número de produtos que compõem este kit</td><td colspan="3">' . $nbChildren . '</td></tr>';
            print '<tr><td class="titlefield">Número de produtos de embalagem fonte</td><td colspan="3">' . $nbParents . '</td></tr>';
            print '</table>';
            print '</div>';
            print '<div class="clearboth"></div>';
            print dol_get_fiche_end();
            
        } catch (Exception $e) {
            self::addError("Failed to generate header section: " . $e->getMessage());
            print '<p style="color:red;">Error generating header section.</p>';
        }
    }

    /**
     * Generate children section
     */
    private static function generateChildrenSection($productId, $langs)
    {
        try {
            print '<p><strong>Lista de produtos/serviços que são componentes deste kit</strong></p>';
            print '<table class="noborder" width="100%">';
            
            self::printChildParentTableHead($langs);
            
            // Print top-level row
            self::printLine($productId, 0, 0, array(), true, 0, 1, self::MODE_CHILD);
            
            // Recursively display children
            $visitedChildren = array();
            self::fancyChildRecursive($productId, 1, 5, $visitedChildren, array());
            
            print '</table><br>';
            
        } catch (Exception $e) {
            self::addError("Failed to generate children section: " . $e->getMessage());
            print '<p style="color:red;">Error generating children section.</p>';
        }
    }

    /**
     * Generate parents section
     */
    private static function generateParentsSection($productId, $langs)
    {
        try {
            print '<p><strong>Lista de kits com este produto como componente</strong></p>';
            print '<table class="noborder" width="100%">';
            
            self::printChildParentTableHead($langs);
            
            // Print top-level row
            self::printLine($productId, 0, 0, array(), true, 0, 1, self::MODE_PARENT);
            
            // Recursively display parents
            $visitedParents = array();
            self::fancyParentRecursive($productId, 1, 5, $visitedParents, array());
            
            print '</table><br>';
            
        } catch (Exception $e) {
            self::addError("Failed to generate parents section: " . $e->getMessage());
            print '<p style="color:red;">Error generating parents section.</p>';
        }
    }

    /**
     * Generate technical sheet with enhanced calculations
     */
    private static function generateTechnicalSheet($productId, $langs, $conf)
    {
        try {
            print '<p><strong>' . $langs->trans("FichaTecnica") . '</strong></p>';
            print '<table class="noborder" width="100%">';
            print '<tr class="liste_titre">';
            print '<td width="10%">' . $langs->trans("Reference") . '</td>';
            print '<td width="50%">' . $langs->trans("Label") . '</td>';
            print '<td width="20%">Qty</td>';
            print '<td width="20%">Tipo</td>';
            print '<td width="10%">CostPrice</td>';
            print '<td width="10%">Subtotal</td>';
            print '</tr>';

            $lp = self::getLocalProduct($productId);
            if (!$lp) {
                print '<tr><td colspan="6">No product data available</td></tr>';
                print '</table>';
                return;
            }

            $totalCost = 0;
            
            // Process children
            foreach ($lp->children as $childId => $qty) {
                if (!self::generateChildTechnicalRow($childId, $qty, $langs, $conf, $totalCost)) {
                    self::addWarning("Failed to generate technical row for child $childId");
                }
            }

            // Calculate and display totals
            $sumBuy = self::computeRecursivePriceEnhanced($lp, self::FIELD_BUYPRICE);
            
            self::generateTotalRows($lp, $sumBuy, $langs, $conf);
            
            print '</table>';
            
        } catch (Exception $e) {
            self::addError("Failed to generate technical sheet: " . $e->getMessage());
            print '<p style="color:red;">Error generating technical sheet.</p>';
        }
    }

    /**
     * Generate child technical row
     */
    private static function generateChildTechnicalRow($childId, $qty, $langs, $conf, &$totalCost)
    {
        try {
            $childLP = self::getLocalProduct($childId);
            if (!$childLP) {
                return false;
            }
            
            $ref = self::loadAndValidateProduct($childId);
            if (!$ref) {
                return false;
            }
            
            $linkRef = method_exists($ref, 'getNomUrl') ? $ref->getNomUrl(1) : htmlspecialchars($ref->ref, ENT_QUOTES);

            print '<tr style="font-style: italic;">';
            print '<td>' . $linkRef . '</td>';
            print '<td>' . htmlspecialchars($childLP->label, ENT_QUOTES) . '</td>';
            print '<td>x ' . number_format($qty, 3, '.', '') . '</td>';
            print '<td>' . $langs->trans('Subprodutos') . '</td>';
            
            $buyVal = self::formatPrice($childLP->buyprice, $conf);
            print '<td>' . $buyVal . '</td>';
            
            $subTotal = $qty * $childLP->buyprice;
            $totalCost += $subTotal;
            $subTotalFormatted = self::formatPrice($subTotal, $conf);
            print '<td>' . $subTotalFormatted . '</td>';
            print '</tr>';
            
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to generate child technical row: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate total rows for technical sheet
     */
    private static function generateTotalRows($lp, $sumBuy, $langs, $conf)
    {
        try {
            print '<tr style="font-style: italic;">';
            print '<td colspan="5">' . $langs->trans('TotaisEstimadosDoProduto') . '</td>';
            print '<td>' . self::formatPrice($sumBuy, $conf) . '</td>';
            print '</tr>';

            print '<tr style="font-weight: bold;font-size:1.1em;">';
            print '<td colspan="5">' . $langs->trans('PrecoCusto') . '</td>';
            print '<td>' . self::formatPrice($lp->buyprice, $conf);
            print self::compareIconEnhanced($sumBuy, $lp->buyprice);
            print '</td>';
            print '</tr>';
            
        } catch (Exception $e) {
            self::addError("Failed to generate total rows: " . $e->getMessage());
        }
    }

    /**
     * Enhanced price formatting
     */
    private static function formatPrice($price, $conf)
    {
        $currency = !empty($conf->global->MAIN_MONNAIE) ? $conf->global->MAIN_MONNAIE : 'EUR';
        return price($price, '', '', 0, 3, 3, '') . ' ' . $currency;
    }

    /**
     * Print table headers with enhanced structure
     */
    private static function printChildParentTableHead($langs)
    {
        print '<tr class="liste_titre">';
        print '<td width="20%">' . $langs->trans("Reference") . '</td>';
        print '<td width="35%">' . $langs->trans("Designation") . '</td>';
        print '<td width="10%">' . $langs->trans("Subproducts") . '</td>';
        print '<td width="10%">' . $langs->trans("Type") . '</td>';
        print '<td width="5%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';
    }

    /**
     * Enhanced printLine method with better error handling
     * Fixed PHP 5.6+ compatibility by removing type hints
     */
    private static function printLine($productId, $qty, $level, $prefix, $isLast, $index, $count, $mode)
    {
        global $db, $langs, $conf;

        try {
            $lp = self::getLocalProduct($productId);
            if (!$lp) {
                return;
            }

            $pr = self::loadAndValidateProduct($productId);
            if (!$pr) {
                return;
            }

            // Build enhanced indentation
            $indent = self::buildIndentation($level, $prefix, $isLast);

            // Build association info
            $assoc = self::buildAssociationInfo($qty, $lp);

            // Determine type
            $type = (!empty($lp->children)) ? 'Ficha Técnica' : '';
            
            // Format price
            $priceStr = self::formatPrice($lp->buyprice, $conf);

            // Generate row
            print '<tr>';
            print '<td>' . $indent . $pr->getNomUrl(1) . '</td>';
            print '<td>' . htmlspecialchars($lp->label, ENT_QUOTES) . '</td>';
            print '<td>' . $assoc . '</td>';
            print '<td>' . $type . '</td>';
            print '<td>' . $priceStr . '</td>';
            print '</tr>';
            
        } catch (Exception $e) {
            self::addError("Failed to print line for product $productId: " . $e->getMessage());
        }
    }

    /**
     * Build ASCII indentation
     */
    private static function buildIndentation($level, $prefix, $isLast)
    {
        $indent = '';
        
        for ($i = 1; $i < $level; $i++) {
            if (!empty($prefix[$i])) {
                $indent .= self::TREE_VERTICAL . self::TREE_SPACE;
            } else {
                $indent .= self::TREE_FULL_SPACE;
            }
        }
        
        if ($level > 0) {
            $indent .= $isLast ? self::TREE_LAST : self::TREE_BRANCH;
        }
        
        return $indent;
    }

    /**
     * Build association information
     */
    private static function buildAssociationInfo($qty, $lp)
    {
        if ($qty > 0) {
            return number_format($qty, 3, '.', '');
        } elseif (!empty($lp->children)) {
            return count($lp->children);
        } elseif (!empty($lp->parents)) {
            return count($lp->parents) . ' Pais';
        }
        
        return '';
    }

    /**
     * Enhanced recursive child display with cycle detection
     */
    private static function fancyChildRecursive($productId, $level, $maxLevel, &$visited, $prefix)
    {
        if (in_array($productId, $visited, true) || $level > $maxLevel) {
            return;
        }
        
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return;
        }

        $childIds = array_keys($lp->children);
        $numKids = count($childIds);

        for ($i = 0; $i < $numKids; $i++) {
            $childId = $childIds[$i];
            $qty = $lp->children[$childId];
            $isLast = ($i == $numKids - 1);

            $childPrefix = $prefix;
            $childPrefix[$level] = !$isLast;

            self::printLine($childId, $qty, $level, $childPrefix, $isLast, $i, $numKids, self::MODE_CHILD);

            if ($level < $maxLevel && !in_array($childId, $visited, true)) {
                self::fancyChildRecursive($childId, $level + 1, $maxLevel, $visited, $childPrefix);
            }
        }
    }

    /**
     * Enhanced recursive parent display with cycle detection
     */
    private static function fancyParentRecursive($productId, $level, $maxLevel, &$visited, $prefix)
    {
        if (in_array($productId, $visited, true) || $level > $maxLevel) {
            return;
        }
        
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return;
        }

        $pars = $lp->parents;
        $n = count($pars);

        for ($i = 0; $i < $n; $i++) {
            $parId = $pars[$i];
            if (in_array($parId, $visited, true)) {
                continue;
            }
            
            $isLast = ($i == $n - 1);

            $parPrefix = $prefix;
            $parPrefix[$level] = !$isLast;

            self::printLine($parId, 0, $level, $parPrefix, $isLast, $i, $n, self::MODE_PARENT);

            if ($level < $maxLevel) {
                self::fancyParentRecursive($parId, $level + 1, $maxLevel, $visited, $parPrefix);
            }
        }
    }

    /**
     * Enhanced recursive price computation with caching
     */
    private static function computeRecursivePriceEnhanced($lp, $field)
    {
        $cacheKey = $lp->id . '_' . $field;
        
        if (isset(self::$priceCache[$cacheKey])) {
            self::$processStats['cache_hits']++;
            return self::$priceCache[$cacheKey];
        }

        if (empty($lp->children)) {
            $result = ($field === self::FIELD_PRICE) ? $lp->price : $lp->buyprice;
        } else {
            $sum = 0;
            foreach ($lp->children as $childId => $qty) {
                $child = self::getLocalProduct($childId);
                if ($child) {
                    $sum += $qty * self::computeRecursivePriceEnhanced($child, $field);
                }
            }
            $result = $sum;
        }

        // Cache the result
        self::$priceCache[$cacheKey] = $result;
        
        return $result;
    }

    /**
     * Enhanced compare icon with better validation
     */
    private static function compareIconEnhanced($val1, $val2)
    {
        if (abs($val1 - $val2) < self::PRICE_COMPARISON_DELTA) {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/tick.png" alt="tick" title="Values match">';
        } else {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/error.png" alt="error" title="Values differ">';
        }
    }

    /**
     * Process product updates with enhanced error handling
     */
    private static function processProductUpdates($productId, $user)
    {
        try {
            $lp = self::getLocalProduct($productId);
            if (!$lp) {
                self::addError("Local product not found for ID: $productId");
                return false;
            }

            if (!empty($lp->children)) {
                // Update this product based on children
                return self::updateProductFromChildren($productId, $lp, $user);
            } elseif (!empty($lp->parents)) {
                // Update parent products
                return self::updateParentProducts($lp, $user);
            }

            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process product updates: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update product from children
     */
    private static function updateProductFromChildren($productId, $lp, $user)
    {
        try {
            $newCost = self::computeRecursivePriceEnhanced($lp, self::FIELD_BUYPRICE);
            
            $prod = self::loadAndValidateProduct($productId);
            if (!$prod) {
                return false;
            }

            $prod->cost_price = $newCost;
            $prod->buyprice = $newCost;
            
            $res = $prod->update($productId, $user);
            
            if ($res > 0) {
                self::$processStats['updates_performed']++;
                dol_syslog(__METHOD__ . " Updated product #$productId cost=$newCost", LOG_DEBUG);
                return true;
            } else {
                self::addError("Failed to update product #$productId");
                return false;
            }
            
        } catch (Exception $e) {
            self::addError("Failed to update product from children: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update parent products recursively
     */
    private static function updateParentProducts($lp, $user)
    {
        $success = true;
        
        foreach ($lp->parents as $parentId) {
            if (!self::updateProductAttributes($parentId, $user)) {
                $success = false;
                self::addWarning("Failed to update parent product: $parentId");
            }
        }
        
        return $success;
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
     * Generate error output
     */
    private static function generateErrorOutput($message)
    {
        return '<p style="color:red; font-weight:bold;">' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    }

    /**
     * Log processing statistics
     */
    private static function logProcessingStats($productId)
    {
        $endTime = microtime(true);
        $duration = $endTime - self::$processStats['start_time'];
        
        $stats = array(
            'product_id' => $productId,
            'duration_seconds' => round($duration, 3),
            'database_operations' => self::$processStats['database_operations'],
            'cache_hits' => self::$processStats['cache_hits'],
            'products_processed' => self::$processStats['products_processed'],
            'updates_performed' => self::$processStats['updates_performed']
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
        dol_syslog("ProductHierarchyTree Error: $error", LOG_ERR);
    }

    private static function addWarning($warning)
    {
        self::$warnings[] = $warning;
        dol_syslog("ProductHierarchyTree Warning: $warning", LOG_WARNING);
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
        self::$priceCache = array();
    }

    /**
     * Get processing statistics
     */
    public static function getProcessingStats()
    {
        return self::$processStats;
    }

    // Backward compatibility methods
    private static function computeRecursivePrice($lp, $key)
    {
        return self::computeRecursivePriceEnhanced($lp, $key);
    }

    private static function compareIcon($val1, $val2)
    {
        return self::compareIconEnhanced($val1, $val2);
    }

    private static function buildMapBFS($startId)
    {
        return self::buildEnhancedMapBFS($startId);
    }
}

/**
 * Enhanced LocalProduct class with better data management
 */
class EnhancedLocalProduct
{
    public $id;
    public $label;
    public $ref;
    public $price = 0.0;
    public $buyprice = 0.0;
    public $children = array();
    public $parents = array();

    /**
     * Constructor with validation
     */
    public function __construct($id, $label, $ref, $price, $buyprice)
    {
        $this->id = (int)$id;
        $this->label = $label ? $label : '';
        $this->ref = $ref ? $ref : '';
        $this->price = (float)$price;
        $this->buyprice = (float)$buyprice;
    }

    /**
     * Add child with quantity validation
     */
    public function addChild($childId, $quantity)
    {
        if ($quantity > 0) {
            if (!array_key_exists($childId, $this->children)) {
                $this->children[$childId] = (float)$quantity;
            }
        }
    }

    /**
     * Add parent with deduplication
     */
    public function addParent($parentId)
    {
        if (!in_array($parentId, $this->parents, true)) {
            $this->parents[] = $parentId;
        }
    }

    /**
     * Check if has children
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }

    /**
     * Check if has parents
     */
    public function hasParents()
    {
        return !empty($this->parents);
    }
}

// Backward compatibility
class LocalProduct extends EnhancedLocalProduct
{
    public function __construct($id, $label, $ref, $price, $buyprice)
    {
        parent::__construct($id, $label, $ref, $price, $buyprice);
    }
}
