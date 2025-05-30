<?php

class ProductHierarchy
{
    private const SYNC_FIELD   = 'kreap_spread_buyprice';
    private const GLOBAL_CONST = 'KREAP_SPREAD_BUYPRICE';
    private const DELTA        = 0.001;
    private const MAX_HIERARCHY_DEPTH = 50; // Prevent runaway hierarchies
    private const BATCH_SIZE = 100; // For bulk operations

    public static $inProgress = false;
    public static $productMap = []; // Legacy exposure
    
    // Performance tracking
    private static $updateCount = 0;
    private static $startTime = 0;

    public static function updateProductAttributes($productId, $user)
    {
        global $db, $conf;

        if (self::$inProgress) {
            dol_syslog(__METHOD__ . ' skipped – already running', LOG_DEBUG);
            return 0;
        }
        
        self::$inProgress = true;
        self::$updateCount = 0;
        self::$startTime = microtime(true);

        try {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

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
            dol_syslog(__METHOD__ . " completed: {$result} result, {$self::$updateCount} updates, {$duration}s", LOG_INFO);
            
            return $result;
            
        } catch (Exception $e) {
            dol_syslog(__METHOD__ . ' error: ' . $e->getMessage(), LOG_ERR);
            return 0;
        } finally {
            self::$inProgress = false;
        }
    }

    private static function validateGlobalSettings($conf): bool
    {
        $globalOn = empty($conf->global->{self::GLOBAL_CONST}) ? true : (bool)$conf->global->{self::GLOBAL_CONST};
        if (!$globalOn) {
            dol_syslog(__METHOD__ . ' global sync disabled', LOG_INFO);
            return false;
        }
        return true;
    }

    private static function validateAndLoadProduct($productId, $db): ?Product
    {
        $prod = new Product($db);
        if ($prod->fetch($productId) <= 0) {
            dol_syslog(__METHOD__ . " invalid product id $productId", LOG_ERR);
            return null;
        }

        // Enhanced extrafield validation
        $extrafields = new ExtraFields($db);
        $prod->fetch_optionals($productId, $extrafields);
        
        if (empty($prod->array_options['options_' . self::SYNC_FIELD])) {
            dol_syslog(__METHOD__ . " sync disabled by extra‑field for pid=$productId", LOG_DEBUG);
            return null;
        }

        // Validate product isn't in an invalid state
        if ($prod->cost_price < 0) {
            dol_syslog(__METHOD__ . " warning: negative cost price for pid=$productId", LOG_WARNING);
        }

        return $prod;
    }

    private static function buildAndProcessGraph($productId, $user, $db): int
    {
        // Build graph with enhanced error handling
        $graphResult = GraphBuilder::aroundPivot($productId);
        if (!$graphResult['success']) {
            dol_syslog(__METHOD__ . ' graph build failed: ' . $graphResult['error'], LOG_ERR);
            return 0;
        }

        $nodes = $graphResult['nodes'];
        $stats = $graphResult['stats'];
        
        self::$productMap = $nodes; // Legacy exposure
        
        $pivot = $nodes[$productId] ?? null;
        if (!$pivot) {
            dol_syslog(__METHOD__ . ' pivot not found in graph', LOG_ERR);
            return 0;
        }

        // Enhanced propagation with batch updates
        $updatePlan = self::buildUpdatePlan($pivot, $nodes);
        $success = self::executeBatchUpdates($updatePlan, $user, $db);
        
        dol_syslog(__METHOD__ . " completed: {$stats['nodeCount']} nodes, {$stats['relationCount']} relations, {$self::$updateCount} updates", LOG_INFO);
        
        return $success ? 1 : 0;
    }

    private static function buildUpdatePlan(ProductNode $pivot, array $nodes): array
    {
        $visited = [];
        $updatePlan = [];
        
        self::planUpstreamUpdates($pivot, $nodes, $visited, $updatePlan, 0);
        
        // Sort by dependency order (deeper nodes first)
        usort($updatePlan, function($a, $b) {
            return $b['depth'] <=> $a['depth'];
        });
        
        return $updatePlan;
    }

    private static function planUpstreamUpdates(ProductNode $node, array &$nodes, array &$visited, array &$updatePlan, int $depth): void
    {
        if (isset($visited[$node->id])) return;
        if ($depth > self::MAX_HIERARCHY_DEPTH) {
            dol_syslog(__METHOD__ . " max depth exceeded for node {$node->id}", LOG_WARNING);
            return;
        }
        
        $visited[$node->id] = true;

        foreach ($node->parents as $parentId => $qtyNotUsed) {
            if (!isset($nodes[$parentId])) continue;
            $parent = $nodes[$parentId];

            // Calculate new cost with enhanced validation
            $calculation = self::calculateNewCost($parent, $nodes);
            if (!$calculation['valid']) {
                dol_syslog(__METHOD__ . " invalid calculation for parent {$parentId}: {$calculation['error']}", LOG_WARNING);
                continue;
            }

            $newCost = $calculation['cost'];
            
            // Check if update needed with enhanced precision handling
            if (self::isUpdateNeeded($parent->cost, $newCost)) {
                $updatePlan[] = [
                    'productId' => $parent->id,
                    'oldCost' => $parent->cost,
                    'newCost' => $newCost,
                    'depth' => $depth,
                    'childCount' => count($parent->children),
                    'calculation' => $calculation['details']
                ];
                
                // Update in-memory for subsequent calculations
                $parent->cost = $newCost;
            }

            // Recurse with depth tracking
            self::planUpstreamUpdates($parent, $nodes, $visited, $updatePlan, $depth + 1);
        }
    }

    private static function calculateNewCost(ProductNode $parent, array $nodes): array
    {
        $newCost = 0.0;
        $details = [];
        $hasErrors = false;

        foreach ($parent->children as $childId => $qty) {
            $childNode = $nodes[$childId] ?? null;
            
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
                $details[] = "Negative cost for child $childId: {$childNode->cost}";
            }

            $childCost = max(0, $childNode->cost); // Ensure non-negative
            $contribution = $qty * $childCost;
            $newCost += $contribution;
            
            $details[] = "Child $childId: {$qty} × {$childCost} = {$contribution}";
        }

        return [
            'valid' => !$hasErrors,
            'cost' => $newCost,
            'error' => $hasErrors ? implode('; ', array_filter($details, fn($d) => strpos($d, 'Missing') === 0 || strpos($d, 'Invalid') === 0)) : null,
            'details' => $details
        ];
    }

    private static function isUpdateNeeded(float $oldCost, float $newCost): bool
    {
        // Enhanced precision handling
        $delta = abs($newCost - $oldCost);
        $relativeDelta = $oldCost > 0 ? $delta / $oldCost : $delta;
        
        // Use both absolute and relative thresholds
        return $delta >= self::DELTA && $relativeDelta >= 0.001;
    }

    private static function executeBatchUpdates(array $updatePlan, $user, $db): bool
    {
        if (empty($updatePlan)) {
            dol_syslog(__METHOD__ . ' no updates needed', LOG_DEBUG);
            return true;
        }

        $batches = array_chunk($updatePlan, self::BATCH_SIZE);
        $totalSuccess = true;

        foreach ($batches as $batchIndex => $batch) {
            dol_syslog(__METHOD__ . " processing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " items)", LOG_DEBUG);
            
            if (!self::processBatch($batch, $user, $db)) {
                $totalSuccess = false;
            }
        }

        return $totalSuccess;
    }

    private static function processBatch(array $batch, $user, $db): bool
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
                dol_syslog(__METHOD__ . ' batch rollback due to error', LOG_ERR);
            }

        } catch (Exception $e) {
            $db->rollback();
            dol_syslog(__METHOD__ . ' batch exception: ' . $e->getMessage(), LOG_ERR);
            $batchSuccess = false;
        }

        return $batchSuccess;
    }

    private static function updateSingleProduct(array $update, $user, $db): bool
    {
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        
        $product = new Product($db);
        if ($product->fetch($update['productId']) <= 0) {
            dol_syslog(__METHOD__ . " cannot fetch pid={$update['productId']}", LOG_ERR);
            return false;
        }

        // Validate current state hasn't changed unexpectedly
        if (abs($product->cost_price - $update['oldCost']) > self::DELTA) {
            dol_syslog(__METHOD__ . " concurrent modification detected for pid={$update['productId']}", LOG_WARNING);
            // Continue anyway, but log it
        }

        $product->cost_price = $update['newCost'];
        
        $result = $product->update($update['productId'], $user);
        if ($result <= 0) {
            dol_syslog(__METHOD__ . " update failed for pid={$update['productId']}", LOG_ERR);
            return false;
        }

        // Enhanced logging with calculation details
        dol_syslog("ProductHierarchy updated pid={$update['productId']} cost {$update['oldCost']}→{$update['newCost']} (children: {$update['childCount']})", LOG_DEBUG);
        
        if (!empty($update['calculation']) && dol_syslog_level() >= LOG_DEBUG) {
            dol_syslog("  Calculation: " . implode('; ', $update['calculation']), LOG_DEBUG);
        }

        return true;
    }

    // Enhanced utility methods
    public static function getHierarchyStats($productId): array
    {
        $graphResult = GraphBuilder::aroundPivot($productId);
        if (!$graphResult['success']) {
            return ['error' => $graphResult['error']];
        }

        $nodes = $graphResult['nodes'];
        $stats = $graphResult['stats'];
        
        return [
            'nodeCount' => $stats['nodeCount'],
            'relationCount' => $stats['relationCount'],
            'maxDepth' => $stats['maxDepth'],
            'leafNodes' => array_filter($nodes, fn($n) => empty($n->children)),
            'rootNodes' => array_filter($nodes, fn($n) => empty($n->parents)),
            'totalCost' => array_sum(array_map(fn($n) => $n->cost, $nodes))
        ];
    }

    public static function validateHierarchy($productId): array
    {
        $issues = [];
        $graphResult = GraphBuilder::aroundPivot($productId);
        
        if (!$graphResult['success']) {
            return ['error' => $graphResult['error']];
        }

        $nodes = $graphResult['nodes'];
        
        foreach ($nodes as $node) {
            // Check for negative costs
            if ($node->cost < 0) {
                $issues[] = "Negative cost for product {$node->id}: {$node->cost}";
            }
            
            // Check for orphaned nodes
            if (empty($node->children) && empty($node->parents) && $node->id != $productId) {
                $issues[] = "Orphaned node: {$node->id}";
            }
            
            // Check for invalid quantities
            foreach ($node->children as $childId => $qty) {
                if ($qty <= 0) {
                    $issues[] = "Invalid quantity for {$node->id} → {$childId}: {$qty}";
                }
            }
        }

        return ['issues' => $issues, 'isValid' => empty($issues)];
    }
}

class ProductNode
{
    public $id;
    public $cost = 0.0;
    public $children = []; // childId => qty
    public $parents = [];  // parentId => qty
    
    // Enhanced metadata
    public $name = '';
    public $ref = '';
    public $lastUpdated = null;
    public $isLeaf = false;
    public $isRoot = false;

    public function __construct(int $id, float $cost = 0.0)
    {
        $this->id = $id;
        $this->cost = $cost;
        $this->lastUpdated = date('Y-m-d H:i:s');
    }

    public function updateMetadata(): void
    {
        $this->isLeaf = empty($this->children);
        $this->isRoot = empty($this->parents);
        $this->lastUpdated = date('Y-m-d H:i:s');
    }
}

final class GraphBuilder
{
    public static function aroundPivot(int $pivotId): array
    {
        global $db;

        try {
            $nodes = [];
            $queue = [$pivotId];
            $seen = [];
            $relationCount = 0;
            $maxDepth = 0;

            // Enhanced BFS with depth tracking
            while ($queue) {
                $currentDepth = 0;
                $pid = array_pop($queue);
                if (isset($seen[$pid])) continue;
                $seen[$pid] = true;

                $nodes[$pid] = $nodes[$pid] ?? new ProductNode($pid);

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
                    return [
                        'success' => false, 
                        'error' => 'SQL error: ' . $db->lasterror(),
                        'nodes' => [],
                        'stats' => []
                    ];
                }

                while ($row = $db->fetch_object($res)) {
                    $parentId = (int)$row->parent;
                    $childId = (int)$row->child;
                    $qty = (float)$row->qty;
                    $relationCount++;

                    // Validation
                    if ($qty <= 0) {
                        dol_syslog("GraphBuilder: Invalid quantity {$qty} for {$parentId} → {$childId}", LOG_WARNING);
                        continue;
                    }

                    // Enhanced node creation with metadata
                    if (!isset($nodes[$parentId])) {
                        $nodes[$parentId] = new ProductNode($parentId);
                        $nodes[$parentId]->name = $row->parent_name ?? '';
                        $nodes[$parentId]->ref = $row->parent_ref ?? '';
                    }
                    
                    if (!isset($nodes[$childId])) {
                        $nodes[$childId] = new ProductNode($childId);
                        $nodes[$childId]->name = $row->child_name ?? '';
                        $nodes[$childId]->ref = $row->child_ref ?? '';
                    }

                    // Build relationships (prevent duplicates)
                    $nodes[$parentId]->children[$childId] = $qty;
                    $nodes[$childId]->parents[$parentId] = $qty;

                    if (!isset($seen[$parentId])) $queue[] = $parentId;
                    if (!isset($seen[$childId])) $queue[] = $childId;
                }
                $db->free($res);
            }

            // Bulk-load costs with enhanced query
            self::loadCostsForNodes($nodes, $db);
            
            // Update metadata for all nodes
            foreach ($nodes as $node) {
                $node->updateMetadata();
            }

            return [
                'success' => true,
                'nodes' => $nodes,
                'stats' => [
                    'nodeCount' => count($nodes),
                    'relationCount' => $relationCount,
                    'maxDepth' => $maxDepth
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'nodes' => [],
                'stats' => []
            ];
        }
    }

    private static function loadCostsForNodes(array &$nodes, $db): void
    {
        if (empty($nodes)) return;

        $idList = implode(',', array_keys($nodes));
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
                $nodes[$id]->name = $row->label ?? $nodes[$id]->name;
                $nodes[$id]->ref = $row->ref ?? $nodes[$id]->ref;
            }
        }
        $db->free($res);
    }
}
