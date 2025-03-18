<?php

class ProductHierarchy
{
    /** 
     * @var bool Flag to indicate that we're actively recalculating buy prices. 
     *           Other Dolibarr triggers/hooks can check this and skip if true.
     */
    public static $inProgress = false;

    /** @var ProductHierarchy[] All loaded nodes, keyed by productId */
    public static $productMap = array();

    /** @var bool Whether the graph structure is built yet */
    private static $mapLoaded = false;

    // --------------------------------------------------
    // Instance properties
    // --------------------------------------------------
    public $id;
    public $label;
    public $ref;
    public $data    = array();
    public $children = array();  // [childId => quantity]
    public $parents  = array();  // [parentId1, parentId2, ...]

    /**
     * Constructor
     */
    public function __construct($id, $label, $ref, $data = array())
    {
        $this->id    = $id;
        $this->label = $label;
        $this->ref   = $ref;
        $this->data  = $data;
    }

    /**
     * Main entry point to recalc cost.
     *
     * We set a static "inProgress" flag to true so that
     * other triggers won't do the same logic simultaneously.
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;

        // If we are already updating, skip to prevent recursion loops
        if (self::$inProgress) {
            dol_syslog("ProductHierarchy::updateProductAttributes: Already in progress, skipping...", LOG_WARNING);
            return 0;
        }

        // Indicate that BFS is running
        self::$inProgress = true;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $extrafields     = new ExtraFields($db);
        $extraFieldKey   = 'kreap_spread_buyprice';

        $prodCheck       = new Product($db);
        if ($prodCheck->fetch($productId) <= 0) {
            dol_syslog("updateProductAttributes: Unable to fetch product ID $productId", LOG_ERR);
            self::$inProgress = false; // release lock
            return 0;
        }
        $prodCheck->fetch_optionals($productId, $extrafields);

        // If an extra field is mandatory for the process, check it
        if (empty($prodCheck->array_options["options_" . $extraFieldKey])) {
            dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' not enabled for product ID $productId. Exiting.", LOG_WARNING);
            self::$inProgress = false; // release lock
            return 0;
        }

        dol_syslog("updateProductAttributes: Starting BFS cost update for product ID $productId", LOG_DEBUG);

        // Rebuild the entire graph from the given product outward
        self::$productMap = array();
        self::$mapLoaded  = false;
        self::buildMap($productId);

        // Perform BFS (Kahn's Algorithm) in bottom-up order
        self::bfsBottomUpCostUpdate($user);

        dol_syslog("updateProductAttributes: Completed BFS cost update for product ID $productId", LOG_DEBUG);

        // Release the lock at the end
        self::$inProgress = false;

        return 1;
    }

    /**
     * BFS (Kahn's Algorithm) to recalc cost from leaves to parents (bottom-up).
     */
    private static function bfsBottomUpCostUpdate($user)
    {
        global $db;

        // 1) Build in-degree array => number of children
        $inDegree = array();
        foreach (self::$productMap as $pid => $node) {
            $inDegree[$pid] = count($node->children);
        }

        // 2) Initialize queue with leaves (in-degree=0)
        $queue = array();
        foreach ($inDegree as $pid => $deg) {
            if ($deg === 0) {
                $queue[] = $pid;
            }
        }

        // 3) Process queue
        $processedCount = 0;
        while (!empty($queue)) {
            $childId = array_shift($queue);
            $processedCount++;

            // For each parent, decrement parent's in-degree
            $childNode = self::$productMap[$childId];
            foreach ($childNode->parents as $parentId) {
                if (!isset($inDegree[$parentId])) continue;
                $inDegree[$parentId]--;
                if ($inDegree[$parentId] === 0) {
                    // All of parent's children done => recalc parent's cost
                    self::recalcNodeCost($parentId, $user);
                    $queue[] = $parentId;
                }
            }
        }

        // 4) Check for cycle detection
        $totalNodes = count(self::$productMap);
        if ($processedCount < $totalNodes) {
            dol_syslog("bfsBottomUpCostUpdate: Warning: $totalNodes total nodes, only $processedCount processed => cycle/data error likely.", LOG_WARNING);
        }
    }

    /**
     * Recalc parent's cost from its children, update DB and productMap.
     */
    private static function recalcNodeCost($parentId, $user)
    {
        global $db;
        if (!isset(self::$productMap[$parentId])) return;

        $parentNode = self::$productMap[$parentId];
        $newCost    = 0.0;

        // Sum up child cost * quantity
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

        // Update DB only if there's a meaningful difference
        if (abs($newCost - $oldCost) > 1e-6) {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

            $prod = new Product($db);
            if ($prod->fetch($parentNode->id) > 0) {
                $prod->cost_price = $newCost;
                $prod->buyprice   = $newCost;
                $res = $prod->update($parentNode->id, $user);

                if ($res > 0) {
                    dol_syslog("recalcNodeCost: Updated parent ID {$parentNode->id} cost=$oldCost => $newCost", LOG_DEBUG);
                    // Also update in our map
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
     * Build the graph of parents/children from the initial product outward.
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

            // Query associations: parent->child or child->parent
            $sql = "SELECT pa.fk_product_pere as parent,
                           pa.fk_product_fils as child,
                           pa.qty as qty
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                    WHERE pa.fk_product_pere = " . ((int) $pid) . "
                       OR pa.fk_product_fils = " . ((int) $pid);

            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // Ensure parent/child exist in map
                    self::initNode($obj->parent);
                    self::initNode($obj->child);

                    // Link them
                    self::$productMap[$obj->parent]->children[$obj->child] = (float)$obj->qty;
                    self::$productMap[$obj->child]->parents[]             = $obj->parent;

                    // Explore further
                    if (!isset($visited[$obj->parent])) $toVisit[] = $obj->parent;
                    if (!isset($visited[$obj->child]))  $toVisit[] = $obj->child;
                }
            } else {
                dol_syslog("buildMap: SQL error: " . $db->lasterror(), LOG_ERR);
                return;
            }
        }

        self::$mapLoaded = true;
        // Now load cost_price for all discovered nodes
        self::loadCostsForNodes();
    }

    /**
     * Ensure a node (ProductHierarchy instance) exists for $id in productMap.
     */
    private static function initNode($id)
    {
        if (isset(self::$productMap[$id])) return;
        // Create minimal node (cost_price filled after fetch)
        self::$productMap[$id] = new ProductHierarchy($id, '', '', array('cost_price' => 0.0));
    }

    /**
     * Fetch cost_price (and optionally label/ref) for each node in productMap.
     */
    private static function loadCostsForNodes()
    {
        global $db;

        $allIds = array_keys(self::$productMap);
        if (empty($allIds)) return;

        $listIds = implode(',', $allIds);
        $sql = "SELECT rowid, ref, label, cost_price
                FROM " . MAIN_DB_PREFIX . "product
                WHERE rowid IN (" . $listIds . ")";

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                if (!isset(self::$productMap[$obj->rowid])) continue;
                self::$productMap[$obj->rowid]->ref                = $obj->ref;
                self::$productMap[$obj->rowid]->label              = $obj->label;
                self::$productMap[$obj->rowid]->data['cost_price'] = (float)$obj->cost_price;
            }
        } else {
            dol_syslog("loadCostsForNodes: SQL error: " . $db->lasterror(), LOG_ERR);
        }
    }
}
