<?php
/* Copyright (C) 2025		Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    lib/kreaproducts_productallergens.lib.php
 * \ingroup kreaproducts
 * \brief   Library files with common functions for ProductAllergens
 */

/**
 * Prepare array of tabs for ProductAllergens
 *
 * @param	ProductAllergens	$object					ProductAllergens
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function productallergensPrepareHead($object)
{
	global $langs, $conf;

	$langs->load("kreaproducts@kreaproducts");

	$h = 0;
	$head = array();

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'productallergens@kreaproducts');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'productallergens@kreaproducts', 'remove');

	return $head;
}
