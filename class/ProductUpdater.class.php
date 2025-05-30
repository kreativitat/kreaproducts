<?php

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

/**
 * Enhanced ProductHierarchy class for managing product cost propagation
 * with improved error handling, validation, and performance optimization.
 */
class ProductHierarchy
{
    // Constants for configuration
    const SYNC_FIELD = 'kreap_spread_buyprice';
    const GLOBAL_CONST = 'KREAP_SPREAD_BUYPRICE';
    const DELTA = 0.001;
    const MAX_HIERARCHY_DEPTH = 50;
    const BATCH_SIZE = 100;
    
    // Class state management
    public static $inProgress = false;
    public static $productMap = array(); // Legacy exposure
    
    // Performance tracking
    private static $updateCount = 0;
    private static $startTime = 0;
    private static $errors = array();
    private static $warnings = array();

    /**
     * Main entry point for updating product attributes
     *
     * @param int $productId Starting product ID
     * @param User $user User performing the update
     * @return int 1 on success, 0 on failure or skip
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db, $conf;

        // Prevent concurrent execution
        if (self::$inProgress) {
            dol_syslog(__METHOD__ . ' skipped – already running', LOG_DEBUG);
            return 0;
        }
        
        self::$inProgress = true;
        self::$updateCount = 0;
        self::$startTime = microtime(true);
        self::clearErrors();

        try {
            // Enhanced validation with better error messages
            if (!self::validateGlobalSettings($conf)) {
                return 0;
            }

            $product = self::validateAndLoadProduct($productId, $db);
            if (!$product) {
                return 0;
            }

            // Build enhanced graph with validation
            $result = self::buildAndProcessGraph($productId, $user, $db);
            
            // Performance logging
            $duration = microtime(true) - self::$startTime;
            dol_syslog(__METHOD__ . " completed: result=$result, updates=" . self::$updateCount . ", duration={$duration}s", LOG_INFO);
            
            return $result;
            
        } catch (Exception $e) {
            self::addError('Processing failed: ' . $e->getMessage());
            dol_syslog(__METHOD__ . ' error: ' . $e->getMessage(), LOG_ERR);
            return 0;
        } finally {
            self::$inProgress = false;
        }
    }

    /**
     * Validate global configuration settings
     */
    private static function validateGlobalSettings($conf)
    {
        $globalConstName = self::GLOBAL_CONST;
        $globalOn = empty($conf->global->$globalConstName) ? true : (bool)$conf->global->$globalConstName;
        
        if (!$globalOn) {
            dol_syslog(__METHOD__ . ' global sync disabled', LOG_INFO);
            return false;
        }
        return true;
    }

    /**
     * Validate and load product with enhanced error handling
     */
    private static function validateAndLoadProduct($productId, $db)
    {
        if (!is_numeric($productId) || $productId <= 0) {
            self::addError("Invalid product ID: $productId");
            return null;
        }

        $prod = new Product($db);
        if ($prod->fetch($productId) <= 0) {
            self::addError("Cannot fetch product ID: $productId");
            dol_syslog(__METHOD__ . " invalid product id $productId", LOG_ERR);
            return null;
        }

        // Enhanced extrafield validation
        $extrafields = new ExtraFields($db);
        $prod->fetch_optionals($productId, $extrafields);
        
        $syncFieldName = 'options_' . self::SYNC_FIELD;
        if (empty($prod->array_options[$syncFieldName])) {
            dol_syslog(__METHOD__ . " sync disabled by extra‑field for pid=$productId", LOG_DEBUG);
            return null;
        }

        // Validate product state
        if ($prod->cost_price < 0) {
            self::addWarning("Negative cost price for product $productId: " . $prod->cost_price);
            dol_syslog(__METHOD__ . " warning: negative cost price for pid=$productId", LOG_WARNING);
        }

        return $prod;
    }

    /**
     * Build product graph and process updates
     */
    private static function buildAndProcessGraph($productId, $user, $db)
    {
        // Build graph with enhanced error handling
        $graphResult = GraphBuilder::aroundPivot($productId);
        if (!$graphResult['success']) {
            self::addError('Graph build failed: ' . $graphResult['error']);
            dol_syslog(__METHOD__ . ' graph build failed: ' . $graphResult['error'], LOG_ERR);
            return 0;
        }

        $nodes = $graphResult['nodes'];
        $stats = $graphResult['stats'];
        
        self::$productMap = $nodes; // Legacy exposure
        
        $pivot = isset($nodes[$productId]) ? $nodes[$productId] : null;
        if (!$pivot) {
            self::addError('Pivot product not found in graph');
            dol_syslog(__METHOD__ . ' pivot not found in graph', LOG_ERR);
            return 0;
        }

        // Enhanced propagation with batch updates
        $updatePlan = self::buildUpdatePlan($pivot, $nodes);
        $success = self::executeBatchUpdates($updatePlan, $user, $db);
        
        dol_syslog(__METHOD__ . " completed: nodes=" . $stats['nodeCount'] . ", relations=" . $stats['relationCount'] . ", updates=" . self::$updateCount, LOG_INFO);
        
        return $success ? 1 : 0;
    }

    /**
     * Build comprehensive update plan
     */
    private static function buildUpdatePlan($pivot, $nodes)
    {
        $visited = array();
        $updatePlan = array();
        
        self::planUpstreamUpdates($pivot, $nodes, $visited, $updatePlan, 0);
        
        // Sort by dependency order (deeper nodes first)
        usort($updatePlan, function($a, $b) {
            if ($b['depth'] == $a['depth']) {
                return 0;
            }
            return ($b['depth'] > $a['depth']) ? 1 : -1;
        });
        
        return $updatePlan;
    }

    /**
     * Plan upstream updates recursively
     */
    private static function planUpstreamUpdates($node, &$nodes, &$visited, &$updatePlan, $depth)
    {
        if (isset($visited[$node->id])) {
            return;
        }
        
        if ($depth > self::MAX_HIERARCHY_DEPTH) {
            self::addWarning("Maximum hierarchy depth exceeded for node " . $node->id);
            dol_syslog(__METHOD__ . " max depth exceeded for node " . $node->id, LOG_WARNING);
            return;
        }
        
        $visited[$node->id] = true;

        foreach ($node->parents as $parentId => $qtyNotUsed) {
            if (!isset($nodes[$parentId])) {
                continue;
            }
            
            $parent = $nodes[$parentId];

            // Calculate new cost with enhanced validation
            $calculation = self::calculateNewCost($parent, $nodes);
            if (!$calculation['valid']) {
                self::addWarning("Invalid calculation for parent $parentId: " . $calculation['error']);
                dol_syslog(__METHOD__ . " invalid calculation for parent $parentId: " . $calculation['error'], LOG_WARNING);
                continue;
            }

            $newCost = $calculation['cost'];
            
            // Check if update needed with enhanced precision handling
            if (self::isUpdateNeeded($parent->cost, $newCost)) {
                $updatePlan[] = array(
                    'productId' => $parent->id,
                    'oldCost' => $parent->cost,
                    'newCost' => $newCost,
                    'depth' => $depth,
                    'childCount' => count($parent->children),
                    'calculation' => $calculation['details']
                );
                
                // Update in-memory for subsequent calculations
                $parent->cost = $newCost;
            }

            // Recurse with depth tracking
            self::planUpstreamUpdates($parent, $nodes, $visited, $updatePlan, $depth + 1);
        }
    }

    /**
     * Calculate new cost for a parent product
     */
    private static function calculateNewCost($parent, $nodes)
    {
        $newCost = 0.0;
        $details = array();
        $hasErrors = false;

        foreach ($parent->children as $childId => $qty) {
            $childNode = isset($nodes[$childId]) ? $nodes[$childId] : null;
            
            if (!$childNode) {
                $hasErrors = true;
                $details[] = "Missing child node: $childId";
                continue;
            }

            // Enhanced validation
            if ($qty <= 0) {
                $hasErrors = true;
                $details[] = "Invalid quantity for child $childId: $qty";
                continue;
            }

            if ($childNode->cost < 0) {
                $details[] = "Negative cost for child $childId: " . $childNode->cost;
            }

            $childCost = max(0, $childNode->cost); // Ensure non-negative
            $contribution = $qty * $childCost;
            $newCost += $contribution;
            
            $details[] = "Child $childId: $qty × $childCost = $contribution";
        }

        // Filter error messages for the main error field
        $errorMessages = array();
        foreach ($details as $detail) {
            if (strpos($detail, 'Missing') === 0 || strpos($detail, 'Invalid') === 0) {
                $errorMessages[] = $detail;
            }
        }

        return array(
            'valid' => !$hasErrors,
            'cost' => $newCost,
            'error' => $hasErrors ? implode('; ', $errorMessages) : null,
            'details' => $details
        );
    }

    /**
     * Check if update is needed based on cost difference
     */
    private static function isUpdateNeeded($oldCost, $newCost)
    {
        // Enhanced precision handling
        $delta = abs($newCost - $oldCost);
        $relativeDelta = $oldCost > 0 ? $delta / $oldCost : $delta;
        
        // Use both absolute and relative thresholds
        return $delta >= self::DELTA && $relativeDelta >= 0.001;
    }

    /**
     * Execute batch updates with transaction management
     */
    private static function executeBatchUpdates($updatePlan, $user, $db)
    {
        if (empty($updatePlan)) {
            dol_syslog(__METHOD__ . ' no updates needed', LOG_DEBUG);
            return true;
        }

        $batches = array_chunk($updatePlan, self::BATCH_SIZE);
        $totalSuccess = true;

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $totalBatches = count($batches);
            $batchSize = count($batch);
            
            dol_syslog(__METHOD__ . " processing batch $batchNumber/$totalBatches ($batchSize items)", LOG_DEBUG);
            
            if (!self::processBatch($batch, $user, $db)) {
                $totalSuccess = false;
            }
        }

        return $totalSuccess;
    }

    /**
     * Process a single batch of updates
     */
    private static function processBatch($batch, $user, $db)
    {
        $db->begin();
        $batchSuccess = true;

        try {
            foreach ($batch as $update) {
                if (!self::updateSingleProduct($update, $user, $db)) {
                    $batchSuccess = false;
                    break;
                }
            }

            if ($batchSuccess) {
                $db->commit();
                self::$updateCount += count($batch);
            } else {
                $db->rollback();
                self::addError('Batch update failed - transaction rolled back');
                dol_syslog(__METHOD__ . ' batch rollback due to error', LOG_ERR);
            }

        } catch (Exception $e) {
            $db->rollback();
            self::addError('Batch exception: ' . $e->getMessage());
            dol_syslog(__METHOD__ . ' batch exception: ' . $e->getMessage(), LOG_ERR);
            $batchSuccess = false;
        }

        return $batchSuccess;
    }

    /**
     * Update a single product with enhanced validation
     */
    private static function updateSingleProduct($update, $user, $db)
    {
        $product = new Product($db);
        if ($product->fetch($update['productId']) <= 0) {
            self::addError("Cannot fetch product for update: " . $update['productId']);
            dol_syslog(__METHOD__ . " cannot fetch pid=" . $update['productId'], LOG_ERR);
            return false;
        }

        // Validate current state hasn't changed unexpectedly
        if (abs($product->cost_price - $update['oldCost']) > self::DELTA) {
            self::addWarning("Concurrent modification detected for product " . $update['productId']);
            dol_syslog(__METHOD__ . " concurrent modification detected for pid=" . $update['productId'], LOG_WARNING);
            // Continue anyway, but log it
        }

        $product->cost_price = $update['newCost'];
        
        $result = $product->update($update['productId'], $user);
        if ($result <= 0) {
            self::addError("Product update failed for ID: " . $update['productId']);
            dol_syslog(__METHOD__ . " update failed for pid=" . $update['productId'], LOG_ERR);
            return false;
        }

        // Enhanced logging
        $oldCost = $update['oldCost'];
        $newCost = $update['newCost'];
        $childCount = $update['childCount'];
        
        dol_syslog("ProductHierarchy updated pid=" . $update['productId'] . " cost $oldCost→$newCost (children: $childCount)", LOG_DEBUG);
        
        // Log calculation details if available - FIXED: removed dol_syslog_level() call
        if (!empty($update['calculation'])) {
            $calculationDetails = implode('; ', $update['calculation']);
            dol_syslog("  Calculation details: $calculationDetails", LOG_DEBUG);
        }

        return true;
    }

    /**
     * Get comprehensive hierarchy statistics
     */
    public static function getHierarchyStats($productId)
    {
        $graphResult = GraphBuilder::aroundPivot($productId);
        if (!$graphResult['success']) {
            return array('error' => $graphResult['error']);
        }

        $nodes = $graphResult['nodes'];
        $stats = $graphResult['stats'];
        
        // Calculate additional statistics
        $leafNodes = array();
        $rootNodes = array();
        $totalCost = 0;
        
        foreach ($nodes as $node) {
            if (empty($node->children)) {
                $leafNodes[] = $node->id;
            }
            if (empty($node->parents)) {
                $rootNodes[] = $node->id;
            }
            $totalCost += $node->cost;
        }
        
        return array(
            'nodeCount' => $stats['nodeCount'],
            'relationCount' => $stats['relationCount'],
            'maxDepth' => isset($stats['maxDepth']) ? $stats['maxDepth'] : 0,
            'leafNodes' => $leafNodes,
            'rootNodes' => $rootNodes,
            'totalCost' => $totalCost
        );
    }

    /**
     * Validate hierarchy integrity
     */
    public static function validateHierarchy($productId)
    {
        $issues = array();
        $graphResult = GraphBuilder::aroundPivot($productId);
        
        if (!$graphResult['success']) {
            return array('error' => $graphResult['error']);
        }

        $nodes = $graphResult['nodes'];
        
        foreach ($nodes as $node) {
            // Check for negative costs
            if ($node->cost < 0) {
                $issues[] = "Negative cost for product " . $node->id . ": " . $node->cost;
            }
            
            // Check for orphaned nodes
            if (empty($node->children) && empty($node->parents) && $node->id != $productId) {
                $issues[] = "Orphaned node: " . $node->id;
            }
            
            // Check for invalid quantities
            foreach ($node->children as $childId => $qty) {
                if ($qty <= 0) {
                    $issues[] = "Invalid quantity for " . $node->id . " → $childId: $qty";
                }
            }
        }

        return array('issues' => $issues, 'isValid' => empty($issues));
    }

    /**
     * Error handling methods
     */
    private static function addError($message)
    {
        self::$errors[] = $message;
        dol_syslog("ProductHierarchy Error: $message", LOG_ERR);
    }

    private static function addWarning($message)
    {
        self::$warnings[] = $message;
        dol_syslog("ProductHierarchy Warning: $message", LOG_WARNING);
    }

    private static function clearErrors()
    {
        self::$errors = array();
        self::$warnings = array();
    }

    public static function getErrors()
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
}

/**
 * Enhanced ProductNode class with metadata support
 */
class ProductNode
{
    public $id;
    public $cost = 0.0;
    public $children = array(); // childId => qty
    public $parents = array();  // parentId => qty
    
    // Enhanced metadata
    public $name = '';
    public $ref = '';
    public $lastUpdated = null;
    public $isLeaf = false;
    public $isRoot = false;

    public function __construct($id, $cost = 0.0)
    {
        $this->id = (int)$id;
        $this->cost = (float)$cost;
        $this->lastUpdated = date('Y-m-d H:i:s');
    }

    /**
     * Update node metadata
     */
    public function updateMetadata()
    {
        $this->isLeaf = empty($this->children);
        $this->isRoot = empty($this->parents);
        $this->lastUpdated = date('Y-m-d H:i:s');
    }

    /**
     * Get child count
     */
    public function getChildCount()
    {
        return count($this->children);
    }

    /**
     * Get parent count
     */
    public function getParentCount()
    {
        return count($this->parents);
    }
}

/**
 * Enhanced GraphBuilder class with improved error handling
 */
final class GraphBuilder
{
    /**
     * Build product graph around a pivot product
     */
    public static function aroundPivot($pivotId)
    {
        global $db;

        try {
            $nodes = array();
            $queue = array($pivotId);
            $seen = array();
            $relationCount = 0;
            $maxDepth = 0;

            // Enhanced BFS with depth tracking
            while (!empty($queue)) {
                $pid = array_pop($queue);
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;

                if (!isset($nodes[$pid])) {
                    $nodes[$pid] = new ProductNode($pid);
                }

                // Enhanced query with better error handling
                $sql = 'SELECT pa.fk_product_pere AS parent, pa.fk_product_fils AS child, pa.qty,
                               p.label as parent_name, p.ref as parent_ref,
                               c.label as child_name, c.ref as child_ref
                        FROM ' . MAIN_DB_PREFIX . 'product_association pa
                        LEFT JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid = pa.fk_product_pere  
                        LEFT JOIN ' . MAIN_DB_PREFIX . 'product c ON c.rowid = pa.fk_product_fils
                        WHERE pa.fk_product_pere = ' . (int)$pid . '
                           OR pa.fk_product_fils = ' . (int)$pid;
                           
                $res = $db->query($sql);
                if (!$res) {
                    return array(
                        'success' => false, 
                        'error' => 'SQL error: ' . $db->lasterror(),
                        'nodes' => array(),
                        'stats' => array()
                    );
                }

                while ($row = $db->fetch_object($res)) {
                    $parentId = (int)$row->parent;
                    $childId = (int)$row->child;
                    $qty = (float)$row->qty;
                    $relationCount++;

                    // Validation
                    if ($qty <= 0) {
                        dol_syslog("GraphBuilder: Invalid quantity $qty for $parentId → $childId", LOG_WARNING);
                        continue;
                    }

                    // Enhanced node creation with metadata
                    if (!isset($nodes[$parentId])) {
                        $nodes[$parentId] = new ProductNode($parentId);
                        $nodes[$parentId]->name = $row->parent_name ? $row->parent_name : '';
                        $nodes[$parentId]->ref = $row->parent_ref ? $row->parent_ref : '';
                    }
                    
                    if (!isset($nodes[$childId])) {
                        $nodes[$childId] = new ProductNode($childId);
                        $nodes[$childId]->name = $row->child_name ? $row->child_name : '';
                        $nodes[$childId]->ref = $row->child_ref ? $row->child_ref : '';
                    }

                    // Build relationships (prevent duplicates)
                    $nodes[$parentId]->children[$childId] = $qty;
                    $nodes[$childId]->parents[$parentId] = $qty;

                    if (!isset($seen[$parentId])) {
                        $queue[] = $parentId;
                    }
                    if (!isset($seen[$childId])) {
                        $queue[] = $childId;
                    }
                }
                $db->free($res);
            }

            // Bulk-load costs with enhanced query
            self::loadCostsForNodes($nodes, $db);
            
            // Update metadata for all nodes
            foreach ($nodes as $node) {
                $node->updateMetadata();
            }

            return array(
                'success' => true,
                'nodes' => $nodes,
                'stats' => array(
                    'nodeCount' => count($nodes),
                    'relationCount' => $relationCount,
                    'maxDepth' => $maxDepth
                )
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'nodes' => array(),
                'stats' => array()
            );
        }
    }

    /**
     * Load cost information for all nodes
     */
    private static function loadCostsForNodes(&$nodes, $db)
    {
        if (empty($nodes)) {
            return;
        }

        $nodeIds = array_keys($nodes);
        $idList = implode(',', array_map('intval', $nodeIds));
        
        $sql = 'SELECT rowid, cost_price, label, ref 
                FROM ' . MAIN_DB_PREFIX . 'product 
                WHERE rowid IN (' . $idList . ')';
                
        $res = $db->query($sql);
        if (!$res) {
            dol_syslog('GraphBuilder: SQL error on cost fetch: ' . $db->lasterror(), LOG_ERR);
            return;
        }

        while ($row = $db->fetch_object($res)) {
            $id = (int)$row->rowid;
            if (isset($nodes[$id])) {
                $nodes[$id]->cost = max(0, (float)$row->cost_price); // Ensure non-negative
                $nodes[$id]->name = $row->label ? $row->label : $nodes[$id]->name;
                $nodes[$id]->ref = $row->ref ? $row->ref : $nodes[$id]->ref;
            }
        }
        $db->free($res);
    }
}
