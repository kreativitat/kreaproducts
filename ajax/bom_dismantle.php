<?php
/* Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 *
 * This program is dual-licensed under the GNU General Public License (GPL) v3.0 and a proprietary license.
 *
 * GPL-3.0 License:
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
 * Proprietary License:
 * For commercial use, support, or if you prefer not to disclose your source code modifications,
 * please contact Kreativitat at <mail@kreativitat.com> for information on purchasing a proprietary license.
 *
 * For more information, visit <https://www.kreativitat.com>.
 */

/**
 *       \file       htdocs/kreaproducts/ajax/bom_dismantle.php
 *       \brief      Fetch BOM entity and dismantle settings for UI helpers
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1); // Disables token renewal
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
$langs->loadLangs(array('other', 'kreaproducts@kreaproducts'));

$token = GETPOST('token', 'alpha');
if (!empty($token) && !hash_equals(currentToken(), $token)) {
	top_httphead('application/json');
	http_response_code(403);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('KreapInvalidCsrfToken')));
	$db->close();
	exit;
}

$bomId = GETPOSTINT('id');
if ($bomId <= 0) {
	top_httphead('application/json');
	http_response_code(400);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('KreapInvalidBomId')));
	$db->close();
	exit;
}

// @phan-suppress-next-line PhanUndeclaredClass
$object = new BOM($db);
$result = $object->fetch($bomId);
if ($result <= 0) {
	top_httphead('application/json');
	http_response_code(404);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('KreapBomNotFound')));
	$db->close();
	exit;
}

// Security check
if (!$user->hasRight('bom', 'read') && !$user->hasRight('bom', 'lire')) {
	accessforbidden();
}

$entityId = isset($object->entity) ? (int) $object->entity : 0;
$entityList = getEntity('bom');
$entityIds = preg_split('/\s*,\s*/', (string) $entityList, -1, PREG_SPLIT_NO_EMPTY);
$entityIds = array_map('intval', $entityIds);
if ($entityId !== 0 && !in_array($entityId, $entityIds, true)) {
	accessforbidden();
}

$productId = (int) $object->fk_product;
$dismantleValue = 0;
if ($productId > 0) {
	$sql = "SELECT kreap_dismantle FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . $productId . " LIMIT 1";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && $obj->kreap_dismantle !== null) {
			$dismantleValue = (int) $obj->kreap_dismantle;
		}
		$db->free($resql);
	}
}

$entityLabel = $langs->trans('Entity');
$allEntitiesLabel = $langs->trans('AllEntities');
$entityValue = ($entityId === 0)
	? $allEntitiesLabel . ' (0)'
	: $entityLabel . ' ' . $entityId;
$dismantleLabel = dol_html_entity_decode($langs->trans('kreap_dismantle'), ENT_QUOTES | ENT_HTML5);

$data = array(
	'status' => 'success',
	'entity' => $entityId,
	'entity_label' => $entityLabel,
	'entity_value' => $entityValue,
	'dismantle_label' => $dismantleLabel,
	'dismantle_value' => $dismantleValue,
	'product_id' => $productId,
);

// View
top_httphead('application/json');
print json_encode($data);

$db->close();
