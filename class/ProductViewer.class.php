<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */

require_once __DIR__ . '/ProductUpdater.class.php';

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
    const MAX_PRODUCTS_PER_LEVEL = 5000;
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
    private static $processedBomIds = array();
    
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
        $langs->loadLangs(array('kreaproducts@kreaproducts', 'products', 'other'));

        try {
            self::initializeProcessing($productId);
            
            // Enhanced input validation
            if (!self::validateInputs($productId)) {
                return self::generateErrorOutput($langs->trans('KreapInvalidProductIdProvided'));
            }

            // Build enhanced BFS tree with error handling
            if (!self::buildEnhancedMapBFS($productId)) {
                return self::generateErrorOutput($langs->trans('KreapBuildHierarchyFailed'));
            }

            // Load and validate top-level product
            $prod = self::loadAndValidateProduct($productId);
            if (!$prod) {
                return self::generateErrorOutput($langs->trans('KreapProductNotFound', $productId));
            }

            // Start output buffering with error handling
            ob_start();
            
            try {
                // Generate page sections
                self::generateCollapsibleTreeAssets($langs);
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
            return self::generateErrorOutput($langs->trans('KreapPageGenerationError'));
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
        self::$processedBomIds = array();
        
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

            // Load all relations in one pass so child products bring along their own associations/BOMs
            $queue = array();
            $seen = array();
            if (!self::loadAllRelations($queue, $seen)) {
                return false;
            }

            // Ensure root product exists in map even if it has no relations
            if (!isset(self::$productMap[$startId])) {
                $rootProd = self::loadAndValidateProduct($startId);
                if ($rootProd) {
                    self::$productMap[$startId] = new EnhancedLocalProduct(
                        $startId,
                        $rootProd->label,
                        $rootProd->ref,
                        (float)$rootProd->price,
                        (float)$rootProd->cost_price
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
     * Load all product relations (associations and BOMs) in one pass.
     * This guarantees that when a child has its own tree, it is merged into the map.
     */
    private static function loadAllRelations(&$queue, &$seen)
    {
        global $db, $conf;

        // Product associations
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
                       f.cost_price as childBuy,
                       NULL as fk_bom_child
                FROM " . MAIN_DB_PREFIX . "product_association pa
                JOIN " . MAIN_DB_PREFIX . "product p ON (p.rowid = pa.fk_product_pere)
                JOIN " . MAIN_DB_PREFIX . "product f ON (f.rowid = pa.fk_product_fils)
                WHERE p.entity IN (" . getEntity('product') . ")
                  AND f.entity IN (" . getEntity('product') . ")";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Database error: " . $db->lasterror());
            return false;
        }
        while ($obj = $db->fetch_object($resql)) {
            self::processAssociationObject($obj, $queue, $seen, false);
        }
        $db->free($resql);
        self::$processStats['database_operations']++;

        // BOM relations (only if module enabled)
        if (!empty($conf->bom->enabled)) {
            $sqlBom = "SELECT b.fk_product as father,
                              COALESCE(bl.fk_product, cb.fk_product) as child,
                              bl.qty as qty,
                              bl.fk_bom_child as fk_bom_child,
                              p.label as fatherLabel,
                              p.ref as fatherRef,
                              p.price as fatherPrice,
                              p.cost_price as fatherBuy,
                              COALESCE(f.label, cprod.label) as childLabel,
                              COALESCE(f.ref, cprod.ref) as childRef,
                              COALESCE(f.price, cprod.price) as childPrice,
                              COALESCE(f.cost_price, cprod.cost_price) as childBuy
                       FROM " . MAIN_DB_PREFIX . "bom_bom b
                       JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON bl.fk_bom = b.rowid
                       JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                       LEFT JOIN " . MAIN_DB_PREFIX . "product f ON f.rowid = bl.fk_product
                       LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                       LEFT JOIN " . MAIN_DB_PREFIX . "product cprod ON cprod.rowid = cb.fk_product
                       WHERE b.bomtype IN (0,1)
                         AND b.status = 1
                         AND b.entity IN (0," . getEntity('bom') . ")
                         AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (
                             SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom b2
                             WHERE b2.fk_product = b.fk_product
                               AND b2.entity = " . ((int) $conf->entity) . "
                               AND b2.bomtype = b.bomtype AND b2.status = 1
                         )))
                         AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                         AND p.entity IN (" . getEntity('product') . ")
                         AND (f.rowid IS NULL OR f.entity IN (" . getEntity('product') . "))
                         AND (cprod.rowid IS NULL OR cprod.entity IN (" . getEntity('product') . "))";

            $resBom = $db->query($sqlBom);
            if (!$resBom) {
                self::addError("Database error: " . $db->lasterror());
                return false;
            }
            while ($obj = $db->fetch_object($resBom)) {
                self::processAssociationObject($obj, $queue, $seen, false);
            }
            $db->free($resBom);
            self::$processStats['database_operations']++;
        }

        return true;
    }

    /**
     * Process product associations for a single product
     */
    private static function processProductAssociations($current, &$queue, &$seen)
    {
        global $db, $conf;

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
                    WHERE (pa.fk_product_pere = " . (int)$current . " 
                       OR pa.fk_product_fils = " . (int)$current . ")
                      AND p.entity IN (" . getEntity('product') . ")
                      AND f.entity IN (" . getEntity('product') . ")";

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

            // Also include BOM-based relations when the MRP/BOM module is enabled
            if (!empty($conf->bom->enabled)) {
                if (!self::processBomAssociations($current, $queue, $seen)) {
                    return false;
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            self::addError("Failed to process associations for product $current: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process BOM associations (MRP children/parents)
     */
    private static function processBomAssociations($current, &$queue, &$seen)
    {
        global $db, $conf;

        try {
            // Use COALESCE to pull the produced product when a BOM line references another BOM (fk_bom_child)
            $queries = array(
                // Current product is the BOM parent (Produtos Filho)
                "SELECT b.fk_product as father,
                        COALESCE(bl.fk_product, cb.fk_product) as child,
                        bl.qty as qty,
                        bl.fk_bom_child as fk_bom_child,
                        p.label as fatherLabel,
                        p.ref as fatherRef,
                        p.price as fatherPrice,
                        p.cost_price as fatherBuy,
                        COALESCE(f.label, cprod.label) as childLabel,
                        COALESCE(f.ref, cprod.ref) as childRef,
                        COALESCE(f.price, cprod.price) as childPrice,
                        COALESCE(f.cost_price, cprod.cost_price) as childBuy
                 FROM " . MAIN_DB_PREFIX . "bom_bom b
                 JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON bl.fk_bom = b.rowid
                 JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                 LEFT JOIN " . MAIN_DB_PREFIX . "product f ON f.rowid = bl.fk_product
                 LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                 LEFT JOIN " . MAIN_DB_PREFIX . "product cprod ON cprod.rowid = cb.fk_product
                 WHERE b.fk_product = " . (int)$current . "
                   AND b.bomtype IN (0,1)
                   AND b.status = 1
                   AND b.entity IN (0," . getEntity('bom') . ")
                   AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (
                       SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom b2
                       WHERE b2.fk_product = b.fk_product
                         AND b2.entity = " . ((int) $conf->entity) . "
                         AND b2.bomtype = b.bomtype AND b2.status = 1
                   )))
                   AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                   AND p.entity IN (" . getEntity('product') . ")
                   AND (f.rowid IS NULL OR f.entity IN (" . getEntity('product') . "))
                   AND (cprod.rowid IS NULL OR cprod.entity IN (" . getEntity('product') . "))",
                // Current product is a BOM component (Produtos Pai)
                "SELECT b.fk_product as father,
                        COALESCE(bl.fk_product, cb.fk_product) as child,
                        bl.qty as qty,
                        bl.fk_bom_child as fk_bom_child,
                        p.label as fatherLabel,
                        p.ref as fatherRef,
                        p.price as fatherPrice,
                        p.cost_price as fatherBuy,
                        COALESCE(f.label, cprod.label) as childLabel,
                        COALESCE(f.ref, cprod.ref) as childRef,
                        COALESCE(f.price, cprod.price) as childPrice,
                        COALESCE(f.cost_price, cprod.cost_price) as childBuy
                 FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                 JOIN " . MAIN_DB_PREFIX . "bom_bom b ON b.rowid = bl.fk_bom
                 JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                 LEFT JOIN " . MAIN_DB_PREFIX . "product f ON f.rowid = bl.fk_product
                 LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                 LEFT JOIN " . MAIN_DB_PREFIX . "product cprod ON cprod.rowid = cb.fk_product
                 WHERE COALESCE(bl.fk_product, cb.fk_product) = " . (int)$current . "
                   AND b.bomtype IN (0,1)
                   AND b.status = 1
                   AND b.entity IN (0," . getEntity('bom') . ")
                   AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (
                       SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom b2
                       WHERE b2.fk_product = b.fk_product
                         AND b2.entity = " . ((int) $conf->entity) . "
                         AND b2.bomtype = b.bomtype AND b2.status = 1
                   )))
                   AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                   AND p.entity IN (" . getEntity('product') . ")
                   AND (f.rowid IS NULL OR f.entity IN (" . getEntity('product') . "))
                   AND (cprod.rowid IS NULL OR cprod.entity IN (" . getEntity('product') . "))"
            );

            foreach ($queries as $sql) {
                $resql = $db->query($sql);

                if (!$resql) {
                    throw new Exception("Database error: " . $db->lasterror());
                }

                while ($obj = $db->fetch_object($resql)) {
                self::processAssociationObject($obj, $queue, $seen);
            }

                $db->free($resql);
                self::$processStats['database_operations']++;
            }

            return true;
        } catch (Exception $e) {
            self::addError("Failed to process BOM associations for product $current: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recursively process a child BOM to include its produced product and components
     */
    private static function processChildBom($bomId, &$queue, &$seen)
    {
        global $db, $conf;

        // Avoid loops on the same BOM id
        if (!empty(self::$processedBomIds[$bomId])) {
            return true;
        }
        self::$processedBomIds[$bomId] = true;

        $sql = "SELECT b.fk_product as father,
                       COALESCE(bl.fk_product, cb.fk_product) as child,
                       bl.qty as qty,
                       bl.fk_bom_child as fk_bom_child,
                       p.label as fatherLabel,
                       p.ref as fatherRef,
                       p.price as fatherPrice,
                       p.cost_price as fatherBuy,
                       COALESCE(f.label, cprod.label) as childLabel,
                       COALESCE(f.ref, cprod.ref) as childRef,
                       COALESCE(f.price, cprod.price) as childPrice,
                       COALESCE(f.cost_price, cprod.cost_price) as childBuy
                FROM " . MAIN_DB_PREFIX . "bom_bom b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON bl.fk_bom = b.rowid
                JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "product f ON f.rowid = bl.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                LEFT JOIN " . MAIN_DB_PREFIX . "product cprod ON cprod.rowid = cb.fk_product
                WHERE b.rowid = " . (int)$bomId . "
                  AND b.bomtype IN (0,1)
                  AND b.status = 1
                  AND b.entity IN (0," . getEntity('bom') . ")
                  AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (
                      SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom b2
                      WHERE b2.fk_product = b.fk_product
                        AND b2.entity = " . ((int) $conf->entity) . "
                        AND b2.bomtype = b.bomtype AND b2.status = 1
                  )))
                  AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))
                  AND p.entity IN (" . getEntity('product') . ")
                  AND (f.rowid IS NULL OR f.entity IN (" . getEntity('product') . "))
                  AND (cprod.rowid IS NULL OR cprod.entity IN (" . getEntity('product') . "))";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to process child BOM $bomId: " . $db->lasterror());
            return false;
        }

        while ($obj = $db->fetch_object($resql)) {
            self::processAssociationObject($obj, $queue, $seen);
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return true;
    }

    /**
     * Process a single association object
     */
    private static function processAssociationObject($obj, &$queue, &$seen, $allowChildBom = true)
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
            // Keep edge even when quantity is missing/zero (common on nested BOM links); default to 1
            if ($quantity <= 0) {
                $quantity = 1;
            }

            self::$productMap[$obj->father]->addChild($obj->child, $quantity);
            self::$productMap[$obj->child]->addParent($obj->father);

            // If this link references a nested BOM, process that BOM immediately to pull its children
            if ($allowChildBom && !empty($obj->fk_bom_child)) {
                self::processChildBom((int)$obj->fk_bom_child, $queue, $seen);
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
            
            print '<tr><td class="titlefield">' . $langs->trans('KreapKitChildCount') . '</td><td colspan="3">' . $nbChildren . '</td></tr>';
            print '<tr><td class="titlefield">' . $langs->trans('KreapSourcePackageCount') . '</td><td colspan="3">' . $nbParents . '</td></tr>';
            print '</table>';
            print '</div>';
            print '<div class="clearboth"></div>';
            print dol_get_fiche_end();
            
        } catch (Exception $e) {
            self::addError("Failed to generate header section: " . $e->getMessage());
            print '<p style="color:red;">' . $langs->trans('KreapErrorHeaderSection') . '</p>';
        }
    }

    /**
     * Generate CSS and JavaScript for compact collapsible product trees.
     */
    private static function generateCollapsibleTreeAssets($langs)
    {
        print '<style>
.kreap-collapsible-tree{table-layout:auto;min-width:100%;width:max-content;max-width:none}
.kreap-collapsible-tree tr.kreap-tree-row.is-hidden{display:none}
.kreap-collapsible-tree th,.kreap-collapsible-tree td{vertical-align:middle;white-space:nowrap}
.kreap-collapsible-tree td:nth-child(3),.kreap-collapsible-tree td:nth-child(5){text-align:right}
.kreap-tree-scroll{max-width:100%;overflow-x:auto}
.kreap-tree-cell{white-space:nowrap}
.kreap-tree-toggle{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;margin-right:4px;border:1px solid #bbb;background:#fff;color:#333;border-radius:3px;line-height:16px;font-size:12px;cursor:pointer}
.kreap-tree-toggle-placeholder{display:inline-block;width:22px}
.kreap-tree-toggle[aria-expanded="true"]::before{content:"-"}
.kreap-tree-toggle[aria-expanded="false"]::before{content:"+"}
.kreap-tree-row.kreap-level-0 .kreap-tree-cell{font-weight:600}
.kreap-tree-row.kreap-selected-ingredient{background:#fff8d7}
</style>';

        print '<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("table.kreap-collapsible-tree").forEach(function (table) {
        var rows = Array.prototype.slice.call(table.querySelectorAll("tr.kreap-tree-row"));
        rows.forEach(function (row, index) {
            var level = parseInt(row.getAttribute("data-kreap-level") || "0", 10);
            var next = rows[index + 1];
            var nextLevel = next ? parseInt(next.getAttribute("data-kreap-level") || "0", 10) : -1;
            var firstCell = row.querySelector("td");
            if (!firstCell) return;
            firstCell.classList.add("kreap-tree-cell");

            if (next && nextLevel > level) {
                var button = document.createElement("button");
                button.type = "button";
                button.className = "kreap-tree-toggle";
                button.setAttribute("aria-expanded", level === 0 ? "true" : "false");
                button.setAttribute("title", ' . json_encode($langs->trans('KreapToggleBranch')) . ');
                button.addEventListener("click", function () {
                    var expanded = button.getAttribute("aria-expanded") === "true";
                    button.setAttribute("aria-expanded", expanded ? "false" : "true");
                    setDescendantsVisible(rows, index, level, !expanded);
                });
                firstCell.insertBefore(button, firstCell.firstChild);
                if (level > 0) {
                    setDescendantsVisible(rows, index, level, false);
                }
            } else {
                var spacer = document.createElement("span");
                spacer.className = "kreap-tree-toggle-placeholder";
                firstCell.insertBefore(spacer, firstCell.firstChild);
            }
        });
    });

    function setDescendantsVisible(rows, startIndex, parentLevel, visible) {
        for (var i = startIndex + 1; i < rows.length; i++) {
            var row = rows[i];
            var level = parseInt(row.getAttribute("data-kreap-level") || "0", 10);
            if (level <= parentLevel) break;
            var directChild = level === parentLevel + 1;
            if (visible && directChild) {
                row.classList.remove("is-hidden");
            } else {
                row.classList.add("is-hidden");
            }
            if (!visible) {
                var toggle = row.querySelector(".kreap-tree-toggle");
                if (toggle) toggle.setAttribute("aria-expanded", "false");
            }
        }
    }
});
</script>';
    }

    /**
     * Generate children section
     */
	private static function generateChildrenSection($productId, $langs)
	{
		try {
			$lp = self::getLocalProduct($productId);
			if (!$lp) {
				return;
			}

			$hasChildren = !empty($lp->children);
			$hasParents = !empty($lp->parents);

			// Hide the child list when the product only appears as a parent in MRP (and has no components of its own).
			if (!$hasChildren && $hasParents) {
				return;
			}

			print '<p><strong>' . $langs->trans('KreapKitComponentsList') . '</strong></p>';
			print '<div class="kreap-tree-scroll"><table class="noborder kreap-collapsible-tree" width="100%">';
			
			self::printChildParentTableHead($langs, self::MODE_CHILD);

            $maxDepth = self::MAX_HIERARCHY_DEPTH;
            
            // Print top-level row
            self::printLine($productId, 0, 0, array(), true, 0, 1, self::MODE_CHILD);
            
            // Recursively display children
            $visitedChildren = array();
            self::fancyChildRecursive($productId, 1, $maxDepth, $visitedChildren, array());
            
            print '</table></div><br>';
            
        } catch (Exception $e) {
            self::addError("Failed to generate children section: " . $e->getMessage());
            print '<p style="color:red;">' . $langs->trans('KreapErrorChildrenSection') . '</p>';
        }
    }

    /**
     * Generate parents section
     */
    private static function generateParentsSection($productId, $langs)
    {
        try {
            $rows = 0;
            $rows += self::generateFilteredParentsSection($productId, $langs->trans('KreapAssociatedSubproductsList'), 'association');
            $rows += self::generateFilteredParentsSection($productId, $langs->trans('KreapBomParentsList'), 'bom');

            if ($rows === 0) {
                print '<p><strong>' . $langs->trans('KreapKitParentsList') . '</strong></p>';
                print '<div class="kreap-tree-scroll"><table class="noborder kreap-collapsible-tree" width="100%">';
                self::printChildParentTableHead($langs, self::MODE_PARENT);
                self::printLine($productId, 0, 0, array(), true, 0, 1, self::MODE_PARENT);
                print '</table></div><br>';
            }
            
        } catch (Exception $e) {
            self::addError("Failed to generate parents section: " . $e->getMessage());
            print '<p style="color:red;">' . $langs->trans('KreapErrorParentsSection') . '</p>';
        }
    }

    /**
     * Generate one filtered upstream parent tree.
     */
    private static function generateFilteredParentsSection($productId, $title, $sourceFilter)
    {
        global $langs;

        ob_start();

        print '<p><strong>' . $title . '</strong></p>';
        print '<div class="kreap-tree-scroll"><table class="noborder kreap-collapsible-tree" width="100%">';
        self::printChildParentTableHead($langs, self::MODE_PARENT);
        self::printLine($productId, 0, 0, array(), true, 0, 1, self::MODE_PARENT);

        $visitedParents = array();
        $rows = self::fancyParentRecursive($productId, 1, self::MAX_HIERARCHY_DEPTH, $visitedParents, array(), $sourceFilter);

        print '</table></div><br>';

        $html = ob_get_clean();
        if ($rows > 0) {
            print $html;
        }

        return $rows;
    }

    /**
     * Generate complete BOM and associated-product traceability for the selected product.
     */
    private static function generateBomTraceabilitySection($productId, $langs)
    {
        global $conf, $user;

        try {
            if (empty($conf->bom->enabled) || (!$user->hasRight('bom', 'read') && !$user->hasRight('bom', 'lire'))) {
                return;
            }

            $visitedProducts = array();
            $visitedBoms = array();

            print '<p><strong>' . $langs->trans('KreapBomTraceability') . '</strong></p>';
            print '<table class="noborder" width="100%">';
            self::printTraceabilityTableHead($langs);

            $rows = self::printProductTraceabilityRows($productId, 0, $visitedProducts, $visitedBoms);
            $rows += self::printWhereUsedTraceabilityRows($productId, 0);

            if ($rows === 0) {
                print '<tr><td colspan="6">' . $langs->trans('KreapNoBomTraceability') . '</td></tr>';
            }

            print '</table><br>';
        } catch (Exception $e) {
            self::addError("Failed to generate BOM traceability section: " . $e->getMessage());
            print '<p style="color:red;">' . $langs->trans('KreapErrorBomTraceabilitySection') . '</p>';
        }
    }

    /**
     * Print standard traceability table header.
     */
    private static function printTraceabilityTableHead($langs)
    {
        print '<tr class="liste_titre">';
        print '<td width="16%">' . $langs->trans('Source') . '</td>';
        print '<td width="18%">' . $langs->trans('Reference') . '</td>';
        print '<td width="36%">' . $langs->trans('Designation') . '</td>';
        print '<td width="10%" class="right">' . $langs->trans('Qty') . '</td>';
        print '<td width="10%">' . $langs->trans('Type') . '</td>';
        print '<td width="10%">' . $langs->trans('Entity') . '</td>';
        print '</tr>';
    }

    /**
     * Print traceability rows for one product and recurse into its BOM/sub-association graph.
     */
    private static function printProductTraceabilityRows($productId, $level, &$visitedProducts, &$visitedBoms)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || $level > self::MAX_HIERARCHY_DEPTH) {
            return 0;
        }

        if (!empty($visitedProducts[$productId])) {
            self::printTraceabilityCycleRow('product', $productId, $level);
            return 1;
        }

        $visitedProducts[$productId] = true;
        $rows = 0;

        $boms = self::loadTraceabilityBomsForProduct($productId);
        foreach ($boms as $bom) {
            $rows += self::printBomTraceabilityRows($bom, $level, $visitedProducts, $visitedBoms);
        }

        $associations = self::loadTraceabilityAssociatedProducts($productId);
        foreach ($associations as $association) {
            self::printAssociatedProductTraceabilityRow($association, $level);
            $rows++;

            $childId = (int) $association->child_id;
            if ($childId > 0) {
                $rows += self::printProductTraceabilityRows($childId, $level + 1, $visitedProducts, $visitedBoms);
            }
        }

        unset($visitedProducts[$productId]);

        return $rows;
    }

    /**
     * Print direct upstream BOM and association usage for the selected product.
     */
    private static function printWhereUsedTraceabilityRows($productId, $level)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || $level > self::MAX_HIERARCHY_DEPTH) {
            return 0;
        }

        $rows = 0;

        $bomUsages = self::loadTraceabilityBomUsagesForProduct($productId);
        foreach ($bomUsages as $usage) {
            self::printBomUsageTraceabilityRow($usage, $level);
            $rows++;
        }

        $associationUsages = self::loadTraceabilityAssociationUsagesForProduct($productId);
        foreach ($associationUsages as $usage) {
            self::printAssociationUsageTraceabilityRow($usage, $level);
            $rows++;
        }

        return $rows;
    }

    /**
     * Print one BOM and all its lines, including lines linked to a child BOM.
     */
    private static function printBomTraceabilityRows($bom, $level, &$visitedProducts, &$visitedBoms)
    {
        $bomId = (int) $bom->bom_id;
        if ($bomId <= 0 || $level > self::MAX_HIERARCHY_DEPTH) {
            return 0;
        }

        if (!empty($visitedBoms[$bomId])) {
            self::printTraceabilityCycleRow('bom', $bomId, $level);
            return 1;
        }

        $visitedBoms[$bomId] = true;
        self::printBomTraceabilityHeaderRow($bom, $level);
        $rows = 1;

        $lines = self::loadTraceabilityBomLines($bomId);
        foreach ($lines as $line) {
            self::printBomLineTraceabilityRow($line, $level + 1);
            $rows++;

            if (!empty($line->child_bom_id)) {
                $childBom = self::loadTraceabilityBomById((int) $line->child_bom_id);
                if ($childBom) {
                    $rows += self::printBomTraceabilityRows($childBom, $level + 2, $visitedProducts, $visitedBoms);
                }
            } elseif (!empty($line->product_id)) {
                $rows += self::printProductTraceabilityRows((int) $line->product_id, $level + 2, $visitedProducts, $visitedBoms);
            }
        }

        unset($visitedBoms[$bomId]);

        return $rows;
    }

    /**
     * Load active BOMs composing a product with current-entity BOMs taking precedence over shared BOMs.
     */
    private static function loadTraceabilityBomsForProduct($productId)
    {
        global $db, $conf;

        $records = array();

        $sql = "SELECT b.rowid AS bom_id, b.ref AS bom_ref, b.label AS bom_label, b.qty AS bom_qty,";
        $sql .= " b.bomtype, b.entity, b.fk_product AS product_id";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bom_bom AS b";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product";
        $sql .= " WHERE b.fk_product = " . ((int) $productId);
        $sql .= " AND b.bomtype IN (0,1)";
        $sql .= " AND b.status = 1";
        $sql .= " AND b.entity IN (0," . getEntity('bom') . ")";
        $sql .= " AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (";
        $sql .= " SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom AS b2";
        $sql .= " WHERE b2.fk_product = b.fk_product";
        $sql .= " AND b2.entity = " . ((int) $conf->entity);
        $sql .= " AND b2.bomtype = b.bomtype";
        $sql .= " AND b2.status = 1";
        $sql .= ")))";
        $sql .= " AND p.entity IN (" . getEntity('product') . ")";
        $sql .= " ORDER BY b.entity DESC, b.bomtype ASC, b.ref ASC, b.rowid ASC";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load traceability BOMs for product $productId: " . $db->lasterror());
            return $records;
        }

        while ($obj = $db->fetch_object($resql)) {
            $records[] = $obj;
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return $records;
    }

    /**
     * Load one active BOM by id, respecting entity scope.
     */
    private static function loadTraceabilityBomById($bomId)
    {
        global $db;

        $sql = "SELECT b.rowid AS bom_id, b.ref AS bom_ref, b.label AS bom_label, b.qty AS bom_qty,";
        $sql .= " b.bomtype, b.entity, b.fk_product AS product_id";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bom_bom AS b";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product";
        $sql .= " WHERE b.rowid = " . ((int) $bomId);
        $sql .= " AND b.bomtype IN (0,1)";
        $sql .= " AND b.status = 1";
        $sql .= " AND b.entity IN (0," . getEntity('bom') . ")";
        $sql .= " AND p.entity IN (" . getEntity('product') . ")";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load traceability BOM $bomId: " . $db->lasterror());
            return null;
        }

        $obj = $db->fetch_object($resql);
        $db->free($resql);
        self::$processStats['database_operations']++;

        return $obj ?: null;
    }

    /**
     * Load BOM lines with either a direct product or a child BOM target.
     */
    private static function loadTraceabilityBomLines($bomId)
    {
        global $db;

        $records = array();

        $sql = "SELECT bl.rowid AS line_id, bl.qty, bl.position, bl.description,";
        $sql .= " bl.fk_product AS direct_product_id, bl.fk_bom_child AS child_bom_id,";
        $sql .= " COALESCE(bl.fk_product, cb.fk_product) AS product_id,";
        $sql .= " COALESCE(p.ref, cp.ref) AS product_ref, COALESCE(p.label, cp.label) AS product_label,";
        $sql .= " cb.ref AS child_bom_ref, cb.label AS child_bom_label, cb.bomtype AS child_bomtype, cb.entity AS child_bom_entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bom_bomline AS bl";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = bl.fk_product";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS cb ON cb.rowid = bl.fk_bom_child";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS cp ON cp.rowid = cb.fk_product";
        $sql .= " WHERE bl.fk_bom = " . ((int) $bomId);
        $sql .= " AND (p.rowid IS NULL OR p.entity IN (" . getEntity('product') . "))";
        $sql .= " AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))";
        $sql .= " AND (cp.rowid IS NULL OR cp.entity IN (" . getEntity('product') . "))";
        $sql .= " ORDER BY bl.position ASC, bl.rowid ASC";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load traceability BOM lines for BOM $bomId: " . $db->lasterror());
            return $records;
        }

        while ($obj = $db->fetch_object($resql)) {
            $records[] = $obj;
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return $records;
    }

    /**
     * Load product associations for a product.
     */
    private static function loadTraceabilityAssociatedProducts($productId)
    {
        global $db;

        $records = array();

        $sql = "SELECT pa.fk_product_pere AS parent_id, pa.fk_product_fils AS child_id, pa.qty,";
        $sql .= " child.ref AS child_ref, child.label AS child_label, child.entity AS child_entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = pa.fk_product_pere";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS child ON child.rowid = pa.fk_product_fils";
        $sql .= " WHERE pa.fk_product_pere = " . ((int) $productId);
        $sql .= " AND parent.entity IN (" . getEntity('product') . ")";
        $sql .= " AND child.entity IN (" . getEntity('product') . ")";
        $sql .= " ORDER BY child.ref ASC, child.rowid ASC";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load associated products for traceability product $productId: " . $db->lasterror());
            return $records;
        }

        while ($obj = $db->fetch_object($resql)) {
            $records[] = $obj;
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return $records;
    }

    /**
     * Load active BOMs that use the product as a direct line or child-BOM produced product.
     */
    private static function loadTraceabilityBomUsagesForProduct($productId)
    {
        global $db, $conf;

        $records = array();

        $sql = "SELECT b.rowid AS bom_id, b.ref AS bom_ref, b.label AS bom_label, b.bomtype, b.entity,";
        $sql .= " b.fk_product AS parent_product_id, parent.ref AS parent_product_ref, parent.label AS parent_product_label,";
        $sql .= " bl.rowid AS line_id, bl.qty AS line_qty, bl.fk_bom_child AS child_bom_id";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bom_bomline AS bl";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.rowid = bl.fk_bom";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = b.fk_product";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS direct_product ON direct_product.rowid = bl.fk_product";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS cb ON cb.rowid = bl.fk_bom_child";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS child_bom_product ON child_bom_product.rowid = cb.fk_product";
        $sql .= " WHERE COALESCE(bl.fk_product, cb.fk_product) = " . ((int) $productId);
        $sql .= " AND b.bomtype IN (0,1)";
        $sql .= " AND b.status = 1";
        $sql .= " AND b.entity IN (0," . getEntity('bom') . ")";
        $sql .= " AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (";
        $sql .= " SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom AS b2";
        $sql .= " WHERE b2.fk_product = b.fk_product";
        $sql .= " AND b2.entity = " . ((int) $conf->entity);
        $sql .= " AND b2.bomtype = b.bomtype";
        $sql .= " AND b2.status = 1";
        $sql .= ")))";
        $sql .= " AND parent.entity IN (" . getEntity('product') . ")";
        $sql .= " AND (direct_product.rowid IS NULL OR direct_product.entity IN (" . getEntity('product') . "))";
        $sql .= " AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))";
        $sql .= " AND (child_bom_product.rowid IS NULL OR child_bom_product.entity IN (" . getEntity('product') . "))";
        $sql .= " ORDER BY b.entity DESC, b.ref ASC, bl.position ASC, bl.rowid ASC";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load BOM usages for traceability product $productId: " . $db->lasterror());
            return $records;
        }

        while ($obj = $db->fetch_object($resql)) {
            $records[] = $obj;
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return $records;
    }

    /**
     * Load associated products that use this product as a component.
     */
    private static function loadTraceabilityAssociationUsagesForProduct($productId)
    {
        global $db;

        $records = array();

        $sql = "SELECT pa.fk_product_pere AS parent_id, pa.fk_product_fils AS child_id, pa.qty,";
        $sql .= " parent.ref AS parent_ref, parent.label AS parent_label, parent.entity AS parent_entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = pa.fk_product_pere";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS child ON child.rowid = pa.fk_product_fils";
        $sql .= " WHERE pa.fk_product_fils = " . ((int) $productId);
        $sql .= " AND parent.entity IN (" . getEntity('product') . ")";
        $sql .= " AND child.entity IN (" . getEntity('product') . ")";
        $sql .= " ORDER BY parent.ref ASC, parent.rowid ASC";

        $resql = $db->query($sql);
        if (!$resql) {
            self::addError("Failed to load association usages for traceability product $productId: " . $db->lasterror());
            return $records;
        }

        while ($obj = $db->fetch_object($resql)) {
            $records[] = $obj;
        }

        $db->free($resql);
        self::$processStats['database_operations']++;

        return $records;
    }

    /**
     * Print a BOM header traceability row.
     */
    private static function printBomTraceabilityHeaderRow($bom, $level)
    {
        global $langs;

        $bomUrl = DOL_URL_ROOT . '/bom/bom_card.php?id=' . ((int) $bom->bom_id);
        $ref = '<a href="' . dol_escape_htmltag($bomUrl) . '">' . img_object('', 'bom') . ' ' . dol_escape_htmltag($bom->bom_ref) . '</a>';

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $langs->trans('BOM') . '</td>';
        print '<td>' . $ref . '</td>';
        print '<td>' . dol_escape_htmltag($bom->bom_label) . '</td>';
        print '<td class="right">' . price2num($bom->bom_qty, 'MS') . '</td>';
        print '<td>' . self::getBomTypeLabel((int) $bom->bomtype) . '</td>';
        print '<td>' . ((int) $bom->entity) . '</td>';
        print '</tr>';
    }

    /**
     * Print a BOM line traceability row.
     */
    private static function printBomLineTraceabilityRow($line, $level)
    {
        global $langs;

        $productId = (int) $line->product_id;
        $product = $productId > 0 ? self::loadAndValidateProduct($productId) : null;
        $ref = $product && method_exists($product, 'getNomUrl') ? $product->getNomUrl(1) : dol_escape_htmltag($line->product_ref);
        $source = !empty($line->child_bom_id) ? $langs->trans('KreapSubBomLine') : $langs->trans('KreapBomLine');
        $type = !empty($line->child_bom_id) ? self::getBomTypeLabel((int) $line->child_bomtype) : $langs->trans('Product');
        $entity = !empty($line->child_bom_id) ? (int) $line->child_bom_entity : '';

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $source . '</td>';
        print '<td>' . $ref . '</td>';
        print '<td>' . dol_escape_htmltag($line->product_label) . '</td>';
        print '<td class="right">' . price2num($line->qty, 'MS') . '</td>';
        print '<td>' . $type . '</td>';
        print '<td>' . $entity . '</td>';
        print '</tr>';
    }

    /**
     * Print an associated product traceability row.
     */
    private static function printAssociatedProductTraceabilityRow($association, $level)
    {
        global $langs;

        $product = self::loadAndValidateProduct((int) $association->child_id);
        $ref = $product && method_exists($product, 'getNomUrl') ? $product->getNomUrl(1) : dol_escape_htmltag($association->child_ref);

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $langs->trans('KreapAssociatedProduct') . '</td>';
        print '<td>' . $ref . '</td>';
        print '<td>' . dol_escape_htmltag($association->child_label) . '</td>';
        print '<td class="right">' . price2num($association->qty, 'MS') . '</td>';
        print '<td>' . $langs->trans('Product') . '</td>';
        print '<td>' . ((int) $association->child_entity) . '</td>';
        print '</tr>';
    }

    /**
     * Print an upstream BOM usage row.
     */
    private static function printBomUsageTraceabilityRow($usage, $level)
    {
        global $langs;

        $bomUrl = DOL_URL_ROOT . '/bom/bom_card.php?id=' . ((int) $usage->bom_id);
        $ref = '<a href="' . dol_escape_htmltag($bomUrl) . '">' . img_object('', 'bom') . ' ' . dol_escape_htmltag($usage->bom_ref) . '</a>';
        $parentProduct = self::loadAndValidateProduct((int) $usage->parent_product_id);
        $parentRef = $parentProduct && method_exists($parentProduct, 'getNomUrl') ? $parentProduct->getNomUrl(1) : dol_escape_htmltag($usage->parent_product_ref);
        $designation = $parentRef . ' - ' . dol_escape_htmltag($usage->parent_product_label);

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $langs->trans('KreapUsedInBom') . '</td>';
        print '<td>' . $ref . '</td>';
        print '<td>' . $designation . '</td>';
        print '<td class="right">' . price2num($usage->line_qty, 'MS') . '</td>';
        print '<td>' . self::getBomTypeLabel((int) $usage->bomtype) . '</td>';
        print '<td>' . ((int) $usage->entity) . '</td>';
        print '</tr>';
    }

    /**
     * Print an upstream association usage row.
     */
    private static function printAssociationUsageTraceabilityRow($usage, $level)
    {
        global $langs;

        $parentProduct = self::loadAndValidateProduct((int) $usage->parent_id);
        $ref = $parentProduct && method_exists($parentProduct, 'getNomUrl') ? $parentProduct->getNomUrl(1) : dol_escape_htmltag($usage->parent_ref);

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $langs->trans('KreapUsedInAssociatedProduct') . '</td>';
        print '<td>' . $ref . '</td>';
        print '<td>' . dol_escape_htmltag($usage->parent_label) . '</td>';
        print '<td class="right">' . price2num($usage->qty, 'MS') . '</td>';
        print '<td>' . $langs->trans('Product') . '</td>';
        print '<td>' . ((int) $usage->parent_entity) . '</td>';
        print '</tr>';
    }

    /**
     * Print a cycle guard row.
     */
    private static function printTraceabilityCycleRow($type, $id, $level)
    {
        global $langs;

        print '<tr class="oddeven">';
        print '<td>' . self::buildTraceabilityIndent($level) . $langs->trans('KreapTraceabilityCycle') . '</td>';
        print '<td>' . dol_escape_htmltag($type . ':' . (int) $id) . '</td>';
        print '<td colspan="4">' . $langs->trans('KreapTraceabilityCycleSkipped') . '</td>';
        print '</tr>';
    }

    /**
     * Build visible indentation for the traceability table.
     */
    private static function buildTraceabilityIndent($level)
    {
        return str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', max(0, (int) $level));
    }

    /**
     * Return a translated BOM type label.
     */
    private static function getBomTypeLabel($bomtype)
    {
        global $langs;

        return $bomtype === 1 ? $langs->trans('Disassemble') : $langs->trans('Manufacturing');
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
            print '<td width="20%">' . $langs->trans('Qty') . '</td>';
            print '<td width="20%">' . $langs->trans('Type') . '</td>';
            print '<td width="10%">' . $langs->trans('CostPrice') . '</td>';
            print '<td width="10%">' . $langs->trans('SubTotal') . '</td>';
            print '</tr>';

            $lp = self::getLocalProduct($productId);
            if (!$lp) {
                print '<tr><td colspan="6">' . $langs->trans('KreapNoProductData') . '</td></tr>';
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
            print '<p style="color:red;">' . $langs->trans('KreapErrorTechnicalSheet') . '</p>';
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
    private static function printChildParentTableHead($langs, $mode = self::MODE_CHILD)
    {
        print '<tr class="liste_titre">';
        print '<td width="20%">' . $langs->trans("Reference") . '</td>';
        print '<td width="35%">' . $langs->trans("Designation") . '</td>';
        print '<td width="10%">' . ($mode === self::MODE_PARENT ? $langs->trans("Qty") : $langs->trans("Subproducts")) . '</td>';
        print '<td width="10%">' . ($mode === self::MODE_PARENT ? $langs->trans("KreapRelation") : $langs->trans("Type")) . '</td>';
        print '<td width="5%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';
    }

    /**
     * Enhanced printLine method with better error handling
     * Fixed PHP 5.6+ compatibility by removing type hints
     */
    private static function printLine($productId, $qty, $level, $prefix, $isLast, $index, $count, $mode, $relatedProductId = 0)
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
            $type = (!empty($lp->children)) ? $langs->trans('KreapTechnicalSheetType') : '';
            if ($mode === self::MODE_PARENT && $level > 0 && $relatedProductId > 0) {
                $relation = self::loadParentRelationInfo($productId, $relatedProductId);
                $assoc = $relation['qty'];
                $type = $relation['type'];
            } elseif ($mode === self::MODE_PARENT && $level === 0) {
                $type = $langs->trans('KreapSelectedProduct');
            }
            
            // Format price
            $priceStr = self::formatPrice($lp->buyprice, $conf);

            // Generate row
            print '<tr class="kreap-tree-row kreap-level-' . ((int) $level) . '" data-kreap-level="' . ((int) $level) . '">';
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
        global $langs;

        if ($qty > 0) {
            return number_format($qty, 3, '.', '');
        } elseif (!empty($lp->children)) {
            return count($lp->children);
        } elseif (!empty($lp->parents)) {
            return count($lp->parents) . ' ' . $langs->trans('KreapParentsShort');
        }
        
        return '';
    }

    /**
     * Load relation metadata for a parent row in the upstream tree.
     */
    private static function loadParentRelationInfo($parentProductId, $childProductId)
    {
        global $db, $conf, $langs;

        $result = array(
            'qty' => '',
            'type' => $langs->trans('KreapParentProduct'),
            'source' => ''
        );

        $sql = "SELECT bl.qty, b.ref AS bom_ref";
        $sql .= " FROM " . MAIN_DB_PREFIX . "bom_bomline AS bl";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bom_bom AS b ON b.rowid = bl.fk_bom";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = b.fk_product";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS direct_product ON direct_product.rowid = bl.fk_product";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom AS cb ON cb.rowid = bl.fk_bom_child";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product AS child_bom_product ON child_bom_product.rowid = cb.fk_product";
        $sql .= " WHERE b.fk_product = " . ((int) $parentProductId);
        $sql .= " AND COALESCE(bl.fk_product, cb.fk_product) = " . ((int) $childProductId);
        $sql .= " AND b.bomtype IN (0,1)";
        $sql .= " AND b.status = 1";
        $sql .= " AND b.entity IN (0," . getEntity('bom') . ")";
        $sql .= " AND (b.entity = " . ((int) $conf->entity) . " OR (b.entity = 0 AND NOT EXISTS (";
        $sql .= " SELECT 1 FROM " . MAIN_DB_PREFIX . "bom_bom AS b2";
        $sql .= " WHERE b2.fk_product = b.fk_product";
        $sql .= " AND b2.entity = " . ((int) $conf->entity);
        $sql .= " AND b2.bomtype = b.bomtype";
        $sql .= " AND b2.status = 1";
        $sql .= ")))";
        $sql .= " AND parent.entity IN (" . getEntity('product') . ")";
        $sql .= " AND (direct_product.rowid IS NULL OR direct_product.entity IN (" . getEntity('product') . "))";
        $sql .= " AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))";
        $sql .= " AND (child_bom_product.rowid IS NULL OR child_bom_product.entity IN (" . getEntity('product') . "))";
        $sql .= " ORDER BY b.entity DESC, b.ref ASC, bl.position ASC, bl.rowid ASC";

        $resql = $db->query($sql);
        if ($resql) {
            if ($obj = $db->fetch_object($resql)) {
                $result['qty'] = price2num($obj->qty, 'MS');
                $result['type'] = $langs->trans('KreapBomParent') . ' ' . dol_escape_htmltag($obj->bom_ref);
                $result['source'] = 'bom';
            }
            $db->free($resql);
            self::$processStats['database_operations']++;

            if ($result['qty'] !== '') {
                return $result;
            }
        } else {
            self::addError("Failed to load BOM parent relation $parentProductId->$childProductId: " . $db->lasterror());
        }

        $sql = "SELECT pa.qty";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS parent ON parent.rowid = pa.fk_product_pere";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS child ON child.rowid = pa.fk_product_fils";
        $sql .= " WHERE pa.fk_product_pere = " . ((int) $parentProductId);
        $sql .= " AND pa.fk_product_fils = " . ((int) $childProductId);
        $sql .= " AND parent.entity IN (" . getEntity('product') . ")";
        $sql .= " AND child.entity IN (" . getEntity('product') . ")";

        $resql = $db->query($sql);
        if ($resql) {
            if ($obj = $db->fetch_object($resql)) {
                $result['qty'] = price2num($obj->qty, 'MS');
                $result['type'] = $langs->trans('KreapAssociatedSubproduct');
                $result['source'] = 'association';
            }
            $db->free($resql);
            self::$processStats['database_operations']++;
        } else {
            self::addError("Failed to load association parent relation $parentProductId->$childProductId: " . $db->lasterror());
        }

        return $result;
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
    private static function fancyParentRecursive($productId, $level, $maxLevel, &$visited, $prefix, $sourceFilter = '')
    {
        if (in_array($productId, $visited, true) || $level > $maxLevel) {
            return 0;
        }
        
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return 0;
        }

        $pars = $lp->parents;
        $n = count($pars);
        $printedRows = 0;

        for ($i = 0; $i < $n; $i++) {
            $parId = $pars[$i];
            if (in_array($parId, $visited, true)) {
                continue;
            }
            
            $relation = self::loadParentRelationInfo($parId, $productId);
            if ($sourceFilter !== '' && $relation['source'] !== $sourceFilter) {
                continue;
            }

            $isLast = ($i == $n - 1);

            $parPrefix = $prefix;
            $parPrefix[$level] = !$isLast;

            self::printLine($parId, 0, $level, $parPrefix, $isLast, $i, $n, self::MODE_PARENT, $productId);
            $printedRows++;

            if ($level < $maxLevel) {
                $printedRows += self::fancyParentRecursive($parId, $level + 1, $maxLevel, $visited, $parPrefix);
            }
        }

        return $printedRows;
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
        global $langs;
        if (abs($val1 - $val2) < self::PRICE_COMPARISON_DELTA) {
            $matchLabel = $langs->trans('KreapValuesMatch');
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/tick.png" alt="' . dol_escape_htmltag($matchLabel) . '" title="' . dol_escape_htmltag($matchLabel) . '">';
        } else {
            $diffLabel = $langs->trans('KreapValuesDiffer');
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/error.png" alt="' . dol_escape_htmltag($diffLabel) . '" title="' . dol_escape_htmltag($diffLabel) . '">';
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

            ProductUpdater::prepareProductCostUpdate($prod);
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
        $sql .= " AND entity IN (" . getEntity('product') . ")";
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
