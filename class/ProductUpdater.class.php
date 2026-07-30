<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once __DIR__ . '/KreaProductsBomCostCalculator.class.php';

/**
 * Cost Price Updater - Standalone Class
 *
 * Extracted from ProductMixer Dolibarr module to handle product cost price updates
 * Independent implementation using native Dolibarr framework
 *
 * PRODUCT HIERARCHY AND COST CASCADE:
 * - Read-only hierarchy inspection supports product associations and manufacturing BOMs
 * - Cost cascades select the most recently produced active manufacturing BOM automatically
 * - When no active BOM has production history, the newest validated BOM is selected automatically
 * - Product associations are the recipe fallback when no active manufacturing BOM exists
 * - Explicitly changed products remain authoritative source costs for every transitive dependent
 * - Cost cascades reject cyclic product graphs before updating any product
 * - Supports manufacturing BOMs (bomtype = 0) that are validated (status = 1)
 * - Buy price updates are controlled by the product extrafield kreap_updatebuyprice (fallback to kreap_syncprice for legacy installs)
 * - Debug output shows the source of each relationship (association, bom, or both)
 */
class ProductUpdater
{
    /**
     * @var array Product hierarchy map
     */
    private static $productMap = [];

    /**
     * @var array Cached cost calculations per product id
     */
    private static $costCache = [];

    /**
     * @var bool Map loaded flag
     */
    private static $mapLoaded = false;

    /**
     * @var bool Debug mode
     */
    private static $debug = false;

    /**
     * @var array Cached sync flags per product id
     */
	private static $syncFlagsCache = [];

	/**
	 * @var array<int, string> Errors from the current batch cascade.
	 */
	private static $lastErrors = [];

	/**
	 * @var array<string, bool> Product extrafield column cache for the current batch.
	 */
	private static $extraFieldColumnExistsCache = [];

	/**
	 * @var array<int, bool> Products whose persisted cost initiated the current cascade.
	 */
	private static $sourceProductIds = [];

	/**
	 * @var array<int,int>|null Selected active manufacturing BOM IDs by parent product ID.
	 */
	private static $selectedManufacturingBomIds = null;

	/**
	 * Return errors collected by the latest batch cascade.
	 *
	 * @return array<int, string>
	 */
	public static function getLastErrors(): array
	{
		return self::$lastErrors;
	}

	/**
	 * Record one deterministic cascade error.
	 *
	 * @param string $message Error message
	 * @return void
	 */
	private static function addError(string $message): void
	{
		self::$lastErrors[] = $message;
		self::debug($message);
	}

	/**
	 * Preserve the database-backed product state before changing its cost.
	 *
	 * Dolibarr expects callers to populate oldcopy before mutating properties.
	 * Without this snapshot, Product::update() clones the already-modified
	 * object and PRODUCT_MODIFY triggers cannot detect the cost change.
	 *
	 * @param Product $product Product loaded before the cost mutation
	 * @return void
	 */
	public static function prepareProductCostUpdate(Product $product): void
	{
		if (
			isset($product->oldcopy)
			&& is_object($product->oldcopy)
			&& !empty($product->oldcopy->id)
		) {
			return;
		}

		$product->oldcopy = dol_clone($product, 1);
	}

    /**
     * Set debug mode
     *
     * @param bool $debug
     */
    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    /**
     * Debug log function
     *
     * @param string $message
     */
    private static function debug(string $message): void
    {
        if (self::$debug) {
            error_log("[ProductUpdater] " . $message);
            dol_syslog("[ProductUpdater] " . $message, LOG_DEBUG);
        }
    }

    /**
     * Propagate one changed product cost through every transitive dependent.
     *
     * @param int $productId Product ID that was modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array Results array
     */
    public static function updateProductCostPrice(int $productId, bool $useWholeSalePriceSync = true): array
    {
        self::debug("Starting cost price update for product ID: " . $productId);

        if ($productId <= 0) {
            self::debug("Invalid product ID: " . $productId);
            return [];
        }

        return self::batchUpdateCostPrices([$productId], $useWholeSalePriceSync);
    }

    /**
     * Update cost prices for multiple products with one hierarchy load.
     *
     * @param array<int, int> $productIds Product IDs that changed
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting
     * @return array<int, array{updated: bool, ref: string, is_original: bool}>
     */
    public static function batchUpdateCostPrices(array $productIds, bool $useWholeSalePriceSync = true): array
    {
        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return [];
        }

        self::debug("Starting batch cost price update for product IDs: " . implode(',', $productIds));

		self::resetMap();
		self::$sourceProductIds = array_fill_keys($productIds, true);
		self::loadImpactedProductMap($productIds);
		if (!empty(self::$lastErrors)) {
			return array();
		}

		if (empty(self::$productMap)) {
				self::debug("Product map is empty - no impacted association or manufacturing BOM found");
			return [];
		}
		if (!self::validateProductMapIsAcyclic()) {
			return array();
		}

		$processingOrder = self::createProcessingOrder($productIds);
		self::preloadSyncFlagsForProductIds($processingOrder);
		if (!empty(self::$lastErrors)) {
			return array();
		}

        $results = [];
        $originalProductIds = array_fill_keys($productIds, true);

        foreach ($processingOrder as $currentProductId) {
            $mapProduct = self::getProductFromMap($currentProductId);

            // The products that initiated the cascade are authoritative inputs.
            // Recalculating them here would overwrite the cost that must propagate.
            if (!empty($originalProductIds[$currentProductId])) {
                continue;
            }

            // Skip products that don't exist in map or don't have children
            // (matches original logic: only virtual products with children get updated)
            if (!$mapProduct || !self::hasChildren($currentProductId)) {
                continue;
            }

            self::debug("Processing product ID: " . $currentProductId . " (ref: " . $mapProduct['ref'] . ")");

            // Update cost price if sync is enabled via product extrafield (kreap_updatebuyprice/kreap_syncprice)
			if (self::isCostPriceSyncEnabled($currentProductId) && $useWholeSalePriceSync) {
				$updated = self::updateCostPriceFromChildren($currentProductId, null);
                $results[$currentProductId] = [
                    'updated' => $updated,
                    'ref' => $mapProduct['ref'] ?? 'Unknown',
                    'is_original' => !empty($originalProductIds[$currentProductId])
                ];

				if ($updated) {
                    self::debug("Cost price updated for product: " . $mapProduct['ref'] .
                              (!empty($originalProductIds[$currentProductId]) ? " (this is an original modified product)" : " (parent product)"));
                }
            }
        }

        return $results;
    }

    /**
     * Load product hierarchy map from database
     */
    private static function loadProductMap(): void
    {
        global $db;

        if (self::$mapLoaded) {
            return;
        }

        // Validate database connection
        if (!$db) {
            self::debug("Error: Database connection not available");
            return;
        }

        self::debug("Loading product map from database");

        // First load product associations
        self::loadProductAssociations();

        // Then load BOM-based relationships (if BOM module is enabled)
        self::loadBOMRelationships();

        self::$mapLoaded = true;
        self::debug("Product map loaded with " . count(self::$productMap) . " products");
    }

    /**
     * Load product associations from llx_product_association table
     */
    private static function loadProductAssociations(?array $parentIds = null, ?array $childIds = null, bool $costFallbackOnly = false): void
    {
        global $db, $conf;

        self::debug("Loading product associations");

        $parentIds = ($parentIds === null ? null : self::normalizeProductIds($parentIds));
        $childIds = ($childIds === null ? null : self::normalizeProductIds($childIds));

        // Query to get product associations (no sync flags stored here)
        $sql = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child, pa.qty as qty, ";
        // Parent product info
        $sql .= "p.label as p_label, p.ref as p_ref, p.cost_price as p_cost_price, ";
        // Child product info
        $sql .= "f.label as f_label, f.ref as f_ref, f.cost_price as f_cost_price ";
        $sql .= "FROM ".MAIN_DB_PREFIX."product_association as pa, ";
        $sql .= MAIN_DB_PREFIX."product as p, ";
        $sql .= MAIN_DB_PREFIX."product as f ";
        $sql .= "WHERE p.rowid = pa.fk_product_pere AND f.rowid = pa.fk_product_fils";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " AND f.entity IN (".getEntity('product').")";
		if ($costFallbackOnly && !empty($conf->bom->enabled)) {
			// An active manufacturing BOM is the authoritative recipe for its
			// parent. Associations are used only when no applicable BOM exists.
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."bom_bom active_bom";
			$sql .= " WHERE active_bom.fk_product = pa.fk_product_pere";
			$sql .= " AND active_bom.bomtype = 0 AND active_bom.status = 1";
			$sql .= " AND active_bom.entity IN (0,".((int) $conf->entity)."))";
		}
        if ($parentIds !== null) {
            if (empty($parentIds)) {
                return;
            }
            $sql .= " AND pa.fk_product_pere IN (" . implode(',', $parentIds) . ")";
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return;
            }
            $sql .= " AND pa.fk_product_fils IN (" . implode(',', $childIds) . ")";
        }

		$resql = $db->query($sql);
		if (!$resql) {
			self::addError("Error loading product associations: " . $db->lasterror());
            return;
        }

        // Initialize product map if not already done
        if (empty(self::$productMap)) {
            self::$productMap = [];
        }

        $associationCount = 0;
        while ($obj = $db->fetch_object($resql)) {
            // Add parent to map
            if (!isset(self::$productMap[$obj->parent])) {
                self::$productMap[$obj->parent] = [
                    'id' => $obj->parent,
                    'ref' => $obj->p_ref,
                    'label' => $obj->p_label,
                    'cost_price' => $obj->p_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to map
            if (!isset(self::$productMap[$obj->child])) {
                self::$productMap[$obj->child] = [
                    'id' => $obj->child,
                    'ref' => $obj->f_ref,
                    'label' => $obj->f_label,
                    'cost_price' => $obj->f_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to parent's children (with source info)
            self::$productMap[$obj->parent]['children'][$obj->child] = [
                'id' => $obj->child,
                'qty' => $obj->qty,
                'source' => 'association'
            ];

            // Add parent to child's parents
            self::$productMap[$obj->child]['parents'][$obj->parent] = [
                'id' => $obj->parent,
                'source' => 'association'
            ];

            $associationCount++;
        }

        $db->free($resql);
        self::debug("Loaded " . $associationCount . " product associations");
    }

    /**
     * Load BOM-based relationships from bom_bom and bom_bomline tables
     */
    private static function loadBOMRelationships(?array $parentIds = null, ?array $childIds = null): void
    {
        global $db, $conf;

        // Only load BOM data if BOM module is enabled
        if (empty($conf->bom->enabled)) {
            self::debug("BOM module not enabled, skipping BOM relationships");
            return;
        }

        self::debug("Loading BOM relationships");

        $parentIds = ($parentIds === null ? null : self::normalizeProductIds($parentIds));
        $childIds = ($childIds === null ? null : self::normalizeProductIds($childIds));

        $selectedBomIds = self::getSelectedManufacturingBomIds();
        if (!empty(self::$lastErrors) || empty($selectedBomIds)) {
            return;
        }

        // Cost cascades use one automatically selected manufacturing BOM per parent.
        // Dismantling allocates the purchased parent value to outputs separately.
        $sql = "SELECT b.fk_product as parent, COALESCE(bl.fk_product, cb.fk_product) as child, ";
        $sql .= "bl.qty as line_qty, bl.efficiency as line_efficiency, b.qty as header_qty, ";
        // Parent product info
        $sql .= "p.label as p_label, p.ref as p_ref, p.cost_price as p_cost_price, ";
        // Child product info
        $sql .= "COALESCE(f.label, cprod.label) as f_label, COALESCE(f.ref, cprod.ref) as f_ref, COALESCE(f.cost_price, cprod.cost_price) as f_cost_price, ";
        // BOM info
        $sql .= "b.rowid as bom_id, b.ref as bom_ref ";
        $sql .= "FROM ".MAIN_DB_PREFIX."bom_bom as b ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."bom_bomline as bl ON b.rowid = bl.fk_bom ";
        $sql .= "JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = b.fk_product ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."product as f ON f.rowid = bl.fk_product ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."bom_bom as cb ON cb.rowid = bl.fk_bom_child ";
        $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."product as cprod ON cprod.rowid = cb.fk_product ";
        $sql .= "WHERE b.bomtype = 0 AND b.status = 1";
		$sql .= " AND b.rowid IN (".implode(',', $selectedBomIds).")";
        $sql .= " AND (cb.rowid IS NULL OR cb.entity IN (0,".getEntity('bom')."))";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " AND (f.rowid IS NULL OR f.entity IN (".getEntity('product')."))";
        $sql .= " AND (cprod.rowid IS NULL OR cprod.entity IN (".getEntity('product')."))";
        $sql .= " AND COALESCE(bl.fk_product, cb.fk_product) IS NOT NULL";
        if ($parentIds !== null) {
            if (empty($parentIds)) {
                return;
            }
            $sql .= " AND b.fk_product IN (" . implode(',', $parentIds) . ")";
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return;
            }
            $sql .= " AND (bl.fk_product IN (" . implode(',', $childIds) . ") OR cb.fk_product IN (" . implode(',', $childIds) . "))";
        }

		$resql = $db->query($sql);
		if (!$resql) {
			self::addError("Error loading BOM relationships: " . $db->lasterror());
            return;
        }

        $bomCount = 0;
        while ($obj = $db->fetch_object($resql)) {
            $normalizedQuantity = KreaProductsBomCostCalculator::normalizeLineQuantity(
                (float) $obj->line_qty,
                (float) $obj->line_efficiency,
                (float) $obj->header_qty
            );
            // Add parent to map if not exists
            if (!isset(self::$productMap[$obj->parent])) {
                self::$productMap[$obj->parent] = [
                    'id' => $obj->parent,
                    'ref' => $obj->p_ref,
                    'label' => $obj->p_label,
                    'cost_price' => $obj->p_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to map if not exists
            if (!isset(self::$productMap[$obj->child])) {
                self::$productMap[$obj->child] = [
                    'id' => $obj->child,
                    'ref' => $obj->f_ref,
                    'label' => $obj->f_label,
                    'cost_price' => $obj->f_cost_price,
                    'children' => [],
                    'parents' => []
                ];
            }

            // Add child to parent's children (BOM-based relationship)
            $childKey = $obj->child;
            if (!isset(self::$productMap[$obj->parent]['children'][$childKey])) {
                self::$productMap[$obj->parent]['children'][$childKey] = [
                    'id' => $obj->child,
                    'qty' => $normalizedQuantity,
                    'source' => 'bom',
                    'bom_id' => $obj->bom_id,
                    'bom_ref' => $obj->bom_ref
                ];
            } else {
                // If relationship already exists from associations, mark it as having BOM too
                self::$productMap[$obj->parent]['children'][$childKey]['source'] = 'both';
                self::$productMap[$obj->parent]['children'][$childKey]['bom_id'] = $obj->bom_id;
                self::$productMap[$obj->parent]['children'][$childKey]['bom_ref'] = $obj->bom_ref;
                // Use BOM quantity as it's likely more accurate
                self::$productMap[$obj->parent]['children'][$childKey]['qty'] = $normalizedQuantity;
            }

            // Add parent to child's parents
            $parentKey = $obj->parent;
            if (!isset(self::$productMap[$obj->child]['parents'][$parentKey])) {
                self::$productMap[$obj->child]['parents'][$parentKey] = [
                    'id' => $obj->parent,
                    'source' => 'bom',
                    'bom_id' => $obj->bom_id,
                    'bom_ref' => $obj->bom_ref
                ];
            } else {
                // Mark existing relationship as having BOM too
                self::$productMap[$obj->child]['parents'][$parentKey]['source'] = 'both';
                self::$productMap[$obj->child]['parents'][$parentKey]['bom_id'] = $obj->bom_id;
                self::$productMap[$obj->child]['parents'][$parentKey]['bom_ref'] = $obj->bom_ref;
            }

            $bomCount++;
        }

        $db->free($resql);
        self::debug("Loaded " . $bomCount . " BOM relationships");
    }

	/**
	 * Select one active manufacturing BOM per parent without manual input.
	 *
	 * Entity-local BOMs take precedence over global BOMs. Multiple BOMs in the
	 * effective scope are ranked by their latest non-cancelled produced line;
	 * BOM validation and creation dates provide a deterministic fallback when
	 * none of the candidates has production history.
	 *
	 * @return array<int,int> Selected BOM IDs keyed by parent product ID
	 */
	private static function getSelectedManufacturingBomIds(): array
	{
		global $db, $conf;

		if (self::$selectedManufacturingBomIds !== null) {
			return self::$selectedManufacturingBomIds;
		}

		self::$selectedManufacturingBomIds = array();
		$entity = (int) $conf->entity;
		$sql = "SELECT b.rowid, b.fk_product, b.entity, b.ref, b.date_valid, b.date_creation";
		$sql .= " FROM ".MAIN_DB_PREFIX."bom_bom b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = b.fk_product";
		$sql .= " WHERE b.bomtype = 0 AND b.status = 1";
		$sql .= " AND b.entity IN (0,".$entity.")";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		$sql .= " ORDER BY b.fk_product, b.entity, b.rowid";

		$resql = $db->query($sql);
		if (!$resql) {
			self::addError('Error loading active manufacturing BOMs: '.$db->lasterror());
			return self::$selectedManufacturingBomIds;
		}

		$bomsByParentAndEntity = array();
		while ($obj = $db->fetch_object($resql)) {
			$parentId = (int) $obj->fk_product;
			$bomEntity = (int) $obj->entity;
			if (!isset($bomsByParentAndEntity[$parentId])) {
				$bomsByParentAndEntity[$parentId] = array();
			}
			if (!isset($bomsByParentAndEntity[$parentId][$bomEntity])) {
				$bomsByParentAndEntity[$parentId][$bomEntity] = array();
			}
			$bomsByParentAndEntity[$parentId][$bomEntity][] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'date_valid' => (string) $obj->date_valid,
				'date_creation' => (string) $obj->date_creation,
			);
		}
		$db->free($resql);

		$effectiveCandidatesByParent = array();
		$allCandidateIds = array();
		foreach ($bomsByParentAndEntity as $parentId => $bomsByEntity) {
			$candidates = !empty($bomsByEntity[$entity]) ? $bomsByEntity[$entity] : ($bomsByEntity[0] ?? array());
			if (empty($candidates)) {
				continue;
			}
			$effectiveCandidatesByParent[(int) $parentId] = $candidates;
			foreach ($candidates as $candidate) {
				$allCandidateIds[(int) $candidate['id']] = (int) $candidate['id'];
			}
		}

		$latestProductionByBomId = array();
		if (!empty($allCandidateIds)) {
			$sql = "SELECT mo.fk_bom, COALESCE(mp.date_creation, mp.tms) AS production_date, mp.rowid";
			$sql .= " FROM ".MAIN_DB_PREFIX."mrp_mo mo";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."mrp_production mp ON mp.fk_mo = mo.rowid";
			$sql .= " WHERE mo.entity = ".$entity;
			$sql .= " AND mo.status <> ".Mo::STATUS_CANCELED;
			$sql .= " AND mp.role = 'produced' AND mp.qty <> 0";
			$sql .= " AND mo.fk_bom IN (".implode(',', $allCandidateIds).")";
			$sql .= " ORDER BY production_date DESC, mp.rowid DESC";

			$resql = $db->query($sql);
			if (!$resql) {
				self::addError('Error loading manufacturing BOM production history: '.$db->lasterror());
				return self::$selectedManufacturingBomIds;
			}
			while ($obj = $db->fetch_object($resql)) {
				$bomId = (int) $obj->fk_bom;
				if (isset($latestProductionByBomId[$bomId])) {
					continue;
				}
				$latestProductionByBomId[$bomId] = array(
					'date' => (string) $obj->production_date,
					'rowid' => (int) $obj->rowid,
				);
			}
			$db->free($resql);
		}

		foreach ($effectiveCandidatesByParent as $parentId => $candidates) {
			$selectedBomId = KreaProductsBomCostCalculator::selectPreferredBomId($candidates, $latestProductionByBomId);
			if ($selectedBomId <= 0) {
				continue;
			}
			self::$selectedManufacturingBomIds[(int) $parentId] = $selectedBomId;
			self::debug('Selected manufacturing BOM '.$selectedBomId.' automatically for product '.((int) $parentId));
		}

		return self::$selectedManufacturingBomIds;
	}

    /**
     * Load only the hierarchy needed to recalculate every dependent of the changed products.
     *
     * @param array<int, int> $productIds
     */
    private static function loadImpactedProductMap(array $productIds): void
    {
        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return;
        }

        self::debug("Loading impacted product map for product IDs: " . implode(',', $productIds));

        $candidateIds = array_fill_keys($productIds, true);

        $frontier = $productIds;
        $visitedChildLookups = [];
        while (!empty($frontier)) {
            $lookupIds = [];
            foreach ($frontier as $productId) {
                $productId = (int) $productId;
                if ($productId > 0 && empty($visitedChildLookups[$productId])) {
                    $visitedChildLookups[$productId] = true;
                    $lookupIds[] = $productId;
                }
            }
            if (empty($lookupIds)) {
                break;
            }

			self::loadProductAssociations(null, $lookupIds, true);
			self::loadBOMRelationships(null, $lookupIds);

            $frontier = [];
            foreach ($lookupIds as $childId) {
                $child = self::getProductFromMap((int) $childId);
                if (!$child || empty($child['parents'])) {
                    continue;
                }
                foreach ($child['parents'] as $parent) {
                    $parentId = (int) $parent['id'];
                    if ($parentId > 0 && empty($candidateIds[$parentId])) {
                        $candidateIds[$parentId] = true;
                        $frontier[] = $parentId;
                    }
                }
            }
        }

        $frontier = array_map('intval', array_keys($candidateIds));
        $visitedParentLookups = [];
        while (!empty($frontier)) {
            $lookupIds = [];
            foreach ($frontier as $productId) {
                $productId = (int) $productId;
                if ($productId > 0 && empty($visitedParentLookups[$productId])) {
                    $visitedParentLookups[$productId] = true;
                    $lookupIds[] = $productId;
                }
            }
            if (empty($lookupIds)) {
                break;
            }

			self::loadProductAssociations($lookupIds, null, true);
			self::loadBOMRelationships($lookupIds, null);

            $frontier = [];
            foreach ($lookupIds as $parentId) {
                foreach (self::getChildren((int) $parentId) as $child) {
                    $childId = (int) $child['id'];
                    if ($childId > 0 && empty($visitedParentLookups[$childId])) {
                        $frontier[] = $childId;
                    }
                }
            }
        }

		self::$mapLoaded = true;
		self::debug("Impacted product map loaded with " . count(self::$productMap) . " products");
	}

	/**
	 * Reject invalid cost graphs before any product cost write occurs.
	 *
	 * @return bool True when the loaded graph is acyclic
	 */
	private static function validateProductMapIsAcyclic(): bool
	{
		$childrenByProduct = array();
		foreach (self::$productMap as $productId => $product) {
			$childrenByProduct[(int) $productId] = array();
			if (empty($product['children']) || !is_array($product['children'])) {
				continue;
			}
			foreach ($product['children'] as $child) {
				$childId = isset($child['id']) ? (int) $child['id'] : 0;
				if ($childId > 0) {
					$childrenByProduct[(int) $productId][] = $childId;
				}
			}
		}

		$cycle = KreaProductsBomCostCalculator::findCycle($childrenByProduct);
		if (empty($cycle)) {
			return true;
		}

		self::addError('Cyclic product cost graph detected: '.implode(' -> ', $cycle));
		return false;
	}

    /**
     * Reset product map
     */
    private static function resetMap(): void
    {
        self::$productMap = [];
        self::$mapLoaded = false;
		self::$costCache = [];
		self::$syncFlagsCache = [];
		self::$lastErrors = [];
		self::$extraFieldColumnExistsCache = [];
		self::$sourceProductIds = [];
		self::$selectedManufacturingBomIds = null;
    }

    /**
     * Get product from map
     *
     * @param int $productId
     * @return array|null
     */
    private static function getProductFromMap(int $productId): ?array
    {
        return self::$productMap[$productId] ?? null;
    }

    /**
     * Check if product has children
     *
     * @param int $productId
     * @return bool
     */
    private static function hasChildren(int $productId): bool
    {
        $product = self::getProductFromMap($productId);
        return $product && !empty($product['children']);
    }

    /**
     * Get product children
     *
     * @param int $productId
     * @return array
     */
    private static function getChildren(int $productId): array
    {
        $product = self::getProductFromMap($productId);
        return $product['children'] ?? [];
    }

    /**
     * Check if cost price sync is enabled for product
     * Uses product extrafield kreap_updatebuyprice (with legacy kreap_syncprice fallback)
     *
     * @param int $productId
     * @return bool
     */
    private static function isCostPriceSyncEnabled(int $productId): bool
    {
        global $db;

        if (isset(self::$syncFlagsCache[$productId])) {
            return self::$syncFlagsCache[$productId];
        }

        // Load product and its extrafields
        $product = new Product($db);
		if ($product->fetch($productId) <= 0) {
			self::addError("Unable to load product " . $productId . " while reading cost synchronization flags");
			self::$syncFlagsCache[$productId] = false;
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);
        $product->fetch_optionals($productId, $extrafields);

        // Primary flag: kreap_updatebuyprice
        $syncFields = ['options_kreap_updatebuyprice', 'options_kreap_syncprice'];
        foreach ($syncFields as $fieldName) {
            if (!empty($product->array_options[$fieldName])) {
                self::$syncFlagsCache[$productId] = true;
                return true;
            }
        }

        self::$syncFlagsCache[$productId] = false;
        return false;
    }

    /**
     * Normalize and deduplicate product ids.
     *
     * @param array<int, mixed> $productIds
     * @return array<int, int>
     */
    private static function normalizeProductIds(array $productIds): array
    {
        $normalized = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0) {
                $normalized[$productId] = $productId;
            }
        }

        return array_values($normalized);
    }

    /**
     * Preload per-product cost sync flags for the products that may be updated.
     *
     * @param array<int, int> $productIds
     */
    private static function preloadSyncFlagsForProductIds(array $productIds): void
    {
        global $db;

        $productIds = self::normalizeProductIds($productIds);
        if (empty($productIds)) {
            return;
        }

        foreach ($productIds as $productId) {
            self::$syncFlagsCache[$productId] = false;
        }

        $hasUpdateBuyPrice = self::productExtrafieldColumnExists('kreap_updatebuyprice');
        $hasLegacySyncPrice = self::productExtrafieldColumnExists('kreap_syncprice');
        if (!$hasUpdateBuyPrice && !$hasLegacySyncPrice) {
            return;
        }

        $select = ["fk_object"];
        if ($hasUpdateBuyPrice) {
            $select[] = "kreap_updatebuyprice";
        }
        if ($hasLegacySyncPrice) {
            $select[] = "kreap_syncprice";
        }

        $sql = "SELECT " . implode(', ', $select);
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_extrafields";
        $sql .= " WHERE fk_object IN (" . implode(',', $productIds) . ")";

		$resql = $db->query($sql);
		if (!$resql) {
			self::addError("Error preloading cost sync flags: " . $db->lasterror());
            return;
        }

        while ($obj = $db->fetch_object($resql)) {
            $productId = (int) $obj->fk_object;
            self::$syncFlagsCache[$productId] = (
                ($hasUpdateBuyPrice && !empty($obj->kreap_updatebuyprice))
                || ($hasLegacySyncPrice && !empty($obj->kreap_syncprice))
            );
        }

        $db->free($resql);
    }

    private static function productExtrafieldColumnExists(string $columnName): bool
    {
        global $db;

		if (array_key_exists($columnName, self::$extraFieldColumnExistsCache)) {
			return self::$extraFieldColumnExistsCache[$columnName];
        }

        $exists = false;
		$resql = $db->DDLDescTable(MAIN_DB_PREFIX . 'product_extrafields', $columnName);
		if ($resql) {
			$exists = ($db->num_rows($resql) > 0);
			$db->free($resql);
		} else {
			self::addError('Unable to inspect product extrafield column ' . $columnName . ': ' . $db->lasterror());
		}

		self::$extraFieldColumnExistsCache[$columnName] = $exists;
        return $exists;
    }

    /**
     * Build a deterministic bottom-up processing order for changed products and their parents.
     *
     * @param array<int, int> $productIds
     * @return array<int, int>
     */
    private static function createProcessingOrder(array $productIds): array
    {
        $candidates = [];
        foreach ($productIds as $productId) {
            self::collectProductAndParents((int) $productId, $candidates);
        }

        $depthCache = [];
        $order = array_keys($candidates);
        usort($order, function ($left, $right) use ($candidates, &$depthCache) {
            $leftDepth = self::calculateCandidateDepth((int) $left, $candidates, $depthCache);
            $rightDepth = self::calculateCandidateDepth((int) $right, $candidates, $depthCache);
            if ($leftDepth === $rightDepth) {
                return ((int) $left <=> (int) $right);
            }

            return ($leftDepth <=> $rightDepth);
        });

        self::debug("Processing order created with " . count($order) . " products");
        return array_map('intval', $order);
    }

    /**
     * @param array<int, bool> $candidates
     */
    private static function collectProductAndParents(int $productId, array &$candidates): void
    {
        if ($productId <= 0 || !empty($candidates[$productId])) {
            return;
        }

        $candidates[$productId] = true;
        $product = self::getProductFromMap($productId);
        if (!$product || empty($product['parents'])) {
            return;
        }

        foreach ($product['parents'] as $parent) {
            self::collectProductAndParents((int) $parent['id'], $candidates);
        }
    }

    /**
     * @param array<int, bool> $candidates
     * @param array<int, int> $depthCache
     * @param array<int, bool> $path
     */
    private static function calculateCandidateDepth(int $productId, array $candidates, array &$depthCache, array $path = []): int
    {
        if (isset($depthCache[$productId])) {
            return $depthCache[$productId];
        }
        if (!empty($path[$productId])) {
            self::debug("Cycle detected while ordering product hierarchy at product ID: " . $productId);
            return 0;
        }

        $path[$productId] = true;
        $maxChildDepth = -1;
        foreach (self::getChildren($productId) as $child) {
            $childId = (int) $child['id'];
            if (empty($candidates[$childId])) {
                continue;
            }
            $maxChildDepth = max($maxChildDepth, self::calculateCandidateDepth($childId, $candidates, $depthCache, $path));
        }

        $depthCache[$productId] = ($maxChildDepth < 0 ? 0 : $maxChildDepth + 1);
        return $depthCache[$productId];
    }

    /**
     * Update cost price from children calculation
     * This matches the original updateBuyprice() method behavior
     *
     * @param int $productId Product to update
     * @param int $originalProductId The original product that was modified (for warning messages)
     * @return bool True if updated, false otherwise
     */
    private static function updateCostPriceFromChildren(int $productId, ?int $originalProductId = null): bool
    {
        global $db, $user;

        // Load product from database
        $product = new Product($db);
		if ($product->fetch($productId) <= 0) {
			self::addError("Failed to load product: " . $productId);
            return false;
        }

		// Calculate new cost price from children.
		try {
			$newCostPrice = self::calculateCostPriceFromChildren($productId);
		} catch (Throwable $exception) {
			self::addError($exception->getMessage());
			return false;
		}

        // Compare with current cost price (tolerance of 0.001 - matches original)
        if (abs($product->cost_price - $newCostPrice) < 0.001) {
            self::debug("No cost price change needed for product " . $product->ref .
                       " (current: " . $product->cost_price . ", calculated: " . $newCostPrice . ")");
            return false;
        }

        $oldCostPrice = $product->cost_price;

        // Update product cost price (matches original method)
        self::prepareProductCostUpdate($product);
        $product->cost_price = $newCostPrice;
		$product->context = (array) $product->context;
		$hadSkipRealtimeSync = array_key_exists('skip_kreawoo_realtime_sync', $product->context);
		$previousSkipRealtimeSync = $hadSkipRealtimeSync ? $product->context['skip_kreawoo_realtime_sync'] : null;
		$product->context['skip_kreawoo_realtime_sync'] = true;
		try {
			$result = $product->update($productId, $user);
		} finally {
			if ($hadSkipRealtimeSync) {
				$product->context['skip_kreawoo_realtime_sync'] = $previousSkipRealtimeSync;
			} else {
				unset($product->context['skip_kreawoo_realtime_sync']);
			}
		}

        if ($result > 0) {
            self::debug("Updated cost price for product " . $product->ref . " from " .
                       $oldCostPrice . " to " . $newCostPrice);

            // If this is the original product that was modified, show a warning
            // (matches original behavior: line 215-217 in MapProduct.php)
            if ($productId == $originalProductId) {
                self::debug("WARNING: Cost price for " . $product->ref .
                           " was recalculated from children and overrode your manual entry!");
            }

            return true;
        }

		self::addError("Failed to update product " . $product->ref . ": " . ($product->error ?: $db->lasterror()));
        return false;
    }

    /**
     * Calculate cost price from children
     *
     * @param int $productId
     * @return float
     */
    private static function calculateCostPriceFromChildren(int $productId, array $path = []): float
    {
        if (isset(self::$costCache[$productId])) {
            return self::$costCache[$productId];
        }
		if (in_array($productId, $path, true)) {
			throw new RuntimeException('Cyclic product cost graph detected while calculating product '.$productId);
        }
        $path[] = $productId;

        $children = self::getChildren($productId);
        if (empty($children)) {
            return 0.0;
        }

        $totalCostPrice = 0.0;
        $parentProduct = self::getProductFromMap($productId);
        $parentRef = $parentProduct ? $parentProduct['ref'] : $productId;

        self::debug("Calculating cost for {$parentRef} with " . count($children) . " children");

		foreach ($children as $child) {
			$childProduct = self::getProductFromMap($child['id']);
			if (!$childProduct) {
				throw new RuntimeException('Unable to load BOM component '.((int) $child['id']).' while calculating product '.$productId);
            }

            $childCostPrice = 0.0;
            $source = isset($child['source']) ? $child['source'] : 'unknown';
            $bomInfo = '';

            if ($source === 'bom' || $source === 'both') {
                $bomInfo = " (BOM: " . (isset($child['bom_ref']) ? $child['bom_ref'] : $child['bom_id']) . ")";
            }

			// A product that initiated this batch remains an authoritative source,
			// even when it also has its own recipe. This preserves manual and
			// supplier-driven cost changes while propagating them downstream.
			if (!empty(self::$sourceProductIds[(int) $child['id']])) {
				$childCostPrice = (float) $childProduct['cost_price'];
				self::debug("  Child {$childProduct['ref']}: authoritative source cost {$childCostPrice} * qty {$child['qty']} = ".
					($childCostPrice * $child['qty'])." [source: {$source}]{$bomInfo}");
			// If child has its own children, calculate recursively
			} elseif (!empty($childProduct['children'])) {
                $childCostPrice = self::calculateCostPriceFromChildren($child['id'], $path);
                self::debug("  Child {$childProduct['ref']}: calculated cost {$childCostPrice} * qty {$child['qty']} = " .
                          ($childCostPrice * $child['qty']) . " [source: {$source}]{$bomInfo}");
            } else {
                // Use child's current cost price
                $childCostPrice = (float)$childProduct['cost_price'];
                self::debug("  Child {$childProduct['ref']}: direct cost {$childCostPrice} * qty {$child['qty']} = " .
                          ($childCostPrice * $child['qty']) . " [source: {$source}]{$bomInfo}");
            }

            $totalCostPrice += $childCostPrice * $child['qty'];
        }

        self::debug("Total calculated cost for {$parentRef}: {$totalCostPrice}");
        self::$costCache[$productId] = $totalCostPrice;
        return $totalCostPrice;
    }

    /**
     * Get all products in hierarchy for a given product
     *
     * @param int $productId
     * @return array
     */
    public static function getProductHierarchy(int $productId): array
    {
        self::loadProductMap();

        $hierarchy = [
            'product' => self::getProductFromMap($productId),
            'children' => [],
            'parents' => []
        ];

        if ($hierarchy['product']) {
            // Get children recursively
            $hierarchy['children'] = self::getChildrenHierarchy($productId);
            // Get parents recursively
            $hierarchy['parents'] = self::getParentsHierarchy($productId);
        }

        return $hierarchy;
    }

    /**
     * Get children hierarchy recursively
     *
     * @param int $productId
     * @return array
     */
    private static function getChildrenHierarchy(int $productId): array
    {
        $children = [];
        $productChildren = self::getChildren($productId);

        foreach ($productChildren as $child) {
            $childData = self::getProductFromMap($child['id']);
            if ($childData) {
                $children[] = [
                    'product' => $childData,
                    'qty' => $child['qty'],
                    'children' => self::getChildrenHierarchy($child['id'])
                ];
            }
        }

        return $children;
    }

    /**
     * Get parents hierarchy recursively
     *
     * @param int $productId
     * @return array
     */
    private static function getParentsHierarchy(int $productId): array
    {
        $parents = [];
        $product = self::getProductFromMap($productId);

        if ($product && !empty($product['parents'])) {
            foreach ($product['parents'] as $parent) {
                $parentData = self::getProductFromMap($parent['id']);
                if ($parentData) {
                    $parents[] = [
                        'product' => $parentData,
                        'parents' => self::getParentsHierarchy($parent['id'])
                    ];
                }
            }
        }

        return $parents;
    }

    /**
     * Simulate the exact behavior when a product is saved in Dolibarr
     * This is what gets called when you modify a product and save it
     * (equivalent to the trigger PRODUCT_MODIFY calling ProductMixer::updateProductAttributes)
     *
     * @param int $productId The product that was just saved/modified
     * @param bool $useWholeSalePriceSync Use global wholesale price sync setting (default true)
     * @return array Results array showing what was updated
     */
    public static function onProductModified(int $productId, bool $useWholeSalePriceSync = true): array
    {
        self::debug("=== Product Modified Event for Product ID: " . $productId . " ===");

        // This exactly matches the trigger behavior:
        // 1. Product A is saved
        // 2. Trigger calls ProductMixer::updateProductAttributes(A)
        // 3. This recalculates cost prices for A (if A has children) and all its parents

        $results = self::updateProductCostPrice($productId, $useWholeSalePriceSync);

        self::debug("=== End Product Modified Event ===");

        return $results;
    }

    /**
     * Main entry point for updating product attributes (backward compatibility)
     * This maintains compatibility with existing code that calls ProductHierarchy::updateProductAttributes
     *
     * @param int $productId Starting product ID
     * @param mixed $user User performing the update
     * @return int 1 on success, 0 on failure or skip
     */
	public static function updateProductAttributes($productId, $user)
	{
		// Call the new method and return simplified result
		$results = self::updateProductCostPrice($productId, true);
		if (!empty(self::getLastErrors())) {
			return -1;
		}

		// Return 1 if any products were updated, 0 when no update was needed.
        foreach ($results as $result) {
            if ($result['updated']) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Test method to verify the class is working correctly
     *
     * @return array Test results
     */
    public static function runSelfTest(): array
    {
        global $db;

        $results = [
            'database_connection' => false,
            'product_associations_table' => false,
            'product_table' => false,
            'test_query' => false,
            'errors' => []
        ];

        // Test database connection
        if ($db) {
            $results['database_connection'] = true;
        } else {
            $results['errors'][] = 'No database connection available';
            return $results;
        }

        // Test if product_association table exists
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "product_association'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $results['product_associations_table'] = true;
        } else {
            $results['errors'][] = 'product_association table not found';
        }
        if ($resql) $db->free($resql);

        // Test if product table exists
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "product'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $results['product_table'] = true;
        } else {
            $results['errors'][] = 'product table not found';
        }
        if ($resql) $db->free($resql);

        // Test a simple query
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "product_association";
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $results['test_query'] = true;
            $results['association_count'] = $obj->count;
            $db->free($resql);
        } else {
            $results['errors'][] = 'Failed to query product_association table: ' . $db->lasterror();
        }

        return $results;
    }
}

/**
 * Backward compatibility alias
 * Maintains compatibility with existing code that references ProductHierarchy
 */
class ProductHierarchy extends ProductUpdater
{
    // All functionality is inherited from ProductUpdater
}
