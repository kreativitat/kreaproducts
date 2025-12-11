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
        global $conf;

        $bomType = (int) ($conf->global->KREAPRODUCTS_DISMANTLE_BOMTYPE ?? 1);
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom
            WHERE fk_product = " . (int) $productId . "
            AND bomtype = " . $bomType;

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

        $productDismantleCategory = 0;
        if (!empty($conf->global->KREAPRODUCTS_DISMANTLE_CATEGORY)) {
            $productDismantleCategory = (int) $conf->global->KREAPRODUCTS_DISMANTLE_CATEGORY;
        } elseif (!empty($conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY)) {
            // Backward compatibility
            $productDismantleCategory = (int) $conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY;
        }

        if ($productDismantleCategory <= 0) {
            return false;
        }

        $sql = "SELECT fk_categorie FROM " . MAIN_DB_PREFIX . "categorie_product WHERE fk_product = " . (int) $productId;
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

    public function produceAndConsume($bomId, $qtyMovement, $priceMovement, $originRef, $originId, $originType, $movementDate = null)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        global $user, $conf;

        $movementDate = $movementDate ?: dol_now();
        $warehouseId  = (int) ($conf->global->KREAPRODUCTS_DISMANTLE_WAREHOUSE ?? $conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
        $error = 0;

        // Load BOM
        $bom = new BOM($this->db);
        if ($bom->fetch($bomId) <= 0) {
            dol_syslog("Failed to fetch BOM details", LOG_ERR);
            return -1;
        }

        $productToConsume = new Product($this->db);
        if ($productToConsume->fetch($bom->fk_product) > 0) {
            $currentCostPrice = $productToConsume->cost_price;
            dol_syslog(
                "Current cost price for product #" . $bom->fk_product . ": " . $currentCostPrice,
                LOG_DEBUG
            );
        } else {
            dol_syslog(
                "Failed to fetch product #" . $bom->fk_product . " for cost price",
                LOG_ERR
            );
            $currentCostPrice = null; // handle error as needed
        }

        // Ensure BOM has lines
        if (!is_array($bom->lines) || empty($bom->lines)) {
            dol_syslog("BOM has no lines", LOG_WARNING);
            return -1;
        }

        $arraytoconsume = [];
        $arraytoproduce = [];

        $finalProductQty = $bom->qty;

        // Add main product to consume
        $arraytoconsume[] = [
            'objectid'    => $bom->fk_product,
            'qty'         => $finalProductQty,
            'fk_warehouse' => $warehouseId,
        ];
        dol_syslog("arraytoconsume: " . json_encode($arraytoconsume, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Add BOM components to produce
        foreach ($bom->lines as $line) {
            $arraytoproduce[] = [
                'objectid'    => $line->fk_product,
                'qty'         => $line->qty,
                'fk_warehouse' => $warehouseId,
            ];
        }
        dol_syslog("arraytoproduce: " . json_encode($arraytoproduce, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Initialize stock movement handler
        $stockmove = new MouvementStock($this->db);

        // Process consumption and production arrays
        foreach (['arraytoconsume', 'arraytoproduce'] as $arrayname) {
            foreach (${$arrayname} as $item) {
                $product = new Product($this->db);
                if ($product->fetch($item['objectid']) <= 0) {
                    dol_syslog("Failed to fetch product with ID " . $item['objectid'], LOG_ERR);
                    $error++;
                    break;
                }

                // Calculate signed and absolute quantities
                $rawQty = $item['qty'] * $qtyMovement;
                $qty    = abs($rawQty);

                // Update cost price
                if ($arrayname === 'arraytoconsume') {
                    $product->cost_price = $priceMovement;
                } else {
                    if ($qty > 0) {
                        $product->cost_price = $currentCostPrice / $item['qty'];
                    } else {
                        dol_syslog("Cannot divide by zero for product ID " . $item['objectid'], LOG_ERR);
                        $error++;
                        break;
                    }
                }
                if ($product->update($product->id, $user) <= 0) {
                    dol_syslog("Failed to update cost_price for product ID " . $item['objectid'], LOG_ERR);
                    $error++;
                    break;
                }

                // Perform the correct stock movement:
                if ($arrayname === 'arraytoconsume') {
                    if ($rawQty >= 0) {
                        // Normal consumption: stock -> out
                        $result = $stockmove->livraison(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $product->cost_price,
                            "Consume for MO ($originRef)",
                            $movementDate,
                            '',
                            '',
                            '',
                            $originId,
                            $originType
                        );
                    } else {
                        // Reverse consumption: stock <- in
                        $result = $stockmove->reception(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $product->cost_price,
                            "Reverse consume for MO ($originRef)",
                            '',
                            '',
                            '',
                            $movementDate,
                            $originId,
                            $originType
                        );
                    }
                } else {
                    if ($rawQty >= 0) {
                        // Normal production: stock <- in
                        $result = $stockmove->reception(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $product->cost_price,
                            "Produce for MO ($originRef)",
                            '',
                            '',
                            '',
                            $movementDate,
                            $originId,
                            $originType
                        );
                    } else {
                        // Reverse production: stock -> out
                        $result = $stockmove->livraison(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $product->cost_price,
                            "Reverse produce for MO ($originRef)",
                            $movementDate,
                            '',
                            '',
                            '',
                            $originId,
                            $originType
                        );
                    }
                }

                // Link origin and check for errors
                $stockmove->setOrigin($originType, $originId);
                if ($result <= 0) {
                    dol_syslog("Stock movement failed for product ID " . $item['objectid'] . " with error " . $stockmove->error, LOG_ERR);
                    $error++;
                    break;
                }
            }
            if ($error) break;
        }

        if ($error) {
            dol_syslog("Errors encountered, rolling back.", LOG_ERR);
            return -1;
        }

        dol_syslog("MO processed successfully, transaction committed.", LOG_DEBUG);
        return 0;
    }
}
