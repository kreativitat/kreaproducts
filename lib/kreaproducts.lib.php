<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
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

/**
 * \file    kreaproducts/lib/kreaproducts.lib.php
 * \ingroup kreaproducts
 * \brief   Library files with common functions for KreaProducts
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function kreaproductsAdminPrepareHead()
{
	global $db, $langs, $conf, $user;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("kreaproducts@kreaproducts");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/kreaproducts/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/kreaproducts/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['myobject']['label']) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/kreaproducts/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;


	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreaproducts@kreaproducts');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreaproducts@kreaproducts', 'remove');

	return $head;
}

/**
 * Normalize a Dolibarr product weight-unit scale.
 *
 * Dolibarr stores weight units as powers around kilograms, so scale 0 is a
 * valid kilogram value. The native measuring-unit selector currently submits
 * an empty value for kilograms because CUnits::fetchAll() maps scale 0 to null.
 *
 * @param mixed $weightUnit    Submitted or stored unit scale
 * @param mixed $fallbackScale Unit scale used when the value is missing or invalid
 * @return int
 */
function kreaproducts_normalize_weight_unit_scale($weightUnit, $fallbackScale = 0)
{
	$fallbackScale = is_numeric($fallbackScale) ? (int) $fallbackScale : 0;
	if (!is_scalar($weightUnit)) {
		return $fallbackScale;
	}

	$weightUnit = trim((string) $weightUnit);
	if ($weightUnit === '') {
		return 0;
	}
	if (!preg_match('/^-?\d+$/D', $weightUnit)) {
		return $fallbackScale;
	}

	return (int) $weightUnit;
}

/**
 * Resolve the value expected by Dolibarr's scale-based weight selector.
 *
 * @param mixed $weightUnit Stored or submitted unit scale
 * @return string
 */
function kreaproducts_weight_unit_select_value($weightUnit)
{
	$weightUnit = kreaproducts_normalize_weight_unit_scale($weightUnit);

	return $weightUnit === 0 ? '' : (string) $weightUnit;
}
