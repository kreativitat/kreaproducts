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
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/lib/mrp_mo.lib.php';
require_once __DIR__ . '/ProductUpdater.class.php';


class ProductDismantleController extends CommonObject
{
    public $db;

    /**
     * @var Mo $mo {@type Mo}
     */
    public $mo;

	public function __construct($db)
	{
		$this->db = $db;
        $this->mo = new Mo($this->db);
    }

    /**
     * Find BOM ID associated with a product ID.
     *
     * @param int $productId The ID of the product to find the BOM for.
	 * @return int|false|null The BOM ID if found, false when absent, null on query failure.
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
            ORDER BY (entity = " . ((int) $conf->entity) . ") DESC, (entity = 0) DESC, rowid DESC
            LIMIT 2";

        $resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("findBom query failed for product ID " . $productId, LOG_ERR);
			return null;
        }

        $rows = $this->db->num_rows($resql);
        if ($rows > 1) {
            dol_syslog(
                "Multiple active BOMs found for product ID " . $productId . " (bomtype=" . $bomType . "). Using most specific/newest.",
                LOG_WARNING
            );
        }

        if ($obj = $this->db->fetch_object($resql)) {
            $this->db->free($resql);
            return (int) $obj->rowid;
        }

        $this->db->free($resql);
        dol_syslog("No active BOM found for product ID " . $productId . " (bomtype=" . $bomType . ")", LOG_DEBUG);
        return false;
    }

	public function productInDismantleCategory($productId)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        $productId = (int) $productId;
        if ($productId <= 0) {
		return false;
	}

		$sql = "SELECT pe.kreap_dismantle"
            . " FROM " . MAIN_DB_PREFIX . "product_extrafields pe"
            . " WHERE pe.fk_object = " . $productId
            . " LIMIT 1";
        $resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("Error checking dismantle flag: " . $this->db->lasterror(), LOG_ERR);
			return null;
        }

        $obj = $this->db->fetch_object($resql);
        $flag = ($obj && $obj->kreap_dismantle !== null) ? (int) $obj->kreap_dismantle : 0;
        if ($flag === 1) {
            return true;
        }

		dol_syslog("Product ID " . $productId . " not flagged for dismantle", LOG_DEBUG);
		return false;
	}

	/**
	 * Recalculate all dismantle output costs from one shared source value.
	 *
	 * This is the manual counterpart of supplier-invoice dismantling valuation.
	 * The caller must own the surrounding transaction so every output and the
	 * subsequent manufacturing-BOM cascade commit or roll back together.
	 *
	 * @param int  $productId Dismantled parent product ID
	 * @param User $user      User performing the update
	 * @return int 1 when updated, 0 when not applicable, -1 on failure
	 */
	public function refreshDismantleOutputCostPrices($productId, $user)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return 0;
		}
		$isDismantleProduct = $this->productInDismantleCategory($productId);
		if ($isDismantleProduct === null) {
			return -1;
		}
		if (!$isDismantleProduct) {
			return 0;
		}
		if (!$this->isProductAvailable($productId)) {
			dol_syslog(__METHOD__.' parent product is outside the active product entity scope: '.$productId, LOG_ERR);
			return -1;
		}

		$bomId = $this->findBom($productId);
		if ($bomId === null) {
			return -1;
		}
		if (empty($bomId)) {
			return 0;
		}
		$bomData = $this->loadDismantleBom((int) $bomId);
		if (empty($bomData) || (int) $bomData['fk_product'] !== $productId) {
			dol_syslog(__METHOD__.' unable to load the entity-scoped dismantle BOM for product '.$productId, LOG_ERR);
			return -1;
		}

		$parentProduct = new Product($this->db);
		if ($parentProduct->fetch($productId) <= 0) {
			dol_syslog(__METHOD__.' unable to load dismantle parent product '.$productId, LOG_ERR);
			return -1;
		}
		$baseCostPrice = is_numeric($parentProduct->cost_price) ? (float) $parentProduct->cost_price : 0.0;
		if ($baseCostPrice <= 0) {
			return 0;
		}
		if (!$this->updateProducedCostPrices($bomData, $baseCostPrice, $user)) {
			return -1;
		}

		$outputProductIds = array();
		foreach ($bomData['lines'] as $line) {
			$outputProductId = (int) ($line->fk_product ?? 0);
			if ($outputProductId > 0) {
				$outputProductIds[$outputProductId] = $outputProductId;
			}
		}
		if (!empty($outputProductIds)) {
			ProductUpdater::batchUpdateCostPrices(array_values($outputProductIds), true);
			$cascadeErrors = ProductUpdater::getLastErrors();
			if (!empty($cascadeErrors)) {
				dol_syslog(__METHOD__.' output cascade failed: '.implode('; ', $cascadeErrors), LOG_ERR);
				return -1;
			}
		}

		return 1;
	}

    public function produceAndConsume($bomId, $qtyMovement, $priceMovement, $originRef, $originId, $originType, $movementDate = null, $userContext = null, $originMovementId = 0)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        global $conf;
        $user = $userContext ?: ($GLOBALS['user'] ?? null);
        $applyValuationUpdates = $this->shouldApplyValuationUpdates($originType);

		$movementDate = $movementDate ?: dol_now();
		$warehouseId  = (int) ($conf->global->KREAPRODUCTS_DISMANTLE_WAREHOUSE ?? $conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
		$error = 0;
		if (!$this->isWarehouseAvailable($warehouseId)) {
			dol_syslog(__METHOD__." invalid or out-of-entity dismantle warehouse #".$warehouseId, LOG_ERR);
			return -1;
		}

        // Load BOM header + lines with entity=0 support
        $bomData = $this->loadDismantleBom((int) $bomId);
        if (!$bomData) {
            dol_syslog("Failed to load BOM details", LOG_ERR);
            return -1;
        }

		$baseCostPrice = null;
		if (!$this->isProductAvailable((int) $bomData['fk_product'])) {
			dol_syslog(__METHOD__." dismantle parent product is outside the current entity scope", LOG_ERR);
			return -1;
		}
		if ($applyValuationUpdates) {
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
                $currentCostPrice = null;
            }

            if (is_numeric($priceMovement) && (float) $priceMovement > 0) {
                $baseCostPrice = (float) $priceMovement;
            } elseif (is_numeric($currentCostPrice) && (float) $currentCostPrice > 0) {
                $baseCostPrice = (float) $currentCostPrice;
            } elseif (is_numeric($currentPmp) && (float) $currentPmp > 0) {
                $baseCostPrice = (float) $currentPmp;
            }
        } else {
            dol_syslog(
                __METHOD__ . " skipping valuation updates for origin type '" . (string) $originType . "'",
                LOG_DEBUG
            );
        }

        // Ensure BOM has lines
        if (!is_array($bomData['lines']) || empty($bomData['lines'])) {
            dol_syslog("BOM has no lines", LOG_WARNING);
            return -1;
        }

        $arraytoconsume = [];
        $arraytoproduce = [];

		// Add main product to consume
        $arraytoconsume[] = [
            'objectid'    => $bomData['fk_product'],
            'qty'         => 1,
            'fk_warehouse' => $warehouseId,
        ];
        dol_syslog("arraytoconsume: " . json_encode($arraytoconsume, JSON_PRETTY_PRINT), LOG_DEBUG);

        // Add BOM components to produce
		foreach ($bomData['lines'] as $line) {
			$arraytoproduce[] = [
				'objectid'    => $line->fk_product,
				'qty'         => (float) $line->qty,
                'fk_warehouse' => $warehouseId,
            ];
		}
		dol_syslog("arraytoproduce: " . json_encode($arraytoproduce, JSON_PRETTY_PRINT), LOG_DEBUG);
		$totalProducedQtyPerPackage = 0.0;
		foreach ($arraytoproduce as $producedItem) {
			$totalProducedQtyPerPackage += max(0.0, (float) $producedItem['qty']);
		}
		if ($totalProducedQtyPerPackage <= 0) {
			dol_syslog(__METHOD__." dismantle BOM has no positive output quantity", LOG_ERR);
			return -1;
		}

        // Create or reuse MO so generated stock movements are linked to MRP and visible on movement tabs.
		$registeredMoId = $this->registerDismantleMo(
            (int) $bomId,
            $bomData,
            (float) $qtyMovement,
            (int) $warehouseId,
            (string) $originRef,
            (int) $originId,
            (string) $originType,
            $movementDate,
            $user,
			(int) $originMovementId
		);
		if ((int) $registeredMoId <= 0) {
			dol_syslog(__METHOD__." unable to create or reuse dismantle MO", LOG_ERR);
			return -1;
		}
		$plannedLineMap = [];
		$hasExecutionLines = $this->hasMoExecutionLines((int) $registeredMoId);
		if ($hasExecutionLines === null) {
			dol_syslog(__METHOD__." unable to verify dismantle idempotency for MO #".(int) $registeredMoId, LOG_ERR);
			return -1;
		}
		if ($hasExecutionLines) {
			dol_syslog(__METHOD__ . " movement already processed for MO #" . (int) $registeredMoId, LOG_DEBUG);
			return 0;
		}
		if (!$this->isMoValidated((int) $registeredMoId)) {
			dol_syslog(__METHOD__." dismantle MO is not validated: #".(int) $registeredMoId, LOG_ERR);
			return -1;
		}
		// Remove any orphaned execution lines (fk_stock_movement NULL or pointing to a
		// deleted movement) left by a previous failed attempt before writing new ones.
		if (!$this->purgeOrphanedExecutionLinesForMo((int) $registeredMoId)) {
			return -1;
		}
		$plannedLineMap = $this->buildMoPlannedLineMap((int) $registeredMoId);

        $movementRows = [];
        $movementPosition = 0;

        // Initialize stock movement handler
        $stockmove = new MouvementStock($this->db);

        // Process consumption and production arrays
        foreach (['arraytoconsume', 'arraytoproduce'] as $arrayname) {
			foreach (${$arrayname} as $item) {
				if (!$this->isProductAvailable((int) $item['objectid'])) {
					dol_syslog(__METHOD__." dismantle product is outside the current entity scope: ".(int) $item['objectid'], LOG_ERR);
					$error++;
					break;
				}
				$product = new Product($this->db);
                if ($product->fetch($item['objectid']) <= 0) {
                    dol_syslog("Failed to fetch product with ID " . $item['objectid'], LOG_ERR);
                    $error++;
                    break;
                }
				if (!$product->isStockManaged() && !$this->ensureProductStockManagedForMo($product, $user, $arrayname)) {
					$error++;
					break;
				}

                // Calculate signed and absolute quantities
                $rawQty = $item['qty'] * $qtyMovement;
                $qty    = abs($rawQty);

                // Default to current product cost for movement valuation when supplier-only updates are disabled.
                $movementPrice = is_numeric($product->cost_price) ? (float) $product->cost_price : 0.0;
                $shouldUpdateCost = false;
                if ($applyValuationUpdates) {
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
							$movementPrice = (float) $baseCostPrice / $totalProducedQtyPerPackage;
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
					if ($shouldUpdateCost && !$this->persistCostPrice($product, $movementPrice, $user, $arrayname)) {
						dol_syslog(__METHOD__." failed to persist cost price for product #".(int) $product->id, LOG_ERR);
						$error++;
						break;
                    }
                }

                // Link generated stock movements to MO so /mrp/mo_movements.php can load them.
                if ((int) $registeredMoId > 0) {
                    $stockmove->setOrigin('mo', (int) $registeredMoId);
                } else {
                    $stockmove->setOrigin($originType, $originId);
                }

				$movementQty = ($arrayname === 'arraytoconsume' ? -$rawQty : $rawQty);
				$movementLabel = ($arrayname === 'arraytoconsume'
					? ($rawQty >= 0 ? 'Consume' : 'Reverse consume')
					: ($rawQty >= 0 ? 'Produce' : 'Reverse produce'));
				$result = $this->createDismantleStockMovement(
					$stockmove,
					$product,
					$user,
					(int) $item['fk_warehouse'],
					(float) $movementQty,
					(float) $movementPrice,
					$movementLabel." for MO ($originRef)",
					$movementDate
				);

                // Check for errors
                if ($result <= 0) {
                    dol_syslog("Stock movement failed for product ID " . $item['objectid'] . " with error " . $stockmove->error, LOG_ERR);
                    $error++;
                    break;
                }

                if ((int) $registeredMoId > 0) {
                    $movementRows[] = [
                        'planned_role' => ($arrayname === 'arraytoconsume' ? 'toconsume' : 'toproduce'),
                        'execution_role' => ($arrayname === 'arraytoconsume' ? 'consumed' : 'produced'),
                        'product_id' => (int) $item['objectid'],
                        'qty' => (float) $rawQty,
                        'fk_warehouse' => (int) $item['fk_warehouse'],
                        'fk_stock_movement' => (int) $result,
                        'position' => (int) $movementPosition,
                    ];
                    $movementPosition++;
                }
            }
            if ($error) {
                break;
            }
        }

        if ($error) {
            dol_syslog("Errors encountered, rolling back.", LOG_ERR);
            return -1;
        }

        if ((int) $registeredMoId > 0 && !empty($movementRows)) {
            $lineResult = $this->recordMoExecutionLines((int) $registeredMoId, $movementRows, $plannedLineMap, $user);
			if (!$lineResult || !$this->setMoProducedStatus((int) $registeredMoId)) {
				dol_syslog(__METHOD__." failed to finalize dismantle MO #".(int) $registeredMoId, LOG_ERR);
				return -1;
			}
        }

        if ($applyValuationUpdates) {
            if (is_numeric($baseCostPrice) && (float) $baseCostPrice > 0) {
				if (!$this->updateProducedCostPrices($bomData, (float) $baseCostPrice, $user)) {
					return -1;
				}
            } else {
                dol_syslog("Skipping produced cost price update (missing base cost price)", LOG_DEBUG);
            }
        } else {
            dol_syslog(
                __METHOD__ . " skipping produced cost price update for origin type '" . (string) $originType . "'",
                LOG_DEBUG
            );
        }

        dol_syslog("Dismantle processed successfully.", LOG_DEBUG);
        return 0;
    }

	/**
	 * Create one MO movement without invoking Dolibarr's separate kit-child cascade.
	 *
	 * @param MouvementStock $stockmove    Stock movement object
	 * @param Product        $product      Product being moved
	 * @param User           $user         Movement author
	 * @param int            $warehouseId  Warehouse ID
	 * @param float          $quantity     Signed quantity
	 * @param float          $price        Unit cost
	 * @param string         $label        Movement label
	 * @param int|string     $movementDate Value date
	 * @return int
	 */
	private function createDismantleStockMovement($stockmove, $product, $user, $warehouseId, $quantity, $price, $label, $movementDate)
	{
		global $conf;

		$isKitParent = getDolGlobalInt('PRODUIT_SOUSPRODUITS') && (int) $product->hasFatherOrChild(1) > 0;
		$restoreParentSetting = false;
		$parentSettingExisted = isset($conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE);
		$parentSettingValue = $parentSettingExisted ? $conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE : null;
		if ($isKitParent && !getDolGlobalInt('PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE')) {
			$conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE = 1;
			$restoreParentSetting = true;
		}

		try {
			return $stockmove->_create(
				$user,
				(int) $product->id,
				(int) $warehouseId,
				(float) $quantity,
				($quantity < 0 ? 2 : 3),
				(float) $price,
				(string) $label,
				'',
				$movementDate,
				'',
				'',
				'',
				!isModEnabled('productbatch'),
				0,
				1
			);
		} finally {
			if ($restoreParentSetting) {
				if ($parentSettingExisted) {
					$conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE = $parentSettingValue;
				} else {
					unset($conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE);
				}
			}
		}
	}

    private function shouldApplyValuationUpdates($originType): bool
    {
        return ((string) $originType === 'invoice_supplier');
    }

    private function registerDismantleMo($bomId, array $bomData, $qtyMovement, $warehouseId, $originRef, $originId, $originType, $movementDate, $user, $originMovementId = 0)
    {
        if (!isModEnabled('mrp')) {
            return 0;
        }
        if (empty($bomData['fk_product'])) {
            return 0;
        }
        if (!is_object($user) || empty($user->id)) {
            dol_syslog(__METHOD__ . " unable to register MO (missing user context)", LOG_WARNING);
            return 0;
        }

        $qtyToDismantle = abs((float) $qtyMovement);
        if ($qtyToDismantle <= 0) {
            return 0;
        }

		$importKey = $this->buildDismantleImportKey((int) $originMovementId, (string) $originType, (int) $originId, (int) $bomId, $movementDate);
		$existingMoId = $this->findMoIdByImportKey($importKey);
		if ($existingMoId < 0) {
			return -1;
		}
		if ($existingMoId > 0) {
			$existingMo = new Mo($this->db);
			if ($existingMo->fetch((int) $existingMoId) <= 0) {
				return -1;
			}
			if ((int) $existingMo->status === (int) Mo::STATUS_DRAFT) {
				$validateResult = $existingMo->validate($user);
				if ($validateResult <= 0) {
					dol_syslog(__METHOD__ . " failed to validate existing MO #" . (int) $existingMoId . " error=" . $existingMo->error, LOG_ERR);
					return -1;
				}
			}
			return $existingMoId;
        }

        $mo = new Mo($this->db);
        $mo->ref = '(PROV)';
        $mo->fk_product = (int) $bomData['fk_product'];
        $mo->fk_bom = (int) $bomId;
        $mo->qty = $qtyToDismantle;
        $mo->fk_warehouse = ((int) $warehouseId > 0 ? (int) $warehouseId : 0);
        if (!empty($movementDate)) {
            $mo->date_start_planned = $movementDate;
            $mo->date_end_planned = $movementDate;
        }

        $prefix = ((float) $qtyMovement >= 0 ? 'Auto dismantle' : 'Auto reverse dismantle');
        $originRef = trim((string) preg_replace('/\s+/', ' ', (string) $originRef));
        $mo->label = $this->truncateMoLabel($prefix . (!empty($originRef) ? ' - ' . $originRef : ''));
        $mo->note_private = $prefix . ' from ' . (string) $originType . ' #' . ((int) $originId)
            . ((int) $originMovementId > 0 ? ', movement #' . ((int) $originMovementId) : '');
        $mo->import_key = $importKey;

        $moId = $mo->create($user);
        if ($moId <= 0) {
            dol_syslog(__METHOD__ . " failed to create MO for bomId=" . (int) $bomId . " error=" . $mo->error, LOG_WARNING);
            return 0;
        }

        if ((int) $originId > 0 && !empty($originType)) {
            $mo->add_object_linked((string) $originType, (int) $originId, $user, 1);
        }

		if ($mo->fetch($moId) <= 0) {
			return -1;
		}
		if ((int) $mo->status === (int) Mo::STATUS_DRAFT) {
			$validateResult = $mo->validate($user);
			if ($validateResult <= 0) {
				dol_syslog(__METHOD__ . " failed to validate MO #" . (int) $moId . " error=" . $mo->error, LOG_ERR);
				return -1;
			}
		}

        return (int) $moId;
    }

	private function hasMoExecutionLines($moId)
	{
		$moId = (int) $moId;
		if ($moId <= 0) {
			return null;
        }

        // JOIN with stock_mouvement so that orphaned execution lines (fk_stock_movement NULL
        // or pointing to a deleted movement) are NOT counted as "processed". This prevents the
        // dedupe guard from blocking re-execution when secondary movements were never committed.
        $sql = "SELECT COUNT(mp.rowid) AS nb"
            . " FROM " . MAIN_DB_PREFIX . "mrp_production AS mp"
            . " INNER JOIN " . MAIN_DB_PREFIX . "stock_mouvement AS sm ON sm.rowid = mp.fk_stock_movement"
            . " WHERE mp.fk_mo = " . $moId
            . " AND mp.role IN ('consumed','produced')";
        $resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " failed to verify execution lines: " . $this->db->lasterror(), LOG_ERR);
			return null;
        }

        $obj = $this->db->fetch_object($resql);
        return (is_object($obj) && ((int) $obj->nb > 0));
    }

	private function purgeOrphanedExecutionLinesForMo($moId)
	{
		$moId = (int) $moId;
		if ($moId <= 0) {
			return false;
        }

        $findSql = "SELECT mp.rowid"
            . " FROM " . MAIN_DB_PREFIX . "mrp_production AS mp"
            . " LEFT JOIN " . MAIN_DB_PREFIX . "stock_mouvement AS sm ON sm.rowid = mp.fk_stock_movement"
            . " WHERE mp.fk_mo = " . $moId
            . " AND mp.role IN ('consumed','produced')"
            . " AND (mp.fk_stock_movement IS NULL OR sm.rowid IS NULL)";
        $resql = $this->db->query($findSql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " failed to identify orphaned lines for MO #" . $moId . ": " . $this->db->lasterror(), LOG_ERR);
			return false;
        }

        $ids = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->rowid;
        }
        $this->db->free($resql);

		if (empty($ids)) {
			return true;
        }

        $delSql = "DELETE FROM " . MAIN_DB_PREFIX . "mrp_production"
            . " WHERE rowid IN (" . implode(',', $ids) . ")";
		if (!$this->db->query($delSql)) {
			dol_syslog(__METHOD__ . " failed to delete orphaned execution lines for MO #" . $moId . ": " . $this->db->lasterror(), LOG_ERR);
			return false;
        }

		dol_syslog(__METHOD__ . " purged " . count($ids) . " orphaned execution line(s) for MO #" . $moId, LOG_INFO);
		return true;
	}

	private function isMoValidated($moId): bool
	{
		$mo = new Mo($this->db);
		return $mo->fetch((int) $moId) > 0 && (int) $mo->status === (int) Mo::STATUS_VALIDATED;
	}

    private function buildMoPlannedLineMap($moId)
    {
        $map = [];
        $mo = new Mo($this->db);
        if ($mo->fetch((int) $moId) <= 0) {
            return $map;
        }

        foreach ((array) $mo->lines as $line) {
            $plannedRole = (string) ($line->role ?? '');
            $productId = (int) ($line->fk_product ?? 0);
            if (($plannedRole !== 'toconsume' && $plannedRole !== 'toproduce') || $productId <= 0) {
                continue;
            }
            if (!isset($map[$plannedRole])) {
                $map[$plannedRole] = [];
            }
            if (!isset($map[$plannedRole][$productId])) {
                $map[$plannedRole][$productId] = [];
            }
            $map[$plannedRole][$productId][] = (int) $line->id;
        }

        return $map;
    }

    private function popPlannedLineId(array &$plannedLineMap, $plannedRole, $productId)
    {
        $plannedRole = (string) $plannedRole;
        $productId = (int) $productId;
        if ($productId <= 0 || empty($plannedLineMap[$plannedRole][$productId])) {
            return 0;
        }

        $lineId = array_shift($plannedLineMap[$plannedRole][$productId]);
        return (int) $lineId;
    }

    private function recordMoExecutionLines($moId, array $movementRows, array $plannedLineMap, $user)
    {
        $moId = (int) $moId;
        if ($moId <= 0 || empty($movementRows)) {
            return false;
        }
        if (!is_object($user) || empty($user->id)) {
            dol_syslog(__METHOD__ . " unable to save execution lines (missing user context)", LOG_WARNING);
            return false;
        }

		foreach ($movementRows as $row) {
			$plannedLineId = $this->popPlannedLineId($plannedLineMap, (string) ($row['planned_role'] ?? ''), (int) ($row['product_id'] ?? 0));
			if ($plannedLineId <= 0) {
				dol_syslog(__METHOD__." missing planned MO line for product #".(int) ($row['product_id'] ?? 0), LOG_ERR);
				return false;
			}
			$executionLine = new MoLine($this->db);
            $executionLine->fk_mo = $moId;
            $executionLine->position = (int) ($row['position'] ?? 0);
            $executionLine->fk_product = (int) ($row['product_id'] ?? 0);
            $executionLine->fk_warehouse = ((int) ($row['fk_warehouse'] ?? 0) > 0 ? (int) $row['fk_warehouse'] : null);
            $executionLine->qty = (float) ($row['qty'] ?? 0);
            $executionLine->batch = '';
            $executionLine->role = (string) ($row['execution_role'] ?? '');
			$executionLine->fk_mrp_production = $plannedLineId;
            $executionLine->fk_stock_movement = ((int) ($row['fk_stock_movement'] ?? 0) > 0 ? (int) $row['fk_stock_movement'] : null);
            $executionLine->fk_user_creat = (int) $user->id;

            $createResult = $executionLine->create($user, 0);
            if ($createResult <= 0) {
                dol_syslog(__METHOD__ . " failed to create execution line for MO #" . $moId . " error=" . $executionLine->error, LOG_WARNING);
                return false;
            }
        }

        return true;
    }

	private function setMoProducedStatus($moId)
	{
		$moId = (int) $moId;
		if ($moId <= 0) {
			return false;
        }

        $mo = new Mo($this->db);
		if ($mo->fetch($moId) <= 0) {
			return false;
        }
		if ((int) $mo->status === (int) Mo::STATUS_PRODUCED) {
			return true;
        }

        $statusResult = $mo->setStatut(Mo::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED');
		if ($statusResult <= 0) {
			dol_syslog(__METHOD__ . " failed to close MO #" . $moId . " as produced", LOG_WARNING);
			return false;
		}
		return true;
	}

    private function findMoIdByImportKey($importKey)
    {
        $importKey = trim((string) $importKey);
        if ($importKey === '') {
            return 0;
        }

        $sql = "SELECT rowid"
            . " FROM " . MAIN_DB_PREFIX . "mrp_mo"
            . " WHERE import_key = '" . $this->db->escape($importKey) . "'"
            . " AND entity IN (" . getEntity('mo') . ")"
            . " ORDER BY rowid DESC"
            . " LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " failed to query existing MO by import key: " . $this->db->lasterror(), LOG_ERR);
			return -1;
        }

        $obj = $this->db->fetch_object($resql);
        if (is_object($obj) && !empty($obj->rowid)) {
            return (int) $obj->rowid;
        }

        return 0;
    }

    private function buildDismantleImportKey($originMovementId, $originType, $originId, $bomId, $movementDate)
    {
        $originMovementId = (int) $originMovementId;
        if ($originMovementId > 0) {
            return sprintf('KPDM%010d', $originMovementId); // 14 chars max (mrp_mo.import_key)
        }

        $movementMarker = is_numeric($movementDate) ? (string) ((int) $movementDate) : (string) $movementDate;
        $seed = (string) $originType . '|' . ((int) $originId) . '|' . ((int) $bomId) . '|' . $movementMarker;
        return 'KPDM' . strtoupper(substr(sha1($seed), 0, 10));
    }

    private function truncateMoLabel($label)
    {
        $label = trim((string) $label);
        if ($label === '') {
            return 'Auto dismantle';
        }
        if (strlen($label) > 255) {
            return substr($label, 0, 255);
        }
        return $label;
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

		ProductUpdater::prepareProductCostUpdate($product);
        $product->cost_price = $costPrice;

		if (empty($user) || empty($user->id)) {
			dol_syslog(__METHOD__." missing user context for product #".$productId." (".$context.")", LOG_ERR);
			return false;
		}

		$product->context = (array) $product->context;
		$hadSkipRealtimeSync = array_key_exists('skip_kreawoo_realtime_sync', $product->context);
		$previousSkipRealtimeSync = $hadSkipRealtimeSync ? $product->context['skip_kreawoo_realtime_sync'] : null;
		$product->context['skip_kreawoo_realtime_sync'] = true;
		try {
			$updateRes = $product->update($productId, $user);
		} finally {
			if ($hadSkipRealtimeSync) {
				$product->context['skip_kreawoo_realtime_sync'] = $previousSkipRealtimeSync;
			} else {
				unset($product->context['skip_kreawoo_realtime_sync']);
			}
		}
		if ($updateRes <= 0) {
			$detail = !empty($product->error) ? $product->error : 'unknown error';
			dol_syslog(__METHOD__." failed to update cost_price for product #".$productId." (".$context."): ".$detail, LOG_ERR);
			return false;
		}

		$verify = new Product($this->db);
		if ($verify->fetch($productId) <= 0 || !is_numeric($verify->cost_price)
			|| abs(((float) $verify->cost_price) - $costPrice) >= 0.0001
		) {
			dol_syslog(__METHOD__." cost_price verification failed for product #".$productId." (".$context.")", LOG_ERR);
			return false;
		}

		dol_syslog(__METHOD__." updated cost_price for product #".$productId." to ".$costPrice." (".$context.")", LOG_DEBUG);
		return true;
    }

	/**
	 * Make every automatic-dismantling MO product stock-managed before execution.
	 *
	 * @param Product $product Product participating in the MO
	 * @param User    $user    Update author
	 * @param string  $context Execution role
	 * @return bool
	 */
	private function ensureProductStockManagedForMo(Product $product, $user, string $context): bool
	{
		$productId = (int) $product->id;
		if ($productId <= 0 || empty($user) || empty($user->id)) {
			dol_syslog(__METHOD__." missing product or user context (".$context.")", LOG_ERR);
			return false;
		}

		$product->context = (array) $product->context;
		$hadSkipRealtimeSync = array_key_exists('skip_kreawoo_realtime_sync', $product->context);
		$previousSkipRealtimeSync = $hadSkipRealtimeSync ? $product->context['skip_kreawoo_realtime_sync'] : null;
		$product->context['skip_kreawoo_realtime_sync'] = true;
		$product->stockable_product = Product::ENABLED_STOCK;
		try {
			$updateResult = $product->update($productId, $user);
		} finally {
			if ($hadSkipRealtimeSync) {
				$product->context['skip_kreawoo_realtime_sync'] = $previousSkipRealtimeSync;
			} else {
				unset($product->context['skip_kreawoo_realtime_sync']);
			}
		}
		if ($updateResult <= 0) {
			dol_syslog(__METHOD__." failed to enable stock management for product #".$productId." (".$context."): ".($product->error ?: $this->db->lasterror()), LOG_ERR);
			return false;
		}

		$verify = new Product($this->db);
		if ($verify->fetch($productId) <= 0 || !$verify->isStockManaged()) {
			dol_syslog(__METHOD__." stock-management verification failed for product #".$productId." (".$context.")", LOG_ERR);
			return false;
		}
		$product->stockable_product = Product::ENABLED_STOCK;
		dol_syslog(__METHOD__." enabled stock management for MO product #".$productId." (".$context.")", LOG_INFO);
		return true;
	}

	private function updateProducedCostPrices(array $bomData, float $baseCostPrice, $user): bool
	{
		if (empty($bomData['lines']) || !is_array($bomData['lines'])) {
			return false;
        }

		$qtyPerProduct = [];
        foreach ($bomData['lines'] as $line) {
            $productId = (int) ($line->fk_product ?? 0);
            if ($productId <= 0) {
                continue;
            }
			$outputQty = (float) $line->qty;
			if ($outputQty <= 0) {
				continue;
            }
            if (!isset($qtyPerProduct[$productId])) {
                $qtyPerProduct[$productId] = 0.0;
            }
			$qtyPerProduct[$productId] += $outputQty;
        }

		$totalProducedQty = array_sum($qtyPerProduct);
		if ($totalProducedQty <= 0) {
			return false;
		}

		$unitCost = $baseCostPrice / $totalProducedQty;
		foreach ($qtyPerProduct as $productId => $qty) {
			if ($qty <= 0) {
				continue;
			}
			if ($unitCost <= 0) {
				return false;
            }
			if (!$this->isProductAvailable((int) $productId)) {
				dol_syslog(__METHOD__." dismantle output is outside the active product entity scope: ".(int) $productId, LOG_ERR);
				return false;
			}
			$product = new Product($this->db);
			if ($product->fetch($productId) <= 0) {
				dol_syslog(__METHOD__ . " failed to load product #" . (int) $productId . " for cost update", LOG_WARNING);
				return false;
			}
			if (!$this->persistCostPrice($product, $unitCost, $user, 'post-dismantle')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param int $warehouseId Warehouse ID
	 * @return bool
	 */
	private function isWarehouseAvailable($warehouseId): bool
	{
		$warehouseId = (int) $warehouseId;
		if ($warehouseId <= 0) {
			return false;
		}
		$sql = 'SELECT e.rowid FROM '.MAIN_DB_PREFIX.'entrepot as e';
		$sql .= ' WHERE e.rowid = '.$warehouseId;
		$sql .= ' AND e.entity IN ('.getEntity('stock').')';
		$sql .= ' AND e.statut = 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return (bool) $obj;
	}

	/**
	 * @param int $productId Product ID
	 * @return bool
	 */
	private function isProductAvailable($productId): bool
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return false;
		}
		$sql = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.'product as p';
		$sql .= ' WHERE p.rowid = '.$productId;
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return (bool) $obj;
	}
}
