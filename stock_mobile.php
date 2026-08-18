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
 * \file    kreaproducts/stock_mobile.php
 * \ingroup kreaproducts
 * \brief   Google-only mobile stock-counting application.
 */

$requestedAction = '';
if (isset($_REQUEST['kps_action']) && is_string($_REQUEST['kps_action'])) {
	$requestedAction = preg_replace('/[^a-z0-9_]/i', '', $_REQUEST['kps_action']);
}
$requestedGoogleLoginStart = !empty($_REQUEST['kps_google_login']);
$requestedGoogleOAuthReturn = !empty($_REQUEST['kps_google_oauth_return']);

if (!defined('NOREDIRECTBYMAINTOLOGIN')) {
	define('NOREDIRECTBYMAINTOLOGIN', '1');
}
if (($requestedGoogleLoginStart || $requestedGoogleOAuthReturn) && !defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (($requestedGoogleLoginStart || $requestedGoogleOAuthReturn)
	&& !defined('DOLENTITY')
	&& !empty($_REQUEST['entity'])
	&& ctype_digit((string) $_REQUEST['entity'])
	&& (int) $_REQUEST['entity'] > 0
) {
	define('DOLENTITY', (int) $_REQUEST['entity']);
}
if ($requestedAction !== '' || $requestedGoogleLoginStart || $requestedGoogleOAuthReturn) {
	if (!defined('NOREQUIREMENU')) {
		define('NOREQUIREMENU', '1');
	}
	if (!defined('NOREQUIREHTML')) {
		define('NOREQUIREHTML', '1');
	}
	if (!defined('NOREQUIREAJAX')) {
		define('NOREQUIREAJAX', '1');
	}
	if (!defined('NOCSRFCHECK')) {
		// JSON mutations use the X-CSRF-Token header and are validated centrally below.
		// Core validation only reads form/query tokens, so it must not pre-empt the API.
		define('NOCSRFCHECK', '1');
	}
	if (ob_get_level() === 0) {
		ob_start();
	}
}

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i], $tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
	$res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, $i + 1)).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

$langs->loadLangs(array('main', 'errors', 'stocks', 'kreaproducts@kreaproducts'));
require_once __DIR__.'/class/KreaProductsStockMobileApi.class.php';

$api = new KreaProductsStockMobileApi($db, $user, $langs, $conf);
$inventoryService = new KreaProductsMobileInventoryService($db, $user, $langs, $conf);
$mobileMutationActions = array(
	'start_inventory',
	'save_counts',
	'close_inventory',
	'edit_inventory',
	'delete_inventory',
	'logout',
);

if ($requestedAction === 'service_worker') {
	$serviceWorkerFile = __DIR__.'/stock_frontend/sw.js';
	if (empty($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
		http_response_code(405);
		header('Allow: GET');
		exit;
	}
	if (!is_readable($serviceWorkerFile)) {
		http_response_code(404);
		exit;
	}
	$serviceWorker = file_get_contents($serviceWorkerFile);
	if ($serviceWorker === false) {
		http_response_code(500);
		exit;
	}
	$frontendBaseUrl = dol_buildpath('/kreaproducts/stock_frontend/', 1);
	$serviceWorker = str_replace('"./workbox-', '"'.$frontendBaseUrl.'workbox-', $serviceWorker);
	$serviceWorker = str_replace(
		array('{url:"manifest.webmanifest"', 'createHandlerBoundToURL("index.html")'),
		array('{url:"stock_frontend/manifest.webmanifest"', 'createHandlerBoundToURL("stock_frontend/index.html")'),
		$serviceWorker
	);
	$serviceWorker = preg_replace_callback(
		'/importScripts\((["\'])(?!https?:|\/)([^"\']+)\1\)/',
		static function ($matches) use ($frontendBaseUrl) {
			return 'importScripts("'.$frontendBaseUrl.$matches[2].'")';
		},
		$serviceWorker
	);
	header('Content-Type: application/javascript; charset=UTF-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Service-Worker-Allowed: '.dol_buildpath('/kreaproducts/', 1));
	print $serviceWorker;
	$db->close();
	exit;
}

if ($requestedGoogleLoginStart) {
	try {
		$api->requireMethod('POST');
		$api->requireGoogleLoginToken(GETPOST('token', 'alpha'));
		if (GETPOST('actionlogin', 'aZ09') !== 'login' || GETPOST('beforeoauthloginredirect', 'alpha') !== 'google') {
			throw new KreaProductsStockApiException($langs->trans('ErrorBadParameters'), 400);
		}
		$backUrl = dol_buildpath('/kreaproducts/stock_mobile.php', 1).'?kps_google_oauth_return=1';
		$redirectUrl = $api->buildGoogleOAuthLoginRedirectUrl(GETPOSTINT('entity'), $backUrl);
		header('Location: '.$redirectUrl);
		$db->close();
		exit;
	} catch (KreaProductsStockApiException $e) {
		if ($e->httpCode >= 500) {
			$_SESSION['dol_loginmesg'] = $langs->trans('KreaProductsStockUnexpectedError');
			dol_syslog(__FILE__.' Google login start failed: '.$e->getMessage(), LOG_ERR);
		} else {
			$_SESSION['dol_loginmesg'] = $e->getMessage();
			dol_syslog(__FILE__.' Google login start refused: '.$e->getMessage(), LOG_WARNING);
		}
		header('Location: '.dol_buildpath('/kreaproducts/stock_mobile.php', 1));
		$db->close();
		exit;
	} catch (Throwable $e) {
		$_SESSION['dol_loginmesg'] = $langs->trans('KreaProductsStockUnexpectedError');
		dol_syslog(__FILE__.' Google login start failed: '.$e->getMessage(), LOG_ERR);
		header('Location: '.dol_buildpath('/kreaproducts/stock_mobile.php', 1));
		$db->close();
		exit;
	}
}

if ($requestedGoogleOAuthReturn) {
	try {
		$username = GETPOST('username', 'alphanohtml');
		if ($username !== ''
			&& GETPOST('actionlogin', 'aZ09') === 'login'
			&& GETPOST('afteroauthloginreturn', 'alpha') === 'google'
		) {
			$api->loginWithGoogleOAuthReturn($username, GETPOSTINT('entity'));
		} else {
			throw new KreaProductsStockApiException($langs->trans('ErrorBadParameters'), 400);
		}
	} catch (KreaProductsStockApiException $e) {
		if ($e->httpCode >= 500) {
			$_SESSION['dol_loginmesg'] = $langs->trans('KreaProductsStockUnexpectedError');
			dol_syslog(__FILE__.' Google OAuth return failed: '.$e->getMessage(), LOG_ERR);
		} else {
			$_SESSION['dol_loginmesg'] = $e->getMessage();
			dol_syslog(__FILE__.' Google OAuth return refused: '.$e->getMessage(), LOG_WARNING);
		}
	} catch (Throwable $e) {
		$_SESSION['dol_loginmesg'] = $langs->trans('KreaProductsStockUnexpectedError');
		dol_syslog(__FILE__.' Google OAuth return failed: '.$e->getMessage(), LOG_ERR);
	}
	header('Location: '.dol_buildpath('/kreaproducts/stock_mobile.php', 1));
	$db->close();
	exit;
}

if ($requestedAction !== '') {
	try {
		if (in_array($requestedAction, $mobileMutationActions, true)) {
			$api->requireMethod('POST');
			$api->requireCsrfToken();
		}
		if ($requestedAction === 'login_meta') {
			$api->requireMethod('GET');
			$api->respond(array('success' => true, 'data' => $api->getLoginMeta()));
		}
		if ($requestedAction === 'whoami') {
			$api->requireMethod('GET');
			$api->respond(array('success' => true, 'data' => $api->getWhoAmI()));
		}
		if ($requestedAction === 'templates') {
			$api->requireMethod('GET');
			$api->respond(array('success' => true, 'data' => $inventoryService->listTemplates()));
		}
		if ($requestedAction === 'inventory') {
			$api->requireMethod('GET');
			$api->respond(array('success' => true, 'data' => $inventoryService->getInventory(GETPOSTINT('id'))));
		}
		if ($requestedAction === 'inventories' || $requestedAction === 'completed_inventories') {
			$api->requireMethod('GET');
			$api->respond(array('success' => true, 'data' => $inventoryService->listInventories()));
		}
		if ($requestedAction === 'start_inventory') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$categoryId = isset($payload['category_id']) ? (int) $payload['category_id'] : 0;
			$warehouseId = isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : 0;
			$api->respond(array('success' => true, 'data' => $inventoryService->startInventory($categoryId, $warehouseId)));
		}
		if ($requestedAction === 'save_counts') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$inventoryId = isset($payload['inventory_id']) ? (int) $payload['inventory_id'] : 0;
			$counts = isset($payload['counts']) && is_array($payload['counts']) ? $payload['counts'] : array();
			$valueDate = isset($payload['date_inventory']) ? trim((string) $payload['date_inventory']) : '';
			$api->respond(array('success' => true, 'data' => $inventoryService->saveCounts($inventoryId, $counts, $valueDate)));
		}
		if ($requestedAction === 'close_inventory') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$inventoryId = isset($payload['inventory_id']) ? (int) $payload['inventory_id'] : 0;
			$allowIncomplete = !empty($payload['allow_incomplete']);
			$api->respond(array('success' => true, 'data' => $inventoryService->closeInventory($inventoryId, $allowIncomplete)));
		}
		if ($requestedAction === 'edit_inventory') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$inventoryId = isset($payload['inventory_id']) ? (int) $payload['inventory_id'] : 0;
			$api->respond(array('success' => true, 'data' => $inventoryService->editInventory($inventoryId)));
		}
		if ($requestedAction === 'delete_inventory') {
			$api->requireMethod('POST');
			$payload = $api->getRequestJsonBody();
			$inventoryId = isset($payload['inventory_id']) ? (int) $payload['inventory_id'] : 0;
			$api->respond(array('success' => true, 'data' => $inventoryService->deleteInventory($inventoryId)));
		}
		if ($requestedAction === 'logout') {
			$api->requireMethod('POST');
			$api->respond(array('success' => true, 'data' => $api->logout()));
		}

		$api->respondError('Unknown action.', 404);
	} catch (KreaProductsStockApiException $e) {
		if ($e->httpCode >= 500) {
			dol_syslog(__FILE__.' API internal error: '.$e->getMessage(), LOG_ERR);
			$api->respondError($langs->trans('KreaProductsStockUnexpectedError'), $e->httpCode);
		} else {
			$api->respondError($e->getMessage(), $e->httpCode);
		}
	} catch (Throwable $e) {
		dol_syslog(__FILE__.' API error: '.$e->getMessage(), LOG_ERR);
		$api->respondError($langs->trans('KreaProductsStockUnexpectedError'), 500);
	}
}

$bootstrapSession = null;
if (!empty($user->id)) {
	try {
		$bootstrapSession = $api->getWhoAmI();
	} catch (KreaProductsStockApiException $e) {
		if ($e->httpCode >= 500) {
			dol_syslog(__FILE__.' bootstrap session failed: '.$e->getMessage(), LOG_ERR);
			accessforbidden($langs->trans('KreaProductsStockUnexpectedError'));
		} else {
			accessforbidden($e->getMessage());
		}
	}
}

$frontendIndex = __DIR__.'/stock_frontend/index.html';
if (!is_readable($frontendIndex)) {
	llxHeader('', $langs->trans('KreaProductsStockMobileApp'));
	print load_fiche_titre($langs->trans('KreaProductsStockMobileApp'), '', 'kreaproducts@kreaproducts');
	print '<div class="opacitymedium">'.$langs->trans('KreaProductsStockFrontendBuildMissing').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$frontendHtml = file_get_contents($frontendIndex);
if ($frontendHtml === false) {
	accessforbidden($langs->trans('ErrorFileNotFound'));
}

$loginError = !empty($_SESSION['dol_loginmesg']) ? (string) $_SESSION['dol_loginmesg'] : '';
unset($_SESSION['dol_loginmesg']);
$bootstrapScript = '<script>';
$bootstrapScript .= 'window.__KREAPRODUCTS_STOCK_BOOTSTRAP__ = '.json_encode($bootstrapSession, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';';
$bootstrapScript .= 'window.__KREAPRODUCTS_STOCK_DATA_URL__ = '.json_encode(dol_buildpath('/kreaproducts/stock_mobile.php', 1), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';';
$bootstrapScript .= 'window.__KREAPRODUCTS_STOCK_LOGIN_ERROR__ = '.json_encode($loginError, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';';
$bootstrapScript .= '</script>';

$injectedHtml = preg_replace('/<\/head>/i', $bootstrapScript.'</head>', $frontendHtml, 1);
if (is_string($injectedHtml) && $injectedHtml !== '') {
	$frontendHtml = $injectedHtml;
}

header('Content-Type: text/html; charset=UTF-8');
print $frontendHtml;
$db->close();
exit;
