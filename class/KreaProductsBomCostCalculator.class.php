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

/**
 * Pure BOM cost quantity calculations shared by runtime code and tests.
 */
class KreaProductsBomCostCalculator
{
	/**
	 * Select the active manufacturing BOM used by an automatic cost cascade.
	 *
	 * A BOM with completed production always takes precedence over a BOM that
	 * has never been produced. When several BOMs have production history, the
	 * most recently recorded production wins. If none has production history,
	 * the newest validated BOM is selected without requiring manual input.
	 *
	 * @param array<int,array{id:int,date_valid:string,date_creation:string}> $candidates Active BOM candidates in the effective entity scope
	 * @param array<int,array{date:string,rowid:int}> $latestProductionByBomId Latest completed production by BOM ID
	 * @return int Selected BOM ID, or 0 when there is no candidate
	 */
	public static function selectPreferredBomId(array $candidates, array $latestProductionByBomId)
	{
		if (empty($candidates)) {
			return 0;
		}

		usort($candidates, function ($left, $right) use ($latestProductionByBomId) {
			$leftId = isset($left['id']) ? (int) $left['id'] : 0;
			$rightId = isset($right['id']) ? (int) $right['id'] : 0;
			$leftProduction = isset($latestProductionByBomId[$leftId]) ? $latestProductionByBomId[$leftId] : null;
			$rightProduction = isset($latestProductionByBomId[$rightId]) ? $latestProductionByBomId[$rightId] : null;

			if ($leftProduction !== null && $rightProduction === null) {
				return -1;
			}
			if ($leftProduction === null && $rightProduction !== null) {
				return 1;
			}
			if ($leftProduction !== null && $rightProduction !== null) {
				$productionDateComparison = strcmp((string) $rightProduction['date'], (string) $leftProduction['date']);
				if ($productionDateComparison !== 0) {
					return $productionDateComparison;
				}

				$productionRowComparison = ((int) $rightProduction['rowid']) <=> ((int) $leftProduction['rowid']);
				if ($productionRowComparison !== 0) {
					return $productionRowComparison;
				}
			}

			$leftActivationDate = !empty($left['date_valid']) ? (string) $left['date_valid'] : (string) ($left['date_creation'] ?? '');
			$rightActivationDate = !empty($right['date_valid']) ? (string) $right['date_valid'] : (string) ($right['date_creation'] ?? '');
			$activationDateComparison = strcmp($rightActivationDate, $leftActivationDate);
			if ($activationDateComparison !== 0) {
				return $activationDateComparison;
			}

			$creationDateComparison = strcmp((string) ($right['date_creation'] ?? ''), (string) ($left['date_creation'] ?? ''));
			if ($creationDateComparison !== 0) {
				return $creationDateComparison;
			}

			return $rightId <=> $leftId;
		});

		return isset($candidates[0]['id']) ? (int) $candidates[0]['id'] : 0;
	}

	/**
	 * Return the first cycle found in a directed product graph.
	 *
	 * The returned path repeats the first product at the end so logs show the
	 * complete invalid relationship, for example 12 -> 34 -> 12.
	 *
	 * @param array<int,array<int,int>> $childrenByProduct Child product IDs by parent product ID
	 * @return array<int,int> Empty when the graph is acyclic
	 */
	public static function findCycle(array $childrenByProduct)
	{
		$states = array();
		$path = array();
		$pathPositions = array();

		$visit = function ($productId) use (&$visit, &$states, &$path, &$pathPositions, $childrenByProduct) {
			$productId = (int) $productId;
			if (($states[$productId] ?? 0) === 1) {
				$cycleStart = isset($pathPositions[$productId]) ? (int) $pathPositions[$productId] : 0;
				$cycle = array_slice($path, $cycleStart);
				$cycle[] = $productId;
				return $cycle;
			}
			if (($states[$productId] ?? 0) === 2) {
				return array();
			}

			$states[$productId] = 1;
			$pathPositions[$productId] = count($path);
			$path[] = $productId;

			$children = isset($childrenByProduct[$productId]) && is_array($childrenByProduct[$productId])
				? $childrenByProduct[$productId]
				: array();
			foreach ($children as $childId) {
				$cycle = $visit((int) $childId);
				if (!empty($cycle)) {
					return $cycle;
				}
			}

			array_pop($path);
			unset($pathPositions[$productId]);
			$states[$productId] = 2;
			return array();
		};

		$productIds = array();
		foreach ($childrenByProduct as $parentId => $children) {
			$productIds[(int) $parentId] = (int) $parentId;
			if (!is_array($children)) {
				continue;
			}
			foreach ($children as $childId) {
				$productIds[(int) $childId] = (int) $childId;
			}
		}

		foreach ($productIds as $productId) {
			$cycle = $visit($productId);
			if (!empty($cycle)) {
				return array_map('intval', $cycle);
			}
		}

		return array();
	}

	/**
	 * Return the component quantity required for one produced parent unit.
	 *
	 * This follows Dolibarr BOM::calculateCosts(): line cost is divided by
	 * line efficiency, then the total BOM cost is divided by header quantity.
	 *
	 * @param float $lineQuantity  BOM line quantity
	 * @param float $lineEfficiency BOM line efficiency
	 * @param float $headerQuantity BOM output quantity
	 * @return float
	 */
	public static function normalizeLineQuantity($lineQuantity, $lineEfficiency, $headerQuantity)
	{
		$lineEfficiency = (float) $lineEfficiency;
		$headerQuantity = (float) $headerQuantity;
		if ($lineEfficiency <= 0) {
			$lineEfficiency = 1.0;
		}
		if ($headerQuantity <= 0) {
			$headerQuantity = 1.0;
		}

		return (float) $lineQuantity / $lineEfficiency / $headerQuantity;
	}
}
