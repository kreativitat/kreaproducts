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
 * Pure stock-ledger calculations used by delayed physical inventories.
 */
class KreaProductsInventoryLedgerCalculator
{
	public const INVENTORY_ORIGIN = 'inventory';
	public const REVERSAL_ORIGIN = 'kreaproducts_inventory_reversal';
	public const REINSTATEMENT_ORIGIN = 'kreaproducts_inventory_reinstatement';
	public const REBASE_ORIGIN = 'kreaproducts_inventory_rebase';
	public const REBASE_REVERSAL_ORIGIN = 'kreaproducts_inventory_rebase_reversal';
	public const COUNT_CORRECTION_ORIGIN = 'kreaproducts_count_correction';
	public const COUNT_CORRECTION_REVERSAL_ORIGIN = 'kreaproducts_count_correction_reversal';

	/**
	 * @param float $currentQuantity   Current warehouse stock
	 * @param float $postValueQuantity Net operational movements after the value date
	 * @return float
	 */
	public static function expectedQuantityAtValueDate($currentQuantity, $postValueQuantity)
	{
		return (float) price2num(((float) $currentQuantity) - ((float) $postValueQuantity), 'MS');
	}

	/**
	 * @param float $countedQuantity  Physical quantity
	 * @param float $expectedQuantity Reconstructed quantity at the value date
	 * @return float
	 */
	public static function adjustmentQuantity($countedQuantity, $expectedQuantity)
	{
		return (float) price2num(((float) $countedQuantity) - ((float) $expectedQuantity), 'MS');
	}

	/**
	 * Reconstruct stock at a target clock from an immutable inventory anchor.
	 *
	 * @param float $anchorQuantity     Stock stored at the inventory anchor
	 * @param float $movementQuantity   Net operational movement between target and anchor
	 * @param int   $targetTimestamp    Requested stock timestamp
	 * @param int   $anchorTimestamp    Inventory anchor timestamp
	 * @return float
	 */
	public static function quantityAtTimestampFromAnchor($anchorQuantity, $movementQuantity, $targetTimestamp, $anchorTimestamp)
	{
		if ((int) $targetTimestamp === (int) $anchorTimestamp) {
			return (float) price2num((float) $anchorQuantity, 'MS');
		}

		$direction = (int) $targetTimestamp < (int) $anchorTimestamp ? -1 : 1;
		return (float) price2num(((float) $anchorQuantity) + ($direction * (float) $movementQuantity), 'MS');
	}

	/**
	 * @return string[]
	 */
	public static function excludedMovementOrigins()
	{
		return array(
			self::INVENTORY_ORIGIN,
			self::REVERSAL_ORIGIN,
			self::REINSTATEMENT_ORIGIN,
			self::REBASE_ORIGIN,
			self::REBASE_REVERSAL_ORIGIN,
			self::COUNT_CORRECTION_ORIGIN,
			self::COUNT_CORRECTION_REVERSAL_ORIGIN,
		);
	}

	/**
	 * Determine whether a product has independently maintained operational stock.
	 *
	 * @param bool $subproductMovesEnabled Dolibarr composed-product stock mode
	 * @param bool $parentMovesEnabled     Parent stock movement option
	 * @param int  $childCount             Number of stock-linked children
	 * @return bool
	 */
	public static function isIndependentStockItem($subproductMovesEnabled, $parentMovesEnabled, $childCount)
	{
		return !$subproductMovesEnabled || $parentMovesEnabled || (int) $childCount <= 0;
	}

	/**
	 * Normalize a physical count while preserving blank as not counted.
	 *
	 * @param mixed $value Submitted quantity
	 * @return float|null
	 * @throws InvalidArgumentException
	 */
	public static function normalizePhysicalCount($value)
	{
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		if (!is_numeric($value)) {
			throw new InvalidArgumentException('Count quantity must be numeric or blank.');
		}
		$quantity = (float) price2num((string) $value, 'MS');
		if ($quantity < 0 || !is_finite($quantity)) {
			throw new InvalidArgumentException('Count quantity cannot be negative.');
		}
		return $quantity;
	}
}
