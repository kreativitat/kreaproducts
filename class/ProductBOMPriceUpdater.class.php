<?php

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';

/**
 * Product BOM Price Updater with Most Recent Purchase Selection
 *
 * This class updates product prices based on BOM (Bill of Materials) composition.
 * When a product has multiple BOM parents, it selects the one with the most recent purchases
 * to determine which price calculation to use.
 *
 * Features:
 * - Finds all BOM parents for a given product
 * - Selects BOM parent based on most recent purchases
 * - Calculates price based on selected BOM components
 * - Updates product cost price accordingly
 */
class ProductBOMPriceUpdater
{
    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var bool Debug mode
     */
    private $debug = false;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Set debug mode
     *
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Debug log function
     *
     * @param string $message
     */
    private function debug($message)
    {
        if ($this->debug) {
            dol_syslog("[ProductBOMPriceUpdater] " . $message, LOG_DEBUG);
        }
    }

    /**
     * Update product price based on BOM with most recent purchases
     *
     * @param int $productId Product ID to update
     * @param object $user User object for audit trail
     * @return array Result array with status and details
     */
    public function updateProductPriceFromBOM($productId, $user)
    {
        $this->debug("Starting BOM price update for product ID: " . $productId);

        // Validate input
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'Invalid product ID'];
        }

        // Get all BOM parents for this product
        $bomParents = $this->getBOMParentsForProduct($productId);

        if (empty($bomParents)) {
            $this->debug("No BOM parents found for product " . $productId);
            return ['success' => false, 'error' => 'No BOM parents found for this product'];
        }

        // If only one BOM parent, use it directly
        if (count($bomParents) == 1) {
            $selectedBOM = reset($bomParents);
            $this->debug("Single BOM parent found: " . $selectedBOM['bom_id']);
        } else {
            // Multiple BOM parents - select based on most recent purchases
            $selectedBOM = $this->selectBOMBasedOnRecentPurchases($bomParents, $productId);
            if (!$selectedBOM) {
                return ['success' => false, 'error' => 'Could not select appropriate BOM parent'];
            }
            $this->debug("Selected BOM parent based on recent purchases: " . $selectedBOM['bom_id']);
        }

        // Calculate new price based on selected BOM
        $newPrice = $this->calculatePriceFromBOM($selectedBOM['bom_id'], $productId);
        if ($newPrice === false) {
            return ['success' => false, 'error' => 'Failed to calculate price from BOM'];
        }

        // Update product cost price
        $updateResult = $this->updateProductCostPrice($productId, $newPrice, $user);
        if (!$updateResult) {
            return ['success' => false, 'error' => 'Failed to update product cost price'];
        }

        $this->debug("Successfully updated product " . $productId . " price to " . $newPrice);

        return [
            'success' => true,
            'product_id' => $productId,
            'selected_bom' => $selectedBOM,
            'old_price' => $updateResult['old_price'],
            'new_price' => $newPrice,
            'bom_parents_count' => count($bomParents)
        ];
    }

    /**
     * Get all BOM parents for a product (BOMs where this product is a component)
     *
     * @param int $productId Product ID
     * @param bool $allBOMTypes Whether to include all BOM types (not just manufacturing)
     * @return array Array of BOM parent information
     */
    private function getBOMParentsForProduct($productId, $allBOMTypes = true)
    {
        $this->debug("Finding BOM parents for product " . $productId . " (allTypes: " . ($allBOMTypes ? 'yes' : 'no') . ")");

        $bomTypeCondition = $allBOMTypes ? "" : "AND b.bomtype = 0";

        $sql = "SELECT b.rowid as bom_id, b.ref as bom_ref, b.fk_product as parent_product_id,
                       p.ref as parent_product_ref, p.label as parent_product_label,
                       bl.qty as qty_in_bom, b.qty as bom_qty, b.bomtype, b.status
                FROM " . MAIN_DB_PREFIX . "bom_bom b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                WHERE bl.fk_product = " . (int)$productId . "
                AND b.status IN (0, 1)
                $bomTypeCondition";

        $this->debug("Executing SQL: " . $sql);
        $resql = $this->db->query($sql);
        $bomParents = [];

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $bomParents[] = [
                    'bom_id' => $obj->bom_id,
                    'bom_ref' => $obj->bom_ref,
                    'parent_product_id' => $obj->parent_product_id,
                    'parent_product_ref' => $obj->parent_product_ref,
                    'parent_product_label' => $obj->parent_product_label,
                    'qty_in_bom' => $obj->qty_in_bom,
                    'bom_qty' => $obj->bom_qty,
                    'bomtype' => $obj->bomtype,
                    'status' => $obj->status
                ];
            }
            $this->db->free($resql);
        } else {
            $this->debug("Error finding BOM parents: " . $this->db->lasterror());
        }

        $this->debug("Found " . count($bomParents) . " BOM parents");
        return $bomParents;
    }

    /**
     * Select BOM parent based on most recent purchases of the parent product
     *
     * @param array $bomParents Array of BOM parents
     * @param int $productId Product ID (for logging)
     * @return array|false Selected BOM parent or false if none found
     */
    private function selectBOMBasedOnRecentPurchases($bomParents, $productId)
    {
        $this->debug("Selecting BOM based on recent purchases for product " . $productId);

        $bestBOM = null;
        $mostRecentDate = null;

        foreach ($bomParents as $bomParent) {
            // Get most recent purchase date for this parent product
            $recentPurchaseDate = $this->getMostRecentPurchaseDate($bomParent['parent_product_id']);

            $this->debug("BOM " . $bomParent['bom_id'] . " (parent: " . $bomParent['parent_product_ref'] .
                        ") - Recent purchase date: " . ($recentPurchaseDate ? $recentPurchaseDate : 'none'));

            if ($recentPurchaseDate && (!$mostRecentDate || $recentPurchaseDate > $mostRecentDate)) {
                $mostRecentDate = $recentPurchaseDate;
                $bestBOM = $bomParent;
            }
        }

        // If no purchases found for any parent, select the first BOM as fallback
        if (!$bestBOM) {
            $this->debug("No recent purchases found, using first BOM as fallback");
            $bestBOM = reset($bomParents);
        }

        return $bestBOM;
    }

    /**
     * Get most recent purchase date for a product
     *
     * @param int $productId Product ID
     * @return string|false Most recent purchase date or false if none found
     */
    private function getMostRecentPurchaseDate($productId)
    {
        // Query purchase invoices (supplier invoices)
        $sql = "SELECT MAX(f.datef) as most_recent_date
                FROM " . MAIN_DB_PREFIX . "facture_fourn f
                JOIN " . MAIN_DB_PREFIX . "facture_fourn_det d ON f.rowid = d.fk_facture_fourn
                WHERE d.fk_product = " . (int)$productId . "
                AND f.fk_statut = 1"; // Only validated invoices

        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $this->db->free($resql);
            return $obj->most_recent_date;
        }

        return false;
    }

    /**
     * Calculate price from BOM components
     *
     * @param int $bomId BOM ID
     * @param int $targetProductId The product we're calculating price for
     * @return float|false Calculated price or false on error
     */
    private function calculatePriceFromBOM($bomId, $targetProductId = null)
    {
        $this->debug("Calculating price from BOM " . $bomId . " for target product " . $targetProductId);

        // Load BOM
        $bom = new BOM($this->db);
        if ($bom->fetch($bomId) <= 0) {
            $this->debug("Failed to load BOM " . $bomId);
            return false;
        }

        $this->debug("BOM Type: " . $bom->bomtype . " (0=Manufacturing, 1=Dismantle)");

        if ($bom->bomtype == 1) {
            // DISMANTLE BOM: Origin product → Components (e.g., box of eggs → individual eggs)
            return $this->calculatePriceForDismantleBOM($bom, $targetProductId);
        } else {
            // MANUFACTURING BOM: Components → Final product (e.g., ingredients → cake)
            return $this->calculatePriceForManufacturingBOM($bom);
        }
    }

    /**
     * Calculate price for dismantle BOM (e.g., box of eggs → individual eggs)
     *
     * @param object $bom BOM object
     * @param int $targetProductId The component product we're calculating price for
     * @return float|false Calculated price or false on error
     */
    private function calculatePriceForDismantleBOM($bom, $targetProductId)
    {
        $this->debug("Calculating dismantle BOM price");

        // Get the origin product (e.g., box of eggs)
        $originProduct = new Product($this->db);
        if ($originProduct->fetch($bom->fk_product) <= 0) {
            $this->debug("Failed to load origin product " . $bom->fk_product);
            return false;
        }

        $this->debug("Origin product: " . $originProduct->ref . " cost_price=" . $originProduct->cost_price);

        // Find the target product in the BOM lines to get its quantity
        $targetQuantity = 0;
        if (!empty($bom->lines)) {
            foreach ($bom->lines as $line) {
                if ($line->fk_product == $targetProductId) {
                    $targetQuantity = $line->qty;
                    break;
                }
            }
        }

        if ($targetQuantity <= 0) {
            $this->debug("Target product " . $targetProductId . " not found in BOM lines or has zero quantity");
            return false;
        }

        // Calculate unit cost: origin cost ÷ (BOM quantity × target component quantity)
        // Example: €48 box ÷ (1 box × 30 eggs) = €1.60 per egg
        $bomQuantity = $bom->qty > 0 ? $bom->qty : 1;
        $unitCost = $originProduct->cost_price / ($bomQuantity * $targetQuantity);

        $this->debug("Dismantle calculation: origin_cost=" . $originProduct->cost_price .
                    ", bom_qty=" . $bomQuantity . ", target_qty=" . $targetQuantity .
                    ", unit_cost=" . $unitCost);

        return $unitCost;
    }

    /**
     * Calculate price for manufacturing BOM (e.g., ingredients → cake)
     *
     * @param object $bom BOM object
     * @return float|false Calculated price or false on error
     */
    private function calculatePriceForManufacturingBOM($bom)
    {
        $this->debug("Calculating manufacturing BOM price");

        $totalCost = 0.0;

        // Calculate cost of each component
        if (!empty($bom->lines)) {
            foreach ($bom->lines as $line) {
                $product = new Product($this->db);
                if ($product->fetch($line->fk_product) > 0) {
                    $componentCost = $product->cost_price * $line->qty;
                    $totalCost += $componentCost;

                    $this->debug("Component " . $product->ref . ": cost_price=" . $product->cost_price .
                               ", qty=" . $line->qty . ", total=" . $componentCost);
                } else {
                    $this->debug("Failed to load component product " . $line->fk_product);
                }
            }
        }

        // Divide by BOM quantity to get unit cost
        $bomQuantity = $bom->qty > 0 ? $bom->qty : 1;
        $unitCost = $totalCost / $bomQuantity;

        $this->debug("Manufacturing calculation: total_cost=" . $totalCost .
                    ", bom_qty=" . $bomQuantity . ", unit_cost=" . $unitCost);

        return $unitCost;
    }

    /**
     * Update product cost price
     *
     * @param int $productId Product ID
     * @param float $newPrice New cost price
     * @param object $user User object
     * @return array|false Update result or false on error
     */
    private function updateProductCostPrice($productId, $newPrice, $user)
    {
        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            $this->debug("Failed to load product " . $productId);
            return false;
        }

        $oldPrice = $product->cost_price;

        // Only update if price has changed (tolerance of 0.001)
        if (abs($oldPrice - $newPrice) < 0.001) {
            $this->debug("No price change needed for product " . $product->ref .
                        " (current: " . $oldPrice . ", calculated: " . $newPrice . ")");
            return ['old_price' => $oldPrice, 'new_price' => $newPrice, 'updated' => false];
        }

        $product->cost_price = $newPrice;
        $result = $product->update($productId, $user);

        if ($result > 0) {
            $this->debug("Updated product " . $product->ref . " cost price from " . $oldPrice . " to " . $newPrice);
            return ['old_price' => $oldPrice, 'new_price' => $newPrice, 'updated' => true];
        } else {
            $this->debug("Failed to update product " . $product->ref . " cost price");
            return false;
        }
    }

    /**
     * Update multiple products from BOM relationships
     *
     * @param array $productIds Array of product IDs
     * @param object $user User object
     * @return array Results array
     */
    public function updateMultipleProductsFromBOM($productIds, $user)
    {
        $results = [];

        foreach ($productIds as $productId) {
            $result = $this->updateProductPriceFromBOM($productId, $user);
            $results[$productId] = $result;
        }

        return $results;
    }

    /**
     * Get products that have BOM parents (multiple or single)
     *
     * @param bool $includeDraftBOMs Whether to include draft BOMs (status = 0)
     * @param bool $allBOMTypes Whether to include all BOM types (not just manufacturing)
     * @param bool $multipleOnly Whether to return only products with multiple BOM parents
     * @return array Array of products with BOM parents
     */
    public function getProductsWithBOMParents($includeDraftBOMs = false, $allBOMTypes = true, $multipleOnly = false)
    {
        $debugMsg = "Finding products with BOM parents (includeDraft: " . ($includeDraftBOMs ? 'yes' : 'no') .
                   ", allTypes: " . ($allBOMTypes ? 'yes' : 'no') .
                   ", multipleOnly: " . ($multipleOnly ? 'yes' : 'no') . ")";
        $this->debug($debugMsg);

        $statusCondition = $includeDraftBOMs ? "b.status IN (0, 1)" : "b.status = 1";
        $bomTypeCondition = $allBOMTypes ? "" : "AND b.bomtype = 0";
        $havingCondition = $multipleOnly ? "HAVING COUNT(DISTINCT b.rowid) > 1" : "HAVING COUNT(DISTINCT b.rowid) >= 1";

        $sql = "SELECT bl.fk_product, p.ref, p.label, COUNT(DISTINCT b.rowid) as bom_count,
                       GROUP_CONCAT(DISTINCT CONCAT(b.rowid, ':', b.ref, '(type:', b.bomtype, ',status:', b.status, ')') SEPARATOR ', ') as bom_details
                FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                JOIN " . MAIN_DB_PREFIX . "bom_bom b ON b.rowid = bl.fk_bom
                JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = bl.fk_product
                WHERE $statusCondition $bomTypeCondition
                GROUP BY bl.fk_product, p.ref, p.label
                $havingCondition
                ORDER BY bom_count DESC, p.ref";

        $this->debug("Executing SQL: " . $sql);
        $resql = $this->db->query($sql);
        $products = [];

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $products[] = [
                    'product_id' => $obj->fk_product,
                    'ref' => $obj->ref,
                    'label' => $obj->label,
                    'bom_count' => $obj->bom_count,
                    'bom_details' => $obj->bom_details
                ];
            }
            $this->db->free($resql);
        } else {
            $this->debug("SQL Error: " . $this->db->lasterror());
        }

        $this->debug("Found " . count($products) . " products with BOM parents");
        return $products;
    }

    /**
     * Get products that have multiple BOM parents (backward compatibility)
     *
     * @param bool $includeDraftBOMs Whether to include draft BOMs (status = 0)
     * @param bool $allBOMTypes Whether to include all BOM types (not just manufacturing)
     * @return array Array of products with multiple BOM parents
     */
    public function getProductsWithMultipleBOMParents($includeDraftBOMs = false, $allBOMTypes = true)
    {
        return $this->getProductsWithBOMParents($includeDraftBOMs, $allBOMTypes, true);
    }

    /**
     * Get ALL products that are in BOMs (for debugging)
     *
     * @param bool $includeDraftBOMs Whether to include draft BOMs (status = 0)
     * @param bool $allBOMTypes Whether to include all BOM types (not just manufacturing)
     * @return array Array of all products in BOMs
     */
    public function getAllProductsInBOMs($includeDraftBOMs = true, $allBOMTypes = true)
    {
        $this->debug("Finding ALL products in BOMs (includeDraft: " . ($includeDraftBOMs ? 'yes' : 'no') . ", allTypes: " . ($allBOMTypes ? 'yes' : 'no') . ")");

        $statusCondition = $includeDraftBOMs ? "b.status IN (0, 1)" : "b.status = 1";
        $bomTypeCondition = $allBOMTypes ? "" : "AND b.bomtype = 0";

        $sql = "SELECT bl.fk_product, p.ref, p.label, COUNT(DISTINCT b.rowid) as bom_count,
                       GROUP_CONCAT(DISTINCT CONCAT(b.rowid, ':', b.ref, '(type:', b.bomtype, ',status:', b.status, ')') SEPARATOR ', ') as bom_list
                FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                JOIN " . MAIN_DB_PREFIX . "bom_bom b ON b.rowid = bl.fk_bom
                JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = bl.fk_product
                WHERE $statusCondition $bomTypeCondition
                GROUP BY bl.fk_product, p.ref, p.label
                ORDER BY bom_count DESC, p.ref";

        $this->debug("Executing SQL: " . $sql);
        $resql = $this->db->query($sql);
        $products = [];

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $products[] = [
                    'product_id' => $obj->fk_product,
                    'ref' => $obj->ref,
                    'label' => $obj->label,
                    'bom_count' => $obj->bom_count,
                    'bom_list' => $obj->bom_list
                ];
            }
            $this->db->free($resql);
        } else {
            $this->debug("SQL Error: " . $this->db->lasterror());
        }

        $this->debug("Found " . count($products) . " total products in BOMs");
        return $products;
    }

    /**
     * Debug BOM tables structure and content
     *
     * @return array Debug information
     */
    public function debugBOMTables()
    {
        $debug = [];

        // Check if tables exist
        $tables = ['bom_bom', 'bom_bomline'];
        foreach ($tables as $table) {
            $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . $table . "'";
            $resql = $this->db->query($sql);
            $debug['tables'][$table] = ($resql && $this->db->num_rows($resql) > 0);
            if ($resql) $this->db->free($resql);
        }

        // Count total BOMs
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "bom_bom";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $debug['total_boms'] = $obj->total;
            $this->db->free($resql);
        }

        // Count manufacturing BOMs
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "bom_bom WHERE bomtype = 0";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $debug['manufacturing_boms'] = $obj->total;
            $this->db->free($resql);
        }

        // Count validated BOMs
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "bom_bom WHERE bomtype = 0 AND status = 1";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $debug['validated_manufacturing_boms'] = $obj->total;
            $this->db->free($resql);
        }

        // Count draft BOMs
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "bom_bom WHERE bomtype = 0 AND status = 0";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $debug['draft_manufacturing_boms'] = $obj->total;
            $this->db->free($resql);
        }

        // Count BOM lines
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                JOIN " . MAIN_DB_PREFIX . "bom_bom b ON b.rowid = bl.fk_bom
                WHERE b.bomtype = 0 AND b.status = 1";
        $resql = $this->db->query($sql);
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $debug['valid_bom_lines'] = $obj->total;
            $this->db->free($resql);
        }

        // Sample BOMs
        $sql = "SELECT b.rowid, b.ref, b.bomtype, b.status, COUNT(bl.rowid) as line_count
                FROM " . MAIN_DB_PREFIX . "bom_bom b
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bomline bl ON b.rowid = bl.fk_bom
                GROUP BY b.rowid, b.ref, b.bomtype, b.status
                LIMIT 10";
        $resql = $this->db->query($sql);
        $debug['sample_boms'] = [];
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $debug['sample_boms'][] = [
                    'id' => $obj->rowid,
                    'ref' => $obj->ref,
                    'bomtype' => $obj->bomtype,
                    'status' => $obj->status,
                    'line_count' => $obj->line_count
                ];
            }
            $this->db->free($resql);
        }

        return $debug;
    }

    /**
     * Batch update all products with multiple BOM parents
     *
     * @param object $user User object
     * @return array Batch update results
     */
    public function batchUpdateProductsWithMultipleBOMParents($user)
    {
        $products = $this->getProductsWithMultipleBOMParents();
        $productIds = array_column($products, 'product_id');

        return $this->updateMultipleProductsFromBOM($productIds, $user);
    }

    /**
     * Batch update ALL products with BOM parents (single or multiple)
     *
     * @param object $user User object
     * @param bool $includeDraftBOMs Whether to include draft BOMs
     * @param bool $allBOMTypes Whether to include all BOM types
     * @return array Batch update results
     */
    public function batchUpdateAllProductsWithBOMs($user, $includeDraftBOMs = false, $allBOMTypes = true)
    {
        $products = $this->getProductsWithBOMParents($includeDraftBOMs, $allBOMTypes, false);
        $productIds = array_column($products, 'product_id');

        $this->debug("Batch updating " . count($productIds) . " products with BOMs");

        return $this->updateMultipleProductsFromBOM($productIds, $user);
    }

    /**
     * Test calculation for a specific product with detailed output
     *
     * @param int $productId Product ID to test
     * @return array Test results with detailed breakdown
     */
    public function testCalculationForProduct($productId)
    {
        $this->debug("=== Testing calculation for product " . $productId . " ===");

        // Get BOM parents
        $bomParents = $this->getBOMParentsForProduct($productId);

        if (empty($bomParents)) {
            return ['success' => false, 'error' => 'No BOM parents found for product ' . $productId];
        }

        $results = [];
        foreach ($bomParents as $bomParent) {
            $this->debug("Testing BOM " . $bomParent['bom_id'] . " (type " . $bomParent['bomtype'] . ")");

            // Load the product to show current price
            $product = new Product($this->db);
            $currentPrice = 0;
            if ($product->fetch($productId) > 0) {
                $currentPrice = $product->cost_price;
            }

            // Calculate price
            $calculatedPrice = $this->calculatePriceFromBOM($bomParent['bom_id'], $productId);

            $results[] = [
                'bom_id' => $bomParent['bom_id'],
                'bom_ref' => $bomParent['bom_ref'],
                'bom_type' => $bomParent['bomtype'],
                'bom_status' => $bomParent['status'],
                'parent_product_ref' => $bomParent['parent_product_ref'],
                'current_price' => $currentPrice,
                'calculated_price' => $calculatedPrice,
                'calculation_success' => ($calculatedPrice !== false)
            ];
        }

        return [
            'success' => true,
            'product_id' => $productId,
            'bom_parents_count' => count($bomParents),
            'calculations' => $results
        ];
    }

    /**
     * Get product details for display
     *
     * @param int $productId Product ID
     * @return array Product information
     */
    public function getProductDetails($productId)
    {
        $product = new Product($this->db);
        if ($product->fetch($productId) > 0) {
            return [
                'id' => $product->id,
                'ref' => $product->ref,
                'label' => $product->label,
                'cost_price' => $product->cost_price
            ];
        }
        return [
            'id' => $productId,
            'ref' => 'Unknown',
            'label' => 'Product not found',
            'cost_price' => 0
        ];
    }

    /**
     * Test method to verify functionality
     *
     * @return array Test results
     */
    public function runSelfTest()
    {
        $results = [
            'database_connection' => false,
            'bom_table_exists' => false,
            'product_table_exists' => false,
            'supplier_invoice_table_exists' => false,
            'test_queries' => false,
            'errors' => []
        ];

        // Test database connection
        if ($this->db) {
            $results['database_connection'] = true;
        } else {
            $results['errors'][] = 'No database connection available';
            return $results;
        }

        // Test required tables exist
        $tables = [
            'bom_table_exists' => MAIN_DB_PREFIX . 'bom_bom',
            'product_table_exists' => MAIN_DB_PREFIX . 'product',
            'supplier_invoice_table_exists' => MAIN_DB_PREFIX . 'facture_fourn'
        ];

        foreach ($tables as $key => $tableName) {
            $sql = "SHOW TABLES LIKE '" . $tableName . "'";
            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) > 0) {
                $results[$key] = true;
            } else {
                $results['errors'][] = $tableName . ' table not found';
            }
            if ($resql) $this->db->free($resql);
        }

        // Test basic queries
        if ($results['bom_table_exists'] && $results['product_table_exists']) {
            $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "bom_bom WHERE bomtype = 0";
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                $results['test_queries'] = true;
                $results['manufacturing_bom_count'] = $obj->count;
                $this->db->free($resql);
            } else {
                $results['errors'][] = 'Failed to query BOM table: ' . $this->db->lasterror();
            }
        }

        return $results;
    }
}