<?php

/**
 * ProductHierarchy Class with Iterative Topological Sorting, Cycle Protection,
 * Upward-Only BFS Tree Construction, and Debug Logging.
 *
 * This class builds the upward (ancestor) graph for a given product using BFS,
 * then updates each non‑leaf product's cost_price (buyprice) from the bottom‑up via
 * a topological order.
 *
 * Assumptions:
 * - The product associations (kits) form a directed structure upward.
 * - Each product's parent's cost is calculated as the sum of its children’s cost_price multiplied by quantity.
 */
class ProductHierarchy
{
    // Public static map of all ProductHierarchy objects (indexed by product ID)
    public static $productMap = array();
    private static $mapLoaded = false;

    // Instance properties
    public $id;
    public $label;
    public $ref;
    public $data;               // must include 'cost_price'
    public $children = array(); // Format: [childId => quantity]
    public $parents  = array(); // Format: array of parent IDs

    /**
     * Constructor.
     *
     * @param int    $id
     * @param string $label
     * @param string $ref
     * @param array  $data  e.g., ['cost_price' => ...]
     */
    public function __construct($id, $label, $ref, $data = array())
    {
        $this->id    = $id;
        $this->label = $label;
        $this->ref   = $ref;
        $this->data  = $data;
    }

    /**
     * Main entry point.
     *
     * Given a product ID (which may be a leaf or have ancestors), this method:
     *  1. Builds the upward association map using BFS (ancestors only).
     *  2. Uses an iterative topological sort (Kahn's algorithm) to order nodes from leaves upward.
     *  3. Updates each non‑leaf node’s cost_price (buyprice) in that order.
     *
     * @param int  $productId The starting product ID.
     * @param User $user      The Dolibarr user performing the update.
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;

        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        $extrafields = new ExtraFields($db);
        $extraFieldKey = 'kreap_spread_buyprice';

        // Load product extra fields for the starting product.
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            dol_syslog("updateProductAttributes: Failed to fetch product ID $productId", LOG_ERR);
            return 0; // Exit if product is not found
        }
        $product->fetch_optionals($productId, $extrafields); // Load extra fields

        // Check if the extra field is enabled for this product.
        if (empty($product->array_options["options_" . $extraFieldKey])) {
            dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' is not enabled for product ID $productId. Exiting.", LOG_WARNING);
            return 0; // Exit without making changes
        }
        dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' is enabled. Proceeding with update.", LOG_DEBUG);

        // Reset and rebuild the upward association map using BFS.
        self::$mapLoaded  = false;
        self::$productMap = array();
        self::buildMapUpward($productId);

        // ----- Step 2: Build a Topological Order Using Kahn's Algorithm -----
        $inDegree = array();
        // Initialize in-degrees for all nodes in our upward map.
        foreach (self::$productMap as $nodeId => $node) {
            $inDegree[$nodeId] = 0;
        }
        // For each edge child -> parent, increment parent's in-degree.
        foreach (self::$productMap as $nodeId => $node) {
            foreach ($node->parents as $parentId) {
                if (isset($inDegree[$parentId])) {
                    $inDegree[$parentId]++;
                } else {
                    $inDegree[$parentId] = 1;
                }
            }
        }
        // Build the queue of nodes with in-degree 0 (leaves, i.e. products with no ancestors in this chain).
        $queue = array();
        foreach ($inDegree as $nodeId => $deg) {
            if ($deg == 0) {
                $queue[] = $nodeId;
            }
        }

        $topoOrder = array();
        $iteration = 0;
        $maxIterations = 5000; // safety threshold
        while (!empty($queue)) {
            $iteration++;
            if ($iteration > $maxIterations) {
                dol_syslog("updateProductAttributes: Topological sort exceeded max iterations ($maxIterations). Aborting.", LOG_ERR);
                break;
            }
            $current = array_shift($queue);
            $topoOrder[] = $current;
            if (isset(self::$productMap[$current])) {
                foreach (self::$productMap[$current]->parents as $parentId) {
                    if (isset($inDegree[$parentId])) {
                        $inDegree[$parentId]--;
                        if ($inDegree[$parentId] == 0) {
                            $queue[] = $parentId;
                        }
                    }
                }
            }
        }
        if (count($topoOrder) != count(self::$productMap)) {
            dol_syslog("updateProductAttributes: Cycle detected in product associations. Aborting update.", LOG_ERR);
            return 0;
        }
        dol_syslog("updateProductAttributes: Topological order computed with " . count($topoOrder) . " nodes.", LOG_DEBUG);

        // ----- Step 3: Update Product Prices in Bottom-Up Order -----
        $updateIteration = 0;
        foreach ($topoOrder as $nodeId) {
            $updateIteration++;
            if ($updateIteration > $maxIterations) {
                dol_syslog("updateProductAttributes: Update loop exceeded max iterations ($maxIterations). Aborting.", LOG_ERR);
                break;
            }
            if (!isset(self::$productMap[$nodeId])) continue;
            $node = self::$productMap[$nodeId];
            // Only update non‑leaf nodes (those with children in this upward chain)
            if (!empty($node->children)) {
                $newCost = 0;
                foreach ($node->children as $childId => $qty) {
                    if (isset(self::$productMap[$childId])) {
                        $child = self::$productMap[$childId];
                        $childCost = isset($child->data['cost_price']) ? floatval($child->data['cost_price']) : 0;
                        $newCost += $childCost * floatval($qty);
                    }
                }
                $oldCost = isset($node->data['cost_price']) ? floatval($node->data['cost_price']) : 0;
                if (abs($newCost - $oldCost) > 0.0001) {
                    $prod = new Product($db);
                    if ($prod->fetch($node->id) > 0) {
                        $prod->cost_price = $newCost;
                        $prod->buyprice   = $newCost;
                        $res = $prod->update($node->id, $user);
                        if ($res > 0) {
                            dol_syslog("updateProductAttributes: Updated product {$node->ref} (ID {$node->id}) from cost $oldCost to $newCost", LOG_DEBUG);
                            $node->data['cost_price'] = $newCost;
                        } else {
                            dol_syslog("updateProductAttributes: FAILED to update product {$node->ref} (ID {$node->id})", LOG_ERR);
                        }
                    } else {
                        dol_syslog("updateProductAttributes: FAILED to fetch product {$node->ref} (ID {$node->id})", LOG_ERR);
                    }
                }
            }
        }
        dol_syslog("updateProductAttributes: Completed updating products bottom-up.", LOG_DEBUG);
    }

    /**
     * Adds a child association.
     *
     * @param int   $childId
     * @param float $qty
     */
    public function addChild($childId, $qty)
    {
        $this->children[$childId] = $qty;
    }

    /**
     * Adds a parent association.
     *
     * @param int $parentId
     */
    public function addParent($parentId)
    {
        $this->parents[] = $parentId;
    }

    /**
     * Builds the upward association map (ancestors only) for the given product using BFS.
     *
     * This method queries only associations where the current product appears as a child.
     * As a result, only the ancestors (kits that include the product) are collected.
     * Self-references (a product being both father and child) are skipped.
     *
     * @param int $startId The starting product ID.
     */
    private static function buildMapUpward($startId)
    {
        global $db;
        $iteration = 0;
        $maxIterations = 5000; // safety threshold for BFS iterations

        // Initialize queue and seen set.
        $queue = array($startId);
        $seen  = array($startId => true);

        while (!empty($queue)) {
            $iteration++;
            if ($iteration > $maxIterations) {
                dol_syslog("buildMapUpward: Exceeded max iterations ($maxIterations). Breaking out.", LOG_ERR);
                break;
            }
            $current = array_shift($queue);

            // Query only for associations where $current is a child
            // (i.e. kits that include $current)
            $sql  = "SELECT pa.fk_product_pere as father, pa.fk_product_fils as child, pa.qty as qty, ";
            $sql .= "p.label as fatherLabel, p.ref as fatherRef, p.cost_price as fatherBuy, ";
            $sql .= "f.label as childLabel, f.ref as childRef, f.cost_price as childBuy ";
            $sql .= "FROM " . MAIN_DB_PREFIX . "product_association pa ";
            $sql .= "JOIN " . MAIN_DB_PREFIX . "product p ON (p.rowid = pa.fk_product_pere) ";
            $sql .= "JOIN " . MAIN_DB_PREFIX . "product f ON (f.rowid = pa.fk_product_fils) ";
            $sql .= "WHERE pa.fk_product_fils = " . ((int)$current);

            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // Skip self-reference.
                    if ($obj->father == $obj->child) continue;

                    // Ensure father object exists.
                    if (!isset(self::$productMap[$obj->father])) {
                        $data = array('cost_price' => (float)$obj->fatherBuy);
                        self::$productMap[$obj->father] = new ProductHierarchy($obj->father, $obj->fatherLabel, $obj->fatherRef, $data);
                    }
                    // Ensure child object exists.
                    if (!isset(self::$productMap[$obj->child])) {
                        $data = array('cost_price' => (float)$obj->childBuy);
                        self::$productMap[$obj->child] = new ProductHierarchy($obj->child, $obj->childLabel, $obj->childRef, $data);
                    }
                    // Add association: father is an ancestor (parent) of child.
                    if (!isset(self::$productMap[$obj->father]->children[$obj->child])) {
                        self::$productMap[$obj->father]->children[$obj->child] = (float)$obj->qty;
                    }
                    if (!in_array($obj->father, self::$productMap[$obj->child]->parents, true)) {
                        self::$productMap[$obj->child]->parents[] = $obj->father;
                    }
                    // Enqueue father if not already seen.
                    if (!isset($seen[$obj->father])) {
                        $queue[] = $obj->father;
                        $seen[$obj->father] = true;
                    }
                }
                $db->free($resql);
            }
        }
        // Also ensure the starting product is in the map.
        if (!isset(self::$productMap[$startId])) {
            // If it was never found as a child, add it.
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new Product($db);
            if ($prod->fetch($startId) > 0) {
                $data = array('cost_price' => floatval($prod->cost_price));
                self::$productMap[$startId] = new ProductHierarchy($startId, $prod->label, $prod->ref, $data);
            }
        }
        dol_syslog("buildMapUpward: Completed with " . count(self::$productMap) . " nodes.", LOG_DEBUG);
    }
}
