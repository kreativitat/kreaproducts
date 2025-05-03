<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2025		Marcelo Marinho de Araujo	<marcelomarinhoaraujo@gmail.com>
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
 * \brief   Example hook overload.
 *
 * TODO: Write detailed description here.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

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
	}


	/**
	 * Execute action
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonObject		$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 *                           				=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overload the doMassActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {        // do something only for the context 'somecontext1' or 'somecontext2'
			// @phan-suppress-next-line PhanPluginEmptyStatementForeachLoop
			foreach ($parameters['toselect'] as $objectid) {
				// Do action on each object id
			}
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = 'A text to show';
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Overload the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters     Hook metadata (context, etc...)
	 * @param	CommonObject		$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string	$action						Current action (if set). Generally create or edit or null
	 * @param	HookManager	$hookmanager			Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter
		$disabled = 1;

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {        // do something only for the context 'somecontext1' or 'somecontext2'
			$this->resprints = '<option value="0"' . ($disabled ? ' disabled="disabled"' : '') . '>' . $langs->trans("KreaProductsMassAction") . '</option>';
		}

		if (!$error) {
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Execute action before PDF (document) creation
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonObject		$object		Object output on PDF
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 *											=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function beforePDFCreation($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this) . '::executeHooks action=' . $action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		// @phan-suppress-next-line PhanPluginEmptyStatementIf
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {        // do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}

	/**
	 * Execute action after PDF (document) creation
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonDocGenerator	$pdfhandler	PDF builder handler
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 * 											=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function afterPDFCreation($parameters, &$pdfhandler, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this) . '::executeHooks action=' . $action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		// @phan-suppress-next-line PhanPluginEmptyStatementIf
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
			// do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}

	/**
	 * Overload the loadDataForCustomReports function : returns data to complete the customreport tool
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	?string				$action 		Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager    Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function loadDataForCustomReports($parameters, &$action, $hookmanager)
	{
		global $langs;

		$langs->load("kreaproducts@kreaproducts");

		$this->results = array();

		$head = array();
		$h = 0;

		if ($parameters['tabfamily'] == 'kreaproducts') {
			$head[$h][0] = dol_buildpath('/module/index.php', 1);
			$head[$h][1] = $langs->trans("Home");
			$head[$h][2] = 'home';
			$h++;

			$this->results['title'] = $langs->trans("KreaProducts");
			$this->results['picto'] = 'kreaproducts@kreaproducts';
		}

		$head[$h][0] = 'customreports.php?objecttype=' . $parameters['objecttype'] . (empty($parameters['tabfamily']) ? '' : '&tabfamily=' . $parameters['tabfamily']);
		$head[$h][1] = $langs->trans("CustomReports");
		$head[$h][2] = 'customreports';

		$this->results['head'] = $head;

		$arrayoftypes = array();
		//$arrayoftypes['kreaproducts_myobject'] = array('label' => 'MyObject', 'picto'=>'myobject@kreaproducts', 'ObjectClassName' => 'MyObject', 'enabled' => isModEnabled('kreaproducts'), 'ClassPath' => "/kreaproducts/class/myobject.class.php", 'langs'=>'kreaproducts@kreaproducts')

		$this->results['arrayoftype'] = $arrayoftypes;

		return 0;
	}

	/**
	 * Overload the restrictedArea function : check permission on an object
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer <0 if KO,
	 *												=0 if OK but we want to process standard actions too,
	 *												>0 if OK and we want to replace standard actions.
	 */
	public function restrictedArea($parameters, &$action, $hookmanager)
	{
		global $user;

		if ($parameters['features'] == 'myobject') {
			if ($user->hasRight('kreaproducts', 'myobject', 'read')) {
				$this->results['result'] = 1;
				return 1;
			} else {
				$this->results['result'] = 0;
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Execute action completeTabsHead
	 *
	 * @param	array<string,mixed>	$parameters		Array of parameters
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string				$action			'add', 'update', 'view'
	 * @param	Hookmanager			$hookmanager	Hookmanager
	 * @return	int									Return integer <0 if KO,
	 *												=0 if OK but we want to process standard actions too,
	 *												>0 if OK and we want to replace standard actions.
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf, $user;

		if (!isset($parameters['object']->element)) {
			return 0;
		}
		if ($parameters['mode'] == 'remove') {
			// used to make some tabs removed
			return 0;
		} elseif ($parameters['mode'] == 'add') {
			$langs->load('kreaproducts@kreaproducts');
			// used when we want to add some tabs
			$counter = count($parameters['head']);
			$element = $parameters['object']->element;
			$id = $parameters['object']->id;
			// verifier le type d'onglet comme member_stats où ça ne doit pas apparaitre
			// if (in_array($element, ['societe', 'member', 'contrat', 'fichinter', 'project', 'propal', 'commande', 'facture', 'order_supplier', 'invoice_supplier'])) {
			if (in_array($element, ['context1', 'context2'])) {
				$datacount = 0;

				$parameters['head'][$counter][0] = dol_buildpath('/kreaproducts/kreaproducts_tab.php', 1) . '?id=' . $id . '&amp;module=' . $element;
				$parameters['head'][$counter][1] = $langs->trans('KreaProductsTab');
				if ($datacount > 0) {
					$parameters['head'][$counter][1] .= '<span class="badge marginleftonlyshort">' . $datacount . '</span>';
				}
				$parameters['head'][$counter][2] = 'kreaproductsemails';
				$counter++;
			}
			if ($counter > 0 && (int) DOL_VERSION < 14) {
				$this->results = $parameters['head'];
				// return 1 to replace standard code
				return 1;
			} else {
				// From V14 onwards, $parameters['head'] is modifiable by referende
				return 0;
			}
		} else {
			// Bad value for $parameters['mode']
			return -1;
		}
	}

	/**
	 * Overload the doActions function : replacing the parent's function with the one below
	 *
	 * @param  array<string,mixed> $parameters  Hook metadata (context, etc...)
	 * @param  CommonObject        $object      The object to process
	 * @param  ?string             $action      Current action ("create" or "update")
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                               <0 on error, 0 to continue standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf;

		$error = 0;

		// Only on create/update of an existing product, when stock module is enabled and no batch on this item
		if (
			($action === 'create' || $action === 'update')
			&& $object->element === 'product'
			&& ! empty($object->id)
			&& isModEnabled('stock')
			&& ! $object->hasbatch()
			&& ($object->isProduct()
				|| ($object->isService() && ! empty($conf->global->STOCK_SUPPORTS_SERVICES)))
		) {
			// Grab posted checkbox value
			$val = GETPOST('stockable_product', 'int') ? 1 : 0;

			// Direct SQL update
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
	 * Render additional form options on the product card.
	 *
	 * In create/edit it shows a live checkbox, in view it shows a disabled one.
	 * It will read the current stockable_product value straight from the database.
	 *
	 * @param  array               $parameters  Hook parameters (must contain 'context')
	 * @param  CommonObject        $object      The product object.
	 * @param  string              $action      Current action ("create", "edit", or anything else for view)
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                               0 on success.
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $form;

		$this->resprints = '';

		// Only on the product card tab
		if (! in_array('productcard', explode(':', $parameters['context']))) {
			return 0;
		}

		// Only for stockable products/services without batches
		$canStockToggle = isModEnabled('stock')
			&& ! $object->hasbatch()
			&& ($object->isProduct()
				|| ($object->isService() && ! empty($conf->global->STOCK_SUPPORTS_SERVICES)));

		if (! $canStockToggle) {
			return 0;
		}

		// Fetch the real value from the DB
		$enabled = 0;
		if (! empty($object->id)) {
			$sql   = "SELECT stockable_product FROM " . MAIN_DB_PREFIX . "product";
			$sql  .= " WHERE rowid = " . ((int) $object->id);
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$row     = $db->fetch_object($resql);
				$enabled = (int) $row->stockable_product;
			}
		}

		// Build the single table row
		$this->resprints .= '<tr>';
		$this->resprints .=   '<td valign="top">'
			.     $form->textwithpicto(
				$langs->trans("StockableProduct"),
				$langs->trans("StockableProductDescription")
			)
			.   '</td>';
		$this->resprints .=   '<td>';

		if ($action === 'create' || $action === 'edit') {
			// editable checkbox
			$this->resprints .= '<input type="checkbox"'
				. ' name="stockable_product"'
				. ' value="1"'
				. ($enabled ? ' checked' : '')
				. '>';
		} else {
			// read-only checkbox
			$this->resprints .= '<input type="checkbox" readonly disabled'
				. ($enabled ? ' checked' : '')
				. '>';
		}

		$this->resprints .=   '</td>';
		$this->resprints .= '</tr>';

		return 0;
	}



	/* Add other hook methods here... */
}
