<?php
/* Copyright (C) 2001-2007  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2020  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005       Eric Seigne             <eric.seigne@ryxeo.com>
 * Copyright (C) 2005-2018  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2006       Andre Cianfarani        <acianfa@free.fr>
 * Copyright (C) 2011-2014  Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2015       Raphaël Doursenaud      <rdoursenaud@gpcsolutions.fr>
 * Copyright (C) 2023       Benjamin Falière        <benjamin.faliere@altairis.fr>
 * Copyright (C) 2024       Kreativitat             <mail@kreativitat.com>
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
 *  \file       htdocs/custom/kreaproducts/associatedProducts.php
 *  \ingroup    product
 *  \brief      Page of product file
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsNutrientUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsAllergenUpdater.class.php';

// Load translation files required by the page
$langs->loadLangs(array('bills', 'products', 'stocks'));

$id     = GETPOST('id', 'int');
$ref    = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel  = GETPOST('cancel', 'alpha');
$key     = GETPOST('key');
$parent  = GETPOST('parent');

// Security check
if (!empty($user->socid)) {
	$socid = $user->socid;
}
$fieldvalue = (!empty($id) ? $id : (!empty($ref) ? $ref : ''));
$fieldtype  = (!empty($ref) ? 'ref' : 'rowid');

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('productcompositioncard', 'globalcard'));

$object = new Product($db);
$objectid = 0;
if ($id > 0 || !empty($ref)) {
	$result = $object->fetch($id, $ref);
	// IMPORTANT: load all extra–fields so that current values are available.
	$object->fetch_optionals();
	$objectid = $object->id;
	$id = $object->id;
}

$result = restrictedArea($user, 'produit|service', $fieldvalue, 'product&product', '', '', $fieldtype);

if ($object->id > 0) {
	if ($object->type == $object::TYPE_PRODUCT) {
		restrictedArea($user, 'produit', $object->id, 'product&product', '', '');
	}
	if ($object->type == $object::TYPE_SERVICE) {
		restrictedArea($user, 'service', $object->id, 'product&product', '', '');
	}
} else {
	restrictedArea($user, 'produit|service', $fieldvalue, 'product&product', '', '', $fieldtype);
}
$usercanread   = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->lire) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'lire')));
$usercancreate = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->creer) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'creer')));
$usercandelete = (($object->type == Product::TYPE_PRODUCT && $user->rights->produit->supprimer) || ($object->type == Product::TYPE_SERVICE && $user->rights->service->supprimer));


/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Add subproduct to product
	if ($action == 'add_prod' && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
		$error = 0;
		$maxprod = GETPOST("max_prod", 'int');
		for ($i = 0; $i < $maxprod; $i++) {
			$qty = price2num(GETPOST("prod_qty_" . $i, 'alpha'), 'MS');
			if ($qty > 0) {
				if ($object->add_sousproduit($id, GETPOST("prod_id_" . $i, 'int'), $qty, GETPOST("prod_incdec_" . $i, 'int')) > 0) {
					$action = 'edit';
				} else {
					$error++;
					$action = 're-edit';
					if ($object->error == "isFatherOfThis") {
						setEventMessages($langs->trans("ErrorAssociationIsFatherOfThis"), null, 'errors');
					} else {
						setEventMessages($object->error, $object->errors, 'errors');
					}
				}
			} else {
				if ($object->del_sousproduit($id, GETPOST("prod_id_" . $i, 'int')) > 0) {
					$action = 'edit';
				} else {
					$error++;
					$action = 're-edit';
					setEventMessages($object->error, $object->errors, 'errors');
				}
			}
		}
		if (!$error) {
			header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
			exit;
		}
	} elseif ($action === 'save_composed_product') {
		$TProduct = GETPOST('TProduct', 'array');
		if (!empty($TProduct)) {
			foreach ($TProduct as $id_product => $row) {
				if ($row['qty'] > 0) {
					$object->update_sousproduit($id, $id_product, $row['qty'], isset($row['incdec']) ? 1 : 0);
				} else {
					$object->del_sousproduit($id, $id_product);
				}
			}
			setEventMessages('RecordSaved', null);
		}
		$action = '';
		header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
		exit;
	}
}

if ($action == 'save_kreaproducts_nutrition') {
	dol_syslog("Starting save_kreaproducts_nutrition action for product ID: " . (int) $object->id);

	// Check if a nutritional record already exists for this product.
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
	$resql = $db->query($sql);

	$existing_rowid = null;
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		$existing_rowid = $obj->rowid;
		dol_syslog("Existing nutrition record found: " . $existing_rowid);
	}
	$db->free($resql);

	// Mandatory fields only for initial insertion.
	$mandatoryData = [
		'fk_product'    => (int) $object->id,
		'date_creation' => date('Y-m-d H:i:s'),
		'fk_user_creat' => $user->id,
	];

	// If no record exists, create one with just the mandatory fields.
	if (!$existing_rowid) {
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreaproducts_nutritional (";
		$sql .= implode(", ", array_keys($mandatoryData)) . ") VALUES ('";
		$sql .= implode("', '", array_map([$db, 'escape'], array_values($mandatoryData))) . "')";
		dol_syslog("Executing INSERT SQL: " . $sql);
		$res = $db->query($sql);
		if ($res) {
			$existing_rowid = $db->last_insert_id(MAIN_DB_PREFIX . "kreaproducts_nutritional");
			dol_syslog("Nutritional record created with mandatory fields (Row ID: " . $existing_rowid . ")");
		} else {
			dol_syslog("Error inserting mandatory nutritional data: " . $db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans("ErrorSavingData") . ": " . $db->lasterror(), null, 'errors');
			return;
		}
	}

	// Prepare additional nutritional data from the POST values.
	$updateData = [
		'energy_kcal'   => (isset($_POST['nutritional_energy_kcal']) && is_numeric($_POST['nutritional_energy_kcal'])) ? (float) $_POST['nutritional_energy_kcal'] : null,
		'energy_kj'     => (isset($_POST['nutritional_energy_kj']) && is_numeric($_POST['nutritional_energy_kj'])) ? (float) $_POST['nutritional_energy_kj'] : null,
		'fat'           => (isset($_POST['nutritional_fat']) && is_numeric($_POST['nutritional_fat'])) ? (float) $_POST['nutritional_fat'] : null,
		'saturates'     => (isset($_POST['nutritional_saturates']) && is_numeric($_POST['nutritional_saturates'])) ? (float) $_POST['nutritional_saturates'] : null,
		'carbohydrates' => (isset($_POST['nutritional_carbohydrates']) && is_numeric($_POST['nutritional_carbohydrates'])) ? (float) $_POST['nutritional_carbohydrates'] : null,
		'sugars'        => (isset($_POST['nutritional_sugars']) && is_numeric($_POST['nutritional_sugars'])) ? (float) $_POST['nutritional_sugars'] : null,
		'protein'       => (isset($_POST['nutritional_protein']) && is_numeric($_POST['nutritional_protein'])) ? (float) $_POST['nutritional_protein'] : null,
		'salt'          => (isset($_POST['nutritional_salt']) && is_numeric($_POST['nutritional_salt'])) ? (float) $_POST['nutritional_salt'] : null,
		'fiber'         => (isset($_POST['nutritional_fiber']) && is_numeric($_POST['nutritional_fiber'])) ? (float) $_POST['nutritional_fiber'] : null,
	];

	// Build and execute the UPDATE SQL query.
	$updateSQL = "UPDATE " . MAIN_DB_PREFIX . "kreaproducts_nutritional SET ";
	$setClauses = [];
	foreach ($updateData as $field => $value) {
		// If value is null, we explicitly set it to NULL, otherwise use the escaped value.
		$setClauses[] = ($value === null) ? "$field = NULL" : "$field = " . $db->escape($value);
	}
	$updateSQL .= implode(", ", $setClauses);
	$updateSQL .= " WHERE rowid = " . (int)$existing_rowid;

	dol_syslog("Executing UPDATE SQL: " . $updateSQL);
	$resUpdate = $db->query($updateSQL);
	if ($resUpdate) {
		dol_syslog("Nutritional data successfully updated (Row ID: " . $existing_rowid . ")");
		setEventMessages($langs->trans("NutritionalDataUpdated"), null, 'mesgs');
	} else {
		dol_syslog("Error updating nutritional data: " . $db->lasterror(), LOG_ERR);
		setEventMessages($langs->trans("ErrorUpdatingData") . ": " . $db->lasterror(), null, 'errors');
	}

	// Call the updater method; $user is provided by the Dolibarr environment.
	$result = KreaProductsNutrientUpdater::updateNutrientAttributes($object->id, $user);
	if ($result < 0) {
		$message = "Error updating nutritional attributes for product ID $object->id.";
	} else {
		$message = "Nutritional attributes updated successfully for product ID $object->id.";
	}
}

if ($action == 'updateAllergens') {
	KreaProductsAllergenUpdater::updateAllergenAttributes($object->id, $user, 0);
	setEventMessages($langs->trans("AllergenUpdateFired"), null, 'mesgs');
}




















if ($action == 'saveAllergens' && $usercancreate) {
	// Retrieve submitted allergens (non-traces and traces)
	$selectedAllergens       = GETPOST('KREAPRODUCTS_ALLERGENS', 'array');
	$selectedAllergensTraces = GETPOST('KREAPRODUCTS_ALLERGENS_TRACES', 'array');

	// Example: merge arrays and adjust if "Sem alergenios" (id 1) is selected along with others
	$mergedAllergens = array_unique(array_merge($selectedAllergens, $selectedAllergensTraces));
	if (count($mergedAllergens) > 1 && in_array(1, $mergedAllergens)) {
		$selectedAllergens = array_diff($selectedAllergens, array(1));
		$selectedAllergensTraces = array_diff($selectedAllergensTraces, array(1));
	}

	// Remove previous allergen associations for this product
	$sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens WHERE fk_product = " . (int)$object->id;
	$resql = $db->query($sql);
	if (!$resql) {
		$this->errors[] = $db->error();
	}

	// Insert new associations (non-traces)
	if (!empty($selectedAllergens) && is_array($selectedAllergens)) {
		dol_include_once('/kreaproducts/class/productallergens.class.php');
		foreach ($selectedAllergens as $allergenId) {
			$allergenId = (int)$allergenId;
			if ($allergenId > 0) {
				$prodAllergen = new ProductAllergens($db);
				$prodAllergen->fk_product = $object->id;
				$prodAllergen->fk_allergen  = $allergenId;
				$prodAllergen->traces       = 0;
				$res = $prodAllergen->create($user);
				if ($res < 0) {
					$this->errors[] = $prodAllergen->error;
				}
			}
		}
	}

	// Insert associations for allergens with traces (skip duplicates)
	if (!empty($selectedAllergensTraces) && is_array($selectedAllergensTraces)) {
		dol_include_once('/kreaproducts/class/productallergens.class.php');
		foreach ($selectedAllergensTraces as $allergenId) {
			$allergenId = (int)$allergenId;
			if (!empty($selectedAllergens) && in_array($allergenId, $selectedAllergens)) {
				continue;
			}
			if ($allergenId > 0) {
				$prodAllergenTraces = new ProductAllergens($db);
				$prodAllergenTraces->fk_product = $object->id;
				$prodAllergenTraces->fk_allergen  = $allergenId;
				$prodAllergenTraces->traces       = 1;
				$res = $prodAllergenTraces->create($user);
				if ($res < 0) {
					$this->errors[] = $prodAllergenTraces->error;
				}
			}
		}
	}
	setEventMessages($langs->trans("AllergenUpdateFired"), null, 'mesgs');
	// After saving, you might want to switch back to view mode.
	$action = '';
}






















/*
 * View
 */

$form         = new Form($db);
$formproduct  = new FormProduct($db);
$product_fourn = new ProductFournisseur($db);
$productstatic = new Product($db);

// action recherche des produits par mot-cle et/ou par categorie
if ($action == 'search') {
	$current_lang = $langs->getDefaultLang();
	$sql = 'SELECT DISTINCT p.rowid, p.ref, p.label, p.fk_product_type as type, p.barcode, p.price, p.price_ttc, p.price_base_type, p.entity,';
	$sql .= ' p.fk_product_type, p.tms as datem, p.tobatch';
	$sql .= ', p.tosell as status, p.tobuy as status_buy';
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		$sql .= ', pl.label as labelm, pl.description as descriptionm';
	}
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object);
	$sql .= $hookmanager->resPrint;
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'product as p';
	$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'categorie_product as cp ON p.rowid = cp.fk_product';
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_lang as pl ON pl.fk_product = p.rowid AND lang='" . ($current_lang) . "'";
	}
	$sql .= ' WHERE p.entity IN (' . getEntity('product') . ')';
	$parameters = array();
	$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object);
	$sql .= $hookmanager->resPrint;
	if ($key != "") {
		$params = array('p.ref', 'p.label', 'p.description', 'p.note');
		if (getDolGlobalInt('MAIN_MULTILANGS')) {
			$params[] = 'pl.label';
			$params[] = 'pl.description';
			$params[] = 'pl.note';
		}
		if (isModEnabled('barcode')) {
			$params[] = 'p.barcode';
		}
		$sql .= natural_search($params, $key);
	}
	if (isModEnabled('categorie') && !empty($parent) && $parent != -1) {
		$sql .= " AND cp.fk_categorie ='" . $db->escape($parent) . "'";
	}
	$sql .= " ORDER BY p.ref ASC";
	$resql = $db->query($sql);
}

$title = $langs->trans('ProductServiceCard');
$help_url = '';
$shortlabel = dol_trunc($object->label, 16);
if (GETPOST("type") == '0' || ($object->type == Product::TYPE_PRODUCT)) {
	$title = $langs->trans('Product') . " " . $shortlabel . " - " . $langs->trans('AssociatedProducts');
	$help_url = 'EN:Module_Products|FR:Module_Produits|ES:M&oacute;dulo_Productos|DE:Modul_Produkte';
}
if (GETPOST("type") == '1' || ($object->type == Product::TYPE_SERVICE)) {
	$title = $langs->trans('Service') . " " . $shortlabel . " - " . $langs->trans('AssociatedProducts');
	$help_url = 'EN:Module_Services_En|FR:Module_Services|ES:M&oacute;dulo_Servicios|DE:Modul_Leistungen';
}

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-kreaproducts page-card_krea_subproduct');

$head = product_prepare_head($object);
$titre = $langs->trans("CardProduct" . $object->type);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');
print dol_get_fiche_head($head, 'krea_subproduct', $titre, -1, $picto);

if ($id > 0 || !empty($ref)) {
	// Product card
	if ($user->hasRight('produit', 'lire') || $user->hasRight('service', 'lire')) {
		$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
		$shownav = 1;
		if ($user->socid && !in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
			$shownav = 0;
		}
		dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref', '');
		if ($object->type != Product::TYPE_SERVICE || getDolGlobalString('STOCK_SUPPORTS_SERVICES') || !getDolGlobalString('PRODUIT_MULTIPRICES')) {
			print '<div class="fichecenter">';
			print '<div class="fichehalfleft">';
			print '<div class="underbanner clearboth"></div>';
			print '<table class="border centpercent tableforfield">';
			// Type
			if (isModEnabled("product") && isModEnabled("service")) {
				$typeformat = 'select;0:' . $langs->trans("Product") . ',1:' . $langs->trans("Service");
				print '<tr><td class="titlefield">';
				print (!getDolGlobalString('PRODUCT_DENY_CHANGE_PRODUCT_TYPE')) ? $form->editfieldkey("Type", 'fk_product_type', $object->type, $object, $usercancreate, $typeformat) : $langs->trans('Type');
				print '</td><td>';
				print $form->editfieldval("Type", 'fk_product_type', $object->type, $object, $usercancreate, $typeformat);
				print '</td></tr>';
			}
			print '</table>';
			print '</div><div class="fichehalfright">';
			print '<div class="underbanner clearboth"></div>';
			print '<table class="border centpercent tableforfield">';
			// Nature
			if ($object->type != Product::TYPE_SERVICE) {
				if (!getDolGlobalString('PRODUCT_DISABLE_NATURE')) {
					print '<tr><td>' . $form->textwithpicto($langs->trans("NatureOfProductShort"), $langs->trans("NatureOfProductDesc")) . '</td><td>';
					print $object->getLibFinished();
					print '</td></tr>';
				}
			}
			if (!getDolGlobalString('PRODUIT_MULTIPRICES')) {
				// Price
				print '<tr><td class="titlefield">' . $langs->trans("SellingPrice") . '</td><td>';
				if ($object->price_base_type == 'TTC') {
					print price($object->price_ttc) . ' ' . $langs->trans($object->price_base_type);
				} else {
					print price($object->price) . ' ' . $langs->trans($object->price_base_type ? $object->price_base_type : 'HT');
				}
				print '</td></tr>';
				// Price minimum
				print '<tr><td>' . $langs->trans("MinPrice") . '</td><td>';
				if ($object->price_base_type == 'TTC') {
					print price($object->price_min_ttc) . ' ' . $langs->trans($object->price_base_type);
				} else {
					print price($object->price_min) . ' ' . $langs->trans($object->price_base_type ? $object->price_base_type : 'HT');
				}
				print '</td></tr>';
			}
			print '</table>';
			print '</div>';
			print '</div>';
		}
		print dol_get_fiche_end();

		print '<br>';

		/**
		 * This code snippet checks if the current product has an associated **disassemble BOM** (Bill of Materials) in the system. 
		 * If a BOM of type "disassemble" exists (where `bomtype = 1`), the script fetches and displays the **origin product** 
		 * associated with the BOM. The origin product is displayed as a clickable link that directs the user to the product's 
		 * detailed page. 
		 * 
		 * Additionally, this logic only executes if the BOM module is enabled in the Dolibarr system.
		 */
		if (!empty($conf->bom->enabled)) {

			// Fetch all BOMs and the origin product for the current product
			$sql_bom = "SELECT b.rowid, b.bomtype, b.fk_product AS fk_product_origin, p.ref, p.label
                FROM " . MAIN_DB_PREFIX . "bom_bom AS b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline AS bl ON b.rowid = bl.fk_bom
                JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product
                WHERE bl.fk_product = " . (int)$object->id . " AND b.bomtype = 1";

			$resql_bom = $db->query($sql_bom);
			$boms = [];

			// Check if the query returns results
			if ($resql_bom) {
				while ($obj_bom = $db->fetch_object($resql_bom)) {
					// Store each BOM's origin product and BOM row ID
					$boms[] = array(
						'bom_id' => $obj_bom->rowid,        // The BOM ID
						'product_id' => $obj_bom->fk_product_origin,  // The origin product ID
						'ref' => $obj_bom->ref,             // The origin product reference
						'label' => $obj_bom->label          // The origin product label
					);
				}
			}
			if (count($boms) > 0) {

				// Only display the table if there is at least one BOM
				print '<div class="fichecenter">';

				// Print the title of the section
				print load_fiche_titre($langs->trans("BOMExistsAndOriginProduct"), '', '');

				// Begin table structure
				print '<table class="liste">';
				print '<tr class="liste_titre">';

				// Column headers
				print '<td>' . $langs->trans('BOMExists') . '</td>';
				print '<td>' . $langs->trans('OriginProductId') . '</td>';
				print '<td>' . $langs->trans('OriginProduct') . '</td>';
				print '</tr>';


				// If BOMs exist, display each one
				foreach ($boms as $bom) {
					print '<tr class="oddeven">';
					// Link to the BOM (assuming there is a page that shows BOM details)
					print '<td><a href="' . dol_buildpath('/bom/bom_card.php?id=' . $bom['bom_id'], 1) . '">' .  $langs->trans('kreaproducts_BOM') . ' #' . $bom['bom_id'] . '</a></td>';

					// Display the origin product with a link to the product card
					print '<td><a href="' . dol_buildpath('/product/card.php?id=' . $bom['product_id'], 1) . '">' . $bom['ref'] . '</a></td>';
					print '<td><a href="' . dol_buildpath('/product/card.php?id=' . $bom['product_id'], 1) . '">' . $bom['label'] . '</a></td>';
					print '</tr>';
				}


				print '</table>';
				print '</div>';
			}
		}

		$prodsfather = $object->getFather(); // Parent Products
		$object->get_sousproduits_arbo(); // Load $object->sousprods
		$parent_label = $object->label;
		$prods_arbo = $object->get_arbo_each_prod();
		$tmpid = $id;
		if (!empty($conf->use_javascript_ajax)) {
			$nboflines = $prods_arbo;
			$table_element_line = 'product_association';
			include DOL_DOCUMENT_ROOT . '/core/tpl/ajaxrow.tpl.php';
		}
		$id = $tmpid;
		$nbofsubsubproducts = count($prods_arbo);
		$prodschild = $object->getChildsArbo($id, 1);
		$nbofsubproducts = count($prodschild);

		print '<div class="fichecenter">';
		$atleastonenotdefined = 0;
		print load_fiche_titre($langs->trans("ProductAssociationList"), '', '');
		print '<form name="formComposedProduct" action="' . $_SERVER['PHP_SELF'] . '" method="post">';
		print '<input type="hidden" name="token" value="' . newToken() . '" />';
		print '<input type="hidden" name="action" value="save_composed_product" />';
		print '<input type="hidden" name="id" value="' . $id . '" />';
		print '<table id="tablelines" class="ui-sortable liste nobottom">';
		print '<tr class="liste_titre nodrag nodrop">';
		// Rank
		print '<td>' . $langs->trans('Position') . '</td>';
		// Product ref
		print '<td>' . $langs->trans('ComposedProduct') . '</td>';
		// Product label
		print '<td>' . $langs->trans('Label') . '</td>';
		// Min supplier price
		print '<td class="right" colspan="2">' . $langs->trans('Custo do ingrediente') . '</td>';
		// Stock
		if (isModEnabled('stock')) {
			print '<td class="right">' . $langs->trans('Stock') . '</td>';
		}
		// Hook fields
		$parameters = array();
		$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters);
		print $hookmanager->resPrint;
		// Qty in kit
		print '<td class="center">' . $langs->trans('Qty') . '</td>';
		// Valor por componente
		print '<td class="right" colspan="2">Custo por componente</td>';
		// Stoc inc/dev
		print '<td class="center">' . $langs->trans('ComposedProductIncDecStock') . '</td>';
		// Move
		print '<td class="linecolmove" style="width: 10px"></td>';
		print '</tr>' . "\n";

		$totalsell = 0;
		$total = 0;
		if (count($prods_arbo)) {
			foreach ($prods_arbo as $value) {
				$productstatic->fetch($value['id']);
				if ($value['level'] <= 1) {
					print '<tr id="' . $object->sousprods[$parent_label][$value['id']][6] . '" class="drag drop oddeven level1">';
					// Rank
					print '<td>' . $object->sousprods[$parent_label][$value['id']][7] . '</td>';
					$notdefined = 0;
					$nb_of_subproduct = $value['nb'];
					// Product ref
					print '<td>' . $productstatic->getNomUrl(1, 'auto') . '</td>';
					// Product label
					print '<td title="' . dol_escape_htmltag($productstatic->label) . '" class="tdoverflowmax150">' . dol_escape_htmltag($productstatic->label) . '</td>';
					// Best buying price
					print '<td class="right">';
					if ($product_fourn->find_min_price_product_fournisseur($productstatic->id) > 0) {
						print $langs->trans("BuyingPriceMinShort") . ': ';
						if ($product_fourn->product_fourn_price_id > 0) {
							print $product_fourn->display_price_product_fournisseur(0, 0);
						} else {
							print $langs->trans("NotDefined");
							$notdefined++;
							$atleastonenotdefined++;
						}
					}
					print '</td>';
					// For avoid a non-numeric value
					$fourn_unitprice = !empty($productstatic->cost_price) ? $productstatic->cost_price : (!empty($product_fourn->fourn_unitprice) ? $product_fourn->fourn_unitprice : $product_fourn->pmp);
					$fourn_remise_percent = (!empty($product_fourn->fourn_remise_percent) ? $product_fourn->fourn_remise_percent : 0);
					$fourn_remise = (!empty($product_fourn->fourn_remise) ? $product_fourn->fourn_remise : 0);
					$unitline = price2num(($fourn_unitprice * (1 - ($fourn_remise_percent / 100)) - $fourn_remise), 'MU');
					$totalline = price2num($value['nb'] * ($fourn_unitprice * (1 - ($fourn_remise_percent / 100)) - $fourn_remise), 'MT');
					$total +=  $totalline;
					print '<td class="right nowraponall">';
					print ($notdefined ? '' : ($value['nb'] > 1 ? $value['nb'] . 'x ' : '') . '<span class="amount">' . price($unitline, '', '', 0, 0, 4, $conf->currency)) . '</span>';
					print '</td>';
					// Stock
					if (isModEnabled('stock')) {
						print '<td class="right">' . number_format((float)$value['stock'], 4, '.', '') . '</td>';
					}
					// Hook fields
					$parameters = array();
					$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $productstatic);
					print $hookmanager->resPrint;
					// Qty + IncDec
					if ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')) {
						print '<td class="center"><input type="text" value="' . $nb_of_subproduct . '" name="TProduct[' . $productstatic->id . '][qty]" class="right width40" /></td>';
						$custo_ingrediente = $fourn_unitprice * $nb_of_subproduct;
						print '<td class="right" colspan="2">' . number_format((float)$custo_ingrediente, 4, '.', '') . " €" . '</td>';
						print '<td class="center"><input type="checkbox" name="TProduct[' . $productstatic->id . '][incdec]" value="1" ' . ($value['incdec'] == 1 ? 'checked' : '') . ' /></td>';
					} else {
						print '<td>' . $nb_of_subproduct . '</td>';
						print '<td>' . ($value['incdec'] == 1 ? 'x' : '') . '</td>';
					}
					// Move action
					print '<td class="linecolmove tdlineupdown center"></td>';
					print '</tr>' . "\n";
				} else {
					$hide = '';
					if (!getDolGlobalString('PRODUCT_SHOW_SUB_SUB_PRODUCTS')) {
						$hide = ' hideobject';
					}
					print '<tr class="oddeven' . $hide . '" id="sub-' . $value['id_parent'] . '" data-ignoreidfordnd=1>';
					$productstatic->ref = $value['ref'];
					// Rank
					print '<td></td>';
					// Product ref
					print '<td>';
					for ($i = 0; $i < $value['level']; $i++) {
						print ' &nbsp; &nbsp; ';
					}
					print $productstatic->getNomUrl(1, 'auto');
					print '</td>';
					// Product label
					print '<td>' . dol_escape_htmltag($productstatic->label) . '</td>';
					// Best buying price
					print '<td>&nbsp;</td>';
					print '<td>&nbsp;</td>';
					// Best selling price
					print '<td>&nbsp;</td>';
					print '<td>&nbsp;</td>';
					// Stock
					if (isModEnabled('stock')) {
						print '<td></td>';
					}
					// Hook fields
					$parameters = array();
					$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $productstatic);
					print $hookmanager->resPrint;
					// Qty in kit
					print '<td class="right">' . dol_escape_htmltag($value['nb']) . '</td>';
					// Inc/dec
					print '<td>&nbsp;</td>';
					// Action move
					print '<td>&nbsp;</td>';
					print '</tr>' . "\n";
				}
			}
			// Total
			print '<tr class="liste_total">';
			print '<td></td>';
			print '<td class="liste_total"></td>';
			print '<td class="liste_total"></td>';
			print '<td></td>';
			print '<td></td>';
			print '<td></td>';
			print '<td class="liste_total right">' . $langs->trans("TotalBuyingPriceMinShort") . '</td>';
			print '<td class="liste_total right">';
			if ($atleastonenotdefined) {
				print $langs->trans("Unknown") . ' (' . $langs->trans("SomeSubProductHaveNoPrices") . ')';
			}
			print($atleastonenotdefined ? '' : price($total, '', '', 0, 0, 4, $conf->currency));
			print '</td>';
			if (isModEnabled('stock')) {
				print '<td class="liste_total right">&nbsp;</td>';
			}
			print '<td class="center">';
			if ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer')) {
				print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
			}
			print '</td>';
			print '<td></td>';
			print '</tr>' . "\n";
		} else {
			$colspan = 10;
			if (isModEnabled('stock')) {
				$colspan++;
			}
			print '<tr class="oddeven">';
			print '<td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("None") . '</span></td>';
			print '</tr>';
		}
		print '</table>';
		print '</form>';
		print '</div>';

		// Form with product to add
		if ((empty($action) || $action == 'view' || $action == 'edit' || $action == 'search' || $action == 're-edit') && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
			$rowspan = 1;
			if (isModEnabled('categorie')) {
				$rowspan++;
			}
			print load_fiche_titre($langs->trans("ProductToAddSearch"), '', '');
			print '<form action="' . DOL_URL_ROOT . '/custom/kreaproducts/associatedProducts.php?id=' . $id . '" method="POST">';
			print '<input type="hidden" name="action" value="search">';
			print '<input type="hidden" name="id" value="' . $id . '">';
			print '<div class="inline-block">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print $langs->trans("KeywordFilter") . ': ';
			print '<input type="text" name="key" value="' . $key . '"> &nbsp; ';
			print '</div>';
			if (isModEnabled('categorie')) {
				require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
				print '<div class="inline-block">' . $langs->trans("CategoryFilter") . ': ';
				print $form->select_all_categories(Categorie::TYPE_PRODUCT, $parent, 'parent') . ' &nbsp; </div>';
				print ajax_combobox('parent');
			}
			print '<div class="inline-block">';
			print '<input type="submit" class="button small" value="' . $langs->trans("Search") . '">';
			print '</div>';
			print '</form>';
		}

		// List of products (search results)
		if ($action == 'search') {
			//print '<br>';
			print '<form action="' . DOL_URL_ROOT . '/custom/kreaproducts/associatedProducts.php?id=' . $id . '" method="post">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="add_prod">';
			print '<input type="hidden" name="id" value="' . $id . '">';
			print '<table class="noborder centpercent">';
			print '<tr class="liste_titre">';
			print '<th class="liste_titre">' . $langs->trans("ComposedProduct") . '</th>';
			print '<th class="liste_titre">' . $langs->trans("Label") . '</th>';
			print '<th class="liste_titre right">' . $langs->trans("Qty") . '</th>';
			print '<th class="center">' . $langs->trans('ComposedProductIncDecStock') . '</th>';
			print '</tr>';
			if ($resql) {
				$num = $db->num_rows($resql);
				$i = 0;
				if ($num == 0) {
					print '<tr><td colspan="4">' . $langs->trans("NoMatchFound") . '</td></tr>';
				}
				$MAX = 100;
				while ($i < min($num, $MAX)) {
					$objp = $db->fetch_object($resql);
					if ($objp->rowid != $id) {
						$prod_arbo = new Product($db);
						$prod_arbo->id = $objp->rowid;
						if (getDolGlobalString('PRODUCT_USE_DEPRECATED_ASSEMBLY_AND_STOCK_KIT_TYPE')) {
							if ($prod_arbo->type == 2 || $prod_arbo->type == 3) {
								$is_pere = 0;
								$prod_arbo->get_sousproduits_arbo();
								$prods_arbo = $prod_arbo->get_arbo_each_prod();
								if (count($prods_arbo) > 0) {
									foreach ($prods_arbo as $key => $value) {
										if ($value[1] == $id) {
											$is_pere = 1;
										}
									}
								}
								if ($is_pere == 1) {
									$i++;
									continue;
								}
							}
						}
						print "\n";
						print '<tr class="oddeven">';
						$productstatic->id = $objp->rowid;
						$productstatic->ref = $objp->ref;
						$productstatic->label = $objp->label;
						$productstatic->type = $objp->type;
						$productstatic->entity = $objp->entity;
						$productstatic->status = $objp->status;
						$productstatic->status_buy = $objp->status_buy;
						print '<td>' . $productstatic->getNomUrl(1, '', 24) . '</td>';
						$labeltoshow = $objp->label;
						if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($objp->labelm)) {
							$labeltoshow = $objp->labelm;
						}
						print '<td>' . $labeltoshow . '</td>';
						if ($object->is_sousproduit($id, $objp->rowid)) {
							$qty = $object->is_sousproduit_qty;
							$incdec = $object->is_sousproduit_incdec;
						} else {
							$qty = 0;
							$incdec = 0;
						}
						print '<td class="right"><input type="hidden" name="prod_id_' . $i . '" value="' . $objp->rowid . '"><input type="text" size="2" name="prod_qty_' . $i . '" value="' . ($qty ? $qty : '') . '"></td>';
						print '<td class="center">';
						if ($qty) {
							print '<input type="checkbox" name="prod_incdec_' . $i . '" value="1" ' . ($incdec ? 'checked' : '') . '>';
						} else {
							print '<input type="checkbox" name="prod_incdec_' . $i . '" value="1" checked>';
						}
						print '</td>';
						print '</tr>';
					}
					$i++;
				}
				if ($num > $MAX) {
					print '<tr class="oddeven">';
					print '<td><span class="opacitymedium">' . $langs->trans("More") . '...</span></td>';
					print '<td></td>';
					print '<td></td>';
					print '<td></td>';
					print '</tr>';
				}
			} else {
				dol_print_error($db);
			}
			print '</table>';
			print '<input type="hidden" name="max_prod" value="' . $i . '">';
			if ($num > 0) {
				print '<div class="center">';
				print '<input type="submit" class="button button-save" name="save" value="' . $langs->trans("Add") . '/' . $langs->trans("Update") . '">';
				print '<input type="submit" class="button button-cancel" name="cancel" value="' . $langs->trans("Cancel") . '">';
				print '</div>';
			}
			print '</form>';
		}

		/**
		 * This code snippet checks if the current product acts as a parent in any **assemble BOM** (Bill of Materials) in the system.
		 * If a BOM of type "assemble" exists (where `bomtype = 0`), the script fetches and displays the **components (sons)**
		 * associated with the BOM. Each component is displayed as a clickable link that directs the user to the component's
		 * detailed page.
		 *
		 * Additionally, this logic only executes if the BOM module is enabled in the Dolibarr system.
		 */
		if (!empty($conf->bom->enabled)) {

			// Fetch all BOMs and the components for the current product
			$sql_bom = "SELECT b.rowid AS bom_id, b.bomtype, bl.fk_product AS fk_product_component, p.ref, p.label
                FROM " . MAIN_DB_PREFIX . "bom_bom AS b
                JOIN " . MAIN_DB_PREFIX . "bom_bomline AS bl ON b.rowid = bl.fk_bom
                JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = bl.fk_product
                WHERE b.fk_product = " . (int)$object->id; // . " AND b.bomtype = 1";

			$resql_bom = $db->query($sql_bom);
			$components = [];

			// Check if the query returns results
			if ($resql_bom) {
				while ($obj_bom = $db->fetch_object($resql_bom)) {
					// Store each component's product details and BOM ID
					$components[] = array(
						'bom_id' => $obj_bom->bom_id,                  // The BOM ID
						'product_id' => $obj_bom->fk_product_component, // The component product ID
						'ref' => $obj_bom->ref,                        // The component product reference
						'label' => $obj_bom->label                     // The component product label
					);
				}
			}
			if (count($components) > 0) {

				//print '<br>';

				// Only display the table if there is at least one component
				print '<div class="fichecenter">';

				// Print the title of the section
				print load_fiche_titre($langs->trans("ComponentsOfProduct"), '', '');

				// Begin table structure
				print '<table class="liste">';
				print '<tr class="liste_titre">';

				// Column headers
				print '<td>' . $langs->trans('BOMReference') . '</td>';
				print '<td>' . $langs->trans('ComponentProductId') . '</td>';
				print '<td>' . $langs->trans('ComponentProduct') . '</td>';

				print '</tr>';

				// Display each component
				foreach ($components as $component) {
					print '<tr class="oddeven">';
					// Link to the BOM (assuming there is a page that shows BOM details)
					print '<td><a href="' . dol_buildpath('/bom/bom_card.php?id=' . $component['bom_id'], 1) . '">' .  $langs->trans('kreaproducts_BOM') . ' #' . $component['bom_id'] . '</a></td>';
					// Display the component product reference with a link to its product card
					print '<td><a href="' . dol_buildpath('/product/card.php?id=' . $component['product_id'], 1) . '">' . $component['ref'] . '</a></td>';
					// Display the component product label
					print '<td>' . $component['label'] . '</td>';
					print '</tr>';
				}

				print '</table>';
				print '</div>';
			}
		}

		// Lista de kits com este produto como componente
		if (count($prodsfather) > 0) {
			print '<br><br>';
			print '<div class="fichecenter">';
			print load_fiche_titre($langs->trans("ProductParentList"), '', '');
			print '<table class="liste">';
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('ParentProducts') . '</td>';
			print '<td>' . $langs->trans('Label') . '</td>';
			print '<td class="right">' . $langs->trans('Qty') . '</td>';
			print '</tr>';
			foreach ($prodsfather as $value) {
				$idprod = $value["id"];
				$productstatic->id = $idprod;
				$productstatic->type = $value["fk_product_type"];
				$productstatic->ref = $value['ref'];
				$productstatic->label = $value['label'];
				$productstatic->entity = $value['entity'];
				$productstatic->status = $value['status'];
				$productstatic->status_buy = $value['status_buy'];
				print '<tr class="oddeven">';
				print '<td>' . $productstatic->getNomUrl(1, 'auto') . '</td>';
				print '<td>' . dol_escape_htmltag($productstatic->label) . '</td>';
				print '<td class="right">' . dol_escape_htmltag($value['qty']) . '</td>';
				print '</tr>';
			}
			print '</table>';
			print '</div>';
		}

		/*
    	* This code retrieves the menus where a specific product exists in the 'niveismenu' field,
    	* checks all JSON levels for the product code, and returns the corresponding menu details.
    	* 
    	* - Retrieves the current product's reference.
    	* - Fetches all records with 'niveismenu' from the 'dolizsynch_zsproduct' and 'product' tables.
    	* - Decodes the JSON 'niveismenu' field and iterates over the nested structure.
    	* - If the product's code matches, the corresponding menu information is stored.
    	* - Duplicates are removed, and the result is displayed in a table format.
    	* - Handles invalid JSON and SQL errors.
    	*/
		if (!empty($conf->dolizsynch->enabled)) {
			// Get current product's ref as codigo
			$current_codigo = $object->ref;

			// Fetch all records with niveismenu
			$sql_menu = "SELECT b.rowid, p.rowid as product_id, p.ref, p.label, b.niveismenu";
			$sql_menu .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct AS b";
			$sql_menu .= " JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = b.fk_product";

			$resql_menu = $db->query($sql_menu);
			$menus = [];

			if ($resql_menu) {
				while ($obj_menu = $db->fetch_object($resql_menu)) {
					if (!empty($obj_menu->niveismenu)) {
						$niveismenuData = json_decode($obj_menu->niveismenu, true);
						if (json_last_error() === JSON_ERROR_NONE && is_array($niveismenuData)) {
							foreach ($niveismenuData as $menuLevel) {
								if (isset($menuLevel['niveismenuext']) && is_array($menuLevel['niveismenuext'])) {
									foreach ($menuLevel['niveismenuext'] as $produto) {
										if (isset($produto['codigo']) && (string)$produto['codigo'] == (string)$current_codigo) {
											$menus[] = array(
												'menu_id' => $obj_menu->product_id,
												'ref' => $obj_menu->ref,
												'label' => $obj_menu->label,
												'descricao' => isset($menuLevel['descricao']) ? $menuLevel['descricao'] : 'N/A'
											);
											break; // Assuming a product is only once per menuLevel
										}
									}
								}
							}
						} else {
							dol_syslog("Invalid JSON in 'niveismenu' for product ID " . $obj_menu->rowid, LOG_ERR);
						}
					}
				}
			} else {
				dol_syslog("Error executing SQL query for 'niveismenu': " . $db->lasterror(), LOG_ERR);
			}

			// Remove duplicate menus
			$menus = array_unique($menus, SORT_REGULAR);

			if (count($menus) > 0) {
				// Display the table
				//print '<br>';
				print '<div class="fichecenter">';
				print load_fiche_titre($langs->trans("MenuWhereProductExistsAndOriginProduct"), '', '');
				print '<table class="liste">';
				print '<tr class="liste_titre">';
				print '<td>' . $langs->trans('Reference') . '</td>';
				print '<td>' . $langs->trans('Label') . '</td>';
				print '</tr>';

				foreach ($menus as $menu) {
					print '<tr class="oddeven">';
					print '<td><a href="' . dol_buildpath('/product/card.php?id=' . urlencode($menu['menu_id']), 1) . '" target="_blank">' . htmlspecialchars($menu['ref']) . '</a></td>';

					print '<td>' . htmlspecialchars($menu['label']) . '</td>';
					print '</tr>';
				}

				print '</table>';
				print '</div>';
			}
		}

		// Process the recipe extra–field
		if (isset($_POST['options_kreap_recipe'])) {
			$extrafield_value = trim($_POST['options_kreap_recipe']);
			if ($extrafield_value === '') {
				$extrafield_value = null;
			}
			$object->array_options['options_kreap_recipe'] = $extrafield_value;
		} else {
			$extrafield_value = $object->array_options['options_kreap_recipe'];
		}
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label($object->table_element);
		if (isset($object->array_options)) {
			print '<br>';
			print load_fiche_titre($langs->trans("productRecipeTitle"), '', '');
			print '<div class="fichecenter" id="myAllergenButtons">';
			print '<table class="ui-sortable liste nobottom">';
			print '<tr><td class="titlefield">';
			print $form->editfieldkey($langs->trans("productRecipeInline"), 'options_kreap_recipe', $extrafield_value, $object, $usercancreate, 'ckeditor');
			print '</td><td>';
			print $form->editfieldval($langs->trans("productRecipeInline"), 'options_kreap_recipe', $extrafield_value, $object, $usercancreate, 'ckeditor');
			print '</td></tr>';
			print '</table>';
			print '</div>';
		}

		// Preserve the original extra–fields values.
		$originalOptions = $object->array_options;
		// Merge POST values with the existing extra–fields values
		foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
			$fieldname = 'options_' . $key;
			if (array_key_exists($fieldname, $_POST)) {
				$extrafield_value = trim($_POST[$fieldname]);
				if ($extrafield_value === '') {
					$extrafield_value = null;
				}
				$object->array_options[$fieldname] = $extrafield_value;
			} else {
				if (isset($originalOptions[$fieldname])) {
					$object->array_options[$fieldname] = $originalOptions[$fieldname];
				}
			}
		}
		// Save the changes to the database (one call for all extra–fields)
		$object->insertExtraFields();


		// Allergens table
		// Load available allergens from dictionary
		$TAllergens = array();
		$sql = "SELECT rowid, code, label, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE active = 1 ORDER BY label";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				// Use the code translation as the display value
				$TAllergens[$obj->rowid] = $langs->trans($obj->code);
			}
		} else {
			dol_syslog("Error loading allergens dictionary: " . $db->error());
		}

		// Reload saved allergen associations for current product
		$savedAllergensArray = array();
		$savedAllergensTracesArray = array();
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
		} else {
			dol_syslog("Error retrieving saved allergens: " . $db->error());
		}

		print '<br>';
		print load_fiche_titre($langs->trans("KreaProductAllergensTableTitle"), '', '');
		print '<div>';
		print '<table class="ui-sortable liste nobottom">';
		$key = 'kreap_calc_allergens';
		$fieldName = 'options_' . $key;
		$label = $extrafields->attributes['product']['label'][$key];
		dol_syslog('label: ' . $label, LOG_DEBUG);
		$options = ['' => ''] + $extrafields->attributes['product']['param'][$key]['options'];
		$editorType = 'select;' . implode(',', array_map(
			function ($k, $v) {
				global $langs;
				$langs->load("kreaproducts@kreaproducts");
				return "$k:" . $langs->trans($v);
			},
			array_keys($options),
			$options
		));

		print '<tr><td class="titlefield">';
		print $form->editfieldkey($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
		print '</td><td>';
		print $form->editfieldval($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
		print '</td></tr>';
		print '</table>';
		print '</div>';

		if ($object->array_options['options_kreap_calc_allergens'] != 2) {
			// Check if we are in edit mode and the user has rights to edit
			if ($usercancreate && $action == 'edit_allergens') {

				// Start the form
				print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
				print '<input type="hidden" name="action" value="saveAllergens">';

				// Multiselect for allergens
				print '<table class="ui-sortable liste nobottom">';
				print '<tr><td>' . $langs->trans("Krea_Products_Allergens") . '</td><td colspan="3">';
				print $form->multiselectarray('KREAPRODUCTS_ALLERGENS', $TAllergens, $savedAllergensArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS"');
				print '</td></tr>';

				// Multiselect for allergens traces
				print '<tr><td>' . $langs->trans("Krea_Products_AllergensTraces") . '</td><td colspan="3">';
				print $form->multiselectarray('KREAPRODUCTS_ALLERGENS_TRACES', $TAllergens, $savedAllergensTracesArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="KREAPRODUCTS_ALLERGENS_TRACES"');
				print '</td></tr>';

				print '<tr><td colspan="4" class="maxwidthonsmartphone"><br/></td></tr>';
				print '</table>';

				// Save button
				print '<div class="center"><input type="submit" class="button" value="' . $langs->trans("Save") . '"></div>';
				print '</form>';
			} else {
				// View mode (read-only display)
				print '<table class="ui-sortable liste nobottom">';
				print '<tr><td>' . $langs->trans("Allergens") . '</td><td colspan="3">';
				if (!empty($savedAllergensArray)) {
					foreach ($savedAllergensArray as $allergenId) {
						$sql = "SELECT code, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
						$resql = $db->query($sql);
						if ($resql && $obj = $db->fetch_object($resql)) {
							$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
							print '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
							print '<img src="' . $iconPath . '" alt="' . htmlspecialchars($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
							print '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
							print '</div>';
						}
					}
				} else {
					print $langs->trans("NoneSelected");
				}
				print '</td></tr>';

				print '<tr><td>' . $langs->trans("AllergensTraces") . '</td><td colspan="3">';
				if (!empty($savedAllergensTracesArray)) {
					foreach ($savedAllergensTracesArray as $allergenId) {
						$sql = "SELECT code, icon FROM " . MAIN_DB_PREFIX . "c_kreaproducts WHERE rowid = " . (int)$allergenId;
						$resql = $db->query($sql);
						if ($resql && $obj = $db->fetch_object($resql)) {
							$iconPath = DOL_URL_ROOT . '/custom/kreaproducts/img/' . $obj->icon;
							print '<div class="refidno multicompany-entity-card-container" style="margin-bottom:5px; display: flex; align-items: center;">';
							print '<img src="' . $iconPath . '" alt="' . htmlspecialchars($obj->code) . '" class="allergen-icon" style="width:16px; height:16px; margin-right:5px;" />';
							print '<span class="multiselect-selected-title-text">' . $langs->trans($obj->code) . '</span>';
							print '</div>';
						}
					}
				} else {
					print $langs->trans("NoneSelected");
				}
				print '</td></tr>';
				print '</table>';

				if ($usercancreate && $object->array_options['options_kreap_calc_allergens'] != 1) {
					print '<div class="center" style="margin-top:10px;">';
					print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit_allergens#myAllergenButtons">' . $langs->trans("Edit") . '</a>';
					print '<a class="button" href="#" onclick="document.getElementById(\'formUpdateAllergens\').submit(); return false;">' . $langs->trans("updateAllergens") . '</a>';
					print '<form id="formUpdateAllergens" method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '#myAllergenButtons" style="display:none;">';
					print '<input type="hidden" name="action" value="updateAllergens">';
					print '</form>';
					print '</div>';
				} else {
					print '<div class="center" style="margin-top:10px;">';
					print '<a class="button" href="#" onclick="document.getElementById(\'formUpdateAllergens\').submit(); return false;">' . $langs->trans("updateAllergens") . '</a>';
					print '<form id="formUpdateAllergens" method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '#myAllergenButtons" style="display:none;">';
					print '<input type="hidden" name="action" value="updateAllergens">';
					print '</form>';
					print '</div>';
				}
			}
		}


		// Nutritional table
		print '<br>';
		print load_fiche_titre($langs->trans("KreaProductsProductAssociations"), '', '');
		print '<div class="fichecenter">';
		print '<table class="ui-sortable liste nobottom">';
		$key = 'kreap_calc_nut';
		$fieldName = 'options_' . $key;
		$label = $extrafields->attributes['product']['label'][$key];
		dol_syslog('label: ' . $label, LOG_DEBUG);
		$options = ['' => ''] + $extrafields->attributes['product']['param'][$key]['options'];
		$editorType = 'select;' . implode(',', array_map(
			function ($k, $v) {
				global $langs;
				$langs->load("kreaproducts@kreaproducts");
				return "$k:" . $langs->trans($v);
			},
			array_keys($options),
			$options
		));

		print '<tr><td class="titlefield">';
		print $form->editfieldkey($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
		print '</td><td>';
		print $form->editfieldval($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
		print '</td></tr>';
		print '</table>';
		print '</div>';

		if ($conf->global->KREAPRODUCTS_NUTRITIONAL_TABLE_TAB == 1 && $object->array_options['options_kreap_calc_nut'] == 1) {


			require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsNutritionalCalculator.class.php';
			KreaProductsNutritionalCalculator::computeAndDisplayNutritional($object->id);
		}

		if ($object->array_options['options_kreap_calc_nut'] == 0) {
			print '<div class="fichecenter">';
			print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
			print '<input type="hidden" name="action" value="save_kreaproducts_nutrition">';
			print '<table class="ui-sortable liste nobottom">';

			$nutritionalFields = array(
				'KreaProducts_Energy_kcal'   => 'energy_kcal',
				'KreaProducts_Energy_kj'     => 'energy_kj',
				'KreaProducts_Fat'           => 'fat',
				'KreaProducts_Saturates'     => 'saturates',
				'KreaProducts_Carbohydrates' => 'carbohydrates',
				'KreaProducts_Sugars'        => 'sugars',
				'KreaProducts_Protein'       => 'protein',
				'KreaProducts_Salt'          => 'salt',
				'KreaProducts_Fiber'         => 'fiber',
			);

			$sqlColumns = implode(', ', $nutritionalFields);
			$sql = "SELECT $sqlColumns FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional WHERE fk_product = " . (int)$object->id;
			$resql = $db->query($sql);

			$nutritionalData = new stdClass();
			foreach ($nutritionalFields as $dbField) {
				$nutritionalData->$dbField = '';
			}

			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				foreach ($nutritionalFields as $dbField) {
					$nutritionalData->$dbField = isset($obj->$dbField) ? $obj->$dbField : '';
				}
			}

			foreach ($nutritionalFields as $label => $dbField) {
				$fieldName = 'nutritional_' . $dbField;
				$object->array_options[$fieldName] = $nutritionalData->$dbField;
				print '<tr><td class="titlefield">';
				print '<label for="' . $fieldName . '">' . $langs->trans($label) . '</label>';
				print '</td><td>';
				print '<input type="text" name="' . $fieldName . '" value="' . dol_escape_htmltag($object->array_options[$fieldName]) . '">';
				print '</td></tr>';
			}

			print '</table>';
			print '<div class="center"><input type="submit" class="button" value="' . $langs->trans("Save") . '"></div>';
			print '</form>';
			print '</div>';
		}

		if (isset($object->array_options)) {
			print '<br>';
			print load_fiche_titre($langs->trans("ExtraFields"), '', '');

			print '<div class="fichecenter">';
			print '<table class="ui-sortable liste nobottom">';

			// Define the keys you want to display
			$fieldsToDisplay = ['kreap_brand', 'kreap_description', 'kreap_video'];

			foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $label) {
				// Only process the keys you want
				if (in_array($key, $fieldsToDisplay)) {
					// Optionally, if you want to set a manual field key mapping, do it here
					// For this example, we'll use the key as is
					$fieldKey = $key;

					// Retrieve the type for the current extra field
					$type = $extrafields->attributes[$object->table_element]['type'][$key];

					// Map the extra field type to an editor type
					switch ($type) {
						case 'varchar':
							$editorType = 'string';
							break;
						case 'int':
						case 'integer':
							$editorType = 'numeric';
							break;
						case 'double':
						case 'float':
							$editorType = 'amount:99';
							break;
						case 'text':
							$editorType = 'textarea';
							break;
						case 'date':
							$editorType = 'datepicker';
							break;
						case 'datetime':
							$editorType = 'datehourpicker';
							break;
						case 'boolean':
							$editorType = 'checkbox';
							break;
						case 'email':
							$editorType = 'email';
							break;
						case 'url':
							$editorType = 'url';
							break;
						case 'ckeditor':
							$editorType = 'ckeditor';
							break;
						case 'select':
							if (
								isset($extrafields->attributes['product']['param'][$key]['options'])
								&& is_array($extrafields->attributes['product']['param'][$key]['options'])
							) {
								// Get options and add an empty option at the beginning
								$options = ['' => ''] + $extrafields->attributes['product']['param'][$key]['options'];
								$editorType = 'select;' . implode(',', array_map(function ($k, $v) {
									return "$k:$v";
								}, array_keys($options), $options));
							} else {
								echo "'options' key is missing or not an array.";
								$editorType = 'select;';
							}
							break;
						default:
							$editorType = 'string';
							break;
					}

					// Use the manual field key when building the field name
					$fieldName = 'options_' . $fieldKey;
					print '<tr><td class="titlefield">';
					print $form->editfieldkey($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
					print '</td><td>';
					print $form->editfieldval($label, $fieldName, $object->array_options[$fieldName], $object, $usercancreate, $editorType);
					print '</td></tr>';
				}
			}
			print '</table>';
			print '</div>';
		}
	}
}





llxFooter();
$db->close();
