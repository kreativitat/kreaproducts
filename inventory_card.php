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
 * \file       inventory_card.php
 * \ingroup    kreaproducts
 * \brief      Compatibility redirect to the unified inventory workflow
 */

$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) {
	$res = @include_once __DIR__.'/../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = @include_once __DIR__.'/../../main.inc.php';
}
if (!$res) {
	die('Failed to include main.inc.php');
}

/**
 * @var Conf   $conf
 * @var DoliDB $db
 * @var User   $user
 */

if ($user->socid > 0) {
	accessforbidden();
}

$id = GETPOSTINT('id');
if ($id > 0) {
	$sql = 'SELECT ref FROM '.MAIN_DB_PREFIX.'inventory';
	$sql .= ' WHERE rowid='.$id.' AND entity='.((int) $conf->entity);
	$resql = $db->query($sql);
	$row = $resql ? $db->fetch_object($resql) : false;
	if ($resql) {
		$db->free($resql);
	}
	if ($row && preg_match('/^(?:KPS|KS)-/', (string) $row->ref) !== 1) {
		header('Location: '.DOL_URL_ROOT.'/product/inventory/card.php?id='.$id);
		exit;
	}
}

$target = dol_buildpath('/custom/kreaproducts/inventory.php', 1);
if ($id > 0) {
	$target .= '?id='.$id;
}
header('Location: '.$target);
exit;
