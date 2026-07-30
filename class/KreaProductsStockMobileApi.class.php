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

require_once __DIR__.'/KreaProductsMobileInventoryService.class.php';

/**
 * Session and Google OAuth adapter for the KreaProductsStock mobile application.
 */
class KreaProductsStockMobileApi
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var Translate */
	private $langs;

	/** @var Conf */
	private $conf;

	/**
	 * @param DoliDB    $db    Database handler
	 * @param User      $user  Current user
	 * @param Translate $langs Translation handler
	 * @param Conf      $conf  Dolibarr configuration
	 */
	public function __construct($db, $user, $langs, $conf)
	{
		$this->db = $db;
		$this->user = $user;
		$this->langs = $langs;
		$this->conf = $conf;
	}

	/**
	 * @param array<string,mixed> $payload  Response payload
	 * @param int                 $httpCode HTTP status
	 * @return never
	 */
	public function respond(array $payload, $httpCode = 200)
	{
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code((int) $httpCode);
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload);
		exit;
	}

	/**
	 * @param string $message  Error message
	 * @param int    $httpCode HTTP status
	 * @return never
	 */
	public function respondError($message, $httpCode = 400)
	{
		$this->respond(array(
			'success' => false,
			'error' => array('message' => (string) $message),
		), (int) $httpCode);
	}

	/**
	 * @param string $method Required HTTP method
	 * @return void
	 */
	public function requireMethod($method)
	{
		$currentMethod = empty($_SERVER['REQUEST_METHOD']) ? 'GET' : strtoupper((string) $_SERVER['REQUEST_METHOD']);
		if ($currentMethod !== strtoupper((string) $method)) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorBadMethod'), 405);
		}
	}

	/**
	 * Validate the application CSRF header for authenticated mutations.
	 *
	 * @return void
	 */
	public function requireCsrfToken()
	{
		$requestToken = '';
		if (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
			$requestToken = preg_replace('/[^a-zA-Z0-9_-]/', '', $_SERVER['HTTP_X_CSRF_TOKEN']);
		}
		if ($requestToken === '') {
			$requestToken = GETPOST('token', 'alpha');
		}

		$validTokens = array_filter(array(currentToken(), newToken()));
		$valid = false;
		foreach ($validTokens as $validToken) {
			if (is_string($validToken) && hash_equals($validToken, $requestToken)) {
				$valid = true;
				break;
			}
		}
		if (!$valid) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorBadOrExpiredToken'), 403);
		}
	}

	/**
	 * Validate the CSRF token used to start the public Google redirect.
	 *
	 * @param string $requestToken Request token
	 * @return void
	 */
	public function requireGoogleLoginToken($requestToken)
	{
		$validTokens = array_filter(array(currentToken(), newToken()));
		foreach ($validTokens as $validToken) {
			if (is_string($validToken) && hash_equals($validToken, (string) $requestToken)) {
				return;
			}
		}
		throw new KreaProductsStockApiException($this->langs->trans('ErrorBadOrExpiredToken'), 403);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getRequestJsonBody()
	{
		$raw = file_get_contents('php://input');
		if ($raw === false || trim($raw) === '') {
			return array();
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_INVALID_JSON'), 400);
		}
		return $decoded;
	}

	/**
	 * Return public Google-only login metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function getLoginMeta()
	{
		$manager = $this->getKrea2FactorAuthManager();
		if (is_object($manager)) {
			$this->langs->load('krea2factorauth@krea2factorauth');
		}

		$entities = $this->getModuleEntities();
		foreach ($entities as &$entity) {
			$entity['google'] = $this->buildEntityGoogleMeta($manager, (int) $entity['id']);
		}
		unset($entity);

		$defaultEntity = empty($entities) ? 0 : (int) $entities[0]['id'];
		$currentEntity = !empty($this->conf->entity) ? (int) $this->conf->entity : 0;
		foreach ($entities as $entity) {
			if ((int) $entity['id'] === $currentEntity) {
				$defaultEntity = $currentEntity;
				break;
			}
		}

		return array(
			'default_entity' => $defaultEntity,
			'entities' => $entities,
			'google_login_token' => newToken(),
			'google_button_label' => is_object($manager)
				? $this->langs->transnoentitiesnoconv('Krea2FactorAuthLoginWithGoogle')
				: $this->langs->transnoentitiesnoconv('KREAPRODUCTS_GOOGLE_LOGIN'),
		);
	}

	/**
	 * Build the KreaProductsStock-owned OAuth start URL.
	 *
	 * @param int    $entity            Entity ID
	 * @param string $backToKreaProductsStockUrl Return URL
	 * @return string
	 */
	public function buildGoogleOAuthLoginRedirectUrl($entity, $backToKreaProductsStockUrl)
	{
		$entity = (int) $entity;
		if ($entity <= 0) {
			$entity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
		}
		if (!$this->isModuleEnabledForEntity('kreaproducts', $entity)) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaProductsStock'), 403);
		}

		$manager = $this->getKrea2FactorAuthManager();
		$this->langs->load('krea2factorauth@krea2factorauth');
		if (!is_object($manager)
			|| !method_exists($manager, 'isGoogleLoginEnabled')
			|| !method_exists($manager, 'isGoogleOAuthConfigured')
			|| !method_exists($manager, 'isModuleActiveForEntity')
			|| !method_exists($manager, 'getGoogleOAuthLoginCallbackUrl')
			|| !$manager->isModuleActiveForEntity($entity)
			|| !$manager->isGoogleLoginEnabled()
			|| !$manager->isGoogleOAuthConfigured()
		) {
			throw new KreaProductsStockApiException($this->langs->trans('Krea2FactorAuthGoogleLoginUnavailable'), 403);
		}

		$_SESSION['datafromloginform'] = array(
			'entity' => $entity,
			'backtopage' => GETPOST('backtopage'),
			'tz' => GETPOST('tz'),
			'tz_string' => GETPOST('tz_string'),
			'dst_observed' => GETPOST('dst_observed'),
			'dst_first' => GETPOST('dst_first'),
			'dst_second' => GETPOST('dst_second'),
			'dol_screenwidth' => GETPOST('screenwidth'),
			'dol_screenheight' => GETPOST('screenheight'),
			'dol_hide_topmenu' => GETPOST('dol_hide_topmenu'),
			'dol_hide_leftmenu' => GETPOST('dol_hide_leftmenu'),
			'dol_optimize_smallscreen' => GETPOST('dol_optimize_smallscreen'),
			'dol_no_mouse_hover' => GETPOST('dol_no_mouse_hover'),
			'dol_use_jmobile' => GETPOST('dol_use_jmobile'),
		);

		if (method_exists($manager, 'clearGoogleLoginPendingChallenge')) {
			$manager->clearGoogleLoginPendingChallenge();
		}

		$scope = Krea2FactorAuthManager::GOOGLE_OAUTH_SCOPES;
		$state = bin2hex(random_bytes(16));
		$url = $manager->getGoogleOAuthLoginCallbackUrl();
		$url .= (strpos($url, '?') === false ? '?' : '&').'shortscope='.urlencode($scope);
		$url .= '&state='.urlencode('forlogin-'.$scope.'-entity'.$entity.'-'.$state);
		$url .= '&backtourl='.urlencode((string) $backToKreaProductsStockUrl);
		$url .= '&entity='.$entity;

		return $url;
	}

	/**
	 * Complete Google login after Krea2FactorAuth validates the callback.
	 *
	 * @param string $username Dolibarr username
	 * @param int    $entity   Entity ID
	 * @return array<string,mixed>
	 */
	public function loginWithGoogleOAuthReturn($username, $entity)
	{
		$username = trim((string) $username);
		$entity = (int) $entity;
		if ($username === '' || $entity <= 0) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorBadParameters'), 400);
		}
		if (!$this->isModuleEnabledForEntity('kreaproducts', $entity)) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorModuleNotEnabled', 'KreaProductsStock'), 403);
		}

		$manager = $this->getKrea2FactorAuthManager();
		$this->langs->load('krea2factorauth@krea2factorauth');
		if (!is_object($manager)
			|| !$manager->isModuleActiveForEntity($entity)
			|| !$manager->isGoogleLoginEnabled()
			|| !$manager->isGoogleOAuthConfigured()
		) {
			throw new KreaProductsStockApiException($this->langs->trans('Krea2FactorAuthGoogleLoginUnavailable'), 403);
		}

		include_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
		$authModes = $this->resolveAuthModes();
		if (!in_array('krea2factorauth', $authModes, true)) {
			array_unshift($authModes, 'krea2factorauth');
		}
		$login = checkLoginPassEntity($username, '', $entity, array_values(array_unique($authModes)), '');
		if (empty($login) || $login === '--bad-login-validity--') {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorBadLoginPassword'), 401);
		}

		$authenticatedUser = new User($this->db);
		$result = $authenticatedUser->fetch(0, $login, '', 1, $entity);
		if ($result <= 0
			|| empty($authenticatedUser->statut)
			|| $authenticatedUser->isNotIntoValidityDateRange()
			|| !empty($authenticatedUser->socid)
		) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorForbidden'), 403);
		}

		return $this->initializeAuthenticatedSession($authenticatedUser, $entity);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getWhoAmI()
	{
		if (empty($this->user->id)) {
			throw new KreaProductsStockApiException($this->langs->trans('ErrorNotLogged'), 401);
		}
		$inventoryService = new KreaProductsMobileInventoryService($this->db, $this->user, $this->langs, $this->conf);
		$inventoryService->requireReadAccess();

		return array(
			'user' => array(
				'id' => (int) $this->user->id,
				'login' => (string) $this->user->login,
				'fullname' => (string) dolGetFirstLastname($this->user->firstname, $this->user->lastname),
				'email' => (string) $this->user->email,
			),
			'entity' => (int) $this->conf->entity,
			'entity_label' => $this->getEntityLabelById((int) $this->conf->entity),
			'rights' => array(
				'count' => $inventoryService->canCount() ? 1 : 0,
				'close' => $inventoryService->canClose() ? 1 : 0,
				'history' => $inventoryService->isHistoryEnabled() ? 1 : 0,
			),
			'module_info' => $this->getModuleInfo(),
			'token' => newToken(),
		);
	}

	/**
	 * @return array<string,int>
	 */
	public function logout()
	{
		if (!empty($this->user->id)) {
			$this->user->call_trigger('USER_LOGOUT', $this->user);
		}
		unset($_SESSION['dol_login'], $_SESSION['dol_entity'], $_SESSION['urlfrom']);
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		return array('logged_out' => 1);
	}

	/**
	 * @param User $authenticatedUser Authenticated user
	 * @param int  $entity            Entity ID
	 * @return array<string,mixed>
	 */
	private function initializeAuthenticatedSession(User $authenticatedUser, $entity)
	{
		$authenticatedUser->loadRights();
		$this->user = $authenticatedUser;
		$this->conf->entity = (int) $entity;
		global $user;
		$user = $authenticatedUser;

		$inventoryService = new KreaProductsMobileInventoryService($this->db, $this->user, $this->langs, $this->conf);
		$inventoryService->requireReadAccess();

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_regenerate_id(true);
		}
		$_SESSION['dol_login'] = $this->user->login;
		$_SESSION['dol_logindate'] = dol_now('gmt');
		$_SESSION['dol_authmode'] = 'krea2factorauth';
		$_SESSION['dol_company'] = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
		$_SESSION['dol_entity'] = (int) $entity;

		$this->db->begin();
		$error = 0;
		if ($this->user->update_last_login_date() < 0) {
			$error++;
		}
		$this->user->context['audit'] = 'KreaProductsStock Google OAuth login - entity='.(int) $entity;
		$this->user->context['authentication_method'] = 'krea2factorauth';
		if ($this->user->call_trigger('USER_LOGIN', $this->user) < 0) {
			$error++;
		}
		if ($error) {
			$this->db->rollback();
			throw new KreaProductsStockApiException($this->langs->trans('KREAPRODUCTS_ERROR_LOGIN_SESSION'), 500);
		}
		$this->db->commit();

		return $this->getWhoAmI();
	}

	/**
	 * @param object|null $manager  Krea2FactorAuth manager
	 * @param int         $entityId Entity ID
	 * @return array<string,mixed>
	 */
	private function buildEntityGoogleMeta($manager, $entityId)
	{
		$enabled = 0;
		$logoUrl = '';
		$backgroundColor = '';
		if (is_object($manager)
			&& method_exists($manager, 'isModuleActiveForEntity')
			&& $manager->isModuleActiveForEntity((int) $entityId)
		) {
			$enabled = $manager->isGoogleLoginEnabled() && $manager->isGoogleOAuthConfigured() ? 1 : 0;
			if (method_exists($manager, 'getLoginLogoUrl')) {
				$logoUrl = (string) $manager->getLoginLogoUrl((int) $entityId);
			}
			if (method_exists($manager, 'getLoginBackgroundColorForEntity')) {
				$backgroundColor = (string) $manager->getLoginBackgroundColorForEntity((int) $entityId);
			}
		}

		return array(
			'enabled' => $enabled,
			'login_url' => $enabled ? dol_buildpath('/kreaproducts/stock_mobile.php', 1).'?kps_google_login=1' : '',
			'logo_url' => $logoUrl,
			'background_color' => $backgroundColor,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function getModuleEntities()
	{
		$entities = array();
		if ($this->tableExists('entity')) {
			$sql = 'SELECT e.rowid as id, e.label';
			$sql .= ' FROM '.$this->db->prefix().'entity as e';
			$sql .= ' WHERE e.rowid > 0 AND e.active = 1';
			$sql .= ' ORDER BY e.rowid ASC';
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					if (!$this->isModuleEnabledForEntity('kreaproducts', (int) $obj->id)) {
						continue;
					}
					$entities[] = array(
						'id' => (int) $obj->id,
						'label' => trim((string) $obj->label) !== '' ? (string) $obj->label : 'Entity '.(int) $obj->id,
					);
				}
				$this->db->free($resql);
			}
		}

		if (empty($entities)) {
			$entity = !empty($this->conf->entity) ? (int) $this->conf->entity : 1;
			if ($this->isModuleEnabledForEntity('kreaproducts', $entity)) {
				$entities[] = array('id' => $entity, 'label' => $this->getEntityLabelById($entity));
			}
		}
		return $entities;
	}

	/**
	 * @param string $moduleName Module name
	 * @param int    $entityId   Entity ID
	 * @return bool
	 */
	private function isModuleEnabledForEntity($moduleName, $entityId)
	{
		$entityId = (int) $entityId;
		$moduleConstantName = 'MAIN_MODULE_'.strtoupper((string) $moduleName);

		$sql = 'SELECT rowid';
		$sql .= ' FROM '.$this->db->prefix().'const';
		$sql .= " WHERE ".$this->db->decrypt('name')." = '".$this->db->escape($moduleConstantName)."'";
		$sql .= $entityId > 0 ? ' AND entity IN (0, '.$entityId.')' : ' AND entity = 0';
		$sql .= " AND (".$this->db->decrypt('value')." = '1' OR UPPER(".$this->db->decrypt('value').") = 'TRUE')";
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		return $resql && $this->db->num_rows($resql) > 0;
	}

	/**
	 * @return object|null
	 */
	private function getKrea2FactorAuthManager()
	{
		if (!class_exists('Krea2FactorAuthManager')) {
			dol_include_once('/krea2factorauth/class/krea2factorauthmanager.class.php');
		}
		return class_exists('Krea2FactorAuthManager') ? new Krea2FactorAuthManager($this->db) : null;
	}

	/**
	 * @return array<int,string>
	 */
	private function resolveAuthModes()
	{
		global $dolibarr_main_authentication, $dolibarr_auto_user;
		$authMode = defined('MAIN_AUTHENTICATION_MODE')
			? (string) constant('MAIN_AUTHENTICATION_MODE')
			: (string) $dolibarr_main_authentication;
		if ($authMode === '') {
			$authMode = 'dolibarr';
		}
		if ($authMode === 'forceuser' && empty($dolibarr_auto_user)) {
			$dolibarr_auto_user = 'auto';
		}
		return array_values(array_filter(array_map('trim', explode(',', $authMode))));
	}

	/**
	 * @param int $entityId Entity ID
	 * @return string
	 */
	private function getEntityLabelById($entityId)
	{
		if ($this->tableExists('entity')) {
			$sql = 'SELECT label FROM '.$this->db->prefix().'entity WHERE rowid = '.((int) $entityId);
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : false;
			if ($obj && trim((string) $obj->label) !== '') {
				return (string) $obj->label;
			}
		}
		return 'Entity '.((int) $entityId);
	}

	/**
	 * @param string $table Table name without prefix
	 * @return bool
	 */
	private function tableExists($table)
	{
		return (bool) $this->db->DDLInfoTable($this->db->prefix().$table);
	}

	/**
	 * @return array<string,string>
	 */
	private function getModuleInfo()
	{
		$info = array(
			'module' => 'KreaProducts',
			'version' => '1.1.0',
			'editor' => 'Kreativität Works',
			'license' => 'GPL-3.0-or-later',
			'support' => 'mail@kreativitat.com',
			'website' => 'https://kreativitat.com',
		);
		$descriptorPath = dirname(__DIR__).'/core/modules/modKreaProducts.class.php';
		if (is_readable($descriptorPath)) {
			require_once $descriptorPath;
			if (class_exists('modKreaProducts')) {
				$descriptor = new modKreaProducts($this->db);
				$info['version'] = (string) $descriptor->version;
			}
		}
		return $info;
	}
}
