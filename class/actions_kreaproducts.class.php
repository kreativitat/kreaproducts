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
     * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
     * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
     * @param	?string				$action			Current action (if set). Generally create or edit or null
     * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
     * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
     */
    /*
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf, $langs, $user;

		$error = 0; // Error counter

		// Process only when action is 'create' or 'update', object is a product, and product has an ID.
		if (($action == 'create' || $action == 'update') && $object->element == 'product' && !empty($object->id)) {

			if ($object->array_options['options_kreap_calc_nut'] != 2) {
				// NUTRITIONAL DECLARATION
				// Retrieve nutritional values from the submitted form.
				$energy_kcal     = isset($_POST['KreaProductsEnergy_kcal']) && is_numeric($_POST['KreaProductsEnergy_kcal']) ? $_POST['KreaProductsEnergy_kcal'] : null;
				$energy_kj       = isset($_POST['KreaProductsEnergy_kj']) && is_numeric($_POST['KreaProductsEnergy_kj']) ? $_POST['KreaProductsEnergy_kj'] : null;
				$fat             = isset($_POST['KreaProductsFat']) && is_numeric($_POST['KreaProductsFat']) ? $_POST['KreaProductsFat'] : null;
				$saturates       = isset($_POST['KreaProductsSaturates']) && is_numeric($_POST['KreaProductsSaturates']) ? $_POST['KreaProductsSaturates'] : null;
				$carbohydrates   = isset($_POST['KreaProductsCarbohydrates']) && is_numeric($_POST['KreaProductsCarbohydrates']) ? $_POST['KreaProductsCarbohydrates'] : null;
				$sugars          = isset($_POST['KreaProductsSugars']) && is_numeric($_POST['KreaProductsSugars']) ? $_POST['KreaProductsSugars'] : null;
				$protein         = isset($_POST['KreaProductsProtein']) && is_numeric($_POST['KreaProductsProtein']) ? $_POST['KreaProductsProtein'] : null;
				$salt            = isset($_POST['KreaProductsSalt']) && is_numeric($_POST['KreaProductsSalt']) ? $_POST['KreaProductsSalt'] : null;
				$fiber           = isset($_POST['KreaProductsFiber']) && is_numeric($_POST['KreaProductsFiber']) ? $_POST['KreaProductsFiber'] : null;

				// If energy values are not provided, calculate them.
				if ($energy_kcal <= 0) {
					$energy_kcal = ($fat * 9) + ($carbohydrates * 4) + ($protein * 4);
				}
				if ($energy_kj <= 0) {
					$energy_kj = $energy_kcal * 4.184;
				}

				// Include and instantiate the Nutritional class.
				dol_include_once('/kreaproducts/class/nutritional.class.php');
				$nutritional = new Nutritional($this->db);

				// Check if a nutritional record already exists for this product.
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
				$resql = $this->db->query($sql);
				if ($resql && $this->db->num_rows($resql) > 0) {
					$obj = $this->db->fetch_object($resql);
					// Load the existing nutritional record.
					$nutritional->fetch($obj->rowid);
				}

				// Set (or update) nutritional fields.
				$nutritional->fk_product     = $object->id;
				$nutritional->energy_kcal    = $energy_kcal;
				$nutritional->energy_kj      = $energy_kj;
				$nutritional->fat            = $fat;
				$nutritional->saturates      = $saturates;
				$nutritional->carbohydrates  = $carbohydrates;
				$nutritional->sugars         = $sugars;
				$nutritional->protein        = $protein;
				$nutritional->salt           = $salt;
				$nutritional->fiber          = $fiber;

				// If the record doesn't exist (no id loaded), create a new one; otherwise update.
				if (empty($nutritional->id)) {
					$res = $nutritional->create($user);
					if ($res < 0) {
						$this->errors[] = $nutritional->error;
						$error++;
					}
				} else {
					$res = $nutritional->update($user);
					if ($res < 0) {
						$this->errors[] = $nutritional->error;
						$error++;
					}
				}
			}

			// ALLERGENS
			// Retrieve allergen IDs from the submitted form (adjust field names as needed)
			$selectedAllergens = GETPOST('KREAPRODUCTS_ALLERGENS', 'array');
			$selectedAllergensTraces = GETPOST('KREAPRODUCTS_ALLERGENS_TRACES', 'array');

			// Merge arrays to check if there's more than one unique allergen selected.
			$mergedAllergens = array_unique(array_merge($selectedAllergens, $selectedAllergensTraces));
			// If more than one allergen is selected and allergen with id 1 ("Sem alergenios") is present, remove it.
			if (count($mergedAllergens) > 1 && in_array(1, $mergedAllergens)) {
				$selectedAllergens = array_diff($selectedAllergens, array(1));
				$selectedAllergensTraces = array_diff($selectedAllergensTraces, array(1));
			}

			// First, remove any previous allergen associations for this product
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens WHERE fk_product = " . (int)$object->id;
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->errors[] = $this->db->error();
				$error++;
			}

			// Then, if there are selected allergens (non-traces), save each association using the ProductAllergens class
			if (!$error && !empty($selectedAllergens) && is_array($selectedAllergens)) {
				dol_include_once('/kreaproducts/class/productallergens.class.php');
				foreach ($selectedAllergens as $allergenId) {
					$allergenId = (int)$allergenId;
					if ($allergenId > 0) {
						$prodAllergen = new ProductAllergens($this->db);
						$prodAllergen->fk_product = $object->id;
						$prodAllergen->fk_allergen  = $allergenId;
						$prodAllergen->traces       = 0;
						$res = $prodAllergen->create($user);
						if ($res < 0) {
							$this->errors[] = $prodAllergen->error;
							$error++;
						}
					}
				}
			}

			// Now, if there are selected allergens for traces, save each association with traces flag set to 1.
			// Skip those allergens that are already saved in the non-traces array.
			if (
				!$error && !empty($selectedAllergensTraces) && is_array($selectedAllergensTraces)
			) {
				dol_include_once('/kreaproducts/class/productallergens.class.php');
				foreach ($selectedAllergensTraces as $allergenId) {
					$allergenId = (int)$allergenId;
					// Skip if this allergen was already processed in the non-traces list.
					if (!empty($selectedAllergens) && in_array($allergenId, $selectedAllergens)) {
						continue;
					}
					if ($allergenId > 0) {
						$prodAllergenTraces = new ProductAllergens($this->db);
						$prodAllergenTraces->fk_product = $object->id;
						$prodAllergenTraces->fk_allergen  = $allergenId;
						$prodAllergenTraces->traces       = 1;
						$res = $prodAllergenTraces->create($user);
						if ($res < 0) {
							$this->errors[] = $prodAllergenTraces->error;
							$error++;
						}
					}
				}
			}
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = 'Nutritional declaration saved successfully';
			return 0; // Continue standard processing.
		} else {
			$this->errors[] = 'Error saving nutritional declaration';
			return -1;
		}
	}
    */

    /**
     * Render additional form options for product nutritional declaration using the Nutritional class.
     *
     * This method displays nutritional fields on the product card. In create/edit mode it shows input fields
     * populated with existing data (if any) from the nutritional table. In view (read-only) mode it displays the values.
     *
     * @param array $parameters Hook parameters (should contain a key 'context', e.g., "productcard")
     * @param CommonObject $object The product object.
     * @param string $action Current action ("create", "edit", or view)
     * @param HookManager $hookmanager Hook manager object.
     * @return int 0 on success.
     */
    /*
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $form;

		$this->resprints = '';

		// Check that we are on the product card.
		if (in_array('productcard', explode(':', $parameters['context']))) {

			// Include and instantiate the Nutritional class.
			dol_include_once('/kreaproducts/class/nutritional.class.php');
			$nutritional = new Nutritional($db);

			// Attempt to load the nutritional record for this product.
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				$nutritional->fetch($obj->rowid);
			}

			// In our case we do not need the ZS stores stuff so we simply initialize saved allergen data.
			$savedAllergensArray = array();

			// Get saved allergens for this product from our relation table
			$sql = "SELECT fk_allergen, traces FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens WHERE fk_product = " . (int)$object->id;
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					if ($obj->traces == 1) {
						$savedAllergensTracesArray[] = $obj->fk_allergen;
					} else {
						$savedAllergensArray[] = $obj->fk_allergen;
					}
				}
			}
			if ($action == 'create' || $action == 'edit') {
				if (empty($object->array_options['options_kreap_calc_nut'])) {
					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><strong>' . $langs->trans("KreaProductsNutritionalDeclaration") . '</strong></td></tr>' . "\n";

					// Energy (kcal)
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsEnergy_kcal") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsEnergy_kcal" value="' . (!empty($nutritional->energy_kcal) ? $nutritional->energy_kcal : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Energy (kj)
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsEnergy_kj") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsEnergy_kj" value="' . (!empty($nutritional->energy_kj) ? $nutritional->energy_kj : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Fat
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsFat") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsFat" value="' . (!empty($nutritional->fat) ? $nutritional->fat : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Saturates
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSaturates") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsSaturates" value="' . (!empty($nutritional->saturates) ? $nutritional->saturates : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Carbohydrates
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsCarbohydrates") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsCarbohydrates" value="' . (!empty($nutritional->carbohydrates) ? $nutritional->carbohydrates : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Sugars
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSugars") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsSugars" value="' . (!empty($nutritional->sugars) ? $nutritional->sugars : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Protein
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsProtein") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsProtein" value="' . (!empty($nutritional->protein) ? $nutritional->protein : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Salt
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSalt") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsSalt" value="' . (!empty($nutritional->salt) ? $nutritional->salt : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					// Fiber
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsFiber") . '</td><td colspan="3">';
					$this->resprints .= '<input type="text" class="flat" name="KreaProductsFiber" value="' . (!empty($nutritional->fiber) ? $nutritional->fiber : '0') . '" size="10" />';
					$this->resprints .= '</td></tr>';

					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
				}

				// Allergens

				// Retrieve active allergens from dictionary table
				$TAllergens = array();
				$sql = "SELECT rowid, code, label, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE active = 1 ORDER BY label";
				$resql = $db->query($sql);
				if ($resql) {
					while ($obj = $db->fetch_object($resql)) {
						$TAllergens[$obj->rowid] = $langs->trans($obj->code);
					}
				}

				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><strong>' . $langs->trans("KreaProductsProductsCardTitle") . '</strong></td></tr>' . "\n";

				// Allergens selector
				$this->resprints .= '<tr><td>' . $langs->trans("Krea_Products_Allergens") . '</td><td colspan="3">';
				// Use a multiselect field (change the field name as needed)
				$this->resprints .= $form->multiselectarray('KREAPRODUCTS_ALLERGENS', $TAllergens, $savedAllergensArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS"');
				$this->resprints .= '</td></tr>';

				// Allergens traces selector
				$this->resprints .= '<tr><td>' . $langs->trans("Krea_Products_AllergensTraces") . '</td><td colspan="3">';
				// Use a multiselect field (change the field name as needed)
				$this->resprints .= $form->multiselectarray('KREAPRODUCTS_ALLERGENS_TRACES', $TAllergens, $savedAllergensTracesArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS_TRACES"');
				$this->resprints .= '</td></tr>';

				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
			} else {
				if (empty($object->array_options['options_kreap_calc_nut'])) {
					// Read-only view mode.
					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><strong>' . $langs->trans("KreaProductsNutritionalDeclaration") . '</strong></td></tr>' . "\n";

					// Energy (kcal)
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsEnergy_kcal") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->energy_kcal) ? $nutritional->energy_kcal : '0');
					$this->resprints .= '</td></tr>';

					// Energy (kj)
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsEnergy_kj") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->energy_kj) ? $nutritional->energy_kj : '0');
					$this->resprints .= '</td></tr>';

					// Fat
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsFat") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->fat) ? $nutritional->fat : '0');
					$this->resprints .= '</td></tr>';

					// Saturates
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSaturates") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->saturates) ? $nutritional->saturates : '0');
					$this->resprints .= '</td></tr>';

					// Carbohydrates
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsCarbohydrates") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->carbohydrates) ? $nutritional->carbohydrates : '0');
					$this->resprints .= '</td></tr>';

					// Sugars
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSugars") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->sugars) ? $nutritional->sugars : '0');
					$this->resprints .= '</td></tr>';

					// Protein
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsProtein") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->protein) ? $nutritional->protein : '0');
					$this->resprints .= '</td></tr>';

					// Salt
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsSalt") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->salt) ? $nutritional->salt : '0');
					$this->resprints .= '</td></tr>';

					// Fiber
					$this->resprints .= '<tr><td>' . $langs->trans("KreaProductsFiber") . '</td><td colspan="3">';
					$this->resprints .= htmlspecialchars(!empty($nutritional->fiber) ? $nutritional->fiber : '0');
					$this->resprints .= '</td></tr>';

					$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
				}

				// Allergens

				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><strong>' . $langs->trans("KreaProductsProductsCardTitle") . '</strong></td></tr>' . "\n";

				// Read-only view: Display selected allergens
				$this->resprints .= '<tr><td>' . $langs->trans("Krea_Products_Allergens") . '</td><td colspan="3">';
				if (!empty($savedAllergensArray)) {
					foreach ($savedAllergensArray as $allergenId) {
						$sql = "SELECT code, label, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
						$resql = $db->query($sql);
						if ($resql && $obj = $db->fetch_object($resql)) {
							$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
							$this->resprints .= '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
							$this->resprints .= '<img src="' . $iconPath . '" alt="' . $langs->trans($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
							$this->resprints .= '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
							$this->resprints .= '</div>';
						}
					}
				} else {
					$this->resprints .= $langs->trans("NoneSelected");
				}
				$this->resprints .= '</td></tr>';

				// Read-only view: Display selected allergens
				$this->resprints .= '<tr><td>' . $langs->trans("Krea_Products_AllergensTraces") . '</td><td colspan="3">';
				if (!empty($savedAllergensTracesArray)) {
					foreach ($savedAllergensTracesArray as $allergenId) {
						$sql = "SELECT code, label, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
						$resql = $db->query($sql);
						if ($resql && $obj = $db->fetch_object($resql)) {
							$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
							$this->resprints .= '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
							$this->resprints .= '<img src="' . $iconPath . '" alt="' . $langs->trans($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
							$this->resprints .= '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
							$this->resprints .= '</div>';
						}
					}
				} else {
					$this->resprints .= $langs->trans("NoneSelected");
				}
				$this->resprints .= '</td></tr>';

				$this->resprints .= '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>' . "\n";
			}
		}
		return 0;
	}
    */

    /* Add other hook methods here... */
}
