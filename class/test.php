<?php

class ProductHierarchy
{
    /**
     * Flag to indicate that we're actively recalculating cost prices.
     * Other Dolibarr triggers/hooks can check this and skip if true.
     *
     * @var bool
     */
    public static $inProgress = false;

    /**
     * All loaded nodes, keyed by productId.
     *
     * @var ProductHierarchy[]
     */
    public static $productMap = array();

    /**
     * Whether the graph structure is built yet.
     *
     * @var bool
     */
    private static $mapLoaded = false;

    // --------------------------------------------------
    // Instance properties
    // --------------------------------------------------
    public $id;
    public $label;
    public $ref;
    public $data = array();
    public $children = array();  // [childId => quantity]
    public $parents  = array();  // [parentId1, parentId2, ...]

    /**
     * Constructor.
     *
     * @param int    $id
     * @param string $label
     * @param string $ref
     * @param array  $data
     */
    public function __construct($id, $label, $ref, $data = array())
    {
        $this->id    = $id;
        $this->label = $label;
        $this->ref   = $ref;
        $this->data  = $data;
    }

    /**
     * Main entry point to recalc cost price.
     *
     * A static lock is set to prevent recursive trigger calls.
     *
     * @param int   $productId
     * @param mixed $user
     * @return int  Returns 1 if update process runs, 0 otherwise.
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;

        // Skip if already updating (to prevent recursive loops)
        if (self::$inProgress) {
            dol_syslog("ProductHierarchy::updateProductAttributes: Already in progress, skipping...", LOG_WARNING);
            return 0;
        }

        self::$inProgress = true;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $extrafields   = new ExtraFields($db);
        $extraFieldKey = 'kreap_spread_buyprice';

        $prodCheck = new Product($db);
        if ($prodCheck->fetch($productId) <= 0) {
            dol_syslog("updateProductAttributes: Unable to fetch product ID $productId", LOG_ERR);
            self::$inProgress = false; // release lock
            return 0;
        }
        $prodCheck->fetch_optionals($productId, $extrafields);

        // Check if the extra field for cost price sync is enabled. This mimics the ProductMixer feature check.
        if (empty($prodCheck->array_options["options_" . $extraFieldKey])) {
            dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' not enabled for product ID $productId. Exiting.", LOG_WARNING);
            self::$inProgress = false; // release lock
            return 0;
        }

        dol_syslog("updateProductAttributes: Starting BFS cost update for product ID $productId", LOG_DEBUG);

        // Rebuild the entire graph of product associations starting at the given product.
        self::$productMap = array();
        self::$mapLoaded  = false;
        self::buildMap($productId);

        // Traverse the graph in bottom-up order using a BFS (Kahn's algorithm) approach.
        self::bfsBottomUpCostUpdate($user);

        dol_syslog("updateProductAttributes: Completed BFS cost update for product ID $productId", LOG_DEBUG);

        self::$inProgress = false; // Release the lock

        return 1;
    }

    /**
     * Performs a bottom-up breadth-first search (BFS) to update product cost prices.
     *
     * @param mixed $user
     */
    private static function bfsBottomUpCostUpdate($user)
    {
        global $db;

        // 1) Build an in-degree array representing how many children each node has.
        $inDegree = array();
        foreach (self::$productMap as $pid => $node) {
            $inDegree[$pid] = count($node->children);
        }

        // 2) Initialize a queue with all leaves (nodes that have no children).
        $queue = array();
        foreach ($inDegree as $pid => $deg) {
            if ($deg === 0) {
                $queue[] = $pid;
            }
        }

        // 3) Process the queue and update each parent when all its children have been processed.
        $processedCount = 0;
        while (!empty($queue)) {
            $childId = array_shift($queue);
            $processedCount++;

            $childNode = self::$productMap[$childId];
            foreach ($childNode->parents as $parentId) {
                if (!isset($inDegree[$parentId])) continue;
                $inDegree[$parentId]--;
                if ($inDegree[$parentId] === 0) {
                    // All children processed; recalc this parent's cost.
                    self::recalcNodeCost($parentId, $user);
                    $queue[] = $parentId;
                }
            }
        }

        // 4) Check for cycles or data errors.
        $totalNodes = count(self::$productMap);
        if ($processedCount < $totalNodes) {
            dol_syslog("bfsBottomUpCostUpdate: Warning: $totalNodes total nodes, only $processedCount processed; possible cycle or data error.", LOG_WARNING);
        }
    }

    /**
     * Recalculate a parent's cost by summing up its children's cost multiplied by their quantities.
     * If a significant difference is detected, update the database record.
     *
     * @param int   $parentId
     * @param mixed $user
     */
    private static function recalcNodeCost($parentId, $user)
    {
        global $db;
        if (!isset(self::$productMap[$parentId])) return;

        $parentNode = self::$productMap[$parentId];
        $newCost = 0.0;

        // Compute the new cost as the sum of each child's cost times its associated quantity.
        foreach ($parentNode->children as $childId => $qty) {
            if (!isset(self::$productMap[$childId])) continue;
            $childNode = self::$productMap[$childId];

            $childCost = isset($childNode->data['cost_price'])
                ? floatval($childNode->data['cost_price'])
                : 0.0;
            $newCost += ($childCost * floatval($qty));
        }

        $oldCost = isset($parentNode->data['cost_price'])
            ? floatval($parentNode->data['cost_price'])
            : 0.0;

        // Use a threshold (0.001) similar to the ProductMixer implementation to decide if the update is needed.
        if (abs($newCost - $oldCost) > 0.001) {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

            $prod = new Product($db);
            if ($prod->fetch($parentNode->id) > 0) {
                // Update the cost price. Note: In ProductMixer, only cost_price is updated here.
                $prod->cost_price = $newCost;
                // If desired, you can also update the buyprice field:
                // $prod->buyprice = $newCost;
                $res = $prod->update($parentNode->id, $user);

                if ($res > 0) {
                    dol_syslog("recalcNodeCost: Updated parent ID {$parentNode->id} cost changed from $oldCost to $newCost", LOG_DEBUG);
                    // Update our in-memory data for consistency.
                    $parentNode->data['cost_price'] = $newCost;
                } else {
                    dol_syslog("recalcNodeCost: FAILED to update product ID {$parentNode->id}", LOG_ERR);
                }
            } else {
                dol_syslog("recalcNodeCost: FAILED to fetch product ID {$parentNode->id}", LOG_ERR);
            }
        }
    }

    /**
     * Builds the graph of parent/child relationships starting from an initial product ID.
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

            // Query all associations where the product is either a parent or a child.
            $sql = "SELECT pa.fk_product_pere as parent,
                           pa.fk_product_fils as child,
                           pa.qty as qty
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                    WHERE pa.fk_product_pere = " . ((int) $pid) . "
                       OR pa.fk_product_fils = " . ((int) $pid);

            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // Ensure that nodes exist for both parent and child.
                    self::initNode($obj->parent);
                    self::initNode($obj->child);

                    // Link parent and child with the specified quantity.
                    self::$productMap[$obj->parent]->children[$obj->child] = (float)$obj->qty;
                    self::$productMap[$obj->child]->parents[] = $obj->parent;

                    // Add these nodes to be visited.
                    if (!isset($visited[$obj->parent])) $toVisit[] = $obj->parent;
                    if (!isset($visited[$obj->child]))  $toVisit[] = $obj->child;
                }
            } else {
                dol_syslog("buildMap: SQL error: " . $db->lasterror(), LOG_ERR);
                return;
            }
        }

        self::$mapLoaded = true;
        // Load current cost prices and basic info for every node in the graph.
        self::loadCostsForNodes();
    }

    /**
     * Ensures a node exists in the map for a given product ID.
     *
     * @param int $id
     */
    private static function initNode($id)
    {
        if (isset(self::$productMap[$id])) return;
        // Create a new node with default cost_price (0.0)
        self::$productMap[$id] = new ProductHierarchy($id, '', '', array('cost_price' => 0.0));
    }

    /**
     * Loads cost prices (and optionally label/reference) for all nodes in productMap.
     */
    private static function loadCostsForNodes()
    {
        global $db;

        $allIds = array_keys(self::$productMap);
        if (empty($allIds)) return;

        $listIds = implode(',', array_map('intval', $allIds));
        $sql = "SELECT rowid, ref, label, cost_price
                FROM " . MAIN_DB_PREFIX . "product
                WHERE rowid IN (" . $listIds . ")";

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                if (!isset(self::$productMap[$obj->rowid])) continue;
                self::$productMap[$obj->rowid]->ref = $obj->ref;
                self::$productMap[$obj->rowid]->label = $obj->label;
                self::$productMap[$obj->rowid]->data['cost_price'] = (float)$obj->cost_price;
            }
        } else {
            dol_syslog("loadCostsForNodes: SQL error: " . $db->lasterror(), LOG_ERR);
        }
    }
}
