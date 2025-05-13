<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/lib/mrp_mo.lib.php';

class ProductDismantleController extends CommonObject
{
    /** @var DoliDB */
    public $db;

    /** @var Mo */
    public $mo;

    public function __construct($db)
    {
        $this->db = $db;
        $this->mo = new Mo($this->db);
    }

    /**
     * Find BOM ID associated with a product ID.
     *
     * @param int $productId
     * @return int|false
     */
    public function findBom($productId)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE fk_product = " . (int)$productId . "
                  AND bomtype = 1";  // 1 = dismantle BOM

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($obj = $this->db->fetch_object($resql)) {
                return (int)$obj->rowid;
            }
            return false; // no BOM found
        }

        dol_syslog("findBom query failed for product ID " . $productId, LOG_ERR);
        return false;
    }

    /**
     * Check if product is in the “dismantle” category.
     *
     * @param int $productId
     * @return bool
     */
    public function productInDismantleCategory($productId)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);
        global $conf;

        $dismantleCat = (int)($conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY ?? 0);
        if (!$dismantleCat) return false;

        $sql = "SELECT fk_categorie FROM " . MAIN_DB_PREFIX . "categorie_product
                WHERE fk_product = " . (int)$productId;
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                if ((int)$obj->fk_categorie === $dismantleCat) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Consume the parent product and produce its components,
     * allocating the parent’s total cost across the produced items.
     *
     * @param int    $bomId
     * @param float  $qtyMovement   Multiplier for BOM quantities
     * @param float  $totalCost     Total cost of the batch being dismantled
     * @param string $originRef
     * @param int    $originId
     * @param string $originType
     * @param string $movementDate  (optional) “YYYY-MM-DD” or datetime
     * @return int  0 on success, –1 on error
     */
    public function produceAndConsume($bomId, $qtyMovement, $totalCost, $originRef, $originId, $originType, $movementDate = null)
    {
        global $user, $conf;
        dol_syslog(__METHOD__, LOG_DEBUG);

        $movementDate = $movementDate ?: dol_now();
        $warehouseId  = (int)($conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);

        // Start transaction
        $this->db->begin();

        // 1) Load BOM
        $bom = new BOM($this->db);
        if ($bom->fetch($bomId) <= 0) {
            dol_syslog("Failed to fetch BOM #$bomId", LOG_ERR);
            $this->db->rollback();
            return -1;
        }
        if (empty($bom->lines)) {
            dol_syslog("BOM #$bomId has no lines", LOG_WARNING);
            $this->db->rollback();
            return -1;
        }

        $stockmove = new MouvementStock($this->db);

        // 2) Consume the parent product from stock
        $parent = new Product($this->db);
        if ($parent->fetch($bom->fk_product) <= 0) {
            dol_syslog("Cannot fetch parent product #".$bom->fk_product, LOG_ERR);
            $this->db->rollback();
            return -1;
        }
        $parentQty = $bom->qty * $qtyMovement;
        $rc = $stockmove->livraison(
            $user,
            $parent->id,
            $warehouseId,
            $parentQty,
            $parent->cost_price,
            "Dismantle consume for MO ($originRef)",
            $movementDate,
            '', '', '',
            $originId,
            $originType
        );
        $stockmove->setOrigin($originType, $originId);
        if ($rc <= 0) {
            dol_syslog("Failed to consume parent product #".$parent->id, LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        // 3) Compute total units to produce (for cost allocation)
        $totalUnits = 0;
        foreach ($bom->lines as $line) {
            $totalUnits += ($line->qty * $qtyMovement);
        }
        if ($totalUnits <= 0) {
            dol_syslog("Invalid total units to produce", LOG_ERR);
            $this->db->rollback();
            return -1;
        }

        // 4) Produce each component and set its new unit cost
        foreach ($bom->lines as $line) {
            $prodQty = $line->qty * $qtyMovement;
            $unitCost = $totalCost / $totalUnits;  // allocate equally

            $comp = new Product($this->db);
            if ($comp->fetch($line->fk_product) <= 0) {
                dol_syslog("Cannot fetch component #".$line->fk_product, LOG_ERR);
                $this->db->rollback();
                return -1;
            }

            // Update unit cost
            $comp->cost_price = $unitCost;
            if ($comp->update($user) < 0) {
                dol_syslog("Failed to update cost_price for product #".$comp->id, LOG_ERR);
                $this->db->rollback();
                return -1;
            }

            // Put produced stock back in
            $rc = $stockmove->reception(
                $user,
                $comp->id,
                $warehouseId,
                $prodQty,
                $unitCost,
                "Dismantle produce for MO ($originRef)",
                '',
                '', '',
                $movementDate,
                $originId,
                $originType
            );
            $stockmove->setOrigin($originType, $originId);
            if ($rc <= 0) {
                dol_syslog("Failed to receive stock for product #".$comp->id, LOG_ERR);
                $this->db->rollback();
                return -1;
            }
        }

        // 5) Commit if all went well
        $this->db->commit();
        dol_syslog("Dismantle processed successfully (MO #$originId)", LOG_DEBUG);
        return 0;
    }
}
