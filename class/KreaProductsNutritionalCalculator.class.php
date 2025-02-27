<?php

/**
 * Class KreaProductsNutritionalCalculator
 *
 * This class builds a father→child map for a given product using BFS,
 * then computes and displays nutritional values (kcal, kJ, fat, etc.) for all
 * subproducts (including nested levels) using each ingredient’s actual weight.
 *
 * For each ingredient, it:
 *  - Retrieves the base weight (and unit) from the product table.
 *  - Converts that weight to grams.
 *  - Multiplies by the aggregated quantity (from associations) to get the total weight (in g) contributed.
 *  - Computes nutrient contributions based on the “per 100 g” values from llxnm_kreaproducts_nutritional.
 *
 * Finally, the recipe’s overall nutrient totals are normalized to a 100 g basis.
 *
 * Usage example (in your card.php or custom page):
 *   require_once __DIR__ . '/KreaProductsNutritionalCalculator.class.php';
 *   KreaProductsNutritionalCalculator::computeAndDisplayNutritional($productId, $db, $langs, $conf, $user);
 */
class KreaProductsNutritionalCalculator
{
    /** @var LocalProduct[] productId => LocalProduct object */
    private static $productMap = array();

    /**
     * Main entry point.
     *
     * @param int       $productId  The parent product rowid.
     * @return void
     */
    public static function computeAndDisplayNutritional($productId)
    {
        global $langs, $conf, $db;

        // Check permissions
        //if (empty($user->rights->produit->lire)) accessforbidden();

        // Build BFS map of father→child relationships.
        self::$productMap = array();
        self::buildMapBFS($productId);

        // Get the parent’s local product object.
        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            print '<p style="color:red;">Error: Could not find local product map for #' . $productId . '</p>';
            return;
        }

        // Gather all subproducts (any depth) with aggregated quantity.
        // Here, the aggregated quantity (finalQty) represents the number of units used.
        $subList = array();
        self::gatherSubProducts($productId, $subList);

        // 4) Begin outputting the table.
        print '<br>';
        print load_fiche_titre($langs->trans("KreaProductsProductAssociations"), '', '');
        print '<table class="noborder" width="100%">';
        print '<tr class="liste_titre">';
        print '<td width="5%">' . $langs->trans("KreaProductsTableProductRef") . '</td>';
        print '<td width="25%">' . $langs->trans("KreaProductsTableProductName") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableProductWeight") . '</td>';
        print '<td width="10%" style="text-align: right;">' . $langs->trans("KreaProductsTableProductQuantity") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableQuantityWeight") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableEnergy_kcal") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableEnergy_kj") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableFat") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSaturates") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableCarbohydrates") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSugars") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableProtein") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableSalt") . '</td>';
        print '<td width="5%" style="text-align: right;">' . $langs->trans("KreaProductsTableFiber") . '</td>';
        print '</tr>';

        // Initialize overall totals.
        $totalWeightInGrams = 0;
        $totals = array(
            'energy_kcal'   => 0,
            'energy_kj'     => 0,
            'fat'           => 0,
            'saturates'     => 0,
            'carbohydrates' => 0,
            'sugars'        => 0,
            'protein'       => 0,
            'salt'          => 0,
            'fiber'         => 0
        );

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        // Process each subproduct.
        foreach ($subList as $childId => $finalQty) {
            $childLp = self::getLocalProduct($childId);
            if (!$childLp) continue;
            $childLabel = $childLp->label;

            // Get the clickable reference link.
            $subProdObj = new Product($db);
            $res2 = $subProdObj->fetch($childId);
            $linkRef = ($res2 > 0) ? $subProdObj->getNomUrl(1) : 'N/A';
            $nameStr = htmlspecialchars($childLabel, ENT_QUOTES);

            // Retrieve weight data from the local product.
            // It is assumed that $childLp->weight and $childLp->weight_units exist.
            $rawWeight = $childLp->weight ? $childLp->weight : 1;       // e.g. 0.5 or 500, depending on unit.
            $weightUnit = $childLp->weight_units;  // e.g. 'kg', 'g', or 'mg'

            // Retrieve all active weight units from the database
            $sql  = "SELECT scale, short_label FROM " . MAIN_DB_PREFIX . "c_units ";
            $sql .= "WHERE unit_type = '" . $conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL . "' ";
            $sql .= "  AND active = 1";
            $resql = $db->query($sql);
            $unitMapping = array();
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // Build mapping: key = scale, value = short_label
                    $unitMapping[$obj->scale] = $obj->short_label;
                }
            } else {
                dol_print_error($db);
            }

            if (is_null($weightUnit)) {
                $weightUnit = 0; // default to 0 if scale is null
            }

            // Dynamically get the corresponding short_label from the mapping
            $weightUnitShortLabel = isset($unitMapping[$weightUnit]) ? $unitMapping[$weightUnit] : 'kg';

            $displayWeight = htmlspecialchars($rawWeight . ' ' . $weightUnitShortLabel, ENT_QUOTES);

            // Convert the base weight to grams.
            $baseWeightInGrams = self::convertToGrams($rawWeight, $weightUnit);
            // The total weight contributed by this ingredient is:
            $subWeightInGrams = $finalQty * $baseWeightInGrams;

            // Fetch nutritional data (values per 100 g) from custom table.
            $sql = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, sugars, protein, salt, fiber
                    FROM llxnm_kreaproducts_nutritional
                    WHERE fk_product = " . (int)$childId . " LIMIT 1";
            $resql = $db->query($sql);
            $nut = ($resql) ? $db->fetch_object($resql) : null;

            // Compute absolute nutrient contributions:
            // (nutrient per 100 g / 100) × (ingredient weight in grams)
            $subKcal   = ($nut) ? ($nut->energy_kcal / 100) * $subWeightInGrams : 0;
            $subKj     = ($nut) ? ($nut->energy_kj   / 100) * $subWeightInGrams : 0;
            $subFat    = ($nut) ? ($nut->fat         / 100) * $subWeightInGrams : 0;
            $subSatur  = ($nut) ? ($nut->saturates   / 100) * $subWeightInGrams : 0;
            $subCarbs  = ($nut) ? ($nut->carbohydrates / 100) * $subWeightInGrams : 0;
            $subSugars = ($nut) ? ($nut->sugars      / 100) * $subWeightInGrams : 0;
            $subProt   = ($nut) ? ($nut->protein     / 100) * $subWeightInGrams : 0;
            $subSalt   = ($nut) ? ($nut->salt        / 100) * $subWeightInGrams : 0;
            $subFiber  = ($nut) ? ($nut->fiber       / 100) * $subWeightInGrams : 0;

            // Accumulate overall totals.
            $totalWeightInGrams += $subWeightInGrams;
            $totals['energy_kcal']   += $subKcal;
            $totals['energy_kj']     += $subKj;
            $totals['fat']           += $subFat;
            $totals['saturates']     += $subSatur;
            $totals['carbohydrates'] += $subCarbs;
            $totals['sugars']        += $subSugars;
            $totals['protein']       += $subProt;
            $totals['salt']          += $subSalt;
            $totals['fiber']         += $subFiber;

            // Print the subproduct row.
            print '<tr style="font-style: italic;">';
            print '<td>' . $linkRef . '</td>';
            print '<td>' . $nameStr . '</td>';
            print '<td style="text-align: right;">' . $displayWeight . '</td>';
            print '<td style="text-align: right;">x ' . number_format($finalQty, 3, '.', '') . '</td>';
            print '<td style="text-align: right;">' . round($subWeightInGrams, 2) . '</td>';
            print '<td style="text-align: right;">' . round($subKcal, 2)   . '</td>';
            print '<td style="text-align: right;">' . round($subKj, 2)     . '</td>';
            print '<td style="text-align: right;">' . round($subFat, 2)    . '</td>';
            print '<td style="text-align: right;">' . round($subSatur, 2)  . '</td>';
            print '<td style="text-align: right;">' . round($subCarbs, 2)  . '</td>';
            print '<td style="text-align: right;">' . round($subSugars, 2) . '</td>';
            print '<td style="text-align: right;">' . round($subProt, 2)   . '</td>';
            print '<td style="text-align: right;">' . round($subSalt, 2)   . '</td>';
            print '<td style="text-align: right;">' . round($subFiber, 2)  . '</td>';
            print '</tr>';
        }

        // Print totals row (absolute totals for the entire recipe).
        print '<tr style="font-style: italic;">';
        print '<td colspan = 4>' . $langs->trans("EstimatedTotalsForTheProduct") . '</td>';
        print '<td style="text-align: right;">' . round($totalWeightInGrams, 2) . '</td>';
        print '<td style="text-align: right;">' . round($totals['energy_kcal'], 2) . '</td>';
        print '<td style="text-align: right;">' . round($totals['energy_kj'], 2)   . '</td>';
        print '<td style="text-align: right;">' . round($totals['fat'], 2)         . '</td>';
        print '<td style="text-align: right;">' . round($totals['saturates'], 2)   . '</td>';
        print '<td style="text-align: right;">' . round($totals['carbohydrates'], 2) . '</td>';
        print '<td style="text-align: right;">' . round($totals['sugars'], 2)        . '</td>';
        print '<td style="text-align: right;">' . round($totals['protein'], 2)       . '</td>';
        print '<td style="text-align: right;">' . round($totals['salt'], 2)          . '</td>';
        print '<td style="text-align: right;">' . round($totals['fiber'], 2)         . '</td>';
        print '</tr>';

        // Normalize totals to a 100 g basis.
        if ($totalWeightInGrams > 0) {
            $normKcal   = ($totals['energy_kcal']   / $totalWeightInGrams) * 100;
            $normKj     = ($totals['energy_kj']     / $totalWeightInGrams) * 100;
            $normFat    = ($totals['fat']           / $totalWeightInGrams) * 100;
            $normSatur  = ($totals['saturates']     / $totalWeightInGrams) * 100;
            $normCarbs  = ($totals['carbohydrates'] / $totalWeightInGrams) * 100;
            $normSugars = ($totals['sugars']        / $totalWeightInGrams) * 100;
            $normProt   = ($totals['protein']       / $totalWeightInGrams) * 100;
            $normSalt   = ($totals['salt']          / $totalWeightInGrams) * 100;
            $normFiber  = ($totals['fiber']         / $totalWeightInGrams) * 100;

            print '<tr style="font-weight:bold;">';
            print '<td colspan = 4>' . $langs->trans("AverageValuesPer100g") . '</td>';
            print '<td>&nbsp;</td>';
            print '<td style="text-align: right;">' . round($normKcal,   2) . '</td>';
            print '<td style="text-align: right;">' . round($normKj,     2) . '</td>';
            print '<td style="text-align: right;">' . round($normFat,    2) . '</td>';
            print '<td style="text-align: right;">' . round($normSatur,  2) . '</td>';
            print '<td style="text-align: right;">' . round($normCarbs,  2) . '</td>';
            print '<td style="text-align: right;">' . round($normSugars, 2) . '</td>';
            print '<td style="text-align: right;">' . round($normProt,   2) . '</td>';
            print '<td style="text-align: right;">' . round($normSalt,   2) . '</td>';
            print '<td style="text-align: right;">' . round($normFiber,  2) . '</td>';
            print '</tr>';
        }

        print '</table>';
    }

    /**
     * Converts a given weight to grams based on its unit.
     *
     * @param float  $weight The weight value.
     * @param string $unit   The unit.
     * @return float         Weight in grams.
     */
    private static function convertToGrams($weight, $unit)
    {
        $unit = strtolower(trim($unit));
        if ($unit == 98) {
            return $weight / 35.274;
        } elseif ($unit == 99) {
            return $weight / 2.20462;
        } else {
            return $weight * pow(10, $unit) * 1000;
        }
    }

    /**
     * BFS approach: builds the product association map.
     *
     * @param DoliDB $db
     * @param int    $startId
     * @return void
     */
    private static function buildMapBFS($startId)
    {
        global $db;
        $queue = array($startId);
        $seen  = array($startId => true);

        // The query now also fetches weight and weight_units.
        $sqlBase = "SELECT pa.fk_product_pere as father,
                           pa.fk_product_fils as child,
                           pa.qty as qty,
                           pf.label as fatherLabel,
                           pf.weight as fatherWeight,
                           pf.weight_units as fatherWeightUnits,
                           pc.label as childLabel,
                           pc.weight as childWeight,
                           pc.weight_units as childWeightUnits
                    FROM " . MAIN_DB_PREFIX . "product_association pa
                      JOIN " . MAIN_DB_PREFIX . "product pf ON (pf.rowid = pa.fk_product_pere)
                      JOIN " . MAIN_DB_PREFIX . "product pc ON (pc.rowid = pa.fk_product_fils)
                    WHERE pa.fk_product_pere = %d OR pa.fk_product_fils = %d";

        while (!empty($queue)) {
            $current = array_shift($queue);
            $sql = sprintf($sqlBase, (int)$current, (int)$current);
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // For father.
                    if (!isset(self::$productMap[$obj->father])) {
                        self::$productMap[$obj->father] = new LocalProduct(
                            $obj->father,
                            $obj->fatherLabel,
                            $obj->fatherWeight,
                            $obj->fatherWeightUnits
                        );
                    }
                    // For child.
                    if (!isset(self::$productMap[$obj->child])) {
                        self::$productMap[$obj->child] = new LocalProduct(
                            $obj->child,
                            $obj->childLabel,
                            $obj->childWeight,
                            $obj->childWeightUnits
                        );
                    }
                    // Set the father→child relationship.
                    if (!array_key_exists($obj->child, self::$productMap[$obj->father]->children)) {
                        self::$productMap[$obj->father]->children[$obj->child] = (float)$obj->qty;
                    }
                    if (empty($seen[$obj->father])) {
                        $seen[$obj->father] = true;
                        $queue[] = $obj->father;
                    }
                    if (empty($seen[$obj->child])) {
                        $seen[$obj->child] = true;
                        $queue[] = $obj->child;
                    }
                }
                $db->free($resql);
            }
        }
    }

    /**
     * Retrieves a LocalProduct from the map.
     *
     * @param int $id
     * @return LocalProduct|null
     */
    private static function getLocalProduct($id)
    {
        return isset(self::$productMap[$id]) ? self::$productMap[$id] : null;
    }

    /**
     * Gathers subproducts for a given product by querying llxnm_product_association.
     * This version simply retrieves the child product IDs (using fk_product_fils)
     * and their associated quantities (qty) for the specified parent product.
     *
     * @param int   $parentId The parent product ID.
     * @param array $results  An associative array [childId => aggregatedQty].
     * @return void
     */
    private static function gatherSubProducts($parentId, &$results)
    {
        global $db;
        $sql = "SELECT fk_product_fils AS childId, qty 
            FROM llxnm_product_association 
            WHERE fk_product_pere = " . (int)$parentId . "
            ORDER BY rang ASC";
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                // Aggregate quantities if more than one row exists for the same child.
                if (!isset($results[$obj->childId])) {
                    $results[$obj->childId] = 0;
                }
                $results[$obj->childId] += (float)$obj->qty;
            }
            $db->free($resql);
        } else {
            dol_print_error($db);
        }
    }

    /**
     * Inserts the computed nutritional totals into the database.
     *
     * @param int   $productId The parent product ID.
     * @param array $totals    Associative array with computed totals.
     * @return int             The result of the create() call from the Nutritional class.
     */
    private static function insertNutritionalTotals($productId, $totals)
    {
        global $db, $user;
        require_once DOL_DOCUMENT_ROOT . '/kreaproducts/nutritional.class.php';

        $nutritional = new Nutritional($db);
        $nutritional->fk_product      = $productId;
        $nutritional->energy_kcal     = round($totals['energy_kcal'], 2);
        $nutritional->energy_kj       = round($totals['energy_kj'], 2);
        $nutritional->fat             = round($totals['fat'], 2);
        $nutritional->saturates       = round($totals['saturates'], 2);
        $nutritional->carbohydrates   = round($totals['carbohydrates'], 2);
        $nutritional->sugars          = round($totals['sugars'], 2);
        $nutritional->protein         = round($totals['protein'], 2);
        $nutritional->salt            = round($totals['salt'], 2);
        $nutritional->fiber           = round($totals['fiber'], 2);

        $result = $nutritional->create($user, 0);
        return $result;
    }

    /**
     * Trigger method to calculate and save the current nutritional totals.
     * This method expects that the current product id is available via GETPOST('id', 'int')
     * and can be called when a product is modified.
     *
     * @return int Result of the Nutritional->create() call (positive if OK, negative otherwise).
     */
    public static function triggerSaveNutritional()
    {
        global $db, $langs, $conf, $user;

        // Get product ID from the current request context.
        $productId = GETPOST('id', 'int');
        if (!$productId) {
            dol_syslog("triggerSaveNutritional: No product ID found in request.", LOG_ERR);
            return -1;
        }

        // Rebuild the product map for the given product.
        self::$productMap = array();
        self::buildMapBFS($productId);

        // Retrieve the local product object for the parent.
        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            dol_syslog("triggerSaveNutritional: Could not find local product for #" . $productId, LOG_ERR);
            return -1;
        }

        // Gather all subproducts and their aggregated quantities.
        $subList = array();
        self::gatherSubProducts($productId, $subList);

        // Initialize totals.
        $totalWeightInGrams = 0;
        $totals = array(
            'energy_kcal'   => 0,
            'energy_kj'     => 0,
            'fat'           => 0,
            'saturates'     => 0,
            'carbohydrates' => 0,
            'sugars'        => 0,
            'protein'       => 0,
            'salt'          => 0,
            'fiber'         => 0
        );

        // Retrieve weight units mapping.
        $sql  = "SELECT scale, short_label FROM " . MAIN_DB_PREFIX . "c_units ";
        $sql .= "WHERE unit_type = '" . $conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL . "' AND active = 1";
        $resql = $db->query($sql);
        $unitMapping = array();
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $unitMapping[$obj->scale] = $obj->short_label;
            }
        } else {
            dol_syslog("triggerSaveNutritional: Error fetching unit mapping: " . $db->lasterror(), LOG_ERR);
        }

        // Loop through each subproduct and compute its nutritional contributions.
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        foreach ($subList as $childId => $finalQty) {
            $childLp = self::getLocalProduct($childId);
            if (!$childLp) continue;

            // Get base weight and unit.
            $rawWeight  = ($childLp->weight) ? $childLp->weight : 1;
            $weightUnit = $childLp->weight_units;
            if (is_null($weightUnit)) {
                $weightUnit = 0;
            }

            // Convert base weight to grams.
            $baseWeightInGrams = self::convertToGrams($rawWeight, $weightUnit);
            $subWeightInGrams  = $finalQty * $baseWeightInGrams;

            // Fetch nutritional data (values per 100 g) from llxnm_kreaproducts_nutritional.
            $sqlNut  = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, sugars, protein, salt, fiber
                    FROM llxnm_kreaproducts_nutritional
                    WHERE fk_product = " . (int)$childId . " LIMIT 1";
            $resNut = $db->query($sqlNut);
            $nut = ($resNut) ? $db->fetch_object($resNut) : null;

            // Calculate contributions for this subproduct.
            $subKcal   = ($nut) ? ($nut->energy_kcal / 100) * $subWeightInGrams : 0;
            $subKj     = ($nut) ? ($nut->energy_kj   / 100) * $subWeightInGrams : 0;
            $subFat    = ($nut) ? ($nut->fat         / 100) * $subWeightInGrams : 0;
            $subSatur  = ($nut) ? ($nut->saturates   / 100) * $subWeightInGrams : 0;
            $subCarbs  = ($nut) ? ($nut->carbohydrates / 100) * $subWeightInGrams : 0;
            $subSugars = ($nut) ? ($nut->sugars      / 100) * $subWeightInGrams : 0;
            $subProt   = ($nut) ? ($nut->protein     / 100) * $subWeightInGrams : 0;
            $subSalt   = ($nut) ? ($nut->salt        / 100) * $subWeightInGrams : 0;
            $subFiber  = ($nut) ? ($nut->fiber       / 100) * $subWeightInGrams : 0;

            // Accumulate totals.
            $totalWeightInGrams += $subWeightInGrams;
            $totals['energy_kcal']   += $subKcal;
            $totals['energy_kj']     += $subKj;
            $totals['fat']           += $subFat;
            $totals['saturates']     += $subSatur;
            $totals['carbohydrates'] += $subCarbs;
            $totals['sugars']        += $subSugars;
            $totals['protein']       += $subProt;
            $totals['salt']          += $subSalt;
            $totals['fiber']         += $subFiber;
        }

        // Save the calculated totals using the Nutritional class.
        require_once DOL_DOCUMENT_ROOT . '/kreaproducts/nutritional.class.php';
        $nutritional = new Nutritional($db);
        $nutritional->fk_product      = $productId;
        $nutritional->energy_kcal     = round($totals['energy_kcal'], 2);
        $nutritional->energy_kj       = round($totals['energy_kj'], 2);
        $nutritional->fat             = round($totals['fat'], 2);
        $nutritional->saturates       = round($totals['saturates'], 2);
        $nutritional->carbohydrates   = round($totals['carbohydrates'], 2);
        $nutritional->sugars          = round($totals['sugars'], 2);
        $nutritional->protein         = round($totals['protein'], 2);
        $nutritional->salt            = round($totals['salt'], 2);
        $nutritional->fiber           = round($totals['fiber'], 2);

        $result = $nutritional->create($user, 0);
        return $result;
    }

    /**
     * Recalculates and saves (or updates) the nutritional totals for a given product.
     *
     * This method is intended to be called by a trigger when a product is modified.
     *
     * @param int  $productId  The product ID (typically $object->id)
     * @param User $user       The current user object
     * @return int             The result of the update() or create() call (<0 on error, >0 on success)
     */
    public static function saveCalculation($productId, $user)
    {
        global $db, $langs, $conf;

        // Build the product association map for the given product.
        self::$productMap = array();
        self::buildMapBFS($productId);

        // Ensure the product exists in the map.
        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            dol_syslog("saveCalculation: Product #$productId not found in map", LOG_ERR);
            return -1;
        }

        // Gather all subproducts (child IDs with aggregated quantities).
        $subList = array();
        self::gatherSubProducts($productId, $subList);

        // Initialize totals array.
        $totalWeightInGrams = 0;
        $totals = array(
            'energy_kcal'   => 0,
            'energy_kj'     => 0,
            'fat'           => 0,
            'saturates'     => 0,
            'carbohydrates' => 0,
            'sugars'        => 0,
            'protein'       => 0,
            'salt'          => 0,
            'fiber'         => 0
        );

        // Loop through each subproduct to compute nutritional contributions.
        foreach ($subList as $childId => $finalQty) {
            $childLp = self::getLocalProduct($childId);
            if (!$childLp) continue;

            // Get raw weight and unit.
            $rawWeight  = $childLp->weight ? $childLp->weight : 1;
            $weightUnit = $childLp->weight_units;

            // Retrieve unit mapping from database.
            $sql = "SELECT scale, short_label FROM " . MAIN_DB_PREFIX . "c_units
                WHERE unit_type = '" . $conf->global->KREAPRODUCTS_DEFAULT_WEIGHT_LABEL . "'
                  AND active = 1";
            $resql = $db->query($sql);
            $unitMapping = array();
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $unitMapping[$obj->scale] = $obj->short_label;
                }
            }
            if (is_null($weightUnit)) {
                $weightUnit = 0;
            }

            // Convert the base weight to grams.
            $baseWeightInGrams = self::convertToGrams($rawWeight, $weightUnit);
            $subWeightInGrams  = $finalQty * $baseWeightInGrams;

            // Retrieve nutritional data (per 100g) for this subproduct.
            $sql = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, sugars, protein, salt, fiber
                FROM llxnm_kreaproducts_nutritional
                WHERE fk_product = " . (int)$childId . " LIMIT 1";
            $resql = $db->query($sql);
            $nut = ($resql) ? $db->fetch_object($resql) : null;

            // Compute absolute nutritional contributions.
            $subKcal   = ($nut) ? ($nut->energy_kcal / 100)   * $subWeightInGrams : 0;
            $subKj     = ($nut) ? ($nut->energy_kj   / 100)   * $subWeightInGrams : 0;
            $subFat    = ($nut) ? ($nut->fat         / 100)   * $subWeightInGrams : 0;
            $subSatur  = ($nut) ? ($nut->saturates   / 100)   * $subWeightInGrams : 0;
            $subCarbs  = ($nut) ? ($nut->carbohydrates / 100) * $subWeightInGrams : 0;
            $subSugars = ($nut) ? ($nut->sugars      / 100)   * $subWeightInGrams : 0;
            $subProt   = ($nut) ? ($nut->protein     / 100)   * $subWeightInGrams : 0;
            $subSalt   = ($nut) ? ($nut->salt        / 100)   * $subWeightInGrams : 0;
            $subFiber  = ($nut) ? ($nut->fiber       / 100)   * $subWeightInGrams : 0;

            // Accumulate overall totals.
            $totalWeightInGrams       += $subWeightInGrams;
            $totals['energy_kcal']    += $subKcal;
            $totals['energy_kj']      += $subKj;
            $totals['fat']            += $subFat;
            $totals['saturates']      += $subSatur;
            $totals['carbohydrates']  += $subCarbs;
            $totals['sugars']         += $subSugars;
            $totals['protein']        += $subProt;
            $totals['salt']           += $subSalt;
            $totals['fiber']          += $subFiber;
        }

        // --- New: Normalize totals per 100g ---
        if ($totalWeightInGrams > 0) {
            $normKcal   = ($totals['energy_kcal']   / $totalWeightInGrams) * 100;
            $normKj     = ($totals['energy_kj']     / $totalWeightInGrams) * 100;
            $normFat    = ($totals['fat']           / $totalWeightInGrams) * 100;
            $normSatur  = ($totals['saturates']     / $totalWeightInGrams) * 100;
            $normCarbs  = ($totals['carbohydrates'] / $totalWeightInGrams) * 100;
            $normSugars = ($totals['sugars']        / $totalWeightInGrams) * 100;
            $normProt   = ($totals['protein']       / $totalWeightInGrams) * 100;
            $normSalt   = ($totals['salt']          / $totalWeightInGrams) * 100;
            $normFiber  = ($totals['fiber']         / $totalWeightInGrams) * 100;
        } else {
            // Prevent division by zero
            $normKcal = $normKj = $normFat = $normSatur = $normCarbs = $normSugars = $normProt = $normSalt = $normFiber = 0;
        }
        // -----------------------------------------

        // Load the Nutritional class.
        require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/nutritional.class.php';

        // Check if a nutritional record already exists for this product.
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$productId;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            // Update existing record.
            $obj = $db->fetch_object($resql);
            $nutritional = new Nutritional($db);
            $nutritional->fetch($obj->rowid);
        } else {
            // Create a new record.
            $nutritional = new Nutritional($db);
            $nutritional->fk_product = $productId;
        }

        // Save the normalized (per 100g) nutritional values.
        $nutritional->energy_kcal      = round($normKcal,   2);
        $nutritional->energy_kj        = round($normKj,     2);
        $nutritional->fat              = round($normFat,    2);
        $nutritional->saturates        = round($normSatur,  2);
        $nutritional->carbohydrates    = round($normCarbs,  2);
        $nutritional->sugars           = round($normSugars, 2);
        $nutritional->protein          = round($normProt,   2);
        $nutritional->salt             = round($normSalt,   2);
        $nutritional->fiber            = round($normFiber,  2);

        // Save the record: update if it exists, or create if not.
        if (!empty($nutritional->id)) {
            $result = $nutritional->update($user, 0);
        } else {
            $result = $nutritional->create($user, 0);
        }

        return $result;
    }
}

/**
 * Minimal local product container.
 */
class LocalProduct
{
    public $id;
    public $label;
    public $weight;       // Base weight as stored in product table.
    public $weight_units; // Units (e.g., 'kg', 'g', 'mg')
    public $children = array();

    /**
     * Constructor.
     *
     * @param int    $id
     * @param string $label
     * @param float  $weight       Base weight.
     * @param string $weight_units Units of weight.
     */
    public function __construct($id, $label, $weight = 0, $weight_units = 'g')
    {
        $this->id = (int)$id;
        $this->label = $label;
        $this->weight = $weight;
        $this->weight_units = $weight_units;
    }
}
