<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/lib/mrp_mo.lib.php';


class ProductDismantleController extends CommonObject
{
    public $db;

    /**
     * @var Mo $mo {@type Mo}
     */
    public $mo;

    public function __construct($db)
    {
        global $db, $conf;
        $this->db = $db;
        $this->mo = new Mo($this->db);
    }

    /**
     * Find BOM ID associated with a product ID.
     *
     * @param int $productId The ID of the product to find the BOM for.
     * @return int|false The BOM ID if found, false otherwise.
     */
    public function findBom($productId)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom
            WHERE fk_product = " . (int) $productId . "
            AND bomtype = 1"; // Assuming bomtype = 1 indicates a dismantle type BOM

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($obj = $this->db->fetch_object($resql)) {
                return (int) $obj->rowid; // Return the BOM ID
            } else {
                // No BOM found for the product
                return false;
            }
        } else {
            // Query failed
            dol_syslog("findBom query failed for product ID " . $productId, LOG_ERR);
            return false;
        }
    }

    public function productInDismantleCategory($productId)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        global $conf;

        $productDismantleCategory = !empty($conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY) ? $conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY : 0;

        $sql = "SELECT fk_categorie FROM " . MAIN_DB_PREFIX . "categorie_product WHERE fk_product = " . $productId;
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                if ($obj->fk_categorie == $productDismantleCategory) { // Dismantle category ID
                    return true;
                }
            }
        }
        return false;
    }

    public function produceAndConsume($bomId, $qtyMovement, $priceMovement, $originRef, $originId, $originType)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        global $user, $conf;

        $defaultWarehouseId = !empty($conf->global->MAIN_DEFAULT_WAREHOUSE) ? $conf->global->MAIN_DEFAULT_WAREHOUSE : 0;

        $error = 0;

        // Load BOM
        $bom = new BOM($this->db);
        if ($bom->fetch($bomId) <= 0) {
            dol_syslog("Failed to fetch BOM details", LOG_ERR);
            return -1; // Handle error appropriately
        }

        // Fetch BOM lines for components to consume
        if (!is_array($bom->lines) || empty($bom->lines)) {
            dol_syslog("BOM has no lines", LOG_WARNING);
            return -1; // Handle error appropriately
        }

        $arraytoconsume = [];
        $arraytoproduce = [];

        $finalProductQty = $bom->qty;

        // Add the main product (origin product) to consumption
        $arraytoconsume[] = [
            'objectid' => $bom->fk_product,
            'qty' => $finalProductQty,
            'fk_warehouse' => $defaultWarehouseId,
        ];
        dol_syslog("arraytoconsume: " . json_encode($arraytoconsume, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Add products to produce (from BOM lines)
        foreach ($bom->lines as $line) {
            $arraytoproduce[] = [
                'objectid' => $line->fk_product,
                'qty' => $line->qty,
                'fk_warehouse' => $defaultWarehouseId,
            ];
        }
        dol_syslog("arraytoproduce: " . json_encode($arraytoproduce, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Initialize stock movement object
        $stockmove = new MouvementStock($this->db);

        // Process consumption and production
        foreach (array('arraytoconsume', 'arraytoproduce') as $arrayname) {
            foreach (${$arrayname} as $item) {
                // Fetch product
                $product = new Product($this->db);
                if ($product->fetch($item['objectid']) <= 0) {
                    dol_syslog("Failed to fetch product with ID " . $item['objectid'], LOG_ERR);
                    $error++;
                    break; // Exit on error
                }

                // Determine the direction of stock movement
                $qty = $item['qty']; // * $qtyMovement;

                if ($arrayname == 'arraytoconsume') {
                    // Update cost price for the origin product being consumed
                    $product->cost_price = $priceMovement;
                    dol_syslog("Quantity: $qty, Cost Price: $product->cost_price", LOG_ERR);

                    // Consume product (remove from stock)
                    $result = $stockmove->livraison(
                        $user,
                        $item['objectid'],
                        $item['fk_warehouse'],
                        $qty,
                        $product->cost_price,
                        "Consume for MO ($originRef)",
                        dol_now(),
                        '',    // Eat-by date (not used here)
                        '',    // Sell-by date (not used here)
                        '',    // Batch (not used here)
                        $originId, // Origin ID
                        $originType // Origin Type
                    );

                    if ($product->update($product->id, $user) <= 0) {
                        dol_syslog("Failed to update cost_price for consumed product ID " . $item['objectid'], LOG_ERR);
                        $error++;
                        break;
                    }
                } else {
                    // Update cost price for the produced product
                    if ($qty > 0) {
                        $costPerUnit = $priceMovement / $qty; // Divide total cost by produced quantity
                        $product->cost_price = $costPerUnit;
                        dol_syslog("Quantity: $qty, Cost per unit: $costPerUnit", LOG_ERR);
                    } else {
                        dol_syslog("Cannot divide by zero. Invalid quantity for product ID " . $item['objectid'], LOG_ERR);
                        $error++;
                        break;
                    }

                    // Produce product (add to stock)
                    $result = $stockmove->reception(
                        $user,
                        $item['objectid'],
                        $item['fk_warehouse'],
                        $qty,
                        $product->cost_price,
                        "Produce for MO ($originRef)",
                        '',
                        '',    // Eat-by date (not used here)
                        '',    // Sell-by date (not used here)
                        '',    // Batch (not used here)
                        dol_now(),
                        $originId, // Origin ID
                        $originType // Origin Type
                    );

                    // Set the origin for the stock movement
                    $stockmove->setOrigin($originType, $originId);

                    if ($product->update($product->id, $user) <= 0) {
                        dol_syslog("Failed to update cost_price for produced product ID " . $item['objectid'], LOG_ERR);
                        $error++;
                        break;
                    }
                }

                if ($result <= 0) {
                    dol_syslog("Stock movement failed for product ID " . $item['objectid'] . " with error " . $stockmove->error, LOG_ERR);
                    $error++;
                    break; // Exit on error
                }
            }

            if ($error) break; // Exit if there was an error processing any item
        }

        if ($error) {
            // Rollback transaction on error
            dol_syslog("Errors encountered, rolling back.", LOG_ERR);
            return -1; // Indicate failure
        } else {
            dol_syslog("MO processed successfully, transaction committed.", LOG_DEBUG);
            // Update MO status here as needed
        }

        return 0; // Success
    }
}
