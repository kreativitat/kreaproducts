<?php
/* Copyright (C) 2022 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *       \file       htdocs/kreaproducts/ajax/allergens.php
 *       \brief      File to return Ajax response on product list request
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
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
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
dol_include_once('/kreaproducts/class/allergens.class.php');
$langs->loadLangs(array('other', 'kreaproducts@kreaproducts'));

$token = GETPOST('token', 'alpha');
if (empty($token) || !hash_equals(currentToken(), $token)) {
	top_httphead('application/json');
	http_response_code(403);
	print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidCsrfToken')]);
	$db->close();
	exit;
}

$mode = GETPOST('mode', 'aZ09');
$objectId = GETPOSTINT('objectId');
$field = GETPOST('field', 'alphanohtml');
$valueRaw = GETPOST('value', 'restricthtml');

// @phan-suppress-next-line PhanUndeclaredClass
$object = new Allergens($db);

// Security check
if (!$user->hasRight('kreaproducts', 'allergens', 'write')) {
	accessforbidden();
}

/*
 * View
 */

dol_syslog("Call ajax kreaproducts/ajax/allergens.php");

top_httphead('application/json');

$allowedFields = array(
	'code' => 'string',
	'label' => 'string',
	'icon' => 'string',
	'active' => 'int',
);

if ($objectId <= 0) {
	http_response_code(400);
	print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidObjectId')]);
	$db->close();
	exit;
}

if (empty($field) || !isset($allowedFields[$field])) {
	http_response_code(400);
	print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidField')]);
	$db->close();
	exit;
}

$valueRaw = trim((string) $valueRaw);
$value = null;
if ($valueRaw !== '') {
	switch ($field) {
		case 'active':
			if (!preg_match('/^\d+$/', $valueRaw)) {
				http_response_code(400);
				print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidIntegerValue')]);
				$db->close();
				exit;
			}
			$value = (int) $valueRaw;
			break;
		case 'code':
			if (!preg_match('/^[A-Za-z0-9]{1,5}$/', $valueRaw)) {
				http_response_code(400);
				print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidCodeValue')]);
				$db->close();
				exit;
			}
			$value = $valueRaw;
			break;
		case 'icon':
			if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $valueRaw)) {
				http_response_code(400);
				print json_encode(['status' => 'error', 'message' => $langs->trans('KreapInvalidIconValue')]);
				$db->close();
				exit;
			}
			$value = $valueRaw;
			break;
		case 'label':
			if (strlen($valueRaw) > 255) {
				http_response_code(400);
				print json_encode(['status' => 'error', 'message' => $langs->trans('KreapLabelTooLong')]);
				$db->close();
				exit;
			}
			$value = $valueRaw;
			break;
		default:
			$value = $valueRaw;
	}
}

// Update the object field with the new value
$object->fetch($objectId);
if ($object->id <= 0) {
	http_response_code(404);
	print json_encode(['status' => 'error', 'message' => $langs->trans('KreapObjectNotFound')]);
	$db->close();
	exit;
}

$object->$field = $value;
$result = $object->update($user);

if ($result < 0) {
	print json_encode(['status' => 'error', 'message' => $langs->trans('KreapErrorUpdatingField', $field)]);
} else {
	print json_encode(['status' => 'success', 'message' => $langs->trans('KreapFieldUpdated', $field)]);
}

$db->close();
