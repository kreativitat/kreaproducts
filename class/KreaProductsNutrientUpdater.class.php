<?php

/**
 * KreaProductsNutrientUpdater.class.php
 *
 * This class provides a method to update nutritional records for all non‑leaf products
 * in the product tree by traversing the association map and normalizing nutrient values.
 */

class KreaProductsNutrientUpdater
{
    /**
     * Updates the nutritional records for all non‑leaf products in the tree.
     *
     * @param int  $productId Starting (root) product ID.
     * @param User $user      The current user performing the update.
     * @return void
     */
    public static function updateNutrientAttributes($productId, $user)
    {
        global $db, $langs, $conf;

        // Log start of the process.
        dol_syslog("Starting updateNutrientAttributes for productId: " . $productId, LOG_DEBUG);

        // 1. Build the full association map (using a BFS approach).
        $productMap = self::buildProductMap($productId);
        dol_syslog("Product map built: " . json_encode($productMap), LOG_DEBUG);

        // 2. Compute heights for all nodes so that children are updated first.
        $heights = self::computeHeights($productMap);
        asort($heights); // Process from lowest (leaf) to highest.
        dol_syslog("Computed node heights: " . json_encode($heights), LOG_DEBUG);

        // 3. Loop through non‑leaf nodes and update their nutritional values.
        foreach ($heights as $nodeId => $height) {
            // Skip leaf nodes
            if ($height == 0) {
                dol_syslog("Skipping leaf node: " . $nodeId, LOG_DEBUG);
                continue;
            }
            // Retrieve the node’s details from the map.
            if (!isset($productMap[$nodeId])) {
                dol_syslog("Node " . $nodeId . " not found in productMap", LOG_DEBUG);
                continue;
            }
            $node = $productMap[$nodeId];

            // Gather children associations for the node.
            if (empty($node->children)) {
                dol_syslog("No children for node " . $nodeId, LOG_DEBUG);
                continue;
            }
            dol_syslog("Processing node " . $nodeId . " with height " . $height, LOG_DEBUG);

            // Initialize nutrient totals and overall weight.
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
            $totalWeightInGrams = 0;

            // Process each child.
            foreach ($node->children as $childId => $qty) {
                if (!isset($productMap[$childId])) {
                    dol_syslog("Child node " . $childId . " not found in productMap", LOG_DEBUG);
                    continue;
                }
                $child = $productMap[$childId];

                // Get child's weight (default to 1 if not set) and unit.
                $rawWeight  = ($child->weight) ? $child->weight : 1;
                $weightUnit = $child->weight_units;
                dol_syslog("Child " . $childId . " weight: " . $rawWeight . " " . $weightUnit, LOG_DEBUG);

                // Convert the base weight to grams.
                $baseWeightInGrams = self::convertToGrams($rawWeight, $weightUnit);
                dol_syslog("Child " . $childId . " converted weight: " . $baseWeightInGrams . "g", LOG_DEBUG);

                // Total weight contribution by this child.
                $childTotalWeight = $qty * $baseWeightInGrams;
                $totalWeightInGrams += $childTotalWeight;
                dol_syslog("Child " . $childId . " total weight contribution: " . $childTotalWeight . "g (Qty: " . $qty . ")", LOG_DEBUG);

                // Fetch child's nutritional data (per 100g) from your table.
                $sql = "SELECT energy_kcal, energy_kj, fat, saturates, carbohydrates, sugars, protein, salt, fiber
                    FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional
                    WHERE fk_product = " . (int)$childId . " LIMIT 1";
                dol_syslog("Executing SQL for child " . $childId . ": " . $sql, LOG_DEBUG);
                $resql = $db->query($sql);
                $nut = ($resql) ? $db->fetch_object($resql) : null;
                if (!$nut) {
                    dol_syslog("No nutritional data found for child " . $childId, LOG_DEBUG);
                } else {
                    dol_syslog("Nutritional data for child " . $childId . ": " . json_encode($nut), LOG_DEBUG);
                }

                // Compute absolute contributions for each nutrient.
                if ($nut) {
                    $totals['energy_kcal']   += ($nut->energy_kcal   / 100) * $childTotalWeight;
                    $totals['energy_kj']     += ($nut->energy_kj     / 100) * $childTotalWeight;
                    $totals['fat']           += ($nut->fat           / 100) * $childTotalWeight;
                    $totals['saturates']     += ($nut->saturates     / 100) * $childTotalWeight;
                    $totals['carbohydrates'] += ($nut->carbohydrates / 100) * $childTotalWeight;
                    $totals['sugars']        += ($nut->sugars        / 100) * $childTotalWeight;
                    $totals['protein']       += ($nut->protein       / 100) * $childTotalWeight;
                    $totals['salt']          += ($nut->salt          / 100) * $childTotalWeight;
                    $totals['fiber']         += ($nut->fiber         / 100) * $childTotalWeight;
                    dol_syslog("Updated totals for node " . $nodeId . " after processing child " . $childId . ": " . json_encode($totals), LOG_DEBUG);
                }
            }

            // 4. Normalize totals to a 100g basis.
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
                dol_syslog("Normalized values for node " . $nodeId . ": Kcal=" . $normKcal . ", Kj=" . $normKj . ", Fat=" . $normFat, LOG_DEBUG);
            } else {
                $normKcal = $normKj = $normFat = $normSatur = $normCarbs = $normSugars = $normProt = $normSalt = $normFiber = 0;
                dol_syslog("Total weight is zero for node " . $nodeId . ". Setting normalized values to 0.", LOG_DEBUG);
            }

            // 5. Update (or create) the parent's nutritional record using SQL.
            // Check if a record exists.
            $sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$nodeId;
            dol_syslog("Executing SQL to check record for node " . $nodeId . ": " . $sqlCheck, LOG_DEBUG);
            $resqlCheck = $db->query($sqlCheck);
            if ($resqlCheck && $db->num_rows($resqlCheck) > 0) {
                // Record exists, perform update.
                $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET 
                            energy_kcal = " . round($normKcal, 2) . ", 
                            energy_kj = " . round($normKj, 2) . ", 
                            fat = " . round($normFat, 2) . ", 
                            saturates = " . round($normSatur, 2) . ", 
                            carbohydrates = " . round($normCarbs, 2) . ", 
                            sugars = " . round($normSugars, 2) . ", 
                            protein = " . round($normProt, 2) . ", 
                            salt = " . round($normSalt, 2) . ", 
                            fiber = " . round($normFiber, 2) . " 
                          WHERE fk_product = " . (int)$nodeId;
                dol_syslog("Executing SQL to update record for node " . $nodeId . ": " . $sqlUpdate, LOG_DEBUG);
                $resqlUpdate = $db->query($sqlUpdate);
                if (!$resqlUpdate) {
                    dol_syslog("Error updating nutritional record for node " . $nodeId . ": " . $db->lasterror(), LOG_ERR);
                } else {
                    dol_syslog("Updated nutritional record for node " . $nodeId, LOG_DEBUG);
                }
            } else {
                // No record exists, perform insert.
                $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional 
                          (fk_product, energy_kcal, energy_kj, fat, saturates, carbohydrates, sugars, protein, salt, fiber)
                          VALUES (" . (int)$nodeId . ", " . round($normKcal, 2) . ", " . round($normKj, 2) . ", " . round($normFat, 2) . ", " . round($normSatur, 2) . ", " . round($normCarbs, 2) . ", " . round($normSugars, 2) . ", " . round($normProt, 2) . ", " . round($normSalt, 2) . ", " . round($normFiber, 2) . ")";
                dol_syslog("Executing SQL to insert record for node " . $nodeId . ": " . $sqlInsert, LOG_DEBUG);
                $resqlInsert = $db->query($sqlInsert);
                if (!$resqlInsert) {
                    dol_syslog("Error inserting nutritional record for node " . $nodeId . ": " . $db->lasterror(), LOG_ERR);
                } else {
                    dol_syslog("Inserted nutritional record for node " . $nodeId, LOG_DEBUG);
                }
            }
        }
        dol_syslog("updateNutrientAttributes: Completed updating nutritional records bottom-up.", LOG_DEBUG);
    }



    // ---------------------------------------------------------------------
    // Helper functions
    // ---------------------------------------------------------------------

    private static function buildProductMap($startId)
    {
        global $db;
        $map   = array();
        $queue = array($startId);
        $seen  = array($startId => true);

        // This SQL fetches the associations plus basic product info needed for nutrient calculations.
        $sqlBase = "SELECT pa.fk_product_pere AS father,
                           pa.fk_product_fils AS child,
                           pa.qty AS qty,
                           pf.label AS fatherLabel,
                           pf.weight AS fatherWeight,
                           pf.weight_units AS fatherWeightUnits,
                           pc.label AS childLabel,
                           pc.weight AS childWeight,
                           pc.weight_units AS childWeightUnits
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
                    // Create/update father node.
                    if (!isset($map[$obj->father])) {
                        $map[$obj->father] = new LocalProductNut(
                            $obj->father,
                            $obj->fatherLabel,
                            $obj->fatherWeight,
                            $obj->fatherWeightUnits
                        );
                    }
                    // Create/update child node.
                    if (!isset($map[$obj->child])) {
                        $map[$obj->child] = new LocalProductNut(
                            $obj->child,
                            $obj->childLabel,
                            $obj->childWeight,
                            $obj->childWeightUnits
                        );
                    }
                    // Record the association (aggregating quantity if needed).
                    if (!isset($map[$obj->father]->children[$obj->child])) {
                        $map[$obj->father]->children[$obj->child] = 0;
                    }
                    $map[$obj->father]->children[$obj->child] += (float)$obj->qty;

                    // Enqueue nodes not seen yet.
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
        return $map;
    }

    private static function computeHeights(&$map)
    {
        $heights = array();
        foreach ($map as $nodeId => $node) {
            self::getHeight($nodeId, $map, $heights);
        }
        return $heights;
    }

    private static function getHeight($nodeId, &$map, &$heights)
    {
        if (isset($heights[$nodeId])) {
            return $heights[$nodeId];
        }
        if (!isset($map[$nodeId]) || empty($map[$nodeId]->children)) {
            $heights[$nodeId] = 0;
            return 0;
        }
        $maxChildHeight = 0;
        foreach ($map[$nodeId]->children as $childId => $qty) {
            $childHeight = self::getHeight($childId, $map, $heights);
            if ($childHeight > $maxChildHeight) {
                $maxChildHeight = $childHeight;
            }
        }
        $heights[$nodeId] = $maxChildHeight + 1;
        return $heights[$nodeId];
    }

    /**
     * Convert a given weight to grams based on its unit.
     * Handles both numeric and string unit types.
     *
     * @param float $weight
     * @param mixed $unit
     * @return float
     */
    private static function convertToGrams($weight, $unit)
    {
        $unit = strtolower(trim($unit));
        // If the unit is numeric, use the original logic.
        if (is_numeric($unit)) {
            if ($unit == 98) {
                return $weight / 35.274;
            } elseif ($unit == 99) {
                return $weight / 2.20462;
            } else {
                return $weight * pow(10, (int)$unit) * 1000;
            }
        } else {
            // Handle string units.
            switch ($unit) {
                case 'kg':
                    return $weight * 1000;
                case 'g':
                    return $weight;
                case 'mg':
                    return $weight / 1000;
                case 'lb':
                case 'lbs':
                    return $weight / 2.20462;
                case 'oz':
                    return $weight * 28.3495;
                default:
                    // Unknown unit, assume grams.
                    return $weight;
            }
        }
    }
}

/**
 * Minimal local product container used in this updater.
 */
class LocalProductNut
{
    public $id;
    public $label;
    public $weight;
    public $weight_units;
    public $children = array();

    public function __construct($id, $label, $weight = 0, $weight_units = 'g')
    {
        $this->id = (int)$id;
        $this->label = $label;
        $this->weight = $weight;
        $this->weight_units = $weight_units;
    }
}
