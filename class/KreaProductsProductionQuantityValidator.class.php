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
 * Pure quantity validation for manufacturing-order production requests.
 */
class KreaProductsProductionQuantityValidator
{
	/**
	 * Require one requested component quantity to equal its planned MO quantity.
	 *
	 * @param mixed $plannedQuantity   Planned manufacturing-order line quantity
	 * @param mixed $requestedQuantity Requested component trace quantity
	 * @return bool
	 */
	public static function matchesRecipeQuantity($plannedQuantity, $requestedQuantity)
	{
		if (!is_numeric($plannedQuantity) || !is_numeric($requestedQuantity)) {
			return false;
		}

		$planned = (float) price2num((float) $plannedQuantity, 'MS');
		$requested = (float) price2num((float) $requestedQuantity, 'MS');
		return $requested === $planned;
	}
}
