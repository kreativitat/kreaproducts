<?php

/**
 * Minimal ProductHierarchy Class with Debug Logging
 *
 * This class builds the full connected graph (associations) for a given product,
 * computes a “height” for each node (leaf = 0; parent = max(child height) + 1),
 * and then updates each non‑leaf product's cost_price (buyprice) in bottom‑up order.
 *
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
    public $data;              // must include 'cost_price'
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
        // dol_syslog("Constructed ProductHierarchy for ID $id ({$this->ref})");
    }

    /**
     * Main entry point.
     *
     * Given a product ID (which may be a leaf or have children), this method:
     *  1. Builds the full connected association map.
     *  2. Computes a height for each node (leaf = 0).
     *  3. Updates each non‑leaf node’s cost_price (buyprice) in order from the bottom up.
     *
     * This ensures that if you update a leaf (which isn’t recalculated), its parent's
     * cost is recalculated after the leaf’s new value is set.
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

        // Reset and rebuild the association map.
        self::$mapLoaded  = false;
        self::$productMap = array();
        self::buildMap($productId);

        // Compute heights for each node (leaf = 0; parent's height = max(child height)+1)
        $heights = self::computeHeights();
        // Sort nodes by height in ascending order (so that when we update a node,
        // all its children—with lower heights—have already been updated)
        asort($heights);

        // Iterate over all nodes in the map in this order.
        // We only update non‑leaf nodes (i.e. nodes that have children).
        foreach ($heights as $nodeId => $height) {
            // Skip leaf nodes
            if ($height == 0) continue;
            if (!isset(self::$productMap[$nodeId])) continue;
            $node = self::$productMap[$nodeId];

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
     * Computes and returns an associative array of heights for all nodes in the map.
     *
     * Height is defined as:
     *   - 0 for leaf nodes (no children)
     *   - max(child height) + 1 for nodes with children.
     *
     * @return array  [productId => height]
     */
    private static function computeHeights()
    {
        $heights = array();
        foreach (self::$productMap as $nodeId => $node) {
            self::getHeight($nodeId, $heights);
        }
        return $heights;
    }

    /**
     * Recursively computes the height of a node.
     *
     * @param int   $nodeId
     * @param array $heights  Memoization array.
     * @return int
     */
    private static function getHeight($nodeId, &$heights)
    {
        if (isset($heights[$nodeId])) return $heights[$nodeId];
        if (!isset(self::$productMap[$nodeId]) || empty(self::$productMap[$nodeId]->children)) {
            $heights[$nodeId] = 0;
            return 0;
        }
        $maxChildHeight = 0;
        foreach (self::$productMap[$nodeId]->children as $childId => $qty) {
            $childHeight = self::getHeight($childId, $heights);
            if ($childHeight > $maxChildHeight) {
                $maxChildHeight = $childHeight;
            }
        }
        $heights[$nodeId] = $maxChildHeight + 1;
        return $heights[$nodeId];
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
        // dol_syslog("addChild: Product ID {$this->id} added child $childId with qty $qty");
    }

    /**
     * Adds a parent association.
     *
     * @param int $parentId
     */
    public function addParent($parentId)
    {
        $this->parents[] = $parentId;
        // dol_syslog("addParent: Product ID {$this->id} added parent $parentId");
    }

    /**
     * Builds the full association map (connected graph) for the given product.
     *
     * It first collects all connected product IDs (via parent/child links),
     * then loads all association details for these IDs.
     *
     * @param int $productId The starting product ID.
     */
    private static function buildMap($productId)
    {
        global $db;
        // dol_syslog("buildMap: Starting buildMap for product ID $productId");

        // Build the complete set of connected product IDs.
        $all_ids  = array($productId);
        $to_check = array($productId);
        while (!empty($to_check)) {
            $ids_string = implode(",", array_map('intval', $to_check));
            $new_ids = array();
            $sql = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child 
                    FROM " . MAIN_DB_PREFIX . "product_association as pa 
                    WHERE pa.fk_product_pere IN ($ids_string) OR pa.fk_product_fils IN ($ids_string)";
            // dol_syslog("buildMap: Iteration SQL: $sql");
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    if (!in_array($obj->parent, $all_ids)) {
                        $all_ids[] = $obj->parent;
                        $new_ids[] = $obj->parent;
                        // dol_syslog("buildMap: New parent found: {$obj->parent}");
                    }
                    if (!in_array($obj->child, $all_ids)) {
                        $all_ids[] = $obj->child;
                        $new_ids[] = $obj->child;
                        // dol_syslog("buildMap: New child found: {$obj->child}");
                    }
                }
                $db->free($resql);
            } else {
                // dol_syslog("buildMap: SQL error: " . $db->error, LOG_ERR);
                break;
            }
            $to_check = $new_ids;
        }
        // dol_syslog("buildMap: Full connected IDs: " . print_r($all_ids, true));

        // Now load the association details for all these IDs.
        $ids_string = implode(",", array_map('intval', $all_ids));
        $sql  = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child, pa.qty as qty, 
                       p.label as p_label, p.ref as p_ref, p.cost_price as p_buyprice, 
                       f.label as f_label, f.ref as f_ref, f.cost_price as f_buyprice 
                 FROM " . MAIN_DB_PREFIX . "product_association as pa, " .
            MAIN_DB_PREFIX . "product as p, " .
            MAIN_DB_PREFIX . "product as f 
                 WHERE p.rowid = pa.fk_product_pere AND f.rowid = pa.fk_product_fils 
                   AND (pa.fk_product_pere IN ($ids_string) OR pa.fk_product_fils IN ($ids_string))";
        // dol_syslog("buildMap: Associations SQL: $sql");
        $resql = $db->query($sql);
        if (!$resql) {
            // dol_syslog("buildMap: Error: " . $db->error, LOG_ERR);
            return;
        }
        while ($obj = $db->fetch_object($resql)) {
            if (!isset(self::$productMap[$obj->parent])) {
                $parentData = array('cost_price' => $obj->p_buyprice);
                self::$productMap[$obj->parent] = new ProductHierarchy($obj->parent, $obj->p_label, $obj->p_ref, $parentData);
                // dol_syslog("buildMap: Created parent product ID {$obj->parent}");
            }
            if (!isset(self::$productMap[$obj->child])) {
                $childData = array('cost_price' => $obj->f_buyprice);
                self::$productMap[$obj->child] = new ProductHierarchy($obj->child, $obj->f_label, $obj->f_ref, $childData);
                // dol_syslog("buildMap: Created child product ID {$obj->child}");
            }
            self::$productMap[$obj->parent]->addChild($obj->child, $obj->qty);
            self::$productMap[$obj->child]->addParent($obj->parent);
        }
        $db->free($resql);
        self::$mapLoaded = true;
        // dol_syslog("buildMap: Completed buildMap. Final map: " . print_r(self::$productMap, true));
    }
}
