<?php

/**
 * ProductHierarchy Class (Iterative BFS Cost Update for Dolibarr)
 *
 * 1) Builds a graph of products (parent <-> child) from a given product ID outward.
 * 2) Uses Kahn's Algorithm (a queue-based, bottom-up approach) to recalc cost_price:
 *    - All leaves (no children) go into the queue initially.
 *    - When a node leaves the queue, we decrement the in-degree of its parents.
 *    - Once a parent’s in-degree hits 0, we recalc its cost (sum of child cost*qty) and push it into the queue.
 *
 * This prevents excessive recursion depth and detects cycles (which shouldn't exist in a valid BOM structure).
 */

class ProductHierarchy
{
    /** @var ProductHierarchy[] All loaded nodes, keyed by productId */
    public static $productMap = array();

    /** @var bool Whether the graph structure is built yet */
    private static $mapLoaded = false;

    // -------------------------------------------------------------------
    // Instance properties
    // -------------------------------------------------------------------
    /** @var int Dolibarr product rowid */
    public $id;
    /** @var string Product label */
    public $label;
    /** @var string Product reference */
    public $ref;
    /**
     * @var array Associative data: e.g. ['cost_price' => 123.45, ...]
     */
    public $data = array();

    /**
     * @var array Child relationships: [childId => quantity]
     *            "This product is made from N units of childId"
     */
    public $children = array();

    /**
     * @var int[] Parents: list of product IDs that include this product as a child
     */
    public $parents = array();

    /**
     * Constructor
     *
     * @param int    $id    Product ID
     * @param string $label Product label
     * @param string $ref   Product reference
     * @param array  $data  e.g. [ 'cost_price' => 123.45 ]
     */
    public function __construct($id, $label, $ref, $data = array())
    {
        $this->id    = $id;
        $this->label = $label;
        $this->ref   = $ref;
        $this->data  = $data;
    }

    // -------------------------------------------------------------------
    // Public: Main entry point
    // -------------------------------------------------------------------

    /**
     * Update cost_price from the bottom up, starting at $productId.
     *
     * 1) Build the graph of connected products (fk_product_pere, fk_product_fils).
     * 2) Perform BFS update using Kahn’s Algorithm (topological sort).
     *
     * @param int  $productId The product to recalc
     * @param User $user      The Dolibarr user performing the update
     * @return int            1 if done, 0 if failed/aborted
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;

        // Load Dolibarr classes
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        // Optional: check if extra field 'kreap_spread_buyprice' is active
        $extrafields   = new ExtraFields($db);
        $extraFieldKey = 'kreap_spread_buyprice';

        $prodCheck = new Product($db);
        if ($prodCheck->fetch($productId) <= 0) {
            dol_syslog(__METHOD__ . ": Could not fetch product ID $productId", LOG_ERR);
            return 0;
        }
        $prodCheck->fetch_optionals($productId, $extrafields);

        if (empty($prodCheck->array_options["options_" . $extraFieldKey])) {
            // If we only proceed when this custom field is set,
            // skip if not set (or remove this check entirely if you want unconditional updates).
            dol_syslog(__METHOD__ . ": Extra field '$extraFieldKey' not set => skipping update for product $productId", LOG_DEBUG);
            return 0;
        }

        // Reset static data
        self::$productMap = array();
        self::$mapLoaded  = false;

        // Build the graph outward from $productId
        self::buildMap($productId);

        // Perform BFS (Kahn's Algorithm) to recalc cost bottom-up
        self::bfsBottomUpCostUpdate($user);

        dol_syslog(__METHOD__ . ": Done BFS cost update for product $productId", LOG_DEBUG);
        return 1;
    }

    // -------------------------------------------------------------------
    // Private: BFS Implementation (Kahn's Algorithm)
    // -------------------------------------------------------------------

    /**
     * BFS (Kahn's Algorithm) to update cost_price from leaves upward.
     * Steps:
     *  1) inDegree[node] = number of children. Leaves => inDegree=0 => queue.
     *  2) pop leaf from queue => for each parent => parent.inDegree--
     *     if parent.inDegree==0 => recalc parent's cost => queue.
     *  3) If any nodes remain with inDegree>0 => cycle or data error.
     *
     * @param User $user
     */
    private static function bfsBottomUpCostUpdate($user)
    {
        global $db;

        // 1) Build inDegree = # of children for each node
        $inDegree = array();
        foreach (self::$productMap as $pid => $node) {
            $inDegree[$pid] = count($node->children);
        }

        // Queue holds productIds with inDegree=0 => leaves
        $queue = array();
        foreach ($inDegree as $pid => $deg) {
            if ($deg === 0) {
                $queue[] = $pid; // leaf node
            }
        }

        $processedCount = 0;
        while (!empty($queue)) {
            $childId = array_shift($queue);
            $processedCount++;

            // child => see which parents use it
            $childNode = self::$productMap[$childId];
            foreach ($childNode->parents as $parentId) {
                if (!isset($inDegree[$parentId])) continue;
                $inDegree[$parentId]--;

                // once parent's in-degree=0 => recalc parent's cost
                if ($inDegree[$parentId] === 0) {
                    self::recalcNodeCost($parentId, $user);
                    $queue[] = $parentId;
                }
            }
        }

        // Check for cycle
        $totalNodes = count(self::$productMap);
        if ($processedCount < $totalNodes) {
            dol_syslog(__METHOD__ . ": Warning: processed $processedCount of $totalNodes => possible cycle in BOM data.", LOG_WARNING);
        }
    }

    /**
     * Sum child cost_price * child.qty => update parent cost in DB.
     *
     * @param int  $parentId
     * @param User $user
     */
    private static function recalcNodeCost($parentId, $user)
    {
        global $db;
        if (!isset(self::$productMap[$parentId])) return;

        $parentNode = self::$productMap[$parentId];
        $newCost = 0.0;

        foreach ($parentNode->children as $childId => $qty) {
            if (!isset(self::$productMap[$childId])) continue;
            $childNode = self::$productMap[$childId];
            $childCost = !empty($childNode->data['cost_price'])
                ? floatval($childNode->data['cost_price'])
                : 0.0;

            $newCost += ($childCost * floatval($qty));
        }

        $oldCost = !empty($parentNode->data['cost_price'])
            ? floatval($parentNode->data['cost_price'])
            : 0.0;

        // Only update if a real change
        if (abs($newCost - $oldCost) > 1e-8) {
            // update in Dolibarr
            $prod = new Product($db);
            if ($prod->fetch($parentNode->id) > 0) {
                $prod->cost_price = $newCost;
                $prod->buyprice   = $newCost;
                $res = $prod->update($parentNode->id, $user);

                if ($res >= 0) {
                    dol_syslog(__METHOD__ . ": Updated product ID $parentId from cost=$oldCost to cost=$newCost", LOG_DEBUG);
                    // store new cost in map
                    $parentNode->data['cost_price'] = $newCost;
                } else {
                    dol_syslog(__METHOD__ . ": FAILED to update product ID $parentId (error code $res)", LOG_ERR);
                }
            } else {
                dol_syslog(__METHOD__ . ": Could not fetch product ID $parentId", LOG_ERR);
            }
        }
    }

    // -------------------------------------------------------------------
    // Private: Build Graph (both directions) for all connected products
    // -------------------------------------------------------------------

    /**
     * buildMap($productId):
     *  - BFS outward from $productId to discover all products connected
     *    as either parent or child in `product_association`.
     *  - For each discovered ID, ensure we have a ProductHierarchy node in $productMap.
     *  - Link child->parent and parent->child relationships accordingly.
     *
     * @param int $productId
     */
    private static function buildMap($productId)
    {
        global $db;

        $visited = array();
        $toVisit = array($productId);

        while (!empty($toVisit)) {
            $pid = array_pop($toVisit);
            if (isset($visited[$pid])) continue;
            $visited[$pid] = true;

            // Gather associations for this product
            $sql = "SELECT pa.fk_product_pere  AS parent,
                           pa.fk_product_fils  AS child,
                           pa.qty             AS qty
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                    WHERE pa.fk_product_pere = " . ((int)$pid) . "
                       OR pa.fk_product_fils = " . ((int)$pid);
            $resql = $db->query($sql);
            if (!$resql) {
                dol_syslog(__METHOD__ . ": SQL error: " . $db->lasterror(), LOG_ERR);
                return;
            }

            while ($obj = $db->fetch_object($resql)) {
                // Initialize parent/child nodes in our map if missing
                self::initNode($obj->parent);
                self::initNode($obj->child);

                // Link them
                self::$productMap[$obj->parent]->children[$obj->child] = (float)$obj->qty;
                self::$productMap[$obj->child]->parents[]             = $obj->parent;

                // Schedule them for BFS if not visited
                if (empty($visited[$obj->parent])) $toVisit[] = $obj->parent;
                if (empty($visited[$obj->child]))  $toVisit[] = $obj->child;
            }
        }

        // Now fetch cost_price (and label/ref if desired) for each discovered product
        self::loadCostsForNodes();
        self::$mapLoaded = true;
    }

    /**
     * initNode($id):
     *   - Create an empty ProductHierarchy node for $id if not already present.
     *
     * @param int $id
     */
    private static function initNode($id)
    {
        if (!isset(self::$productMap[$id])) {
            self::$productMap[$id] = new ProductHierarchy(
                $id,
                '',     // label
                '',     // ref
                array('cost_price' => 0.0)
            );
        }
    }

    /**
     * loadCostsForNodes():
     *   - Single DB query to fetch cost_price, label, ref, etc. for all known product IDs.
     */
    private static function loadCostsForNodes()
    {
        global $db;
        $ids = array_keys(self::$productMap);
        if (empty($ids)) return;

        $listIds = implode(',', $ids);
        $sql = "SELECT rowid, ref, label, cost_price
                FROM " . MAIN_DB_PREFIX . "product
                WHERE rowid IN (" . $listIds . ")";
        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . ": SQL error: " . $db->lasterror(), LOG_ERR);
            return;
        }

        while ($obj = $db->fetch_object($resql)) {
            if (isset(self::$productMap[$obj->rowid])) {
                self::$productMap[$obj->rowid]->ref                = $obj->ref;
                self::$productMap[$obj->rowid]->label              = $obj->label;
                self::$productMap[$obj->rowid]->data['cost_price'] = (float)$obj->cost_price;
            }
        }
    }
}
