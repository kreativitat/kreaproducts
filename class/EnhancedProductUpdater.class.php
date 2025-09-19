<?php

require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

/**
 * Enhanced Product Updater with BOM Priority Selection
 *
 * This class extends the existing ProductUpdater functionality by adding
 * intelligent BOM parent selection based on most recent purchases.
 *
 * It combines:
 * - Existing ProductUpdater hierarchy calculations
 * - New ProductBOMPriceUpdater with purchase-based BOM selection
 */
class EnhancedProductUpdater extends ProductUpdater
{
    /**
     * @var ProductBOMPriceUpdater BOM price updater instance
     */
    private static $bomUpdater = null;

    /**
     * Enhanced product cost price update with BOM priority selection
     *
     * @param int $productId Product ID that was modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @param bool $useBOMPriority Use BOM priority selection based on recent purchases
     * @return array Results array
     */
    public static function updateProductCostPriceEnhanced(int $productId, bool $useWholeSalePriceSync = true, bool $useBOMPriority = true): array
    {
        self::debug("Starting enhanced cost price update for product ID: " . $productId);

        $results = [];

        // Step 1: Try BOM-based price update if enabled
        if ($useBOMPriority) {
            $bomResult = self::updatePriceFromBOM($productId);
            if ($bomResult['success']) {
                $results['bom_update'] = $bomResult;
                self::debug("BOM-based price update successful for product: " . $productId);
            } else {
                self::debug("BOM-based price update skipped: " . $bomResult['error']);
                $results['bom_update'] = $bomResult;
            }
        }

        // Step 2: Run standard hierarchy-based price update
        $hierarchyResults = self::updateProductCostPrice($productId, $useWholeSalePriceSync);
        $results['hierarchy_update'] = $hierarchyResults;

        self::debug("Enhanced update completed for product: " . $productId);

        return $results;
    }

    /**
     * Update product price using BOM with most recent purchases
     *
     * @param int $productId Product ID
     * @return array Update result
     */
    private static function updatePriceFromBOM(int $productId): array
    {
        global $db, $user;

        if (!self::$bomUpdater) {
            self::$bomUpdater = new ProductBOMPriceUpdater($db);
            self::$bomUpdater->setDebug(self::$debug);
        }

        return self::$bomUpdater->updateProductPriceFromBOM($productId, $user);
    }

    /**
     * Enhanced trigger method for product modifications
     *
     * @param int $productId The product that was just saved/modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @param bool $useBOMPriority Use BOM priority selection
     * @return array Results array showing what was updated
     */
    public static function onProductModifiedEnhanced(int $productId, bool $useWholeSalePriceSync = true, bool $useBOMPriority = true): array
    {
        self::debug("=== Enhanced Product Modified Event for Product ID: " . $productId . " ===");

        $results = self::updateProductCostPriceEnhanced($productId, $useWholeSalePriceSync, $useBOMPriority);

        self::debug("=== End Enhanced Product Modified Event ===");

        return $results;
    }

    /**
     * Batch update with BOM priority for products with multiple parents
     *
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array Batch update results
     */
    public static function batchUpdateWithBOMPriority(bool $useWholeSalePriceSync = true): array
    {
        global $db, $user;

        if (!self::$bomUpdater) {
            self::$bomUpdater = new ProductBOMPriceUpdater($db);
            self::$bomUpdater->setDebug(self::$debug);
        }

        // Get products with multiple BOM parents
        $multiParentProducts = self::$bomUpdater->getProductsWithMultipleBOMParents();
        $productIds = array_column($multiParentProducts, 'product_id');

        $results = [];

        foreach ($productIds as $productId) {
            $results[$productId] = self::updateProductCostPriceEnhanced($productId, $useWholeSalePriceSync, true);
        }

        return [
            'processed_products' => count($productIds),
            'results' => $results,
            'multi_parent_products' => $multiParentProducts
        ];
    }

    /**
     * Get statistics about BOM relationships and pricing
     *
     * @return array Statistics array
     */
    public static function getBOMPricingStatistics(): array
    {
        global $db;

        if (!self::$bomUpdater) {
            self::$bomUpdater = new ProductBOMPriceUpdater($db);
        }

        $stats = [];

        // Get products with multiple BOM parents
        $multiParentProducts = self::$bomUpdater->getProductsWithMultipleBOMParents();
        $stats['products_with_multiple_bom_parents'] = count($multiParentProducts);

        // Get total products in BOMs
        $sql = "SELECT COUNT(DISTINCT bl.fk_product) as total_products_in_bom
                FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                JOIN " . MAIN_DB_PREFIX . "bom_bom b ON b.rowid = bl.fk_bom
                WHERE b.bomtype = 0 AND b.status = 1";

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            $stats['total_products_in_bom'] = $obj->total_products_in_bom;
            $db->free($resql);
        }

        // Get total active BOMs
        $sql = "SELECT COUNT(*) as total_active_boms
                FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE bomtype = 0 AND status = 1";

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            $stats['total_active_boms'] = $obj->total_active_boms;
            $db->free($resql);
        }

        return $stats;
    }

    /**
     * Enhanced method compatible with existing trigger calls
     *
     * @param int $productId Starting product ID
     * @param mixed $user User performing the update
     * @return int 1 on success, 0 on failure or skip
     */
    public static function updateProductAttributes($productId, $user)
    {
        // Check if enhanced mode is enabled via configuration
        global $conf;
        $useBOMPriority = !empty($conf->global->KREAPRODUCTS_USE_BOM_PRIORITY);

        if ($useBOMPriority) {
            // Use enhanced update
            $results = self::updateProductCostPriceEnhanced($productId, true, true);

            // Return 1 if any update was successful
            if (!empty($results['bom_update']['success']) || !empty($results['hierarchy_update'])) {
                foreach ($results['hierarchy_update'] as $result) {
                    if ($result['updated']) {
                        return 1;
                    }
                }
                return !empty($results['bom_update']['success']) ? 1 : 0;
            }
            return 0;
        } else {
            // Use standard update method
            return parent::updateProductAttributes($productId, $user);
        }
    }

    /**
     * Enable/disable BOM priority mode globally
     *
     * @param bool $enabled
     */
    public static function setBOMPriorityMode(bool $enabled): void
    {
        global $conf;
        $conf->global->KREAPRODUCTS_USE_BOM_PRIORITY = $enabled ? 1 : 0;
    }

    /**
     * Check if BOM priority mode is enabled
     *
     * @return bool
     */
    public static function isBOMPriorityModeEnabled(): bool
    {
        global $conf;
        return !empty($conf->global->KREAPRODUCTS_USE_BOM_PRIORITY);
    }
}