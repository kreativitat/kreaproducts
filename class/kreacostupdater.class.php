<?php

/**
 * KreaCostUpdater Class
 *
 * This class handles the updating of buy prices (cost prices) for products,
 * consolidating the necessary methods and logic.
 */

class KreaCostUpdater
{
    /**
     * @var int The ID of the product to update.
     */
    private $productId;

    /**
     * @var Product The product object from Dolibarr.
     */
    private $product;

    /**
     * @var bool Indicates whether the buy price sync feature is enabled globally.
     */
    private $isFeatureEnabled;

    /**
     * @var float The new computed buy price.
     */
    private $newBuyPrice;

    /**
     * Constructor
     *
     * @param int $productId The ID of the product to update.
     */
    public function __construct($productId)
    {
        global $db, $conf;
        $this->productId = $productId;
        $this->isFeatureEnabled = !empty($conf->global->KREACOSTUPDATER_UseWholeSalePriceSync);
        $this->product = new Product($db);
    }

    /**
     * Main method to execute the buy price update.
     *
     * @return bool True if the buy price was updated, false otherwise.
     */
    public function execute()
    {
        global $user, $langs, $conf;

        // Check if the global feature is enabled.
        if (!$this->isFeatureEnabled) {
            dol_syslog("KreaCostUpdater: KREACOSTUPDATER_UseWholeSalePriceSync is disabled", LOG_WARNING);

            //return false;
            return true;
        }

        // Load the product from the database.
        if (!$this->loadProduct()) {
            dol_syslog("KreaCostUpdater: Failed to load product with ID {$this->productId}", LOG_ERR);

            return false;
        }

        // Check if buy price sync is enabled for this product.
        if (!$this->isSyncEnabledForProduct()) {
            return false;
        }

        // Compute the new buy price.
        $this->newBuyPrice = $this->computeNewBuyPrice();

        // Update the buy price if it has changed.
        return $this->updateBuyPrice($user);
    }

    /**
     * Loads the product from the database.
     *
     * @return bool True if the product was loaded successfully, false otherwise.
     */
    private function loadProduct()
    {
        $result = $this->product->fetch($this->productId);
        return $result > 0;
    }

    /**
     * Checks if buy price synchronization is enabled for this product.
     *
     * @return bool True if sync is enabled, false otherwise.
     */
    private function isSyncEnabledForProduct()
    {
        // Assuming the synchronization flag is stored in an extra field called 'options_sync_buyprice'
        $this->product->fetch_optionals($this->product->id);

        if (isset($this->product->array_options['options_sync_buyprice'])) {
            return !empty($this->product->array_options['options_sync_buyprice']);
        }

        // Default to true if not set
        return true;
    }

    /**
     * Computes the new buy price for the product.
     *
     * @return float The new computed buy price.
     */
    private function computeNewBuyPrice()
    {
        // Logic to compute the new buy price.
        // This could involve aggregating the buy prices of child products if this product is virtual.

        $newBuyPrice = 0.0;

        // If the product has child products, compute the buy price based on them.
        if ($this->productHasChildren()) {
            $newBuyPrice = $this->computeBuyPriceFromChildren();
        } else {
            // Otherwise, use the product's current cost price.
            $newBuyPrice = $this->product->cost_price;
        }

        return $newBuyPrice;
    }

    /**
     * Checks if the product has child products (is a virtual product).
     *
     * @return bool True if the product has children, false otherwise.
     */
    private function productHasChildren()
    {
        global $db;

        // Check if the product has child products in the 'product_association' table.
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "product_association WHERE fk_product_pere = " . intval($this->productId);
        $resql = $db->query($sql);

        if ($resql) {
            $obj = $db->fetch_object($resql);
            return ($obj->cnt > 0);
        } else {
            dol_syslog("KreaCostUpdater: Error checking for child products: " . $db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Computes the buy price based on child products.
     *
     * @return float The computed buy price from child products.
     */
    private function computeBuyPriceFromChildren()
    {
        global $db;

        $totalBuyPrice = 0.0;

        // Fetch child products and their quantities.
        $sql = "SELECT fk_product_fils as child_id, qty FROM " . MAIN_DB_PREFIX . "product_association WHERE fk_product_pere = " . intval($this->productId);
        $resql = $db->query($sql);

        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $childProduct = new Product($db);
                if ($childProduct->fetch($obj->child_id) > 0) {
                    $childBuyPrice = $childProduct->cost_price;
                    $quantity = $obj->qty;
                    $totalBuyPrice += $childBuyPrice * $quantity;
                } else {
                    dol_syslog("KreaCostUpdater: Failed to fetch child product with ID {$obj->child_id}", LOG_ERR);
                }
            }
            $db->free($resql);
        } else {
            dol_syslog("KreaCostUpdater: Error fetching child products: " . $db->lasterror(), LOG_ERR);
        }

        return $totalBuyPrice;
    }

    /**
     * Updates the buy price if it has changed.
     *
     * @param User $user The current user performing the update.
     *
     * @return bool True if the buy price was updated, false otherwise.
     */
    private function updateBuyPrice($user)
    {
        global $langs, $conf;

        // Compare the current cost price with the new buy price.
        if (abs($this->product->cost_price - $this->newBuyPrice) < 0.001) {
            // No significant change; no need to update.
            return false;
        }

        // Update the product's cost price.
        $this->product->cost_price = $this->newBuyPrice;
        $result = $this->product->update($this->productId, $user);

        if ($result > 0) {
            // Log the update if verbose logging is enabled.
            if (!empty($conf->global->KREACOSTUPDATER_Verbose)) {
                $currency = $conf->currency;
                $message = $langs->trans(
                    "KREACOSTUPDATER_ProductWholeSalePriceUpdated",
                    $this->product->ref,
                    price($this->newBuyPrice) . " " . $currency
                );
                dol_syslog($message, LOG_INFO);
            }

            return true;
        } else {
            // Log an error if the update failed.
            dol_syslog("KreaCostUpdater: Failed to update buy price for product {$this->product->ref}. Error: " . $this->product->error, LOG_ERR);

            return false;
        }
    }
}
