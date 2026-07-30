<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * (at your option) any later version.
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
 *       \file       htdocs/custom/kreaproducts/ajax/toggle_hideproduct.php
 *       \brief      Ajax handler to toggle kreap_hideproduct extrafield
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
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

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

$langs->load('kreaproducts@kreaproducts');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$value = GETPOSTINT('value');
$backtopage = dol_sanitizeUrl(GETPOST('backtopage', 'alphanohtml'), 1);
$backtopage = ($backtopage !== '' && strpos($backtopage, '/') === 0) ? $backtopage : '';
$token = GETPOST('token', 'alpha');

if (empty($token) || (!hash_equals(currentToken(), $token) && !hash_equals(newToken(), $token))) {
	top_httphead('application/json');
	http_response_code(403);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('ErrorBadOrExpiredToken')));
	$db->close();
	exit;
}

if ($action !== 'set' || $id <= 0 || ($value !== 0 && $value !== 1)) {
	top_httphead('application/json');
	http_response_code(400);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('ErrorBadParameters')));
	$db->close();
	exit;
}

$product = new Product($db);
$fetchRes = $product->fetch($id);
if ($fetchRes <= 0) {
	top_httphead('application/json');
	http_response_code(404);
	print json_encode(array('status' => 'error', 'message' => $langs->trans('KreapProductNotFound', $id)));
	$db->close();
	exit;
}

$canEdit = ($product->type == Product::TYPE_SERVICE) ? !empty($user->rights->service->creer) : !empty($user->rights->produit->creer);
if (!$canEdit) {
	accessforbidden();
}

$product->fetch_optionals();
$product->array_options['options_kreap_hideproduct'] = (int) $value;
$updateRes = $product->updateExtraField('kreap_hideproduct');
if ($updateRes < 0) {
	top_httphead('application/json');
	http_response_code(500);
	print json_encode(array('status' => 'error', 'message' => $product->error));
	$db->close();
	exit;
}

if (!empty($backtopage)) {
	header('Location: ' . $backtopage);
	exit;
}

top_httphead('application/json');
print json_encode(array('status' => 'ok'));
