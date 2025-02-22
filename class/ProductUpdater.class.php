<?php

/**
 * Minimal ProductHierarchy Class with Debug Logging
 *
 * This class builds the full connected graph (associations) for a given product,
 * collects all its ancestor (parent) products, and then updates each ancestor's
 * cost_price (buyprice) as the sum of (child cost_price × quantity) for its immediate children.
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
     * Given a product ID (assumed to be a leaf in the tree), this method:
     *  1. Builds the full connected association map.
     *  2. Recursively collects all ancestor (parent) products (with depth).
     *  3. For each ancestor, recalculates its cost_price as the sum over its children.
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

        // Load product extra fields
        $product = new Product($db);
        if ($product->fetch($productId) <= 0) {
            dol_syslog("updateProductAttributes: Failed to fetch product ID $productId", LOG_ERR);
            return 0; // Exit if product is not found
        }

        $product->fetch_optionals($productId, $extrafields); // Load extra fields

        // Check if the extra field is enabled for this product
        if (empty($product->array_options["options_" . $extraFieldKey])) {
            dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' is not enabled for product ID $productId. Exiting.", LOG_WARNING);
            return 0; // Exit without making changes
        }

        dol_syslog("updateProductAttributes: Extra field 'kreap_spread_buyprice' is enabled. Proceeding with update.", LOG_DEBUG);

        // dol_syslog("updateProductAttributes: Starting update for product ID $productId");

        // Reset and rebuild the association map.
        self::$mapLoaded  = false;
        self::$productMap = array();
        self::buildMap($productId);
        // dol_syslog("updateProductAttributes: Completed buildMap. Full map: " . print_r(self::$productMap, true));

        // Collect all ancestors (parents, grandparents, etc.) along with their depth.
        $ancestors = self::getAncestors($productId);
        // dol_syslog("updateProductAttributes: Collected ancestors: " . print_r($ancestors, true));
        asort($ancestors);
        // dol_syslog("updateProductAttributes: Sorted ancestors: " . print_r($ancestors, true));

        // For each ancestor, recalculate its cost_price.
        foreach ($ancestors as $ancId => $depth) {
            if (!isset(self::$productMap[$ancId])) {
                // dol_syslog("updateProductAttributes: Ancestor ID $ancId not found in map.");
                continue;
            }
            $product = self::$productMap[$ancId];
            $newCost = 0;
            foreach ($product->children as $childId => $qty) {
                if (isset(self::$productMap[$childId])) {
                    $child = self::$productMap[$childId];
                    $childCost = isset($child->data['cost_price']) ? floatval($child->data['cost_price']) : 0;
                    $newCost += $childCost * floatval($qty);
                }
            }
            $oldCost = isset($product->data['cost_price']) ? floatval($product->data['cost_price']) : 0;
            // dol_syslog("updateProductAttributes: Ancestor {$product->ref} (ID $ancId, depth $depth): old cost = $oldCost, new cost = $newCost");

            if (abs($newCost - $oldCost) > 0.0001) {
                global $db;
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $prod = new Product($db);
                if ($prod->fetch($product->id) > 0) {
                    $prod->cost_price = $newCost;
                    $prod->buyprice   = $newCost;
                    $res = $prod->update($product->id, $user);
                    if ($res > 0) {
                        // dol_syslog("updateProductAttributes: Updated product {$product->ref} (ID $ancId) to cost $newCost");
                        $product->data['cost_price'] = $newCost;
                    } else {
                        // dol_syslog("updateProductAttributes: FAILED to update product {$product->ref} (ID $ancId)", LOG_ERR);
                    }
                } else {
                    // dol_syslog("updateProductAttributes: FAILED to fetch product {$product->ref} (ID $ancId)", LOG_ERR);
                }
            }
        }
        // dol_syslog("updateProductAttributes: Completed updating ancestors.");
    }

    /**
     * Recursively collect all ancestors (parents) for the given product.
     *
     * Returns an associative array: [productId => depth]
     *
     * @param int   $productId The starting product ID.
     * @param int   $depth     Current depth (default 0).
     * @param array $ancestors Accumulator.
     * @return array
     */
    private static function getAncestors($productId, $depth = 0, &$ancestors = array())
    {
        if (!isset(self::$productMap[$productId])) return $ancestors;
        $product = self::$productMap[$productId];
        foreach ($product->parents as $pid) {
            $newDepth = $depth + 1;
            if (!isset($ancestors[$pid]) || $newDepth < $ancestors[$pid]) {
                $ancestors[$pid] = $newDepth;
                // dol_syslog("getAncestors: Product ID $productId has parent $pid at depth $newDepth");
                self::getAncestors($pid, $newDepth, $ancestors);
            }
        }
        return $ancestors;
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
        $sql  = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child, pa.qty as qty, pa.syncbuyprice as syncbuyprice, 
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
