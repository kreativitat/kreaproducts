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

require_once __DIR__.'/../lib/kreaproducts.lib.php';

/**
 * Authorize interactive edits independently of internal calculation writes.
 */
class KreaProductsNutritionEditPolicy
{
	/**
	 * Read saved modes and food status through the visible parent product.
	 *
	 * @param DoliDB $db Database handler
	 * @param int $productId Parent product ID
	 * @return bool Whether interactive manual edits are allowed
	 */
	public static function canEdit($db, $productId)
	{
		if ((int) $productId <= 0) {
			return false;
		}
		$sql = 'SELECT pe.kreap_calc_nut, pe.kreap_calc_allergens, n.is_food';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product AS p';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_extrafields AS pe ON pe.fk_object = p.rowid';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'kreaproducts_nutritional AS n ON n.fk_product = p.rowid';
		$sql .= ' WHERE p.rowid = '.((int) $productId);
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' failed to read nutrition edit policy: '.$db->lasterror(), LOG_ERR);
			return false;
		}
		$found = false;
		$allowed = true;
		while ($row = $db->fetch_object($resql)) {
			$found = true;
			if (kreaproducts_resolve_nutrition_allergen_mode(
				$row->kreap_calc_nut,
				$row->kreap_calc_allergens,
				$row->is_food === null ? 1 : $row->is_food
			) !== 0) {
				$allowed = false;
			}
		}
		$db->free($resql);
		return $found && $allowed;
	}
}
