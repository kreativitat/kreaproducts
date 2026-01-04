<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

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
            AND bomtype = " . $bomType . "
            AND status = 1
            AND entity IN (0," . getEntity('bom') . ")
            ORDER BY (entity = " . ((int) $conf->entity) . ") DESC, (entity = 0) DESC, rowid DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog("findBom query failed for product ID " . $productId, LOG_ERR);
            return false;
        }
        if ($obj = $this->db->fetch_object($resql)) {
            return (int) $obj->rowid;
        }

        $sqlFallback = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "bom_bom
            WHERE fk_product = " . (int) $productId . "
            AND bomtype = " . $bomType . "
            AND status IN (0,1)
            AND entity IN (0," . getEntity('bom') . ")
            ORDER BY (entity = " . ((int) $conf->entity) . ") DESC, (entity = 0) DESC, rowid DESC";
        $resqlFallback = $this->db->query($sqlFallback);
        if (!$resqlFallback) {
            dol_syslog("findBom fallback query failed for product ID " . $productId, LOG_ERR);
            return false;
        }
        if ($obj = $this->db->fetch_object($resqlFallback)) {
            dol_syslog("Using draft BOM #" . (int) $obj->rowid . " for product ID " . $productId . " (validate BOM to avoid fallback)", LOG_WARNING);
            return (int) $obj->rowid;
        }
        $productRef = '';
        $defaultBomId = 0;
        $sqlProduct = "SELECT ref, fk_default_bom FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int) $productId;
        $resProduct = $this->db->query($sqlProduct);
        if ($resProduct) {
            $objProduct = $this->db->fetch_object($resProduct);
            if ($objProduct && !empty($objProduct->ref)) {
                $productRef = (string) $objProduct->ref;
            }
            if ($objProduct && !empty($objProduct->fk_default_bom)) {
                $defaultBomId = (int) $objProduct->fk_default_bom;
            }
        }
        if ($defaultBomId > 0) {
            $sqlDefaultBom = "SELECT rowid, bomtype, status FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE rowid = " . $defaultBomId . "
                AND entity IN (0," . getEntity('bom') . ")";
            $resDefaultBom = $this->db->query($sqlDefaultBom);
            if ($resDefaultBom && ($objDefaultBom = $this->db->fetch_object($resDefaultBom))) {
                if (in_array((int) $objDefaultBom->status, [0, 1], true)) {
                    if ((int) $objDefaultBom->bomtype !== $bomType) {
                        dol_syslog("Default BOM #" . (int) $objDefaultBom->rowid . " bomtype " . (int) $objDefaultBom->bomtype . " differs from configured bomtype " . $bomType . " for product ID " . $productId, LOG_WARNING);
                    }
                    if ((int) $objDefaultBom->status !== 1) {
                        dol_syslog("Using default BOM #" . (int) $objDefaultBom->rowid . " in draft for product ID " . $productId, LOG_WARNING);
                    }
                    dol_syslog("Using default BOM #" . (int) $objDefaultBom->rowid . " for product ID " . $productId, LOG_DEBUG);
                    return (int) $objDefaultBom->rowid;
                }
                dol_syslog("Default BOM #" . (int) $defaultBomId . " has unsupported status for product ID " . $productId, LOG_DEBUG);
            }
        }
        if ($productRef !== '') {
            $sqlRef = "SELECT b.rowid FROM " . MAIN_DB_PREFIX . "bom_bom b
                JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                WHERE p.ref = '" . $this->db->escape($productRef) . "'
                AND b.bomtype = " . $bomType . "
                AND b.status IN (0,1)
                AND b.entity IN (0," . getEntity('bom') . ")
                ORDER BY (b.entity = " . ((int) $conf->entity) . ") DESC, (b.entity = 0) DESC, b.rowid DESC";
            $resRef = $this->db->query($sqlRef);
            if ($resRef && ($objRef = $this->db->fetch_object($resRef))) {
                dol_syslog("Using BOM #" . (int) $objRef->rowid . " matched by product ref " . $productRef . " for product ID " . $productId, LOG_WARNING);
                return (int) $objRef->rowid;
            }
        }
        if (in_array($bomType, [0, 1], true)) {
            $fallbackBomType = ($bomType === 1 ? 0 : 1);
            $sqlFallbackType = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE fk_product = " . (int) $productId . "
                AND bomtype = " . $fallbackBomType . "
                AND status IN (0,1)
                AND entity IN (0," . getEntity('bom') . ")
                ORDER BY (entity = " . ((int) $conf->entity) . ") DESC, (entity = 0) DESC, rowid DESC";
            $resFallbackType = $this->db->query($sqlFallbackType);
            if ($resFallbackType && ($objFallbackType = $this->db->fetch_object($resFallbackType))) {
                if ((int) $objFallbackType->status !== 1) {
                    dol_syslog("Using draft BOM #" . (int) $objFallbackType->rowid . " with bomtype " . $fallbackBomType . " for product ID " . $productId, LOG_WARNING);
                }
                dol_syslog("Using BOM #" . (int) $objFallbackType->rowid . " with bomtype " . $fallbackBomType . " as fallback (configured bomtype " . $bomType . ") for product ID " . $productId, LOG_WARNING);
                return (int) $objFallbackType->rowid;
            }
            if ($productRef !== '') {
                $sqlRefFallbackType = "SELECT b.rowid, b.status FROM " . MAIN_DB_PREFIX . "bom_bom b
                    JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = b.fk_product
                    WHERE p.ref = '" . $this->db->escape($productRef) . "'
                    AND b.bomtype = " . $fallbackBomType . "
                    AND b.status IN (0,1)
                    AND b.entity IN (0," . getEntity('bom') . ")
                    ORDER BY (b.entity = " . ((int) $conf->entity) . ") DESC, (b.entity = 0) DESC, b.rowid DESC";
                $resRefFallbackType = $this->db->query($sqlRefFallbackType);
                if ($resRefFallbackType && ($objRefFallbackType = $this->db->fetch_object($resRefFallbackType))) {
                    if ((int) $objRefFallbackType->status !== 1) {
                        dol_syslog("Using draft BOM #" . (int) $objRefFallbackType->rowid . " matched by product ref " . $productRef . " with bomtype " . $fallbackBomType . " for product ID " . $productId, LOG_WARNING);
                    }
                    dol_syslog("Using BOM #" . (int) $objRefFallbackType->rowid . " matched by product ref " . $productRef . " with bomtype " . $fallbackBomType . " as fallback (configured bomtype " . $bomType . ") for product ID " . $productId, LOG_WARNING);
                    return (int) $objRefFallbackType->rowid;
                }
            }
        }
        dol_syslog("No dismantle BOM found for product ID " . $productId . " (bomtype=" . $bomType . ")", LOG_DEBUG);
        return false;
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
            dol_syslog("No dismantle category configured", LOG_DEBUG);
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
        dol_syslog("Product ID " . (int) $productId . " not in dismantle category " . (int) $productDismantleCategory, LOG_DEBUG);
        return false;
    }

    public function produceAndConsume($bomId, $qtyMovement, $priceMovement, $originRef, $originId, $originType, $movementDate = null, $userContext = null)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        global $conf;
        $user = $userContext ?: ($GLOBALS['user'] ?? null);

        $movementDate = $movementDate ?: dol_now();
        $warehouseId  = (int) ($conf->global->KREAPRODUCTS_DISMANTLE_WAREHOUSE ?? $conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
        $error = 0;

        // Load BOM header + lines with entity=0 support
        $bomData = $this->loadDismantleBom((int) $bomId);
        if (!$bomData) {
            dol_syslog("Failed to load BOM details", LOG_ERR);
            return -1;
        }

        $productToConsume = new Product($this->db);
        $currentPmp = null;
        if ($productToConsume->fetch($bomData['fk_product']) > 0) {
            $currentCostPrice = $productToConsume->cost_price;
            if (is_numeric($productToConsume->pmp)) {
                $currentPmp = (float) $productToConsume->pmp;
            }
            dol_syslog(
                "Current cost price for product #" . $bomData['fk_product'] . ": " . $currentCostPrice,
                LOG_DEBUG
            );
        } else {
            dol_syslog(
                "Failed to fetch product #" . $bomData['fk_product'] . " for cost price",
                LOG_ERR
            );
            $currentCostPrice = null; // handle error as needed
        }
        $baseCostPrice = null;
        if (is_numeric($priceMovement) && (float) $priceMovement > 0) {
            $baseCostPrice = (float) $priceMovement;
        } elseif (is_numeric($currentCostPrice) && (float) $currentCostPrice > 0) {
            $baseCostPrice = (float) $currentCostPrice;
        } elseif (is_numeric($currentPmp) && (float) $currentPmp > 0) {
            $baseCostPrice = (float) $currentPmp;
        }

        // Ensure BOM has lines
        if (!is_array($bomData['lines']) || empty($bomData['lines'])) {
            dol_syslog("BOM has no lines", LOG_WARNING);
            return -1;
        }

        $arraytoconsume = [];
        $arraytoproduce = [];

        $headerQty = (float) $bomData['qty'];
        if ($headerQty <= 0) {
            dol_syslog("Invalid BOM header qty for bomId=" . (int) $bomId . ", defaulting to 1", LOG_WARNING);
            $headerQty = 1.0;
        }

        // Add main product to consume
        $arraytoconsume[] = [
            'objectid'    => $bomData['fk_product'],
            'qty'         => 1,
            'fk_warehouse' => $warehouseId,
        ];
        dol_syslog("arraytoconsume: " . json_encode($arraytoconsume, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Add BOM components to produce
        foreach ($bomData['lines'] as $line) {
            $normalizedQty = ((float) $line->qty) / $headerQty;
            $arraytoproduce[] = [
                'objectid'    => $line->fk_product,
                'qty'         => $normalizedQty,
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
                $movementPrice = 0.0;
                $shouldUpdateCost = false;
                if ($arrayname === 'arraytoconsume') {
                    $movementPrice = is_numeric($priceMovement) ? (float) $priceMovement : 0.0;
                    if ($movementPrice > 0) {
                        $shouldUpdateCost = true;
                    } else {
                        $movementPrice = is_numeric($product->cost_price) ? (float) $product->cost_price : 0.0;
                        dol_syslog("Skipping cost update for consumed product ID " . $item['objectid'] . " (missing priceMovement)", LOG_DEBUG);
                    }
                } else {
                    if (!empty($item['qty']) && is_numeric($baseCostPrice) && (float) $baseCostPrice > 0) {
                        $movementPrice = (float) $baseCostPrice / $item['qty'];
                        if ($movementPrice > 0) {
                            $shouldUpdateCost = true;
                        }
                    } elseif (empty($item['qty'])) {
                        dol_syslog("Cannot divide by zero for product ID " . $item['objectid'], LOG_ERR);
                        $error++;
                        break;
                    } else {
                        $movementPrice = is_numeric($product->cost_price) ? (float) $product->cost_price : 0.0;
                        dol_syslog("Skipping cost update for produced product ID " . $item['objectid'] . " (missing current cost price)", LOG_WARNING);
                    }
                }
                if ($shouldUpdateCost) {
                    $this->persistCostPrice($product, $movementPrice, $user, $arrayname);
                }

                // Ensure origin is set before movement so fk_origin/origintype are saved.
                $stockmove->setOrigin($originType, $originId);

                // Perform the correct stock movement:
                if ($arrayname === 'arraytoconsume') {
                    if ($rawQty >= 0) {
                        // Normal consumption: stock -> out
                        $result = $stockmove->livraison(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $movementPrice,
                            "Consume for MO ($originRef)",
                            $movementDate
                        );
                    } else {
                        // Reverse consumption: stock <- in
                        $result = $stockmove->reception(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $movementPrice,
                            "Reverse consume for MO ($originRef)",
                            '',
                            '',
                            '',
                            $movementDate
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
                            $movementPrice,
                            "Produce for MO ($originRef)",
                            '',
                            '',
                            '',
                            $movementDate
                        );
                    } else {
                        // Reverse production: stock -> out
                        $result = $stockmove->livraison(
                            $user,
                            $item['objectid'],
                            $item['fk_warehouse'],
                            $qty,
                            $movementPrice,
                            "Reverse produce for MO ($originRef)",
                            $movementDate
                        );
                    }
                }

                // Check for errors
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

        if (is_numeric($baseCostPrice) && (float) $baseCostPrice > 0) {
            $this->updateProducedCostPrices($bomData, (float) $baseCostPrice, $user);
        } else {
            dol_syslog("Skipping produced cost price update (missing base cost price)", LOG_DEBUG);
        }

        dol_syslog("MO processed successfully, transaction committed.", LOG_DEBUG);
        return 0;
    }

    private function loadDismantleBom($bomId)
    {
        global $conf;

        $bomId = (int) $bomId;
        if ($bomId <= 0) {
            return null;
        }

        $sql = "SELECT rowid, fk_product, qty, bomtype, status
                FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE rowid = " . $bomId . "
                  AND entity IN (0," . getEntity('bom') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . " error loading BOM header: " . $this->db->lasterror(), LOG_ERR);
            return null;
        }
        $bom = $this->db->fetch_object($resql);
        if (!$bom) {
            return null;
        }

        $bomType = (int) ($conf->global->KREAPRODUCTS_DISMANTLE_BOMTYPE ?? 1);
        if ((int) $bom->bomtype !== $bomType) {
            dol_syslog(__METHOD__ . " BOM type mismatch for bomId=" . $bomId, LOG_WARNING);
        }
        if ((int) $bom->status !== 1) {
            dol_syslog(__METHOD__ . " BOM status is not validated for bomId=" . $bomId, LOG_WARNING);
        }

        $lines = $this->loadDismantleBomLines($bomId);
        if ($lines === null) {
            return null;
        }

        return [
            'fk_product' => (int) $bom->fk_product,
            'qty' => (float) $bom->qty,
            'lines' => $lines,
        ];
    }

    private function loadDismantleBomLines($bomId)
    {
        $bomId = (int) $bomId;
        if ($bomId <= 0) {
            return null;
        }

        $sql = "SELECT COALESCE(bl.fk_product, cb.fk_product) AS fk_product, bl.qty
                FROM " . MAIN_DB_PREFIX . "bom_bomline bl
                LEFT JOIN " . MAIN_DB_PREFIX . "bom_bom cb ON cb.rowid = bl.fk_bom_child
                WHERE bl.fk_bom = " . $bomId . "
                  AND (cb.rowid IS NULL OR cb.entity IN (0," . getEntity('bom') . "))";
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . " error loading BOM lines: " . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $lines = [];
        while ($obj = $this->db->fetch_object($resql)) {
            if (empty($obj->fk_product)) {
                continue;
            }
            $line = new stdClass();
            $line->fk_product = (int) $obj->fk_product;
            $line->qty = (float) $obj->qty;
            $lines[] = $line;
        }

        return $lines;
    }

    private function persistCostPrice(Product $product, float $costPrice, $user, string $context): bool
    {
        $productId = (int) $product->id;
        if ($productId <= 0 || $costPrice <= 0) {
            return false;
        }

        $product->cost_price = $costPrice;

        if (!empty($user)) {
            $updateRes = $product->update($productId, $user);
            if ($updateRes > 0) {
                $verify = new Product($this->db);
                if ($verify->fetch($productId) > 0 && is_numeric($verify->cost_price)
                    && abs(((float) $verify->cost_price) - $costPrice) < 0.0001) {
                    dol_syslog(__METHOD__ . " updated cost_price for product #" . $productId . " to " . $costPrice . " (" . $context . ")", LOG_DEBUG);
                    return true;
                }
                dol_syslog(__METHOD__ . " cost_price mismatch after update for product #" . $productId . " (" . $context . "), forcing SQL", LOG_WARNING);
            } else {
                $detail = !empty($product->error) ? $product->error : 'unknown error';
                dol_syslog(__METHOD__ . " failed to update cost_price for product #" . $productId . " (" . $context . "): " . $detail, LOG_WARNING);
            }
        } else {
            dol_syslog(__METHOD__ . " missing user context for product #" . $productId . " (" . $context . "), forcing SQL", LOG_WARNING);
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "product"
            . " SET cost_price = " . ((float) $costPrice)
            . ", fk_user_modif = " . (!empty($user) && !empty($user->id) ? (int) $user->id : "NULL")
            . " WHERE rowid = " . $productId;
        if ($this->db->query($sql)) {
            dol_syslog(__METHOD__ . " forced cost_price update for product #" . $productId . " to " . $costPrice . " (" . $context . ")", LOG_DEBUG);
            return true;
        }

        dol_syslog(__METHOD__ . " SQL update failed for product #" . $productId . " (" . $context . "): " . $this->db->lasterror(), LOG_WARNING);
        return false;
    }

    private function updateProducedCostPrices(array $bomData, float $baseCostPrice, $user): void
    {
        if (empty($bomData['lines']) || !is_array($bomData['lines'])) {
            return;
        }

        $headerQty = (float) ($bomData['qty'] ?? 0);
        if ($headerQty <= 0) {
            $headerQty = 1.0;
        }

        $qtyPerProduct = [];
        foreach ($bomData['lines'] as $line) {
            $productId = (int) ($line->fk_product ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $normalizedQty = ((float) $line->qty) / $headerQty;
            if ($normalizedQty <= 0) {
                continue;
            }
            if (!isset($qtyPerProduct[$productId])) {
                $qtyPerProduct[$productId] = 0.0;
            }
            $qtyPerProduct[$productId] += $normalizedQty;
        }

        foreach ($qtyPerProduct as $productId => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $unitCost = $baseCostPrice / $qty;
            if ($unitCost <= 0) {
                continue;
            }
            $product = new Product($this->db);
            if ($product->fetch($productId) <= 0) {
                dol_syslog(__METHOD__ . " failed to load product #" . (int) $productId . " for cost update", LOG_WARNING);
                continue;
            }
            $this->persistCostPrice($product, $unitCost, $user, 'post-dismantle');
        }
    }
}
