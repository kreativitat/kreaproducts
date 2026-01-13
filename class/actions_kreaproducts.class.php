<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2025		Marcelo Marinho de Araujo	<marcelomarinhoaraujo@gmail.com>
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
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
 * \file    kreaproducts/class/actions_kreaproducts.class.php
 * \ingroup kreaproducts
 * \brief   KreaProducts hook handlers.
 *
 * Implements module hooks for menu injection, inventory status overrides,
 * and redirects to KreaProducts custom pages.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/inventory/class/inventory.class.php';

/**
 * Class ActionsKreaProducts
 */
class ActionsKreaProducts extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();


	/**
	 * @var mixed[] Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param	DoliDB	$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->ensureBomlistHookContext();
	}


	/**
	 * Inject inventory print sheet link under Inventory list in left menu.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param array<int,array<string,mixed>> $menuitems Menu items to adjust
	 * @param ?string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function menuLeftMenuItems($parameters, &$menuitems, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!is_array($menuitems)) {
			return 0;
		}
		if (empty($parameters['mainmenu']) || $parameters['mainmenu'] !== 'products') {
			return 0;
		}
		if (!isModEnabled('kreaproducts') || !isModEnabled('stock')) {
			return 0;
		}

		$langs->load('kreaproducts@kreaproducts');
		$permission = ($user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read'));

		$newmenu = array();
		$inserted = false;

		foreach ($menuitems as $item) {
			if (!empty($item['url']) && strpos($item['url'], '/kreaproducts/inventory_printsheet.php') !== false) {
				continue;
			}

			$newmenu[] = $item;

			$isInventoryList = (!empty($item['url'])
				&& strpos($item['url'], '/product/inventory/list.php') !== false
				&& strpos($item['url'], 'leftmenu=stock_inventories') !== false
				&& isset($item['level'])
				&& (int) $item['level'] === 1);

			if (!$inserted && $isInventoryList) {
				$newmenu[] = array(
					'url' => '/kreaproducts/inventory_printsheet.php?leftmenu=stock_inventories',
					'titre' => $langs->trans('KREAPRODUCTS_INVENTORY_PRINT_SHEET'),
					'level' => 1,
					'enabled' => (int) $permission,
					'target' => '_blank',
					'mainmenu' => 'products',
					'leftmenu' => 'stock_inventories',
					'position' => isset($item['position']) ? (int) $item['position'] : 0,
					'id' => '',
					'idsel' => '',
					'classname' => '',
					'prefix' => '',
				);
				$inserted = true;
			}
		}

		if (!$inserted) {
			$newmenu[] = array(
				'url' => '/kreaproducts/inventory_printsheet.php?leftmenu=stock_inventories',
				'titre' => $langs->trans('KREAPRODUCTS_INVENTORY_PRINT_SHEET'),
				'level' => 1,
				'enabled' => (int) $permission,
				'target' => '_blank',
				'mainmenu' => 'products',
				'leftmenu' => 'stock_inventories',
				'position' => 0,
				'id' => '',
				'idsel' => '',
				'classname' => '',
				'prefix' => '',
			);
		}

		$this->results = $newmenu;
		return 1;
	}

	/**
	 * Override inventory status label for started inventories.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject $object The object to process
	 * @param ?string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function LibStatut($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!is_object($object) || $object->element !== 'inventory') {
			return 0;
		}

		$status = isset($parameters['status']) ? (int) $parameters['status'] : (int) $object->status;
		if ($status !== Inventory::STATUS_VALIDATED) {
			return 0;
		}

		$mode = isset($parameters['mode']) ? (int) $parameters['mode'] : 0;
		$langs->load('kreaproducts@kreaproducts');

		$label = $langs->transnoentitiesnoconv('KREAPRODUCTS_INVENTORY_STARTED');
		$statusType = 'status'.$status;

		$this->resprints = dolGetStatus($label, $label, '', $statusType, $mode);
		return 1;
	}

	/**
	 * Overload the doActions function.
	 *
	 * - On inventory pages (card.php and inventory.php): include custom KreaProducts versions and stop core.
	 * - On product create/update: keep existing stockable_product logic.
	 *
	 * @param  array<string,mixed> $parameters  Hook metadata (context, etc...)
	 * @param  CommonObject        $object      The object to process
	 * @param  ?string             $action      Current action ("create", "update", "view", etc.)
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                               <0 on error, 0 to continue standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf, $user, $langs;

		$this->ensureBomlistHookContext();

		$error = 0;
		$handled = $this->redirectToCustomPages($parameters, $object, $action);
		if ($handled) {
			return 0;
		}

		if (is_object($object) && $object->element === 'bom' && $action === 'setkreap_entity') {
			if (!$this->canSetSharedBomEntity()) {
				accessforbidden();
			}

			if (GETPOST('cancel', 'alpha')) {
				header('Location: ' . DOL_URL_ROOT . '/bom/bom_card.php?id=' . (int) $object->id);
				exit;
			}

			$newEntity = GETPOSTINT('kreap_entity');
			$currentEntity = isset($object->entity) ? (int) $object->entity : (int) $conf->entity;
			$allowedEntities = array_values(array_unique(array(0, $currentEntity, (int) $conf->entity)));
			if (!in_array($newEntity, $allowedEntities, true)) {
				$langs->load('errors');
				setEventMessages($langs->trans('ErrorBadValueForField', $langs->transnoentitiesnoconv('Entity')), null, 'errors');
				return -1;
			}

			$object->entity = $newEntity;
			$result = $object->update($user);
			if ($result > 0) {
				header('Location: ' . DOL_URL_ROOT . '/bom/bom_card.php?id=' . (int) $object->id);
				exit;
			}

			setEventMessages($object->error, $object->errors, 'errors');
			return -1;
		}

		if ($this->canSetSharedBomEntity() && is_object($object) && $object->element === 'bom' && ($action === 'add' || $action === 'update')) {
			$requestedEntity = null;
			if (GETPOSTISSET('kreap_entity')) {
				$requestedEntity = GETPOSTINT('kreap_entity');
			} elseif (GETPOSTISSET('entity')) {
				$requestedEntity = GETPOSTINT('entity');
			}
			if ($requestedEntity !== null) {
				$object->entity = ($requestedEntity === 0 ? 0 : (int) $conf->entity);
			}
		}

		if (is_object($object) && $object->element === 'bom' && $action === 'toggle_dismantle') {
			$productId = GETPOSTINT('product_id');
			if ($productId <= 0) {
				return 0;
			}

			$newValue = GETPOSTINT('value') ? 1 : 0;

			// Ensure the extrafield column exists
			$columnReady = false;
			$colRes = $db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "kreap_dismantle");
			if ($colRes) {
				$columnReady = ($db->num_rows($colRes) > 0);
				$db->free($colRes);
			}
			if (!$columnReady) {
				require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
				$extrafields = new ExtraFields($db);
				$field_name = "kreap_dismantle";
				$field_label = $langs->trans("kreap_dismantle");
				$field_help = $langs->trans("kreap_dismantle_help");
				$extrafields->addExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
				$extrafields->updateExtraField($field_name, $field_label, 'boolean', 9, 3, 'product', 0, 0, 0, '', 1, '', 1, $field_help, '', '', 'kreaproducts@kreaproducts', 'isModEnabled("kreaproducts")');
			}

			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, kreap_dismantle) VALUES ("
				. (int) $productId . ", " . (int) $newValue . ") "
				. "ON DUPLICATE KEY UPDATE kreap_dismantle = " . (int) $newValue;
			if (!$db->query($sql)) {
				setEventMessages($langs->trans("Error"), array($db->lasterror()), 'errors');
				return -1;
			}

			// Redirect back to BOM card with the returnaction parameter
			$returnAction = GETPOST('returnaction', 'aZ09');
			if (!in_array($returnAction, array('edit', 'create'), true)) {
				$returnAction = '';
			}
			$redirectUrl = DOL_URL_ROOT . '/bom/bom_card.php?id=' . (int) $object->id;
			if ($returnAction) {
				$redirectUrl .= '&action=' . urlencode($returnAction);
			}
			header('Location: ' . $redirectUrl);
			exit;
		}

		if ($object->element === 'inventory' && $action === 'confirm_validate') {
			if ((int) $object->status === Inventory::STATUS_RECORDED) {
				$langs->load('kreaproducts@kreaproducts');
				setEventMessages($langs->trans('KREAPRODUCTS_INVENTORY_CLOSED_LOCKED'), null, 'errors');
				return -1;
			}
			$permissiontoadd = !getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
				? $user->rights->stock->creer
				: $user->rights->stock->inventory_advance->write;
			if ($permissiontoadd) {
				$sql = 'SELECT COUNT(*) as cnt FROM ' . MAIN_DB_PREFIX . 'inventorydet WHERE fk_inventory=' . (int) $object->id;
				$resql = $db->query($sql);
				if ($resql) {
					$row = $db->fetch_object($resql);
					if ($row && (int) $row->cnt > 0) {
						$result = $object->setStatut(Inventory::STATUS_VALIDATED, null, '', 'INVENTORY_VALIDATED');
						if ($result > 0) {
							header('Location: ' . dol_buildpath('/custom/kreaproducts/inventory.php', 1) . '?id=' . (int) $object->id);
							exit;
						}
						$this->errors[] = $object->error;
						return -1;
					}
				} else {
					$this->errors[] = $db->lasterror();
					return -1;
				}
			}
		}

		// 3) Existing logic: update stockable_product on product create/update
		if (
			($action === 'create' || $action === 'update')
			&& $object->element === 'product'
			&& ! empty($object->id)
			&& isModEnabled('stock')
			&& ! $object->hasbatch()
			&& ($object->isProduct()
				|| ($object->isService() && ! empty($conf->global->STOCK_SUPPORTS_SERVICES)))
		) {
			$val = GETPOST('stockable_product', 'int') ? 1 : 0;

			$sql  = "UPDATE " . MAIN_DB_PREFIX . "product";
			$sql .= " SET stockable_product = " . $val;
			$sql .= " WHERE rowid = " . ((int) $object->id);
			$resql = $db->query($sql);
			if (! $resql) {
				$this->errors[] = $db->lasterror();
				$error++;
			}
		}

		return $error ? -1 : 0;
	}

	/**
	 * Normalize shared-entity selector on BOM add/edit forms.
	 *
	 * @param  array<string,mixed> $parameters Hook metadata
	 * @param  CommonObject        $object     The object to process
	 * @param  ?string             $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $form, $mc;

		static $sharedEntitySelectorPrinted = false;

		if (!is_object($object) || $object->element !== 'bom') {
			return 0;
		}
		if ($sharedEntitySelectorPrinted) {
			return 0;
		}

		$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
		if (strpos($scriptPath, '/bom/bom_card.php') === false) {
			return 0;
		}

		$langs->load('kreaproducts@kreaproducts');

		$multicompanyEnabled = isModEnabled('multicompany');

		$dismantleRowHtml = '';
		$productId = (int) ($object->fk_product ?? 0);
		if ($productId > 0) {
			$dismantleValue = 0;
			$columnReady = false;
			$colRes = $this->db->DDLDescTable(MAIN_DB_PREFIX . "product_extrafields", "kreap_dismantle");
			if ($colRes) {
				$columnReady = ($this->db->num_rows($colRes) > 0);
				$this->db->free($colRes);
			}
			if ($columnReady) {
				$sql = "SELECT kreap_dismantle FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . $productId . " LIMIT 1";
				$resql = $this->db->query($sql);
				if ($resql) {
					$obj = $this->db->fetch_object($resql);
					if ($obj && $obj->kreap_dismantle !== null) {
						$dismantleValue = (int) $obj->kreap_dismantle;
					}
					$this->db->free($resql);
				}
			}

			$dismantleLabel = dol_html_entity_decode($langs->trans('kreap_dismantle'), ENT_QUOTES | ENT_HTML5);
			$toggleValue = $dismantleValue ? 0 : 1;
			$toggleIcon = $dismantleValue ? 'fa-toggle-on font-status4' : 'fa-toggle-off opacitymedium';
			$returnAction = in_array($action, array('edit', 'create'), true)
				? '&returnaction=' . urlencode($action)
				: '';
			$toggleToken = newToken();
			if (empty($toggleToken)) {
				$toggleToken = currentToken();
			}
			$toggleUrl = dol_buildpath('/bom/bom_card.php', 1) . '?id=' . ((int) $object->id)
				. '&action=toggle_dismantle&product_id=' . $productId
				. '&value=' . $toggleValue
				. $returnAction
				. '&token=' . $toggleToken;

			$dismantleRowHtml = '<tr class="kreap-dismantle-row"><td class="titlefield">'
				. $dismantleLabel
				. '</td><td><a class="linkobject" href="' . $toggleUrl . '" title="' . $dismantleLabel . '">'
				. '<span class="fas ' . $toggleIcon . '"></span>'
				. '</a></td></tr>';
		}

		$entityRowHtml = '';
		$entityLabel = $langs->trans('Entity');
		$allEntitiesLabel = $langs->trans('AllEntities');
		if ($multicompanyEnabled) {
			if (!is_object($form)) {
				require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
				$form = new Form($this->db);
			}

			if (GETPOSTISSET('kreap_entity')) {
				$selectedEntity = GETPOSTINT('kreap_entity');
			} elseif (GETPOSTISSET('entity')) {
				$selectedEntity = GETPOSTINT('entity');
			} elseif (!empty($object->id)) {
				$selectedEntity = (int) $object->entity;
			} else {
				$selectedEntity = (int) $conf->entity;
			}

			$alternateEntityId = ($selectedEntity === 0 ? (int) $conf->entity : (int) $selectedEntity);
			if ($alternateEntityId <= 0) {
				$alternateEntityId = (int) $conf->entity;
			}

			$alternateEntityLabel = $entityLabel . ' ' . $alternateEntityId;
			if (is_object($mc) && method_exists($mc, 'getInfo') && $alternateEntityId > 0) {
				$mc->getInfo($alternateEntityId);
				if (!empty($mc->label)) {
					$alternateEntityLabel = $mc->label;
				}
			}

			$selectOptions = array('0' => $allEntitiesLabel . ' (0)');
			if ($alternateEntityId > 0) {
				$selectOptions[(string) $alternateEntityId] = $alternateEntityLabel;
			}

			$selectTypeParts = array();
			foreach ($selectOptions as $key => $label) {
				$selectTypeParts[] = $key . ':' . str_replace(',', ' ', $label);
			}
			$selectType = 'select;' . implode(',', $selectTypeParts);

			$canEdit = $this->canSetSharedBomEntity();
			$isEditMode = in_array($action, array('create', 'edit'), true);
			if ($isEditMode && $canEdit) {
				$selectHtml = $form->selectarray('kreap_entity', $selectOptions, $selectedEntity, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100 maxwidth500');
				$entityRowHtml = '<tr class="kreap-entity-row"><td class="titlefieldcreate">'.$entityLabel.'</td><td class="valuefieldcreate">'.$selectHtml.'</td></tr>';
			} elseif ($canEdit) {
				$keyHtml = $form->editfieldkey('Entity', 'kreap_entity', $selectedEntity, $object, 1, $selectType);
				$valHtml = $form->editfieldval('Entity', 'kreap_entity', $selectedEntity, $object, 1, $selectType);
				$entityRowHtml = '<tr class="kreap-entity-row"><td class="titlefield">'.$keyHtml.'</td><td class="valuefield">'.$valHtml.'</td></tr>';
			} else {
				$entityValue = ($selectedEntity === 0)
					? $allEntitiesLabel . ' (0)'
					: $alternateEntityLabel;
				$entityRowHtml = '<tr class="kreap-entity-row"><td class="titlefield">'.$entityLabel.'</td><td class="valuefield">'.dol_escape_htmltag($entityValue).'</td></tr>';
			}
		} else {
			$selectedEntity = (!empty($object->id) && isset($object->entity)) ? (int) $object->entity : (int) $conf->entity;
			$entityValue = ($selectedEntity === 0)
				? $allEntitiesLabel . ' (0)'
				: $entityLabel . ' ' . $selectedEntity;
			$entityRowHtml = '<tr class="kreap-entity-row"><td class="titlefield">'.$entityLabel.'</td><td class="valuefield">'.dol_escape_htmltag($entityValue).'</td></tr>';
		}

		$script = '';
		if ($multicompanyEnabled) {
			$script = '<script>
	jQuery(function($) {
		var $entitySelects = $(\'select[name="entity"], select[name="kreap_entity"]\');
		if ($entitySelects.length < 2) {
			return;
		}
		var $primary = $entitySelects.filter(\':visible\').first();
		if (!$primary.length) {
			$primary = $entitySelects.first();
		}
		$entitySelects.not($primary).each(function() {
			$(this).val($primary.val()).closest(\'tr\').hide();
		});
		$primary.on(\'change\', function() {
			var val = $(this).val();
			$entitySelects.not($primary).val(val);
		});
	});
</script>';
		}

		if ($script === '' && $entityRowHtml === '' && $dismantleRowHtml === '') {
			return 0;
		}

		$this->resprints = '';
		if ($dismantleRowHtml !== '') {
			$this->resprints .= $dismantleRowHtml;
		}
		if ($entityRowHtml !== '') {
			$this->resprints .= $entityRowHtml;
		}
		if ($script !== '') {
			$this->resprints .= '<tr class="field_kreap_entity_helper" style="display:none;"><td colspan="2">'.$script.'</td></tr>';
		}
		$sharedEntitySelectorPrinted = true;
		return 0;
	}

	/**
	 * Allow shared BOM records (entity 0) to be visible across entities.
	 *
	 * @param  array<string,mixed> $parameters Hook metadata
	 * @param  CommonObject|null   $object     The object to process
	 * @param  ?string             $action     Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function hookGetEntity($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('multicompany')) {
			return 0;
		}
		$element = $parameters['element'] ?? '';
		if ($element !== 'bom' && $element !== 'bom_bom' && $element !== 'bomline' && $element !== 'bom_bomline') {
			return 0;
		}

		$out = isset($parameters['out']) ? (string) $parameters['out'] : '';
		if ($out === '') {
			return 0;
		}

		$entities = preg_split('/\s*,\s*/', $out, -1, PREG_SPLIT_NO_EMPTY);
		if (!in_array('0', $entities, true)) {
			$entities[] = '0';
		}
		$entities = array_values(array_unique($entities));

		$this->resprints = implode(',', $entities);
		return 1;
	}

	/**
	 * Allow shared BOM records (entity 0) to be accessed across entities.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject|null $object The object to process
	 * @param ?string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function restrictedArea($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user;

		if (($parameters['features'] ?? '') !== 'bom') {
			return 0;
		}

		if (!empty($conf->bom) && !empty($conf->bom->multidir_output) && is_array($conf->bom->multidir_output)) {
			if (!array_key_exists(0, $conf->bom->multidir_output)) {
				$fallback = null;
				if (isset($conf->bom->multidir_output[$conf->entity])) {
					$fallback = $conf->bom->multidir_output[$conf->entity];
				} elseif (isset($conf->bom->multidir_output[1])) {
					$fallback = $conf->bom->multidir_output[1];
				} else {
					$values = array_values($conf->bom->multidir_output);
					$fallback = $values[0] ?? null;
				}
				if (($fallback === null || $fallback === '') && !empty($conf->bom->dir_output)) {
					$fallback = $conf->bom->dir_output;
				}
				if ($fallback !== null && $fallback !== '') {
					$conf->bom->multidir_output[0] = $fallback;
				}
			}
		}

		$objectId = isset($parameters['objectid']) ? (int) $parameters['objectid'] : 0;
		if ($objectId <= 0) {
			return 0;
		}

		if (!$user->hasRight('bom', 'read') && !$user->hasRight('bom', 'lire')) {
			return 0;
		}

		$sql = 'SELECT entity FROM ' . MAIN_DB_PREFIX . 'bom_bom WHERE rowid = ' . $objectId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$row = $this->db->fetch_object($resql);
		if ($row && (int) $row->entity === 0) {
			$this->results['result'] = 1;
			return 1;
		}

		return 0;
	}

	/**
	 * Early redirection hook to custom pages for contexts that don't trigger doActions (e.g. supplierpaymentcard).
	 *
	 * @param array<string,mixed> $parameters Hook metadata (context, etc...)
	 * @param CommonObject        $object     The object to process
	 * @param ?string             $action     Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                              Always 0 to keep standard header flow
	 */
	public function llxHeader($parameters, &$object, &$action, $hookmanager)
	{
		$this->redirectToCustomPages($parameters, $object, $action);

		$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
		if (preg_match('#/bom/bom_card\.php$#', $scriptPath)) {
			$arrayofjs = $parameters['arrayofjs'] ?? array();
			if (!is_array($arrayofjs)) {
				$arrayofjs = array($arrayofjs);
			}
			$arrayofjs = array_filter($arrayofjs, 'strlen');
			$jsPath = '/custom/kreaproducts/js/kreaproducts.js';
			$jsFile = DOL_DOCUMENT_ROOT . $jsPath;
			if (file_exists($jsFile)) {
				$jsPath .= '?v=' . ((int) filemtime($jsFile));
			}
			$arrayofjs[] = $jsPath;
			$parameters['arrayofjs'] = array_values(array_unique($arrayofjs));
		}
		return 0;
	}

	/**
	 * Redirect core pages to custom equivalents when needed.
	 *
	 * @param array<string,mixed> $parameters Hook metadata (context, etc...)
	 * @param CommonObject        $object     The object to process
	 * @param ?string             $action     Current action
	 * @return bool                           True if the current context was handled
	 */
	private function redirectToCustomPages($parameters, &$object, &$action)
	{
		global $conf;

		$currentcontext = ! empty($parameters['currentcontext']) ? $parameters['currentcontext'] : '';
		$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
		$isKreaCustomPage = (strpos($scriptPath, '/custom/kreaproducts/') !== false);

		// Redirect core product list to custom simplified list
		if (!$isKreaCustomPage && preg_match('#/product/list\\.php$#', $scriptPath) && getDolGlobalInt('KREAPRODUCTS_REPLACE_PRODUCT_LIST', 1)) {
			if (!defined('KREA_PRODUCTLIST_PAGE_OVERRIDE')) {
				define('KREA_PRODUCTLIST_PAGE_OVERRIDE', true);
				$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
				header('Location: ' . dol_buildpath('/custom/kreaproducts/product_list.php', 1) . $q);
				exit;
			}

			return true;
		}

		$isInventoryCard = (bool) preg_match('#/product/inventory/card\.php$#', $scriptPath);
		$isInventorySheet = (bool) preg_match('#/product/inventory/inventory\.php$#', $scriptPath);
		if ((strpos($currentcontext, 'inventorycard') !== false || $isInventoryCard || $isInventorySheet) && ! $isKreaCustomPage) {
			$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
			if (! $isPost && ! defined('KREA_INVENTORY_PAGE_OVERRIDE')) {
				define('KREA_INVENTORY_PAGE_OVERRIDE', true);
				$scriptName = basename($scriptPath);
				$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
				$target = '/kreaproducts/inventory.php';
				if ($scriptName === 'card.php') {
					$target = '/kreaproducts/inventory_card.php';
				}
				header('Location: ' . dol_buildpath($target, 1) . $q);
				exit;
			}

			return true;
		}

		$isInventoryList = (bool) preg_match('#/product/inventory/list\.php$#', $scriptPath);
		if ((strpos($currentcontext, 'inventorylist') !== false || $isInventoryList) && ! $isKreaCustomPage) {
			if (! defined('KREA_INVENTORYLIST_PAGE_OVERRIDE') && ($action === 'view' || $action === '' || $action === null)) {
				define('KREA_INVENTORYLIST_PAGE_OVERRIDE', true);
				$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
				header('Location: ' . dol_buildpath('/custom/kreaproducts/inventory_list.php', 1) . $q);
				exit;
			}

			return true;
		}

		if (strpos($currentcontext, 'supplierpaymentcard') !== false && ! $isKreaCustomPage) {
			if (! defined('KREA_SUPPLIERPAYMENT_PAGE_OVERRIDE') && ($action === 'view' || $action === '' || $action === null)) {
				define('KREA_SUPPLIERPAYMENT_PAGE_OVERRIDE', true);
				$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
				header('Location: ' . dol_buildpath('/kreaproducts/supplierpayment.php', 1) . $q);
				exit;
			}

			return true;
		}

		return false;
	}

	/**
	 * Show per-warehouse real stock summary on stock movement list when a product is selected.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param mixed $object Context object
	 * @param ?string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldPreListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$currentcontext = $parameters['currentcontext'] ?? '';
		if (strpos($currentcontext, 'stockmovementlist') === false) {
			return 0;
		}

		$productId = GETPOSTINT('idproduct');
		if ($productId <= 0) {
			return 0;
		}

		$langs->load('stocks');

		$sql = "SELECT COALESCE(SUM(ps.reel), 0) as total_stock"
			. " FROM " . MAIN_DB_PREFIX . "product_stock ps"
			. " JOIN " . MAIN_DB_PREFIX . "entrepot e ON e.rowid = ps.fk_entrepot"
			. " WHERE ps.fk_product = " . (int) $productId
			. " AND e.entity IN (" . getEntity('stock') . ")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " error loading warehouse stock: " . $this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$totalStock = 0.0;
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$totalStock = (float) $obj->total_stock;
		}
		$this->db->free($resql);

		$out = '<div style="text-align: right; padding-right: 10px;">';
		$out .= '<span class="bold">Stock total:</span> ';
		$out .= '<span class="nowraponall">' . price($totalStock, 0, $langs, 0, -1, -1) . '</span>';
		$out .= '</div>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Ensure required list hook contexts are registered in module parts.
	 *
	 * @return void
	 */
	private function ensureBomlistHookContext()
	{
		static $checked = false;

		if ($checked) {
			return;
		}
		$checked = true;

		global $conf, $user;

		if (empty($user) || empty($user->admin)) {
			return;
		}
		$entities = array($conf->entity);
		if (isModEnabled('multicompany')) {
			$entities = array();
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "entity";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$entities[] = (int) $obj->rowid;
				}
				$this->db->free($resql);
			}
		}

		if (!function_exists('dolibarr_set_const')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		}

		foreach ($entities as $entityId) {
			$rawHooks = $this->getConstValueForEntity('MAIN_MODULE_KREAPRODUCTS_HOOKS', $entityId);
			if ($rawHooks === '') {
				continue;
			}

			$hooks = json_decode($rawHooks, true);
			if (!is_array($hooks)) {
				$hooks = preg_split('/[,:;]/', $rawHooks, -1, PREG_SPLIT_NO_EMPTY);
			}
			$hooks = array_values(array_unique(array_filter(array_map('trim', $hooks))));

			$required = array('bomlist', 'bomnetneeds', 'stockmovementlist', 'main');
			$missing = array_diff($required, $hooks);
			if (empty($missing)) {
				continue;
			}

			$hooks = array_values(array_unique(array_merge($hooks, $missing)));
			$newValue = json_encode($hooks);

			dolibarr_set_const($this->db, 'MAIN_MODULE_KREAPRODUCTS_HOOKS', $newValue, 'chaine', 0, '', $entityId);
			if ((int) $entityId === (int) $conf->entity) {
				$conf->global->MAIN_MODULE_KREAPRODUCTS_HOOKS = $newValue;
				$conf->modules_parts['hooks']['kreaproducts'] = $hooks;
			}
		}
	}

	/**
	 * Fetch a const value for a specific entity without switching context.
	 *
	 * @param string $name Const name
	 * @param int $entityId Entity id
	 * @return string
	 */
	private function getConstValueForEntity($name, $entityId)
	{
		$sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE name = '" . $this->db->escape($name) . "'";
		$sql .= " AND entity = " . (int) $entityId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (string) $obj->value : '';
	}

	/**
	 * Only allow shared BOM entity selection for multicompany admins.
	 *
	 * @return bool
	 */
	private function canSetSharedBomEntity()
	{
		global $user;

		return (isModEnabled('multicompany') && $user->admin);
	}

	/* Add other hook methods here... */
}
