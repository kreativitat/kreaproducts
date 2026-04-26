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
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */

require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';

class KreaProductsInventoryService
{
	public function prefillInventoryLinesAtCreate($inventory, $db, $user)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		if (empty($inventory->id)) {
			return 0;
		}

		$sqlCheck = 'SELECT COUNT(*) as cnt FROM ' . MAIN_DB_PREFIX . 'inventorydet WHERE fk_inventory=' . (int) $inventory->id;
		$resCheck = $db->query($sqlCheck);
		if (! $resCheck) {
			dol_syslog(__METHOD__ . " Error checking inventory lines: " . $db->lasterror(), LOG_ERR);
			return -1;
		}
		$check = $db->fetch_object($resCheck);
		if ($check && (int) $check->cnt > 0) {
			return 0;
		}

		$inventoryAnchor = !empty($inventory->date_inventory) ? $inventory->date_inventory : null;
		if (!empty($inventoryAnchor) && !is_numeric($inventoryAnchor)) {
			$inventoryAnchor = dol_stringtotime($inventoryAnchor);
		}

		$warehouseIds = array();
		if (!empty($inventory->fk_warehouse)) {
			$warehouseIds[] = (int) $inventory->fk_warehouse;
			if (getDolGlobalInt('INVENTORY_INCLUDE_SUB_WAREHOUSE')) {
				$TChildWarehouses = array();
				$inventory->getChildWarehouse($inventory->fk_warehouse, $TChildWarehouses);
				if (!empty($TChildWarehouses)) {
					foreach ($TChildWarehouses as $childId) {
						$warehouseIds[] = (int) $childId;
					}
				}
			}
		}
		$warehouseIds = array_values(array_unique($warehouseIds));

		$inventoryAnchorDate = '';
		if (!empty($inventoryAnchor)) {
			$inventoryAnchorDate = $db->idate($inventoryAnchor);
		}
		$movedCache = array();

		$sql = "SELECT ps.rowid, ps.fk_entrepot as fk_warehouse, ps.fk_product, ps.reel,";
		if (isModEnabled('productbatch')) {
			$sql .= " COALESCE(pb.batch, '') as batch, pb.qty as qty,";
		} else {
			$sql .= " '' as batch, 0 as qty,";
		}
		$sql .= " p.ref, p.tobatch";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_stock as ps";
		if (isModEnabled('productbatch')) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_batch as pb ON pb.fk_product_stock = ps.rowid";
		}
		$sql .= ", " . MAIN_DB_PREFIX . "product as p, " . MAIN_DB_PREFIX . "entrepot as e";
		$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
		$sql .= " AND ps.fk_product = p.rowid AND ps.fk_entrepot = e.rowid";
		if (!getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
			$sql .= " AND p.fk_product_type = 0";
		}
		if (!empty($inventory->fk_product)) {
			$sql .= " AND ps.fk_product = " . (int) $inventory->fk_product;
		}
		if (!empty($warehouseIds)) {
			$sql .= " AND ps.fk_entrepot IN (" . $db->sanitize(implode(',', $warehouseIds)) . ")";
		}
		if (!empty($inventory->categories_product)) {
			$sql .= " AND EXISTS (";
			$sql .= " SELECT cp.fk_product";
			$sql .= " FROM " . MAIN_DB_PREFIX . "categorie_product AS cp";
			$sql .= " WHERE cp.fk_product = ps.fk_product";
			$sql .= " AND cp.fk_categorie IN (" . $db->sanitize($inventory->categories_product) . ")";
			$sql .= ")";
		}
		if (getDolGlobalInt('PRODUIT_SOUSPRODUITS')) {
			$sql .= " AND NOT EXISTS (";
			$sql .= " SELECT pa.rowid";
			$sql .= " FROM " . MAIN_DB_PREFIX . "product_association as pa";
			$sql .= " WHERE pa.fk_product_pere = ps.fk_product";
			$sql .= ")";
		}
		$sql .= " ORDER BY p.rowid";

		$resql = $db->query($sql);
		if (! $resql) {
			dol_syslog(__METHOD__ . " Error prefill inventory lines: " . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$stockRows = array();
		$productIds = array();
		$warehouseIdsForMoveLookup = array();
		while ($obj = $db->fetch_object($resql)) {
			$stockRows[] = $obj;
			$productIds[(int) $obj->fk_product] = (int) $obj->fk_product;
			$warehouseIdsForMoveLookup[(int) $obj->fk_warehouse] = (int) $obj->fk_warehouse;
		}
		$db->free($resql);

		if (!empty($inventoryAnchorDate) && !empty($stockRows)) {
			$movedCache = $this->loadMovedQuantitiesAfterDate($db, $inventoryAnchorDate, array_values($productIds), array_values($warehouseIdsForMoveLookup));
		}

		$error = 0;
		foreach ($stockRows as $obj) {
			$inventoryline = new InventoryLine($db);
			$inventoryline->fk_inventory = $inventory->id;
			$inventoryline->fk_warehouse = $obj->fk_warehouse;
			$inventoryline->fk_product = $obj->fk_product;
			$inventoryline->batch = $obj->batch;
			$inventoryline->datec = dol_now();

			if (isModEnabled('productbatch')) {
				if ($obj->batch && empty($obj->tobatch)) {
					$error++;
					dol_syslog(__METHOD__ . " Product ID=" . $obj->ref . " has batch stock but is not batch-managed", LOG_ERR);
					break;
				}
				$currentstock = ($obj->batch ? $obj->qty : $obj->reel);
			} else {
				$currentstock = $obj->reel;
			}
			if (!empty($inventoryAnchorDate)) {
				$batchKey = (string) $obj->batch;
				$cacheKey = $obj->fk_product . '|' . $obj->fk_warehouse . '|' . $batchKey;
				$inventoryline->qty_stock = (float) $currentstock - (float) ($movedCache[$cacheKey] ?? 0.0);
			} else {
				$inventoryline->qty_stock = $currentstock;
			}

			if ($inventoryline->create($user) <= 0) {
				$error++;
				dol_syslog(__METHOD__ . " Error creating inventory line: " . $inventoryline->error, LOG_ERR);
				break;
			}
		}

		if ($error) {
			return -1;
		}

		if ((int) $inventory->status === Inventory::STATUS_DRAFT) {
			$result = $inventory->setStatut(Inventory::STATUS_VALIDATED, null, '', 'INVENTORY_VALIDATED');
			if ($result < 0) {
				dol_syslog(__METHOD__ . " Error setting inventory to started: " . $inventory->error, LOG_ERR);
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Load post-anchor movements for all prefilled inventory lines in one grouped query.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $inventoryAnchorDate SQL datetime used as the inventory value date
	 * @param int[] $productIds Candidate product ids
	 * @param int[] $warehouseIds Candidate warehouse ids
	 * @return array<string,float>
	 */
	private function loadMovedQuantitiesAfterDate($db, $inventoryAnchorDate, array $productIds, array $warehouseIds)
	{
		$productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
		$warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));
		if (empty($inventoryAnchorDate) || empty($productIds) || empty($warehouseIds)) {
			return array();
		}

		$movedCache = array();
		foreach (array_chunk($productIds, 500) as $productChunk) {
			$sqlMoved = "SELECT fk_product, fk_entrepot, COALESCE(batch, '') as batch_key, COALESCE(SUM(value), 0) as moved";
			$sqlMoved .= " FROM " . MAIN_DB_PREFIX . "stock_mouvement";
			$sqlMoved .= " WHERE fk_product IN (" . implode(',', $productChunk) . ")";
			$sqlMoved .= " AND fk_entrepot IN (" . implode(',', $warehouseIds) . ")";
			$sqlMoved .= " AND datem > '" . $db->escape($inventoryAnchorDate) . "'";
			$sqlMoved .= " AND origintype <> 'inventory'";
			$sqlMoved .= " GROUP BY fk_product, fk_entrepot, COALESCE(batch, '')";

			$resMoved = $db->query($sqlMoved);
			if (!$resMoved) {
				dol_syslog(__METHOD__ . " Error loading stock movements: " . $db->lasterror(), LOG_ERR);
				continue;
			}

			while ($obj = $db->fetch_object($resMoved)) {
				$cacheKey = (int) $obj->fk_product . '|' . (int) $obj->fk_entrepot . '|' . (string) $obj->batch_key;
				$movedCache[$cacheKey] = (float) $obj->moved;
			}
			$db->free($resMoved);
		}

		return $movedCache;
	}

	public function renameInventoryHeaderRef($inventory, $db)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		// 1) Grab existing ref & date
		$oldRef = trim($inventory->ref);
		$date   = $inventory->date_inventory;
		if (empty($oldRef) || empty($date)) {
			dol_syslog(__METHOD__ . " nothing to do (empty ref or date)", LOG_DEBUG);
			return;
		}

		// 2) Normalize into a DateTime object (handle both timestamps and DATETIME strings)
		if (preg_match('/^\\d+$/', (string)$date)) {
			// Pure integer: treat as Unix timestamp
			$dt = new DateTime();
			$dt->setTimestamp((int)$date);
		} else {
			// DATETIME string e.g. '2025-05-08 19:00:00'
			try {
				$dt = new DateTime($date);
			} catch (Exception $e) {
				dol_syslog(__METHOD__ . " failed to parse date_inventory='{$date}': " . $e->getMessage(), LOG_ERR);
				return;
			}
		}
		$prefix = $dt->format('Ymd');

		// 3) Decide suffix based on selected inventory category (under configured root)
		$categoryLabel = $this->getInventoryCategoryLabel($inventory, $db);
		if ($categoryLabel === '') {
			dol_syslog(__METHOD__ . " no inventory category label available for '{$oldRef}'", LOG_DEBUG);
			return;
		}
		$suffix = $this->normalizeCategorySuffix($categoryLabel);
		if ($suffix === '') {
			dol_syslog(__METHOD__ . " empty suffix after normalization for '{$oldRef}'", LOG_DEBUG);
			return;
		}

		// 4) Build newRef and ensure uniqueness within the same entity
		$baseRef = $prefix . '_' . $suffix;
		$entity = isset($inventory->entity) ? (int) $inventory->entity : (int) $GLOBALS['conf']->entity;
		$sql = 'SELECT ref FROM ' . MAIN_DB_PREFIX . 'inventory'
			. " WHERE entity = " . $entity
			. " AND rowid <> " . (int) $inventory->id
			. " AND ref LIKE '" . $db->escape($baseRef) . "%'";
		$resql = $db->query($sql);
		$hasAny = false;
		$maxVersion = 1;
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$hasAny = true;
				if ($obj->ref === $baseRef) {
					$maxVersion = max($maxVersion, 1);
				} elseif (preg_match('/^' . preg_quote($baseRef, '/') . '_V(\\d+)$/', $obj->ref, $m)) {
					$maxVersion = max($maxVersion, (int) $m[1]);
				}
			}
		} else {
			dol_syslog(__METHOD__ . " Error checking existing refs: " . $db->lasterror(), LOG_ERR);
			return;
		}

		$newRef = $hasAny ? ($baseRef . '_V' . ($maxVersion + 1)) : $baseRef;
		if ($newRef === $oldRef) {
			dol_syslog(__METHOD__ . " newRef '{$newRef}' is identical to oldRef, skipping", LOG_DEBUG);
			return;
		}

		// 5) Persist directly to DB
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'inventory'
			. ' SET ref = \'' . $db->escape($newRef) . '\''
			. ' WHERE rowid = ' . (int)$inventory->id;
		if (! $db->query($sql)) {
			dol_syslog(__METHOD__ . " Error renaming inventory #{$inventory->id}: " . $db->lasterror(), LOG_ERR);
			return;
		}
		dol_syslog(__METHOD__ . " Renamed inventory #{$inventory->id} '{$oldRef}' -> '{$newRef}'", LOG_INFO);
	}

	private function getInventoryCategoryLabel($inventory, $db)
	{
		$categoryIds = $this->parseCategoryIds($inventory->categories_product ?? '');
		if (empty($categoryIds)) {
			return '';
		}

		$rootCategoryId = (int) getDolGlobalInt('KREAPRODUCTS_INVENTORY_CATEGORY_ROOT');
		if ($rootCategoryId > 0) {
			require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
			$cat = new Categorie($db);
			$tree = $cat->get_full_arbo(Categorie::TYPE_PRODUCT, $rootCategoryId, 1);
			$allowedIds = array($rootCategoryId);
			if (is_array($tree)) {
				foreach ($tree as $info) {
					$catId = (int) ($info['id'] ?? 0);
					if ($catId > 0 && $catId !== $rootCategoryId) {
						$allowedIds[] = $catId;
					}
				}
			}
			$allowedIds = array_values(array_unique($allowedIds));
			$categoryIds = array_values(array_intersect($categoryIds, $allowedIds));
			if (empty($categoryIds)) {
				return '';
			}
		}

		sort($categoryIds, SORT_NUMERIC);
		$categoryId = (int) ($categoryIds[0] ?? 0);
		if ($categoryId <= 0) {
			return '';
		}

		$sql = 'SELECT label FROM ' . MAIN_DB_PREFIX . 'categorie';
		$sql .= ' WHERE rowid = ' . $categoryId;
		$sql .= ' AND entity IN (' . getEntity('category') . ')';
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			return $obj ? (string) $obj->label : '';
		}
		dol_syslog(__METHOD__ . " Error fetching category label: " . $db->lasterror(), LOG_ERR);
		return '';
	}

	private function parseCategoryIds($raw)
	{
		if (empty($raw)) {
			return array();
		}
		if (is_array($raw)) {
			$ids = $raw;
		} else {
			$ids = preg_split('/[^0-9]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
		}
		$ids = array_values(array_filter(array_map('intval', $ids)));
		return array_values(array_unique($ids));
	}

	private function normalizeCategorySuffix($label)
	{
		$label = trim((string) $label);
		if ($label === '') {
			return '';
		}
		$label = preg_replace('/\\s*\\([^)]*\\)/', '', $label);
		$label = dol_string_unaccent($label);
		$label = strtoupper($label);
		$label = preg_replace('/[^A-Z0-9]+/', '_', $label);
		return trim($label, '_');
	}
}
