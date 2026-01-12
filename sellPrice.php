<?php
/* Copyright (C) 2001-2007	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2004-2014	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2005		Eric Seigne				<eric.seigne@ryxeo.com>
 * Copyright (C) 2005-2017	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2006		Andre Cianfarani		<acianfa@free.fr>
 * Copyright (C) 2014		Florian Henry			<florian.henry@open-concept.pro>
 * Copyright (C) 2014-2018	Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2014-2019 	Philippe Grand 		    <philippe.grand@atoo-net.com>
 * Copyright (C) 2014		Ion agorria				<ion@agorria.com>
 * Copyright (C) 2015		Alexandre Spangaro		<aspangaro@open-dsi.fr>
 * Copyright (C) 2015		Marcos García			<marcosgdf@gmail.com>
 * Copyright (C) 2016		Ferran Marcet			<fmarcet@2byte.es>
 * Copyright (C) 2018-2020  Frédéric France         <frederic.france@netlogic.fr>
 * Copyright (C) 2018		Nicolas ZABOURI			<info@inovea-conseil.com>
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
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
 * \file htdocs/product/price.php
 * \ingroup product
 * \brief Page to show product prices
 */

// Load Dolibarr environment
require '../../main.inc.php'; // krea coloquei mais um "../"
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/dynamic_price/class/price_expression.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/dynamic_price/class/price_parser.class.php';

if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES)) {
	require_once DOL_DOCUMENT_ROOT . '/product/class/productcustomerprice.class.php';

	$prodcustprice = new Productcustomerprice($db);
}

// Load translation files required by the page
$langs->loadLangs(array('products', 'bills', 'companies', 'other', 'kreaproducts@kreaproducts'));

$error = 0;
$errors = array();

$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$eid = GETPOST('eid', 'int');

$search_soc = GETPOST('search_soc');

// Security check
$fieldvalue = (!empty($id) ? $id : (!empty($ref) ? $ref : ''));
$fieldtype = (!empty($ref) ? 'ref' : 'rowid');
if ($user->socid) {
	$socid = $user->socid;
}

if ($id > 0 || !empty($ref)) {
	$object = new Product($db);
	$object->fetch($id, $ref);
}

// Clean param
if ((!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) && empty($conf->global->PRODUIT_MULTIPRICES_LIMIT)) {
	$conf->global->PRODUIT_MULTIPRICES_LIMIT = 5;
}

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('productpricecard', 'globalcard'));

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


/*
 * Actions
 */

if ($cancel) {
	$action = '';
}

$parameters = array('id' => $id, 'ref' => $ref);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		$search_soc = '';
	}

	if ($action == 'setlabelsellingprice' && $user->admin) {
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		$keyforlabel = 'PRODUIT_MULTIPRICES_LABEL' . GETPOST('pricelevel');
		dolibarr_set_const($db, $keyforlabel, GETPOST('labelsellingprice', 'alpha'), 'chaine', 0, '', $conf->entity);
		$action = '';
	}

	if (($action == 'update_vat') && !$cancel && ($user->rights->produit->creer || $user->rights->service->creer)) {
		$tva_tx_txt = GETPOST('tva_tx', 'alpha'); // tva_tx can be '8.5'  or  '8.5*'  or  '8.5 (XXX)' or '8.5* (XXX)'

		// We must define tva_tx, npr and local taxes
		$tva_tx = $tva_tx_txt;
		$reg = array();
		$vatratecode = '';
		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			$vatratecode = $reg[1];
			$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx_txt); // Remove code into vatrate.
		}

		$tva_tx = price2num(preg_replace('/\*/', '', $tva_tx)); // keep remove all after the numbers and dot
		$npr = preg_match('/\*/', $tva_tx_txt) ? 1 : 0;
		$localtax1 = 0;
		$localtax2 = 0;
		$localtax1_type = '0';
		$localtax2_type = '0';
		// If value contains the unique code of vat line (new recommanded method), we use it to find npr and local taxes

		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			// We look into database using code (we can't use get_localtax() because it depends on buyer that is not known). Same in create product.
			$vatratecode = $reg[1];
			// Get record from code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code = '" . $db->escape($vatratecode) . "'";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}
			}
		} else {
			// Get record with empty code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code = ''";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}
			}
		}

		$object->default_vat_code = $vatratecode;
		$object->tva_tx = $tva_tx;
		$object->tva_npr = $npr;
		$object->localtax1_tx = $localtax1;
		$object->localtax2_tx = $localtax2;
		$object->localtax1_type = $localtax1_type;
		$object->localtax2_type = $localtax2_type;

		$db->begin();

		$resql = $object->update($object->id, $user);
		if ($resql <= 0) {
			$error++;
			setEventMessages($object->error, $object->errors, 'errors');
		}

		if (!$error) {
			if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				for ($i = 1; $i <= $conf->global->PRODUIT_MULTIPRICES_LIMIT; $i++) {
					// Force the update of the price of the product using the new VAT
					if ($object->multiprices_base_type[$i] == 'HT') {
						$oldprice = $object->multiprices[$i];
						$oldminprice = $object->multiprices_min[$i];
					} else {
						$oldprice = $object->multiprices_ttc[$i];
						$oldminprice = $object->multiprices_min_ttc[$i];
					}
					$oldpricebasetype = $object->multiprices_base_type[$i];
					$oldnpr = $object->multiprices_recuperableonly[$i];

					//$localtaxarray=array('0'=>$localtax1_type,'1'=>$localtax1,'2'=>$localtax2_type,'3'=>$localtax2);
					$localtaxarray = array(); // We do not store localtaxes into product, we will use instead the "vat code" to retrieve them.
					$level = $i;
					$ret = $object->updatePrice($oldprice, $oldpricebasetype, $user, $tva_tx, $oldminprice, $level, $oldnpr, 0, 0, $localtaxarray, $vatratecode);

					if ($ret < 0) {
						$error++;
						setEventMessages($object->error, $object->errors, 'errors');
					}
				}
			} else {
				// Force the update of the price of the product using the new VAT
				if ($object->price_base_type == 'HT') {
					$oldprice = $object->price;
					$oldminprice = $object->price_min;
				} else {
					$oldprice = $object->price_ttc;
					$oldminprice = $object->price_min_ttc;
				}
				$oldpricebasetype = $object->price_base_type;
				$oldnpr = $object->tva_npr;

				//$localtaxarray=array('0'=>$localtax1_type,'1'=>$localtax1,'2'=>$localtax2_type,'3'=>$localtax2);
				$localtaxarray = array(); // We do not store localtaxes into product, we will use instead the "vat code" to retrieve them when required.
				$level = 0;
				$ret = $object->updatePrice($oldprice, $oldpricebasetype, $user, $tva_tx, $oldminprice, $level, $oldnpr, 0, 0, $localtaxarray, $vatratecode);

				if ($ret < 0) {
					$error++;
					setEventMessages($object->error, $object->errors, 'errors');
				}
			}
		}

		if (!$error) {
			$db->commit();
		} else {
			$db->rollback();
		}

		$action = '';
	}

	if (($action == 'update_price') && !$cancel && $object->getRights()->creer) {
		$error = 0;
		$pricestoupdate = array();

		$psq = GETPOST('psqflag');
		$psq = empty($newpsq) ? 0 : $newpsq;
		$maxpricesupplier = $object->min_recommended_price();

		if (isModEnabled('dynamicprices')) {
			$object->fk_price_expression = empty($eid) ? 0 : $eid; //0 discards expression

			if ($object->fk_price_expression != 0) {
				//Check the expression validity by parsing it
				require_once DOL_DOCUMENT_ROOT . '/product/dynamic_price/class/price_parser.class.php';
				$priceparser = new PriceParser($db);

				if ($priceparser->parseProduct($object) < 0) {
					$error++;
					setEventMessages($priceparser->translatedError(), null, 'errors');
				}
			}
		}

		// Multiprices
		if (!$error && (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES))) {
			$newprice = GETPOST('price', 'array');
			$newprice_min = GETPOST('price_min', 'array');
			$newpricebase = GETPOST('multiprices_base_type', 'array');
			$newvattx = GETPOST('tva_tx', 'array');
			$newvatnpr = GETPOST('tva_npr', 'array');
			$newlocaltax1_tx = GETPOST('localtax1_tx', 'array');
			$newlocaltax1_type = GETPOST('localtax1_type', 'array');
			$newlocaltax2_tx = GETPOST('localtax2_tx', 'array');
			$newlocaltax2_type = GETPOST('localtax2_type', 'array');

			//Shall we generate prices using price rules?
			$object->price_autogen = GETPOST('usePriceRules') == 'on';

			for ($i = 1; $i <= $conf->global->PRODUIT_MULTIPRICES_LIMIT; $i++) {
				if (!isset($newprice[$i])) {
					continue;
				}

				$tva_tx_txt = $newvattx[$i];

				$tva_tx = $tva_tx_txt;
				$vatratecode = '';
				$reg = array();
				if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
					$vat_src_code = $reg[1];
					$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx_txt); // Remove code into vatrate.
				}
				$tva_tx = price2num(preg_replace('/\*/', '', $tva_tx)); // keep remove all after the numbers and dot

				$npr = preg_match('/\*/', $tva_tx_txt) ? 1 : 0;
				$localtax1 = $newlocaltax1_tx[$i];
				$localtax1_type = $newlocaltax1_type[$i];
				$localtax2 = $newlocaltax2_tx[$i];
				$localtax2_type = $newlocaltax2_type[$i];
				if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
					// We look into database using code
					$vatratecode = $reg[1];
					// Get record from code
					$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
					$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
					$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
					$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
					$sql .= " AND t.code ='" . $db->escape($vatratecode) . "'";
					$resql = $db->query($sql);
					if ($resql) {
						$obj = $db->fetch_object($resql);
						if ($obj) {
							$npr = $obj->recuperableonly;
							$localtax1 = $obj->localtax1;
							$localtax2 = $obj->localtax2;
							$localtax1_type = $obj->localtax1_type;
							$localtax2_type = $obj->localtax2_type;
						}

						// If spain, we don't use the localtax found into tax record in database with same code, but using the get_localtax rule.
						if (in_array($mysoc->country_code, array('ES'))) {
							$localtax1 = get_localtax($tva_tx, 1);
							$localtax2 = get_localtax($tva_tx, 2);
						}
					}
				} else {
					// Get record with empty code
					$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
					$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
					$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
					$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
					$sql .= " AND t.code = ''";
					$resql = $db->query($sql);
					if ($resql) {
						$obj = $db->fetch_object($resql);
						if ($obj) {
							$npr = $obj->recuperableonly;
							$localtax1 = $obj->localtax1;
							$localtax2 = $obj->localtax2;
							$localtax1_type = $obj->localtax1_type;
							$localtax2_type = $obj->localtax2_type;
						}
					}
				}

				$pricestoupdate[$i] = array(
					'price' => price2num($newprice[$i], '', 2),
					'price_min' => price2num($newprice_min[$i], '', 2),
					'price_base_type' => $newpricebase[$i],
					'default_vat_code' => $vatratecode,
					'vat_tx' => $tva_tx, // default_vat_code should be used in priority in a future
					'npr' => $npr, // default_vat_code should be used in priority in a future
					'localtaxes_array' => array('0' => $localtax1_type, '1' => $localtax1, '2' => $localtax2_type, '3' => $localtax2)  // default_vat_code should be used in priority in a future
				);

				//If autogeneration is enabled, then we only set the first level
				if ($object->price_autogen) {
					break;
				}
			}
		} elseif (!$error) {
			$newprice = price2num(GETPOST('price', 'alpha'), '', 2);
			$newprice_min = price2num(GETPOST('price_min', 'alpha'), '', 2);
			$newpricebase = GETPOST('price_base_type', 'alpha');
			$tva_tx_txt = GETPOST('tva_tx', 'alpha'); // tva_tx can be '8.5'  or  '8.5*'  or  '8.5 (XXX)' or '8.5* (XXX)'

			$tva_tx = $tva_tx_txt;
			$vatratecode = '';
			$reg = array();
			if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
				$vat_src_code = $reg[1];
				$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx_txt); // Remove code into vatrate.
			}
			$tva_tx = price2num(preg_replace('/\*/', '', $tva_tx)); // keep remove all after the numbers and dot

			$npr = preg_match('/\*/', $tva_tx_txt) ? 1 : 0;
			$localtax1 = 0;
			$localtax2 = 0;
			$localtax1_type = '0';
			$localtax2_type = '0';
			// If value contains the unique code of vat line (new recommanded method), we use it to find npr and local taxes
			if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
				// We look into database using code
				$vatratecode = $reg[1];
				// Get record from code
				$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
				$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
				$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
				$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
				$sql .= " AND t.code ='" . $db->escape($vatratecode) . "'";
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj) {
						$npr = $obj->recuperableonly;
						$localtax1 = $obj->localtax1;
						$localtax2 = $obj->localtax2;
						$localtax1_type = $obj->localtax1_type;
						$localtax2_type = $obj->localtax2_type;
					}

					// If spain, we don't use the localtax found into tax record in database with same code, but using the get_localtax rule.
					if (in_array($mysoc->country_code, array('ES'))) {
						$localtax1 = get_localtax($tva_tx, 1);
						$localtax2 = get_localtax($tva_tx, 2);
					}
				}
			} else {
				// Get record with empty code
				$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
				$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
				$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
				$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
				$sql .= " AND t.code = ''";
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj) {
						$npr = $obj->recuperableonly;
						$localtax1 = $obj->localtax1;
						$localtax2 = $obj->localtax2;
						$localtax1_type = $obj->localtax1_type;
						$localtax2_type = $obj->localtax2_type;
					}
				}
			}

			$pricestoupdate[0] = array(
				'price' => $newprice,
				'price_min' => $newprice_min,
				'price_base_type' => $newpricebase,
				'default_vat_code' => $vatratecode,
				'vat_tx' => $tva_tx, // default_vat_code should be used in priority in a future
				'npr' => $npr, // default_vat_code should be used in priority in a future
				'localtaxes_array' => array('0' => $localtax1_type, '1' => $localtax1, '2' => $localtax2_type, '3' => $localtax2)   // default_vat_code should be used in priority in a future
			);
		}

		if (!$error) {
			$db->begin();

			foreach ($pricestoupdate as $key => $val) {
				$newprice = $val['price'];

				if ($val['price'] < $val['price_min'] && !empty($object->fk_price_expression)) {
					$newprice = $val['price_min']; //Set price same as min, the user will not see the
				}

				$newprice = price2num($newprice, 'MU');
				$newprice_min = price2num($val['price_min'], 'MU');
				$newvattx = price2num($val['vat_tx']);

				if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE) && $newprice_min < $maxpricesupplier) {
					setEventMessages($langs->trans("MinimumPriceLimit", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')), null, 'errors');
					$error++;
					break;
				}

				// If price has changed, we update it
				if (!array_key_exists($key, $object->multiprices) || $object->multiprices[$key] != $newprice || $object->multiprices_min[$key] != $newprice_min || $object->multiprices_base_type[$key] != $val['price_base_type'] || $object->multiprices_tva_tx[$key] != $newvattx) {
					$res = $object->updatePrice($newprice, $val['price_base_type'], $user, $val['vat_tx'], $newprice_min, $key, $val['npr'], $psq, 0, $val['localtaxes_array'], $val['default_vat_code']);
				} else {
					$res = 0;
				}

				if ($res < 0) {
					$error++;
					setEventMessages($object->error, $object->errors, 'errors');
					break;
				}
			}
		}

		if (!$error && $object->update($object->id, $user) < 0) {
			$error++;
			setEventMessages($object->error, $object->errors, 'errors');
		}

		// Handle Variable Price checkboxes (Zone Soft sync) - Only if dolizsynch module is active
		if (!$error && !empty($conf->dolizsynch->enabled)) {
			$variablePrices = GETPOST('variable_price', 'array');

			// Load ZS Product Synch class
			dol_include_once('/dolizsynch/class/zsprodsynch.class.php');
			$zsProductSync = new ZSProductSynch($db);

			// Update variable prices in llx_dolizsynch_zsproduct table
			$updateResult = $zsProductSync->updateVariablePricesInLocalTable($object->id, $variablePrices);

			if ($updateResult < 0) {
				$error++;
				setEventMessages("Error updating variable prices: " . $zsProductSync->error, $zsProductSync->errors, 'errors');
			} else {
				// Update Dolibarr product prices to -1 for variable prices
				for ($i = 1; $i <= $conf->global->PRODUIT_MULTIPRICES_LIMIT; $i++) {
					$isVariable = !empty($variablePrices[$i]);

					if ($isVariable) {
						// Set price to -1 in Dolibarr for variable price
						$priceBaseType = $object->multiprices_base_type[$i];
						$tva_tx = $object->multiprices_tva_tx[$i];

						// Update price to -1
						$ret = $object->updatePrice(
							-1,                     // Price = -1 (variable)
							$priceBaseType,         // Price base type ('HT' or 'TTC')
							$user,                  // User object
							$tva_tx,                // VAT rate
							-1,                     // Minimum price = -1
							$i,                     // Price level
							0,                      // Non-deductible VAT rate
							0,                      // Minimum price in TTC
							0,                      // Minimum price base type
							array(),                // Local taxes array
							''                      // VAT rate code
						);

						if ($ret < 0) {
							dol_syslog("Failed to update Dolibarr price level $i to -1 for product ID {$object->id}: " . $object->error, LOG_ERR);
							$error++;
							setEventMessages("Error setting variable price in Dolibarr: " . $object->error, $object->errors, 'errors');
							break;
						}
					}
					// Note: If unchecked, the normal price was already set in the main price update loop above
				}

				// Trigger sync to Zone Soft BMS if configured
				if (!$error && !empty($conf->global->ZS_API_ENABLE_PRODUCT_EXPORT)) {
					$syncResult = $zsProductSync->syncVariablePricesToZoneSoft($object->id, $variablePrices);
					if ($syncResult < 0) {
						// Log warning but don't fail the transaction
						dol_syslog("Warning: Failed to sync variable prices to Zone Soft: " . $zsProductSync->error, LOG_WARNING);
						setEventMessages("Prices saved but sync to Zone Soft failed: " . $zsProductSync->error, null, 'warnings');
					}
				}
			}
		}

		if (empty($error)) {
			$action = '';
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			$db->commit();
		} else {
			$action = 'edit_price';
			$db->rollback();
		}
	}

	if ($action == 'forceSync') {
		dol_syslog("forceSync action triggered");

		// Instantiate the synchronization class
		$conf->global->bypass_product_modify_trigger = 1;
		dol_syslog("bypass_product_modify_trigger: " . $conf->global->bypass_product_modify_trigger);

		require_once DOL_DOCUMENT_ROOT . '/custom/dolizsynch/class/zsprodsynch.class.php';
		$zsProductSync = new ZSProductSynch($db);

		// Get external product details
		$externalProductDetails = $zsProductSync->getZoneSoftProductById($object->ref);

		// Log the response for debugging
		$responseJson = json_encode($externalProductDetails, JSON_PRETTY_PRINT);
		dol_syslog("ZSProductSync::updateDolibarrProductFromZoneSoft: log curl: " . $responseJson);

		// Perform synchronization operations
		$insertResult = $zsProductSync->insertProductToLocalTable($externalProductDetails);
		$updateResult = $zsProductSync->updateDolibarrProductFromZoneSoft($externalProductDetails->product);

		// Unset the bypass trigger
		unset($conf->global->bypass_product_modify_trigger);

		// Initialize variables to track success and collect error messages
		$syncSuccess = true;
		$errorMessages = [];

		// Check the result of insertProductToLocalTable
		if ($insertResult < 0) {
			$syncSuccess = false;
			$errorMessages[] = $zsProductSync->error;
			if (!empty($zsProductSync->errors)) {
				foreach ($zsProductSync->errors as $err) {
					$errorMessages[] = $err;
				}
			}
		}

		// Check the result of updateDolibarrProductFromZoneSoft
		if ($updateResult < 0) {
			$syncSuccess = false;
			$errorMessages[] = $zsProductSync->error;
			if (!empty($zsProductSync->errors)) {
				foreach ($zsProductSync->errors as $err) {
					$errorMessages[] = $err;
				}
			}
		}

		// Set event messages based on synchronization outcome
		if ($syncSuccess) {
			setEventMessages($langs->trans("ForceSyncSuccess"), null, 'mesgs');
		} else {
			setEventMessages($errorMessages, null, 'errors');
		}
	}


	if ($action == 'delete' && $user->rights->produit->supprimer) {
		$result = $object->log_price_delete($user, GETPOST('lineid', 'int'));
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}

	// Set Price by quantity
	if ($action == 'activate_price_by_qty') {
		// Activating product price by quantity add a new price line with price_by_qty set to 1
		$level = GETPOST('level', 'int');
		$ret = $object->updatePrice(0, $object->price_base_type, $user, $object->tva_tx, 0, $level, $object->tva_npr, 1);

		if ($ret < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	// Unset Price by quantity
	if ($action == 'disable_price_by_qty') {
		// Disabling product price by quantity add a new price line with price_by_qty set to 0
		$level = GETPOST('level', 'int');
		$ret = $object->updatePrice(0, $object->price_base_type, $user, $object->tva_tx, 0, $level, $object->tva_npr, 0);

		if ($ret < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}

	if ($action == 'edit_price_by_qty') { // Edition d'un prix par quantité
		$rowid = GETPOST('rowid', 'int');
	}

	// Add or update price by quantity
	if ($action == 'update_price_by_qty') {
		// Récupération des variables
		$rowid = GETPOST('rowid', 'int');
		$priceid = GETPOST('priceid', 'int');
		$newprice = price2num(GETPOST("price"), 'MU', 2);
		$quantity = price2num(GETPOST('quantity'), 'MS', 2);
		$remise_percent = price2num(GETPOST('remise_percent'), '', 2);
		$remise = 0;

		if (empty($quantity)) {
			$error++;
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentities("Qty")), null, 'errors');
		}
		if (empty($newprice)) {
			$error++;
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentities("Price")), null, 'errors');
		}
		if (!$error) {
			// Calcul du prix HT et du prix unitaire
			if ($object->price_base_type == 'TTC') {
				$price = price2num($newprice) / (1 + ($object->tva_tx / 100));
			}

			$price = price2num($newprice, 'MU');
			$unitPrice = price2num($price / $quantity, 'MU');

			// Ajout / mise à jour
			if ($rowid > 0) {
				$sql = "UPDATE " . MAIN_DB_PREFIX . "product_price_by_qty SET";
				$sql .= " price=" . ((float) $price) . ",";
				$sql .= " unitprice=" . ((float) $unitPrice) . ",";
				$sql .= " quantity=" . ((float) $quantity) . ",";
				$sql .= " remise_percent=" . ((float) $remise_percent) . ",";
				$sql .= " remise=" . ((float) $remise);
				$sql .= " WHERE rowid = " . ((int) $rowid);

				$result = $db->query($sql);
				if (!$result) {
					dol_print_error($db);
				}
			} else {
				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_price_by_qty (fk_product_price,price,unitprice,quantity,remise_percent,remise) values (";
				$sql .= ((int) $priceid) . ',' . ((float) $price) . ',' . ((float) $unitPrice) . ',' . ((float) $quantity) . ',' . ((float) $remise_percent) . ',' . ((float) $remise) . ')';

				$result = $db->query($sql);
				if (!$result) {
					if ($db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
						setEventMessages($langs->trans("DuplicateRecord"), null, 'errors');
					} else {
						dol_print_error($db);
					}
				}
			}
		}
	}

	if ($action == 'delete_price_by_qty') {
		$rowid = GETPOST('rowid', 'int');
		if (!empty($rowid)) {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "product_price_by_qty";
			$sql .= " WHERE rowid = " . ((int) $rowid);

			$result = $db->query($sql);
		} else {
			setEventMessages(('delete_price_by_qty' . $langs->transnoentities('MissingIds')), null, 'errors');
		}
	}

	if ($action == 'delete_all_price_by_qty') {
		$priceid = GETPOST('priceid', 'int');
		if (!empty($rowid)) {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "product_price_by_qty";
			$sql .= " WHERE fk_product_price = " . ((int) $priceid);

			$result = $db->query($sql);
		} else {
			setEventMessages(('delete_price_by_qty' . $langs->transnoentities('MissingIds')), null, 'errors');
		}
	}

	/**
	 * ***************************************************
	 * Price by customer
	 * ****************************************************
	 */
	if ($action == 'add_customer_price_confirm' && !$cancel && ($user->rights->produit->creer || $user->rights->service->creer)) {
		$maxpricesupplier = $object->min_recommended_price();

		$update_child_soc = GETPOST('updatechildprice', 'int');

		// add price by customer
		$prodcustprice->fk_soc = GETPOST('socid', 'int');
		$prodcustprice->ref_customer = GETPOST('ref_customer', 'alpha');
		$prodcustprice->fk_product = $object->id;
		$prodcustprice->price = price2num(GETPOST("price"), 'MU');
		$prodcustprice->price_min = price2num(GETPOST("price_min"), 'MU');
		$prodcustprice->price_base_type = GETPOST("price_base_type", 'alpha');

		$tva_tx_txt = GETPOST("tva_tx", 'alpha');

		$tva_tx = $tva_tx_txt;
		$vatratecode = '';
		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			$vat_src_code = $reg[1];
			$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx_txt); // Remove code into vatrate.
		}
		$tva_tx = price2num(preg_replace('/\*/', '', $tva_tx)); // keep remove all after the numbers and dot

		$npr = preg_match('/\*/', $tva_tx_txt) ? 1 : 0;
		$localtax1 = 0;
		$localtax2 = 0;
		$localtax1_type = '0';
		$localtax2_type = '0';
		// If value contains the unique code of vat line (new recommanded method), we use it to find npr and local taxes
		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			// We look into database using code
			$vatratecode = $reg[1];
			// Get record from code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code ='" . $db->escape($vatratecode) . "'";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}

				// If spain, we don't use the localtax found into tax record in database with same code, but using the get_localtax rule.
				if (in_array($mysoc->country_code, array('ES'))) {
					$localtax1 = get_localtax($tva_tx, 1);
					$localtax2 = get_localtax($tva_tx, 2);
				}
			}
		} else {
			// Get record with empty code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code = ''";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}
			}
		}

		$prodcustprice->default_vat_code = $vatratecode;
		$prodcustprice->tva_tx = $tva_tx;
		$prodcustprice->recuperableonly = $npr;
		$prodcustprice->localtax1_tx = $localtax1;
		$prodcustprice->localtax2_tx = $localtax2;
		$prodcustprice->localtax1_type = $localtax1_type;
		$prodcustprice->localtax2_type = $localtax2_type;

		if (!($prodcustprice->fk_soc > 0)) {
			$langs->load("errors");
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("ThirdParty")), null, 'errors');
			$error++;
			$action = 'add_customer_price';
		}
		if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE) && $prodcustprice->price_min < $maxpricesupplier) {
			$langs->load("errors");
			setEventMessages($langs->trans("MinimumPriceLimit", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')), null, 'errors');
			$error++;
			$action = 'add_customer_price';
		}

		if (!$error) {
			$result = $prodcustprice->create($user, 0, $update_child_soc);

			if ($result < 0) {
				setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
			} else {
				setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			}

			$action = '';
		}
	}

	if ($action == 'delete_customer_price' && ($user->rights->produit->supprimer || $user->rights->service->supprimer)) {
		// Delete price by customer
		$prodcustprice->id = GETPOST('lineid', 'int');
		$result = $prodcustprice->delete($user);

		if ($result < 0) {
			setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
		} else {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		}
		$action = '';
	}

	if ($action == 'update_customer_price_confirm' && !$cancel && ($user->rights->produit->creer || $user->rights->service->creer)) {
		$maxpricesupplier = $object->min_recommended_price();

		$update_child_soc = GETPOST('updatechildprice', 'int');

		$prodcustprice->fetch(GETPOST('lineid', 'int'));

		// update price by customer
		$prodcustprice->ref_customer = GETPOST('ref_customer', 'alpha');
		$prodcustprice->price = price2num(GETPOST("price"), 'MU');
		$prodcustprice->price_min = price2num(GETPOST("price_min"), 'MU');
		$prodcustprice->price_base_type = GETPOST("price_base_type", 'alpha');

		$tva_tx_txt = GETPOST("tva_tx");

		$tva_tx = $tva_tx_txt;
		$vatratecode = '';
		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			$vat_src_code = $reg[1];
			$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx_txt); // Remove code into vatrate.
		}
		$tva_tx = price2num(preg_replace('/\*/', '', $tva_tx)); // keep remove all after the numbers and dot

		$npr = preg_match('/\*/', $tva_tx_txt) ? 1 : 0;
		$localtax1 = 0;
		$localtax2 = 0;
		$localtax1_type = '0';
		$localtax2_type = '0';
		// If value contains the unique code of vat line (new recommanded method), we use it to find npr and local taxes
		if (preg_match('/\((.*)\)/', $tva_tx_txt, $reg)) {
			// We look into database using code
			$vatratecode = $reg[1];
			// Get record from code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code ='" . $db->escape($vatratecode) . "'";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}

				// If spain, we don't use the localtax found into tax record in database with same code, but using the get_localtax rule.
				if (in_array($mysoc->country_code, array('ES'))) {
					$localtax1 = get_localtax($tva_tx, 1);
					$localtax2 = get_localtax($tva_tx, 2);
				}
			}
		} else {
			// Get record with empty code
			$sql = "SELECT t.rowid, t.code, t.recuperableonly, t.localtax1, t.localtax2, t.localtax1_type, t.localtax2_type";
			$sql .= " FROM " . MAIN_DB_PREFIX . "c_tva as t, " . MAIN_DB_PREFIX . "c_country as c";
			$sql .= " WHERE t.fk_pays = c.rowid AND c.code = '" . $db->escape($mysoc->country_code) . "'";
			$sql .= " AND t.taux = " . ((float) $tva_tx) . " AND t.active = 1";
			$sql .= " AND t.code = ''";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$npr = $obj->recuperableonly;
					$localtax1 = $obj->localtax1;
					$localtax2 = $obj->localtax2;
					$localtax1_type = $obj->localtax1_type;
					$localtax2_type = $obj->localtax2_type;
				}
			}
		}

		$prodcustprice->default_vat_code = $vatratecode;
		$prodcustprice->tva_tx = $tva_tx;
		$prodcustprice->recuperableonly = $npr;
		$prodcustprice->localtax1_tx = $localtax1;
		$prodcustprice->localtax2_tx = $localtax2;
		$prodcustprice->localtax1_type = $localtax1_type;
		$prodcustprice->localtax2_type = $localtax2_type;

		if ($prodcustprice->price_min < $maxpricesupplier && !empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
			setEventMessages($langs->trans("MinimumPriceLimit", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')), null, 'errors');
			$error++;
			$action = 'update_customer_price';
		}

		if (!$error) {
			$result = $prodcustprice->update($user, 0, $update_child_soc);

			if ($result < 0) {
				setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
			} else {
				setEventMessages($langs->trans("Save"), null, 'mesgs');
			}

			$action = '';
		}
	}
}


/*
 * View
 */

$form = new Form($db);

if (!empty($id) || !empty($ref)) {
	// fetch updated prices
	$object->fetch($id, $ref);
}

$title = $langs->trans('ProductServiceCard');
$helpurl = '';
$shortlabel = dol_trunc($object->label, 16);
if (GETPOST("type") == '0' || ($object->type == Product::TYPE_PRODUCT)) {
	$title = $langs->trans('Product') . " " . $shortlabel . " - " . $langs->trans('SellingPrices');
	$helpurl = 'EN:Module_Products|FR:Module_Produits|ES:M&oacute;dulo_Productos';
}
if (GETPOST("type") == '1' || ($object->type == Product::TYPE_SERVICE)) {
	$title = $langs->trans('Service') . " " . $shortlabel . " - " . $langs->trans('SellingPrices');
	$helpurl = 'EN:Module_Services_En|FR:Module_Services|ES:M&oacute;dulo_Servicios';
}

llxHeader('', $title, $helpurl, '', 0, 0, '', '', '', 'mod-kreaproducts page-card_krea_price');

$head = product_prepare_head($object);
//$head = product_prepare_head($product);
$titre = $langs->trans("CardProduct" . $object->type);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');

print dol_get_fiche_head($head, 'krea_price', $titre, -1, $picto);

$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$object->next_prev_filter = " fk_product_type = " . $object->type;

$shownav = 1;
if ($user->socid && !in_array('product', explode(',', $conf->global->MAIN_MODULES_FOR_EXTERNAL))) {
	$shownav = 0;
}

dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref');


print '<div class="fichecenter">';

print '<div class="underbanner clearboth"></div>';
print '<table class="border tableforfield centpercent">';

// Price per customer segment/level
if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
	// Price and min price are variable (depends on level of company).
	if (!empty($socid)) {
		$soc = new Societe($db);
		$soc->id = $socid;
		$soc->fetch($socid);

		// Type
		if (isModEnabled("product") && isModEnabled("service")) {
			$typeformat = 'select;0:' . $langs->trans("Product") . ',1:' . $langs->trans("Service");
			print '<tr><td class="">';
			print (empty($conf->global->PRODUCT_DENY_CHANGE_PRODUCT_TYPE)) ? $form->editfieldkey("Type", 'fk_product_type', $object->type, $object, 0, $typeformat) : $langs->trans('Type');
			print '</td><td>';
			print $form->editfieldval("Type", 'fk_product_type', $object->type, $object, 0, $typeformat);
			print '</td></tr>';
		}

		// Selling price
		print '<tr><td class="titlefieldcreate">';
		print $langs->trans("SellingPrice");
		print '</td>';
		print '<td colspan="2">';
		if ($object->multiprices_base_type[$soc->price_level] == 'TTC') {
			print '<span class="amount">' . price($object->multiprices_ttc[$soc->price_level]) . '</span>';
		} else {
			print '<span class="amount">' . price($object->multiprices[$soc->price_level]) . '</span>';
		}
		if ($object->multiprices_base_type[$soc->price_level]) {
			print ' ' . $langs->trans($object->multiprices_base_type[$soc->price_level]);
		} else {
			print ' ' . $langs->trans($object->price_base_type);
		}
		print '</td></tr>';

		// Price min
		print '<tr><td>' . $langs->trans("MinPrice") . '</td><td colspan="2">';
		if ($object->multiprices_base_type[$soc->price_level] == 'TTC') {
			print price($object->multiprices_min_ttc[$soc->price_level]) . ' ' . $langs->trans($object->multiprices_base_type[$soc->price_level]);
		} else {
			print price($object->multiprices_min[$soc->price_level]) . ' ' . $langs->trans(empty($object->multiprices_base_type[$soc->price_level]) ? 'HT' : $object->multiprices_base_type[$soc->price_level]);
		}
		print '</td></tr>';

		if (!empty($conf->global->PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL)) {  // using this option is a bug. kept for backward compatibility
			// TVA
			print '<tr><td>' . $langs->trans("DefaultTaxRate") . '</td><td colspan="2">';

			$positiverates = '';
			if (price2num($object->multiprices_tva_tx[$soc->price_level])) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->multiprices_tva_tx[$soc->price_level]);
			}
			if (price2num($object->multiprices_localtax1_type[$soc->price_level])) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->multiprices_localtax1_tx[$soc->price_level]);
			}
			if (price2num($object->multiprices_localtax2_type[$soc->price_level])) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->multiprices_localtax2_tx[$soc->price_level]);
			}
			if (empty($positiverates)) {
				$positiverates = '0';
			}
			echo vatrate($positiverates . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), '%', $object->tva_npr);
			//print vatrate($object->multiprices_tva_tx[$soc->price_level], true);
			print '</td></tr>';
		} else {
			// TVA
			print '<tr><td>' . $langs->trans("DefaultTaxRate") . '</td><td>';

			$positiverates = '';
			if (price2num($object->tva_tx)) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->tva_tx);
			}
			if (price2num($object->localtax1_type)) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax1_tx);
			}
			if (price2num($object->localtax2_type)) {
				$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax2_tx);
			}
			if (empty($positiverates)) {
				$positiverates = '0';
			}
			echo vatrate($positiverates . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), '%', $object->tva_npr);
			/*
			if ($object->default_vat_code)
			{
				print vatrate($object->tva_tx, true) . ' ('.$object->default_vat_code.')';
			}
			else print vatrate($object->tva_tx . ($object->tva_npr ? '*' : ''), true);*/
			print '</td></tr>';
		}
	} else {
		if (!empty($conf->global->PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL)) {  // using this option is a bug. kept for backward compatibility
			// Type
			if (isModEnabled("product") && isModEnabled("service")) {
				$typeformat = 'select;0:' . $langs->trans("Product") . ',1:' . $langs->trans("Service");
				print '<tr><td class="">';
				print (empty($conf->global->PRODUCT_DENY_CHANGE_PRODUCT_TYPE)) ? $form->editfieldkey("Type", 'fk_product_type', $object->type, $object, 0, $typeformat) : $langs->trans('Type');
				print '</td><td>';
				print $form->editfieldval("Type", 'fk_product_type', $object->type, $object, 0, $typeformat);
				print '</td></tr>';
			}

			// We show only vat for level 1
			print '<tr><td class="titlefieldcreate">' . $langs->trans("DefaultTaxRate") . '</td>';
			print '<td colspan="2">' . vatrate($object->multiprices_tva_tx[1], true) . '</td>';
			print '</tr>';
		} else {
			// Type
			if (isModEnabled("product") && isModEnabled("service")) {
				$typeformat = 'select;0:' . $langs->trans("Product") . ',1:' . $langs->trans("Service");
				print '<tr><td class="">';
				print (empty($conf->global->PRODUCT_DENY_CHANGE_PRODUCT_TYPE)) ? $form->editfieldkey("Type", 'fk_product_type', $object->type, $object, 0, $typeformat) : $langs->trans('Type');
				print '</td><td>';
				print $form->editfieldval("Type", 'fk_product_type', $object->type, $object, 0, $typeformat);
				print '</td></tr>';
			}

			// TVA
			print '<!-- Default VAT Rate -->';
			print '<tr><td class="titlefieldcreate">' . $langs->trans("DefaultTaxRate") . '</td><td>';

			$positiverates = '';
			$vatRate = $object->tva_tx;
			$vatRateWithCode = $vatRate . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : '');
			$localtaxarray = getLocalTaxesFromRate($vatRateWithCode, 0, $mysoc, $mysoc);
			$localtax1_type = $localtaxarray[0] ?? 0;
			$localtax1_tx = $localtaxarray[1] ?? 0;
			$localtax2_type = $localtaxarray[2] ?? 0;
			$localtax2_tx = $localtaxarray[3] ?? 0;

			if (price2num($vatRate)) {
				$positiverates .= ($positiverates ? '<span class="opacitymedium">/</span>' : '') . price2num($vatRate);
			}
			if (price2num($localtax1_type)) {
				$positiverates .= ($positiverates ? '<span class="opacitymedium">/</span>' : '') . price2num($localtax1_tx);
			}
			if (price2num($localtax2_type)) {
				$positiverates .= ($positiverates ? '<span class="opacitymedium">/</span>' : '') . price2num($localtax2_tx);
			}
			if (empty($positiverates)) {
				$positiverates = '0';
			}

			print vatrate($positiverates . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), true, $object->tva_npr, 1);
			/*
			if ($object->default_vat_code)
			{
				print vatrate($object->tva_tx, true) . ' ('.$object->default_vat_code.')';
			}
			else print vatrate($object->tva_tx . ($object->tva_npr ? '*' : ''), true);*/
			print '</td></tr>';
		}
		print '</table>';

		print '<br>';

		print '<table class="noborder tableforfield centpercent">';
		print '<tr class="liste_titre">';
		print '<td>';
		print $langs->trans("PriceLevel");
		if ($user->admin) {
			print ' <a class="editfielda" href="' . $_SERVER["PHP_SELF"] . '?action=editlabelsellingprice&token=' . newToken() . '&pricelevel=' . $i . '&id=' . $object->id . '">' . img_edit($langs->trans('EditSellingPriceLabel'), 0) . '</a>';
		}
		print '</td>';
		print '<td style="text-align: right">' . $langs->trans('KreapSellPriceVatIncluded') . '</td>';
		print '<td style="text-align: right">' . $langs->trans('KreapSellPriceVatExcluded') . '</td>';
		print '<td style="text-align: right">' . $langs->trans('KreapBuyPriceVatExcluded') . '</td>';
		print '<td style="text-align: right">' . $langs->trans('KreapMargin') . '</td>';
		print '<td style="text-align: right">' . $langs->trans('KreapMinPriceVatExcluded') . '</td>';
		print '</tr>';

		for ($i = 1; $i <= $conf->global->PRODUIT_MULTIPRICES_LIMIT; $i++) {
			print '<tr class="oddeven">';

			// Label of price
			print '<td>';
			$keyforlabel = 'PRODUIT_MULTIPRICES_LABEL' . $i;
			if (preg_match('/editlabelsellingprice/', $action)) {
				print '<form method="post" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '">';
				print '<input type="hidden" name="token" value="' . newToken() . '">';
				print '<input type="hidden" name="action" value="setlabelsellingprice">';
				print '<input type="hidden" name="pricelevel" value="' . $i . '">';
				print $langs->trans("SellingPrice") . ' ' . $i . ' - ';
				print '<input class="maxwidthonsmartphone" type="text" name="labelsellingprice" value="' . $conf->global->$keyforlabel . '">';
				print '&nbsp;<input type="submit" class="button smallpaddingimp" value="' . $langs->trans("Modify") . '">';
				print '</form>';
			} else {
				print $langs->trans("SellingPrice") . ' ' . $i;
				if (!empty($conf->global->$keyforlabel)) {
					print ' - ' . $langs->trans($conf->global->$keyforlabel);
				}
			}
			print '</td>';

			// Krea Multipreços
			print '<td class="right"><span class="amount">' . price($object->multiprices_ttc[$i]) . ' ' . $langs->trans('KreapVatIncluded') . '</td>';
			print '<td class="right"><span class="amount">' . price($object->multiprices[$i]) . ' ' . $langs->trans('KreapVatExcluded') . '</td>';
			print '<td class="right"><span class="amount">' . price($object->cost_price) . ' ' . $langs->trans('KreapVatExcluded') . '</td>';
			print '<td class="right"><span class="amount">' . (($object->cost_price == 0 || $object->multiprices[$i] == 0) ? " 0%" : (round(($object->multiprices[$i] / $object->cost_price - 1) * 100, 2) . "%")) . '</td>';
			print '<td class="right"><span class="amount">' . price($object->multiprices_min[$i]) . ' ' . $langs->trans('KreapVatExcluded') . '</td>';
			print '</tr>';
		}
	}
} else {
	// TVA
	print '<tr><td class="titlefield">' . $langs->trans("DefaultTaxRate") . '</td><td>';

	$positiverates = '';
	if (price2num($object->tva_tx)) {
		$positiverates .= ($positiverates ? '/' : '') . price2num($object->tva_tx);
	}
	if (price2num($object->localtax1_type)) {
		$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax1_tx);
	}
	if (price2num($object->localtax2_type)) {
		$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax2_tx);
	}
	if (empty($positiverates)) {
		$positiverates = '0';
	}
	echo vatrate($positiverates . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), '%', $object->tva_npr, 0, 1);
	/*
	if ($object->default_vat_code)
	{
		print vatrate($object->tva_tx, true) . ' ('.$object->default_vat_code.')';
	}
	else print vatrate($object->tva_tx, true, $object->tva_npr, true);*/
	print '</td></tr>';

	if ($user->rights->degema->dgmargensvendas->read) {

		// krea Preço IVA incluido
		print '<tr class="field_selling_price"><td>' . $langs->trans("SellingPrice") . '</td><td>';
		print price($object->price_ttc) . ' ' . $langs->trans('KreapVatIncluded');

		// krea Preço IVA excluido
		print '<tr class="field_selling_price"><td></td><td>';
		print price($object->price) . ' ' . $langs->trans('KreapVatExcluded');

		// krea Preço de compra IVA excluido
		print '<tr class="field_selling_price"><td>' . $langs->trans('KreapPurchasePrice') . '<td>';
		print price($object->cost_price) . ' ' . $langs->trans('KreapVatExcluded');

		// krea Margem IVA excluido
		print '<tr class="field_selling_price"><td>' . $langs->trans('KreapMargin') . '<td>';
		print ($object->cost_price == 0) ? "0%" : round(($object->price / $object->cost_price - 1) * 100, 2) . "%";
	} else {
		// Price
		print '<tr class="field_selling_price"><td>' . $langs->trans("SellingPrice") . '</td><td>';
		if ($object->price_base_type == 'TTC') {
			print price($object->price_ttc) . ' ' . $langs->trans($object->price_base_type);
		} else {
			print price($object->price) . ' ' . $langs->trans($object->price_base_type);
			if (!empty($conf->global->PRODUCT_DISPLAY_VAT_INCL_PRICES) && !empty($object->price_ttc)) {
				print '<i class="opacitymedium"> - ' . price($object->price_ttc) . ' ' . $langs->trans('TTC') . '</i>';
			}
		}
	}

	// krea esta parte coloca o preço IVA excluido a cinzento a traz do preço IVA incluido
	/*
	    if (!empty($conf->global->PRODUCT_DISPLAY_VAT_INCL_PRICES) && !empty($object->price_ttc)) {
		print '<i class="opacitymedium"> - ' . price($object->price_ttc).' '.$langs->trans('TTC') . '</i>';
	    }
	*/

	print '</td></tr>';

	// Price minimum
	print '<tr class="field_min_price"><td>' . $langs->trans("MinPrice") . '</td><td>';
	if ($object->price_base_type == 'TTC') {
		print price($object->price_min_ttc) . ' ' . $langs->trans('KreapVatIncluded');
	} else {
		print price($object->price_min) . ' ' . $langs->trans('KreapVatIncluded');
		if (!empty($conf->global->PRODUCT_DISPLAY_VAT_INCL_PRICES) && !empty($object->price_min_ttc)) {
			print '<i class="opacitymedium"> - ' . price($object->price_min_ttc) . ' ' . $langs->trans('TTC') . '</i>';
		}
	}

	print '</td></tr>';

	// Price by quantity
	if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
		print '<tr><td>' . $langs->trans("PriceByQuantity");
		if ($object->prices_by_qty[0] == 0) {
			print '&nbsp; <a href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=activate_price_by_qty&level=1&token=' . newToken() . '">(' . $langs->trans("Activate") . ')';
		} else {
			print '&nbsp; <a href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=disable_price_by_qty&level=1&token=' . newToken() . '">(' . $langs->trans("DisablePriceByQty") . ')';
		}
		print '</td><td>';

		if ($object->prices_by_qty[0] == 1) {
			$priceByQtyForms = '';
			$addFormId = 'price_by_qty_add_' . $object->id;
			if ($action != 'edit_price_by_qty') {
				$priceByQtyForms .= '<form id="' . $addFormId . '" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
				$priceByQtyForms .= '<input type="hidden" name="token" value="' . newToken() . '">';
				$priceByQtyForms .= '<input type="hidden" name="action" value="update_price_by_qty">';
				$priceByQtyForms .= '<input type="hidden" name="priceid" value="' . $object->prices_by_qty_id[0] . '">';
				$priceByQtyForms .= '<input type="hidden" value="0" name="rowid">';
				$priceByQtyForms .= '</form>';
			}

			print '<table width="50%" class="border" summary="List of quantities">';
			print '<tr class="liste_titre">';
			//print '<td>' . $langs->trans("PriceByQuantityRange") . '</td>';
			print '<td>' . $langs->trans("Quantity") . '</td>';
			print '<td class="right">' . $langs->trans("Price") . '</td>';
			print '<td class="right"></td>';
			print '<td class="right">' . $langs->trans("UnitPrice") . '</td>';
			print '<td class="right">' . $langs->trans("Discount") . '</td>';
			print '<td>&nbsp;</td>';
			print '</tr>';
			if ($action != 'edit_price_by_qty') {
				print '<tr class="pair">';
				print '<td><input size="5" type="text" value="1" name="quantity" form="' . $addFormId . '"></td>';
				print '<td class="right"><input class="width50 right" type="text" value="0" name="price" form="' . $addFormId . '"></td>';
				print '<td>';
				//print $object->price_base_type;
				print '</td>';
				print '<td class="right">&nbsp;</td>';
				print '<td class="right nowraponall"><input type="text" class="width50 right" value="0" name="remise_percent" form="' . $addFormId . '"> %</td>';
				print '<td class="center"><input type="submit" value="' . $langs->trans("Add") . '" class="button" form="' . $addFormId . '"></td>';
				print '</tr>';
			}
			foreach ($object->prices_by_qty_list[0] as $ii => $prices) {
				if ($action == 'edit_price_by_qty' && $rowid == $prices['rowid'] && ($user->rights->produit->creer || $user->rights->service->creer)) {
					$editFormId = 'price_by_qty_edit_' . $prices['rowid'];
					$priceByQtyForms .= '<form id="' . $editFormId . '" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
					$priceByQtyForms .= '<input type="hidden" name="token" value="' . newToken() . '">';
					$priceByQtyForms .= '<input type="hidden" name="action" value="update_price_by_qty">';
					$priceByQtyForms .= '<input type="hidden" name="priceid" value="' . $object->prices_by_qty_id[0] . '">';
					$priceByQtyForms .= '<input type="hidden" value="' . $prices['rowid'] . '" name="rowid">';
					$priceByQtyForms .= '</form>';

					print '<tr class="' . ($ii % 2 == 0 ? 'pair' : 'impair') . '">';
					print '<td><input size="5" type="text" value="' . $prices['quantity'] . '" name="quantity" form="' . $editFormId . '"></td>';
					print '<td class="right"><input class="width50 right" type="text" value="' . price2num($prices['price'], 'MU') . '" name="price" form="' . $editFormId . '"></td>';
					print '<td class="right">';
					//print $object->price_base_type;
					print $prices['price_base_type'];
					print '</td>';
					print '<td class="right">&nbsp;</td>';
					print '<td class="right nowraponall"><input class="width50 right" type="text" value="' . $prices['remise_percent'] . '" name="remise_percent" form="' . $editFormId . '"> %</td>';
					print '<td class="center"><input type="submit" value="' . $langs->trans("Modify") . '" class="button" form="' . $editFormId . '"></td>';
					print '</tr>';
				} else {
					print '<tr class="' . ($ii % 2 == 0 ? 'pair' : 'impair') . '">';
					print '<td>' . $prices['quantity'] . '</td>';
					print '<td class="right">' . price($prices['price']) . '</td>';
					print '<td class="right">';
					//print $object->price_base_type;
					print $prices['price_base_type'];
					print '</td>';
					print '<td class="right">' . price($prices['unitprice']) . '</td>';
					print '<td class="right">' . price($prices['remise_percent']) . ' %</td>';
					print '<td class="center">';
					if (($user->rights->produit->creer || $user->rights->service->creer)) {
						print '<a class="editfielda marginleftonly marginrightonly" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=edit_price_by_qty&token=' . newToken() . '&rowid=' . $prices["rowid"] . '">';
						print img_edit() . '</a>';
						print '<a class="marginleftonly marginrightonly" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete_price_by_qty&token=' . newToken() . '&rowid=' . $prices["rowid"] . '">';
						print img_delete() . '</a>';
					} else {
						print '&nbsp;';
					}
					print '</td>';
					print '</tr>';
				}
			}
			print '</table>';
			if ($priceByQtyForms !== '') {
				print $priceByQtyForms;
			}
		} else {
			print $langs->trans("No");
		}
		print '</td></tr>';
	}
}

print "</table>\n";

print '</div>';
print '<div style="clear:both"></div>';

print dol_get_fiche_end();

/*
 * Action bar
 */

if (
	!$action || $action == 'delete' || $action == 'forceSync' || $action == 'showlog_customer_price' || $action == 'showlog_default_price' || $action == 'add_customer_price' || $action == 'activate_price_by_qty' || $action == 'disable_price_by_qty'
) {
	print "\n" . '<div class="tabsAction">' . "\n";

	$parameters = array();
	$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action); // Note that $action and $object may have been
	if (empty($reshook)) {
		if ($object->isVariant()) {
			if ($user->rights->produit->creer || $user->rights->service->creer) {
				print '<div class="inline-block divButAction"><a class="butActionRefused classfortooltip" href="#" title="' . dol_escape_htmltag($langs->trans("NoEditVariants")) . '">' . $langs->trans("UpdateDefaultPrice") . '</a></div>';
			}
		} else {
			if (empty($conf->global->PRODUIT_MULTIPRICES) && empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				if ($user->rights->produit->creer || $user->rights->service->creer) {
					print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=edit_price&token=' . newToken() . '&id=' . $object->id . '">' . $langs->trans("UpdateDefaultPrice") . '</a></div>';
				} else {
					print '<div class="inline-block divButAction"><span class="butActionRefused" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans("UpdateDefaultPrice") . '</span></div>';
				}
			}

			if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES)) {
				if ($user->rights->produit->creer || $user->rights->service->creer) {
					print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=add_customer_price&token=' . newToken() . '&id=' . $object->id . '">' . $langs->trans("AddCustomerPrice") . '</a></div>';
				} else {
					print '<div class="inline-block divButAction"><span class="butActionRefused" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans("AddCustomerPrice") . '</span></div>';
				}
			}

			if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				if (isModEnabled("degema")) {
					if ($user->rights->produit->creer || $user->rights->service->creer) {
						print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=forceSync&token=' . newToken() . '">' . $langs->trans("ForceSynchronization") . '</a></div>';
					} else {
						print '<div class="inline-block divButAction"><span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans("ForceSynchronization") . '</span></div>';
					}
				}

				if ($user->rights->produit->creer || $user->rights->service->creer) {
					print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=edit_vat&token=' . newToken() . '&id=' . $object->id . '">' . $langs->trans("UpdateVAT") . '</a></div>';
				} else {
					print '<div class="inline-block divButAction"><span class="butActionRefused" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans("UpdateVAT") . '</span></div>';
				}

				if ($user->rights->produit->creer || $user->rights->service->creer) {
					print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=edit_price&token=' . newToken() . '&id=' . $object->id . '">' . $langs->trans("UpdateLevelPrices") . '</a></div>';
				} else {
					print '<div class="inline-block divButAction"><span class="butActionRefused" title="' . dol_escape_htmltag($langs->trans("NotEnoughPermissions")) . '">' . $langs->trans("UpdateLevelPrices") . '</span></div>';
				}
			}
		}
	}

	print "\n</div>\n";
}

/*
 * Edit price area
 */

if ($action == 'edit_vat' && ($user->rights->produit->creer || $user->rights->service->creer)) {
	print load_fiche_titre($langs->trans("UpdateVAT"), '');

	print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update_vat">';
	print '<input type="hidden" name="id" value="' . $object->id . '">';

	print dol_get_fiche_head('');

	print '<table class="border centpercent">';

	// VAT
	print '<tr><td>' . $langs->trans("DefaultTaxRate") . '</td><td>';
	print $form->load_tva("tva_tx", $object->default_vat_code ? $object->tva_tx . ' (' . $object->default_vat_code . ')' : $object->tva_tx, $mysoc, '', $object->id, $object->tva_npr, $object->type, false, 1);
	print '</td></tr>';

	print '</table>';

	print dol_get_fiche_end();

	print $form->buttonsSaveCancel();

	print '<br></form><br>';
}

if ($action == 'edit_price' && $object->getRights()->creer) {
	print '<br>';
	print load_fiche_titre($langs->trans("NewPrice"), '');

	if (empty($conf->global->PRODUIT_MULTIPRICES) && empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
		print '<!-- Edit price -->' . "\n";
		print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="update_price">';
		print '<input type="hidden" name="id" value="' . $object->id . '">';

		print dol_get_fiche_head('');

		print '<div class="div-table-responsive-no-min">';
		print '<table class="border centpercent">';

		// VAT
		print '<tr><td class="titlefield">' . $langs->trans("DefaultTaxRate") . '</td><td>';
		print $form->load_tva("tva_tx", $object->default_vat_code ? $object->tva_tx . ' (' . $object->default_vat_code . ')' : $object->tva_tx, $mysoc, '', $object->id, $object->tva_npr, $object->type, false, 1);
		print '</td></tr>';

		// Price base
		print '<tr><td>';
		print $langs->trans('PriceBase');
		print '</td>';
		print '<td>';
		print $form->selectPriceBaseType($object->price_base_type, "price_base_type");
		print '</td>';
		print '</tr>';

		// Only show price mode and expression selector if module is enabled
		if (!empty($conf->dynamicprices->enabled)) {
			// Price mode selector
			print '<!-- Show price mode of dynamicprices editor -->' . "\n";
			print '<tr><td>' . $langs->trans("PriceMode") . '</td><td>';
			print img_picto('', 'dynamicprice', 'class="pictofixedwidth"');
			$price_expression = new PriceExpression($db);
			$price_expression_list = array(0 => $langs->trans("Numeric") . ' <span class="opacitymedium">(' . $langs->trans("NoDynamicPrice") . ')</span>'); //Put the numeric mode as first option
			foreach ($price_expression->list_price_expression() as $entry) {
				$price_expression_list[$entry->id] = $entry->title;
			}
			$price_expression_preselection = GETPOST('eid') ? GETPOST('eid') : ($object->fk_price_expression ? $object->fk_price_expression : '0');
			print $form->selectarray('eid', $price_expression_list, $price_expression_preselection);
			print '&nbsp; <a id="expression_editor" class="classlink">' . $langs->trans("PriceExpressionEditor") . '</a>';
			print '</td></tr>';

			// This code hides the numeric price input if is not selected, loads the editor page if editor button is pressed
?>

			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery("#expression_editor").click(function() {
						window.location =
							"<?php echo DOL_URL_ROOT ?>/product/dynamic_price/editor.php?id=<?php echo $id ?>&tab=price&eid=" +
							$("#eid").val();
					});
					jQuery("#eid").change(on_change);
					on_change();
				});

				function on_change() {
					if ($("#eid").val() == 0) {
						jQuery("#price_numeric").show();
					} else {
						jQuery("#price_numeric").hide();
					}
				}
			</script>
		<?php
		}

		// Price
		$product = new Product($db);
		$product->fetch($id, $ref, '', 1); //Ignore the math expression when getting the price
		print '<tr id="price_numeric"><td>';
		$text = $langs->trans('SellingPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		print '</td><td>';
		if ($object->price_base_type == 'TTC') {
			print '<input name="price" size="10" value="' . price($product->price_ttc) . '">';
		} else {
			print '<input name="price" size="10" value="' . price($product->price) . '">';
		}
		print '</td></tr>';

		// Price minimum
		print '<tr><td>';
		$text = $langs->trans('MinPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		print '</td><td>';
		if ($object->price_base_type == 'TTC') {
			print '<input name="price_min" size="10" value="' . price($object->price_min_ttc) . '">';
		} else {
			print '<input name="price_min" size="10" value="' . price($object->price_min) . '">';
		}
		if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
			print ' &nbsp; ' . $langs->trans("MinimumRecommendedPrice", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')) . ' ' . img_warning() . '</td>';
		}
		print '</td>';
		print '</tr>';

		$parameters = array();
		$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action); // Note that $action and $object may have been modified by hook

		print '</table>';
		print '</div>';

		print dol_get_fiche_end();

		print $form->buttonsSaveCancel();

		print '</form>';
	} else {
		print '<!-- Edit price per level -->' . "\n";
		?>
		<script>
			var showHidePriceRules = function() {
				var otherPrices = $('div.fiche form table tbody tr:not(:first)');
				var minPrice1 = $('div.fiche form input[name="price_min[1]"]');

				if (jQuery('input#usePriceRules').prop('checked')) {
					otherPrices.hide();
					minPrice1.hide();
				} else {
					otherPrices.show();
					minPrice1.show();
				}
			};

			jQuery(document).ready(function() {
				showHidePriceRules();

				jQuery('input#usePriceRules').click(showHidePriceRules);
			});
		</script>
<?php

		print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="update_price">';
		print '<input type="hidden" name="id" value="' . $object->id . '">';

		//print dol_get_fiche_head('', '', '', -1);

		if ((!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) && !empty($conf->global->PRODUIT_MULTIPRICES_ALLOW_AUTOCALC_PRICELEVEL)) {
			print $langs->trans('UseMultipriceRules') . ' <input type="checkbox" id="usePriceRules" name="usePriceRules" ' . ($object->price_autogen ? 'checked' : '') . '><br><br>';
		}

		// Fetch Zone Soft product data to check for variable prices - Only if dolizsynch module is active
		$zsProductData = null;
		$zsVariablePrices = array(); // Array to store which price levels are variable
		if (!empty($conf->dolizsynch->enabled)) {
			$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$object->id;
			if (!empty($conf->global->ZS_API_STORE)) {
				$sql .= " AND loja_zs = " . (int)$conf->global->ZS_API_STORE;
			}
			$sql .= " LIMIT 1";
			$resql = $db->query($sql);
			if ($resql) {
				$zsProductData = $db->fetch_object($resql);
				if ($zsProductData) {
					// Check each price level for variable price
					// Variable price can be indicated by:
					// 1. Both prices are exactly -1
					// 2. TTC price is -1 and HT price is -1 divided by (1 + VAT rate)
					// 3. HT price is -1 and TTC price is -1 multiplied by (1 + VAT rate)
					for ($i = 1; $i <= 10; $i++) {
						if ($i == 1) {
							$priceHT = isset($zsProductData->pvp1siva) ? (float)$zsProductData->pvp1siva : null;
							$priceTTC = isset($zsProductData->precovenda) ? (float)$zsProductData->precovenda : null;
						} else {
							$priceHT = isset($zsProductData->{'pvp'.$i.'siva'}) ? (float)$zsProductData->{'pvp'.$i.'siva'} : null;
							$priceTTC = isset($zsProductData->{'pvp'.$i}) ? (float)$zsProductData->{'pvp'.$i} : null;
						}

						if ($priceHT !== null && $priceTTC !== null) {
							$isVariable = false;

							// Check if both are exactly -1
							if ($priceHT == -1 && $priceTTC == -1) {
								$isVariable = true;
							}
							// Check if TTC is -1 and HT is calculated from it
							elseif ($priceTTC == -1 && $object->tva_tx > 0) {
								$expectedHtPrice = -1 / (1 + ($object->tva_tx / 100));
								if (abs($priceHT - $expectedHtPrice) < 0.01) {
									$isVariable = true;
								}
							}
							// Check if HT is -1 and TTC is calculated from it
							elseif ($priceHT == -1 && $object->tva_tx > 0) {
								$expectedTtcPrice = -1 * (1 + ($object->tva_tx / 100));
								if (abs($priceTTC - $expectedTtcPrice) < 0.01) {
									$isVariable = true;
								}
							}

							if ($isVariable) {
								$zsVariablePrices[$i] = true;
							}
						}
					}
				}
				$db->free($resql);
			}
		}

		print '<div class="div-table-responsive-no-min">';
		print '<table class="noborder">';
		print '<thead><tr class="liste_titre">';

		print '<td>' . $langs->trans("PriceLevel") . '</td>';

		if (!empty($conf->global->PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL)) {
			print '<td style="text-align: center">' . $langs->trans("DefaultTaxRate") . '</td>';
		} else {
			print '<td></td>';
		}
		print '<td class="center">' . $langs->trans("SellingPrice") . '</td>';
		print '<td class="center">' . $langs->trans("CalculatePriceOnCost") . '</td>';
		print '<td class="center">' . $langs->trans("MinPrice") . '</td>';
		if (!empty($conf->dolizsynch->enabled)) {
			print '<td class="center" title="' . dol_escape_htmltag($langs->trans('KreapVariablePriceLabel')) . '">' . $langs->trans('KreapVariablePriceAbbr') . '</td>';
		}
		if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
			print '<td></td>';
		}
		print '</tr></thead>';
		print '<tbody>';

		$cost_price_output = $object->cost_price;
		$tva_tx_output = $object->tva_tx;
		print '<script>
    document.addEventListener("DOMContentLoaded", () => {

        // Retrieve the cost price and TVA from PHP
        const costPrice = ' . json_encode($cost_price_output) . ';
        const tvaTx = ' . json_encode($tva_tx_output) . ';

        // Function to calculate and update the price
        function calculatePrice(i) {
            const percentageInput = document.getElementById("percentage_" + i);
            const priceOutput = document.getElementById("price_" + i);
            const htTtcSelect = document.getElementById("select_multiprices_base_type[" + i + "]");

            if (percentageInput && priceOutput && htTtcSelect) {
                const percentageValue = parseFloat(percentageInput.value) || 0;
                const baseType = htTtcSelect.value;

                let priceValue;
                if (baseType === "TTC") {
                    priceValue = (costPrice * (1 + percentageValue / 100) * (1 + tvaTx / 100)).toFixed(2);
                } else {
                    priceValue = (costPrice * (1 + percentageValue / 100)).toFixed(2);
                }

                priceOutput.value = priceValue;
            } else {
                // No-op if expected elements are not found.
            }
        }

		// Generate the levels array dynamically based on PRODUIT_MULTIPRICES_LIMIT
        const levels = ' . json_encode(range(1, $conf->global->PRODUIT_MULTIPRICES_LIMIT)) . ';

        // Loop through the dynamic set of inputs
        levels.forEach(i => {
            const percentageInput = document.getElementById("percentage_" + i);
            const htTtcSelect = document.getElementById("select_multiprices_base_type[" + i + "]");

            if (percentageInput && htTtcSelect) {

                // Event listener for percentage input
                percentageInput.addEventListener("input", () => {
                    calculatePrice(i);
                });

                // Event listener for HT/TTC select
                htTtcSelect.addEventListener("change", () => {
                    calculatePrice(i);
                });

                // Initial calculation on page load
                //calculatePrice(i);
            }
        });

        // Variable Price Checkbox Functionality
        const variablePriceCheckboxes = document.querySelectorAll(".variable-price-checkbox");

        variablePriceCheckboxes.forEach((checkbox) => {
            const priceLevel = checkbox.getAttribute("data-price-level");
            const priceInput = document.getElementById("price_" + priceLevel);
            const percentageInput = document.getElementById("percentage_" + priceLevel);
            const priceMinInput = document.getElementById("price_min_" + priceLevel);
            const htTtcSelect = document.getElementById("select_multiprices_base_type[" + priceLevel + "]");

            // Store original values
            if (priceInput) {
                priceInput.setAttribute("data-original-value", priceInput.value);
            }

            // Function to toggle price fields based on checkbox state
            function togglePriceFields() {
                const isVariable = checkbox.checked;

                if (isVariable) {
                    // Variable price: disable and set to -1
                    if (priceInput) {
                        priceInput.value = "-1,00";
                        priceInput.disabled = true;
                        priceInput.style.backgroundColor = "#f0f0f0";
                        priceInput.style.color = "#999";
                    }
                    if (percentageInput) {
                        percentageInput.disabled = true;
                        percentageInput.style.backgroundColor = "#f0f0f0";
                    }
                    if (priceMinInput) {
                        priceMinInput.disabled = true;
                        priceMinInput.style.backgroundColor = "#f0f0f0";
                    }
                    if (htTtcSelect) {
                        htTtcSelect.disabled = true;
                        htTtcSelect.style.backgroundColor = "#f0f0f0";
                    }
                } else {
                    // Normal price: enable and restore original value
                    if (priceInput) {
                        const originalValue = priceInput.getAttribute("data-original-value");
                        priceInput.value = originalValue || "0,00";
                        priceInput.disabled = false;
                        priceInput.style.backgroundColor = "";
                        priceInput.style.color = "";
                    }
                    if (percentageInput) {
                        percentageInput.disabled = false;
                        percentageInput.style.backgroundColor = "";
                    }
                    if (priceMinInput) {
                        priceMinInput.disabled = false;
                        priceMinInput.style.backgroundColor = "";
                    }
                    if (htTtcSelect) {
                        htTtcSelect.disabled = false;
                        htTtcSelect.style.backgroundColor = "";
                    }
                }
            }

            // Initialize on page load
            togglePriceFields();

            // Add event listener for checkbox change
            checkbox.addEventListener("change", togglePriceFields);
        });
    });
</script>';


		for ($i = 1; $i <= $conf->global->PRODUIT_MULTIPRICES_LIMIT; $i++) {
			print '<tr class="oddeven">';

			// Selling Price Label with Tooltip
			print '<td>';
			$text = $langs->trans('SellingPrice') . ' ' . $i;
			print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
			print '</td>';

			// VAT Handling
			if (empty($conf->global->PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL)) {
				print '<td>';
				print '<input type="hidden" name="tva_tx[' . $i . ']" value="' . ($object->default_vat_code ? $object->tva_tx . ' (' . $object->default_vat_code . ')' : $object->tva_tx) . '">';
				print '<input type="hidden" name="tva_npr[' . $i . ']" value="' . $object->tva_npr . '">';
				print '<input type="hidden" name="localtax1_tx[' . $i . ']" value="' . $object->localtax1_tx . '">';
				print '<input type="hidden" name="localtax1_type[' . $i . ']" value="' . $object->localtax1_type . '">';
				print '<input type="hidden" name="localtax2_tx[' . $i . ']" value="' . $object->localtax2_tx . '">';
				print '<input type="hidden" name="localtax2_type[' . $i . ']" value="' . $object->localtax2_type . '">';
				print '</td>';
			} else {
				// This option is kept for backward compatibility but has no sense
				print '<td style="text-align: center">';
				print $form->load_tva("tva_tx[" . $i . ']', $object->multiprices_tva_tx[$i], $mysoc, '', $object->id, false, $object->type, false, 1);
				print '</td>';
			}

			// Selling Price Input Field with Unique ID and Data Attributes
			print '<td style="text-align: center">';
			if ($object->multiprices_base_type[$i] == 'TTC') {
				$cost_price = $object->cost_price;
				// Store the base price (current price before percentage) in a data attribute
				print '<input type="text" name="price[' . $i . ']" id="price_' . $i . '" size="10" value="' . price($object->multiprices_ttc[$i]) . '" data-base-price="' . price($object->multiprices_ttc[$i]) . '">';
				print '&nbsp;' . $form->selectPriceBaseType($object->multiprices_base_type[$i], "multiprices_base_type[" . $i . "]");
				print '</td>';
				// Percentage Input Field with Unique ID and Data Attributes
				print '<td style="text-align: center">';
				print '<input type="text" style="width: 75px;" id="costprice_' . $i . '" value="' . price($cost_price) . '" disabled>&nbsp&nbsp+&nbsp&nbsp<input type="number" name="percentage[' . $i . ']" id="percentage_' . $i . '" placeholder="0" min="0" step="0.1" size="2" style="width: 50px;">%';
				print '</td>';
			} else {
				print '<input type="text" name="price[' . $i . ']" id="price_' . $i . '" size="10" value="' . price($object->multiprices[$i]) . '" data-base-price="' . price($object->multiprices[$i]) . '">';
				print '&nbsp;' . $form->selectPriceBaseType($object->multiprices_base_type[$i], "multiprices_base_type[" . $i . "]");
				print '</td>';
				print '<td style="text-align: center">';
				print '<input type="text" style="width: 75px;" id="costprice_' . $i . '" value="' . price($cost_price) . '" disabled>&nbsp&nbsp+&nbsp&nbsp<input type="number" name="percentage[' . $i . ']" id="percentage_' . $i . '" placeholder="0" min="0" step="0.1" size="2" style="width: 50px;">%';
				print '</td>';
			}




			// Minimum Price Input Field
			print '<td style="text-align: center">';
			if ($object->multiprices_base_type[$i] == 'TTC') {
				print '<input type="text" name="price_min[' . $i . ']" id="price_min_' . $i . '" size="10" value="' . price($object->multiprices_min_ttc[$i]) . '">';
			} else {
				print '<input type="text" name="price_min[' . $i . ']" id="price_min_' . $i . '" size="10" value="' . price($object->multiprices_min[$i]) . '">';
			}
			print '</td>';

			// Variable Price Checkbox (Zone Soft) - Only show if dolizsynch is active
			if (!empty($conf->dolizsynch->enabled)) {
				print '<td style="text-align: center">';
				// Check if this price level is variable according to Zone Soft data
				$isVariablePrice = !empty($zsVariablePrices[$i]);

				print '<input type="checkbox" class="flat variable-price-checkbox" id="variable_price_' . $i . '" name="variable_price[' . $i . ']" value="1"';
				if ($isVariablePrice) {
					print ' checked';
				}
				print ' title="' . dol_escape_htmltag($langs->trans('KreapVariablePriceTooltip')) . '"';
				print ' data-price-level="' . $i . '">';
				print '<label for="variable_price_' . $i . '"></label>';
				print '</td>';
			}

			if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
				print '<td class="left">' . $langs->trans("MinimumRecommendedPrice", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')) . ' ' . img_warning() . '</td>';
			}

			print '</tr>';
		}


		print '</tbody>';

		print '</table>';
		print '</div>';

		//print dol_get_fiche_end();

		print $form->buttonsSaveCancel();

		print '</form>';
	}
}


// List of price changes - log historic (ordered by descending date)

if ((empty($conf->global->PRODUIT_CUSTOMER_PRICES) || $action == 'showlog_default_price') && !in_array($action, array('edit_price', 'edit_vat'))) {
	$sql = "SELECT p.rowid, p.price, p.price_ttc, p.price_base_type, p.tva_tx, p.default_vat_code, p.recuperableonly, p.localtax1_tx, p.localtax1_type, p.localtax2_tx, p.localtax2_type,";
	$sql .= " p.price_level, p.price_min, p.price_min_ttc,p.price_by_qty,";
	$sql .= " p.date_price as dp, p.fk_price_expression, u.rowid as user_id, u.login";
	$sql .= " FROM " . MAIN_DB_PREFIX . "product_price as p,";
	$sql .= " " . MAIN_DB_PREFIX . "user as u";
	$sql .= " WHERE fk_product = " . ((int) $object->id);
	$sql .= " AND p.entity IN (" . getEntity('productprice') . ")";
	$sql .= " AND p.fk_user_author = u.rowid";
	if (!empty($socid) && !empty($conf->global->PRODUIT_MULTIPRICES)) {
		$sql .= " AND p.price_level = " . ((int) $soc->price_level);
	}
	$sql .= " ORDER BY p.date_price DESC, p.rowid DESC, p.price_level ASC";
	// $sql .= $db->plimit();
	//print $sql;

	$result = $db->query($sql);
	if ($result) {
		print '<div class="divlogofpreviouscustomerprice">';

		$num = $db->num_rows($result);

		if (!$num) {
			$db->free($result);

			// Il doit au moins y avoir la ligne de prix initial.
			// On l'ajoute donc pour remettre a niveau (pb vieilles versions)
			// We emulate the change of the price from interface with the same value than the one into table llx_product
			if (!empty($conf->global->PRODUIT_MULTIPRICES)) {
				$ret = $object->updatePrice(($object->multiprices_base_type[1] == 'TTC' ? $object->multiprices_ttc[1] : $object->multiprices[1]), $object->multiprices_base_type[1], $user, (empty($object->multiprices_tva_tx[1]) ? 0 : $object->multiprices_tva_tx[1]), ($object->multiprices_base_type[1] == 'TTC' ? $object->multiprices_min_ttc[1] : $object->multiprices_min[1]), 1);
			} else {
				$ret = $object->updatePrice(($object->price_base_type == 'TTC' ? $object->price_ttc : $object->price), $object->price_base_type, $user, $object->tva_tx, ($object->price_base_type == 'TTC' ? $object->price_min_ttc : $object->price_min));
			}

			if ($ret < 0) {
				dol_print_error($db, $object->error, $object->errors);
			} else {
				$result = $db->query($sql);
				$num = $db->num_rows($result);
			}
		}

		if ($num > 0) {
			// Default prices or
			// Log of previous customer prices
			$backbutton = '<a class="justalink" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';

			if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES)) {
				print_barre_liste($langs->trans("DefaultPriceLog"), 0, $_SERVER["PHP_SELF"], '', '', '', $backbutton, 0, $num, 'title_accountancy.png');
			} else {
				print_barre_liste($langs->trans("PriceByCustomerLog"), 0, $_SERVER["PHP_SELF"], '', '', '', '', 0, $num, 'title_accountancy.png');
			}

			print '<!-- List of log prices -->' . "\n";
			print '<div class="div-table-responsive">' . "\n";
			print '<table class="liste centpercent">' . "\n";

			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans("AppliedPricesFrom") . '</td>';

			if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				print '<td class="center">' . $langs->trans("PriceLevel") . '</td>';
			}
			if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				print '<td class="center">' . $langs->trans("Type") . '</td>';
			}

			print '<td class="center">' . $langs->trans("PriceBase") . '</td>';
			if (empty($conf->global->PRODUIT_MULTIPRICES) && empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
				print '<td class="right">' . $langs->trans("DefaultTaxRate") . '</td>';
			}
			print '<td class="right">' . $langs->trans("HT") . '</td>';
			print '<td class="right">' . $langs->trans("TTC") . '</td>';
			if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
				print '<td class="right">' . $langs->trans("INCT") . '</td>';
			}
			if (!empty($conf->dynamicprices->enabled)) {
				print '<td class="right">' . $langs->trans("PriceExpressionSelected") . '</td>';
			}
			print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("HT") . '</td>';
			print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("TTC") . '</td>';
			print '<td class="right">' . $langs->trans("ChangedBy") . '</td>';
			if ($user->rights->produit->supprimer) {
				print '<td class="right">&nbsp;</td>';
			}
			print '</tr>';

			$notfirstlineforlevel = array();

			$i = 0;
			while ($i < $num) {
				$objp = $db->fetch_object($result);

				print '<tr class="oddeven">';
				// Date
				print "<td>" . dol_print_date($db->jdate($objp->dp), "dayhour", 'tzuserrel') . "</td>";

				// Price level
				if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
					print '<td class="center">' . $objp->price_level . "</td>";
				}
				// Price by quantity
				if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
					$type = ($objp->price_by_qty == 1) ? 'PriceByQuantity' : 'Standard';
					print '<td class="center">' . $langs->trans($type) . "</td>";
				}

				print '<td class="center">';
				if (empty($objp->price_by_qty)) {
					print $langs->trans($objp->price_base_type);
				}
				print "</td>";

				if (empty($conf->global->PRODUIT_MULTIPRICES) && empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
					print '<td class="right">';

					if (empty($objp->price_by_qty)) {
						$positiverates = '';
						if (price2num($objp->tva_tx)) {
							$positiverates .= ($positiverates ? '/' : '') . price2num($objp->tva_tx);
						}
						if (price2num($objp->localtax1_type)) {
							$positiverates .= ($positiverates ? '/' : '') . price2num($objp->localtax1_tx);
						}
						if (price2num($objp->localtax2_type)) {
							$positiverates .= ($positiverates ? '/' : '') . price2num($objp->localtax2_tx);
						}
						if (empty($positiverates)) {
							$positiverates = '0';
						}
						echo vatrate($positiverates . ($objp->default_vat_code ? ' (' . $objp->default_vat_code . ')' : ''), '%', !empty($objp->tva_npr) ? $objp->tva_npr : 0);
						/*
						if ($objp->default_vat_code)
						{
							print vatrate($objp->tva_tx, true) . ' ('.$objp->default_vat_code.')';
						}
						else print vatrate($objp->tva_tx, true, $objp->recuperableonly);*/
					}

					print "</td>";
				}

				// Line for default price
				if ($objp->price_base_type == 'HT') {
					$pu = $objp->price;
				} else {
					$pu = $objp->price_ttc;
				}

				// Local tax was not saved into table llx_product on old version. So we will use value linked to VAT code.
				$localtaxarray = getLocalTaxesFromRate($objp->tva_tx . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), 0, $mysoc, $mysoc);
				// Define part of HT, VAT, TTC
				$resultarray = calcul_price_total(1, $pu, 0, $objp->tva_tx, 1, 1, 0, $objp->price_base_type, $objp->recuperableonly, $object->type, $mysoc, $localtaxarray);
				// Calcul du total ht sans remise
				$total_ht = $resultarray[0] ?? null;
				$total_vat = $resultarray[1] ?? null;
				$total_localtax1 = $resultarray[9] ?? null;
				$total_localtax2 = $resultarray[10] ?? null;
				$total_ttc = $resultarray[2] ?? null;

				// Price
				if (!empty($objp->fk_price_expression) && !empty($conf->dynamicprices->enabled)) {
					$price_expression = new PriceExpression($db);
					$res = $price_expression->fetch($objp->fk_price_expression);
					$title = $price_expression->title;
					print '<td class="right"></td>';
					print '<td class="right"></td>';
					if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
						print '<td class="right"></td>';
					}
					print '<td class="right">' . $title . "</td>";
				} else {
					// Price HT
					print '<td class="right">';
					if (empty($objp->price_by_qty)) {
						print '<span class="amount">' . price($objp->price) . '</span>';
					}
					print "</td>";
					// Price TTC
					print '<td class="right">';
					if (empty($objp->price_by_qty)) {
						$price_ttc = $objp->price_ttc;
						print '<span class="amount">' . price($price_ttc) . '<span>';
					}
					print "</td>";
					if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
						print '<td class="right">';
						print $resultarray[2];
						print '</td>';
					}
					if (!empty($conf->dynamicprices->enabled)) { //Only if module is enabled
						print '<td class="right"></td>';
					}
				}

				// Price min
				print '<td class="right">';
				if (empty($objp->price_by_qty)) {
					print price($objp->price_min);
				}
				print '</td>';

				// Price min inc tax
				print '<td class="right">';
				if (empty($objp->price_by_qty)) {
					$price_min_ttc = $objp->price_min_ttc;
					print price($price_min_ttc);
				}
				print '</td>';

				// User
				print '<td class="right">';
				if ($objp->user_id > 0) {
					$userstatic = new User($db);
					$userstatic->fetch($objp->user_id);
					print $userstatic->getNomUrl(1, '', 0, 0, 24, 0, 'login');
				}
				print '</td>';

				// Action
				if ($user->rights->produit->supprimer) {
					$candelete = 0;
					if (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES)) {
						if (empty($notfirstlineforlevel[$objp->price_level])) {
							$notfirstlineforlevel[$objp->price_level] = 1;
						} else {
							$candelete = 1;
						}
					} elseif ($i > 0) {
						$candelete = 1;
					}

					print '<td class="right">';
					if ($candelete || ($db->jdate($objp->dp) >= dol_now())) {		// Test on date is to be able to delete a corrupted record with a date in future
						print '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete&token=' . newToken() . '&id=' . $object->id . '&lineid=' . $objp->rowid . '">';
						print img_delete();
						print '</a>';
					} else {
						print '&nbsp;'; // Can not delete last price (it's current price)
					}
					print '</td>';
				}

				print "</tr>\n";
				$i++;
			}

			$db->free($result);
			print "</table>";
			print '</div>';
			print "<br>";
		}

		print '</div>';
	} else {
		dol_print_error($db);
	}
}


// Add area to show/add/edit a price for a dedicated customer
if (!empty($conf->global->PRODUIT_CUSTOMER_PRICES)) {
	$prodcustprice = new Productcustomerprice($db);

	$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
	$sortfield = GETPOST('sortfield', 'aZ09comma');
	$sortorder = GETPOST('sortorder', 'aZ09comma');
	$page = (GETPOST("page", 'int') ? GETPOST("page", 'int') : 0);
	if (empty($page) || $page == -1) {
		$page = 0;
	}     // If $page is not defined, or '' or -1
	$offset = $limit * $page;
	$pageprev = $page - 1;
	$pagenext = $page + 1;
	if (!$sortorder) {
		$sortorder = "ASC";
	}
	if (!$sortfield) {
		$sortfield = "soc.nom";
	}

	// Build filter to diplay only concerned lines
	$filter = array('t.fk_product' => $object->id);

	if (!empty($search_soc)) {
		$filter['soc.nom'] = $search_soc;
	}

	if ($action == 'add_customer_price') {
		// Form to add a new customer price
		$maxpricesupplier = $object->min_recommended_price();

		print '<!-- add_customer_price -->';
		print load_fiche_titre($langs->trans('AddCustomerPrice'));

		print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="add_customer_price_confirm">';
		print '<input type="hidden" name="id" value="' . $object->id . '">';

		print '<div class="tabBar tabBarWithBottom">';

		print '<table class="border centpercent">';
		print '<tr>';
		print '<td class="fieldrequired">' . $langs->trans('ThirdParty') . '</td>';
		print '<td>';
		print img_picto('', 'company') . $form->select_company('', 'socid', 's.client IN (1,2,3)', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300');
		print '</td>';
		print '</tr>';

		// Ref. Customer
		print '<tr><td>' . $langs->trans('RefCustomer') . '</td>';
		print '<td><input name="ref_customer" size="12"></td></tr>';

		// VAT
		print '<tr><td class="fieldrequired">' . $langs->trans("DefaultTaxRate") . '</td><td>';
		print $form->load_tva("tva_tx", $object->default_vat_code ? $object->tva_tx . ' (' . $object->default_vat_code . ')' : $object->tva_tx, $mysoc, '', $object->id, $object->tva_npr, $object->type, false, 1);
		print '</td></tr>';

		// Price base
		print '<tr><td class="fieldrequired">';
		print $langs->trans('PriceBase');
		print '</td>';
		print '<td>';
		print $form->selectPriceBaseType($object->price_base_type, "price_base_type");
		print '</td>';
		print '</tr>';

		// Price
		print '<tr><td class="fieldrequired">';
		$text = $langs->trans('SellingPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		print '</td><td>';
		if ($object->price_base_type == 'TTC') {
			print '<input name="price" size="10" value="' . price($object->price_ttc) . '">';
		} else {
			print '<input name="price" size="10" value="' . price($object->price) . '">';
		}
		print '</td></tr>';

		// Price minimum
		print '<tr><td>';
		$text = $langs->trans('MinPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		if ($object->price_base_type == 'TTC') {
			print '<td><input name="price_min" size="10" value="' . price($object->price_min_ttc) . '">';
		} else {
			print '<td><input name="price_min" size="10" value="' . price($object->price_min) . '">';
		}
		if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
			print '<td class="left">' . $langs->trans("MinimumRecommendedPrice", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')) . ' ' . img_warning() . '</td>';
		}
		print '</td></tr>';

		print '</table>';

		print '</div>';


		print '<div class="center">';

		// Update all child soc
		print '<div class="marginbottomonly">';
		print '<input type="checkbox" name="updatechildprice" id="updatechildprice" value="1"> ';
		print '<label for="updatechildprice">' . $langs->trans('ForceUpdateChildPriceSoc') . '</label>';
		print '</div>';

		print $form->buttonsSaveCancel();

		print '</form>';
	} elseif ($action == 'edit_customer_price') {
		// Edit mode
		$maxpricesupplier = $object->min_recommended_price();

		print '<!-- edit_customer_price -->';
		print load_fiche_titre($langs->trans('PriceByCustomer'));

		$result = $prodcustprice->fetch(GETPOST('lineid', 'int'));
		if ($result < 0) {
			setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
		}

		print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="update_customer_price_confirm">';
		print '<input type="hidden" name="lineid" value="' . $prodcustprice->id . '">';

		print '<table class="liste centpercent">';
		print '<tr>';
		print '<td class="titlefield fieldrequired">' . $langs->trans('ThirdParty') . '</td>';
		$staticsoc = new Societe($db);
		$staticsoc->fetch($prodcustprice->fk_soc);
		print "<td>" . $staticsoc->getNomUrl(1) . "</td>";
		print '</tr>';

		// Ref. Customer
		print '<tr><td>' . $langs->trans('RefCustomer') . '</td>';
		print '<td><input name="ref_customer" size="12" value="' . dol_escape_htmltag($prodcustprice->ref_customer) . '"></td></tr>';

		// VAT
		print '<tr><td class="fieldrequired">' . $langs->trans("DefaultTaxRate") . '</td><td>';
		print $form->load_tva("tva_tx", $prodcustprice->default_vat_code ? $prodcustprice->tva_tx . ' (' . $prodcustprice->default_vat_code . ')' : $prodcustprice->tva_tx, $mysoc, '', $object->id, $prodcustprice->recuperableonly, $object->type, false, 1);
		print '</td></tr>';

		// Price base
		print '<tr><td class="fieldrequired">';
		print $langs->trans('PriceBase');
		print '</td>';
		print '<td>';
		print $form->selectPriceBaseType($prodcustprice->price_base_type, "price_base_type");
		print '</td>';
		print '</tr>';

		// Price
		print '<tr><td class="fieldrequired">';
		$text = $langs->trans('SellingPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		print '</td><td>';
		if ($prodcustprice->price_base_type == 'TTC') {
			print '<input name="price" size="10" value="' . price($prodcustprice->price_ttc) . '">';
		} else {
			print '<input name="price" size="10" value="' . price($prodcustprice->price) . '">';
		}
		print '</td></tr>';

		// Price minimum
		print '<tr><td>';
		$text = $langs->trans('MinPrice');
		print $form->textwithpicto($text, $langs->trans("PrecisionUnitIsLimitedToXDecimals", $conf->global->MAIN_MAX_DECIMALS_UNIT), 1, 1);
		print '</td><td>';
		if ($prodcustprice->price_base_type == 'TTC') {
			print '<input name="price_min" size="10" value="' . price($prodcustprice->price_min_ttc) . '">';
		} else {
			print '<input name="price_min" size="10" value="' . price($prodcustprice->price_min) . '">';
		}
		print '</td>';
		if (!empty($conf->global->PRODUCT_MINIMUM_RECOMMENDED_PRICE)) {
			print '<td class="left">' . $langs->trans("MinimumRecommendedPrice", price($maxpricesupplier, 0, '', 1, -1, -1, 'auto')) . ' ' . img_warning() . '</td>';
		}
		print '</tr>';

		print '</table>';


		print '<div class="center">';
		print '<div class="marginbottomonly">';
		print '<input type="checkbox" name="updatechildprice" id="updatechildprice" value="1"> ';
		print '<label for="updatechildprice">' . $langs->trans('ForceUpdateChildPriceSoc') . '</label>';
		print "</div>";

		print $form->buttonsSaveCancel();

		print '<br></form>';
	} elseif ($action == 'showlog_customer_price') {
		// List of all log of prices by customers
		print '<!-- list of all log of prices per customer -->' . "\n";

		$filter = array('t.fk_product' => $object->id, 't.fk_soc' => GETPOST('socid', 'int'));

		// Count total nb of records
		$nbtotalofrecords = '';
		if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
			$nbtotalofrecords = $prodcustprice->fetch_all_log($sortorder, $sortfield, $conf->liste_limit, $offset, $filter);
		}

		$result = $prodcustprice->fetch_all_log($sortorder, $sortfield, $conf->liste_limit, $offset, $filter);
		if ($result < 0) {
			setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
		}

		$option = '&socid=' . GETPOST('socid', 'int') . '&id=' . $object->id;

		$staticsoc = new Societe($db);
		$staticsoc->fetch(GETPOST('socid', 'int'));

		$title = $langs->trans('PriceByCustomerLog');
		$title .= ' - ' . $staticsoc->getNomUrl(1);

		$backbutton = '<a class="justalink" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';

		print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $option, $sortfield, $sortorder, $backbutton, count($prodcustprice->lines), $nbtotalofrecords, 'title_accountancy.png');

		if (count($prodcustprice->lines) > 0) {
			print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="id" value="' . $object->id . '">';

			print '<div class="div-table-responsive-no-min">';
			print '<table class="liste centpercent">';

			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans("ThirdParty") . '</td>';
			print '<td>' . $langs->trans('RefCustomer') . '</td>';
			print '<td>' . $langs->trans("AppliedPricesFrom") . '</td>';
			print '<td class="center">' . $langs->trans("PriceBase") . '</td>';
			print '<td class="right">' . $langs->trans("DefaultTaxRate") . '</td>';
			print '<td class="right">' . $langs->trans("HT") . '</td>';
			print '<td class="right">' . $langs->trans("TTC") . '</td>';
			if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
				print '<td class="right">' . $langs->trans("INCT") . '</td>';
			}
			print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("HT") . '</td>';
			print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("TTC") . '</td>';
			print '<td class="right">' . $langs->trans("ChangedBy") . '</td>';
			print '<td>&nbsp;</td>';
			print '</tr>';

			foreach ($prodcustprice->lines as $line) {
				// Date
				$staticsoc = new Societe($db);
				$staticsoc->fetch($line->fk_soc);

				$tva_tx = $line->default_vat_code ? $line->tva_tx . ' (' . $line->default_vat_code . ')' : $line->tva_tx;

				// Line for default price
				if ($line->price_base_type == 'HT') {
					$pu = $line->price;
				} else {
					$pu = $line->price_ttc;
				}

				// Local tax is not saved into table of product. We use value linked to VAT code.
				$localtaxarray = getLocalTaxesFromRate($line->tva_tx . ($line->default_vat_code ? ' (' . $line->default_vat_code . ')' : ''), 0, $staticsoc, $mysoc);
				// Define part of HT, VAT, TTC
				$resultarray = calcul_price_total(1, $pu, 0, $line->tva_tx, 1, 1, 0, $line->price_base_type, $line->recuperableonly, $object->type, $mysoc, $localtaxarray);
				// Calcul du total ht sans remise
				$total_ht = $resultarray[0];
				$total_vat = $resultarray[1];
				$total_localtax1 = $resultarray[9];
				$total_localtax2 = $resultarray[10];
				$total_ttc = $resultarray[2];

				print '<tr class="oddeven">';

				print "<td>" . $staticsoc->getNomUrl(1) . "</td>";
				print '<td>' . $line->ref_customer . '</td>';
				print "<td>" . dol_print_date($line->datec, "dayhour", 'tzuserrel') . "</td>";
				print '<td class="center">' . $langs->trans($line->price_base_type) . "</td>";
				print '<td class="right">';

				$positiverates = '';
				if (price2num($line->tva_tx)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->tva_tx);
				}
				if (price2num($line->localtax1_type)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->localtax1_tx);
				}
				if (price2num($line->localtax2_type)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->localtax2_tx);
				}
				if (empty($positiverates)) {
					$positiverates = '0';
				}

				echo vatrate($positiverates . ($line->default_vat_code ? ' (' . $line->default_vat_code . ')' : ''), '%', ($line->tva_npr ? $line->tva_npr : $line->recuperableonly));

				//. vatrate($tva_tx, true, $line->recuperableonly) .
				print "</td>";
				print '<td class="right"><span class="amount">' . price($line->price) . "</span></td>";

				print '<td class="right"><span class="amount">' . price($line->price_ttc) . "</span></td>";
				if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
					print '<td class="right">' . price($resultarray[2]) . '</td>';
				}

				print '<td class="right">' . price($line->price_min) . '</td>';
				print '<td class="right">' . price($line->price_min_ttc) . '</td>';

				// User
				$userstatic = new User($db);
				$userstatic->fetch($line->fk_user);
				print '<td class="right">';
				print $userstatic->getNomUrl(1, '', 0, 0, 24, 0, 'login');
				//print $userstatic->getLoginUrl(1);
				print '</td>';
				print '</tr>';
			}
			print "</table>";
			print '</div>';
		} else {
			print $langs->trans('None');
		}
	} elseif ($action == 'forceSync') {
		dol_syslog("forceSync action triggered");

		// Instantiate the synchronization class
		$conf->global->bypass_product_modify_trigger = 1;
		dol_syslog("bypass_product_modify_trigger: " . $conf->global->bypass_product_modify_trigger);

		require_once DOL_DOCUMENT_ROOT . '/custom/dolizsynch/class/zsprodsynch.class.php';
		$zsProductSync = new ZSProductSynch($db);

		// Get external product details
		$externalProductDetails = $zsProductSync->getZoneSoftProductById($object->ref);

		// Log the response for debugging
		$responseJson = json_encode($externalProductDetails, JSON_PRETTY_PRINT);
		dol_syslog("ZSProductSync::updateDolibarrProductFromZoneSoft: log curl: " . $responseJson);

		$zsProductSync->insertProductToLocalTable($externalProductDetails);
		$zsProductSync->updateDolibarrProductFromZoneSoft($externalProductDetails->product);
		unset($conf->global->bypass_product_modify_trigger);
	} elseif ($action != 'showlog_default_price' && $action != 'edit_price') {
		// List of all prices by customers
		print '<!-- list of all prices per customer -->' . "\n";

		// Count total nb of records
		$nbtotalofrecords = '';
		if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
			$nbtotalofrecords = $prodcustprice->fetchAll($sortorder, $sortfield, 0, 0, $filter);
		}

		$result = $prodcustprice->fetchAll($sortorder, $sortfield, $conf->liste_limit, $offset, $filter);
		if ($result < 0) {
			setEventMessages($prodcustprice->error, $prodcustprice->errors, 'errors');
		}

		$option = '&search_soc=' . $search_soc . '&id=' . $object->id;

		print_barre_liste($langs->trans('PriceByCustomer'), $page, $_SERVER['PHP_SELF'], $option, $sortfield, $sortorder, '', count($prodcustprice->lines), $nbtotalofrecords, 'title_accountancy.png');

		print '<form action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="POST">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="id" value="' . $object->id . '">';

		print '<!-- List of prices per customer -->' . "\n";
		print '<div class="div-table-responsive-no-min">' . "\n";
		print '<table class="liste centpercent">' . "\n";

		if (count($prodcustprice->lines) > 0 || $search_soc) {
			$colspan = 9;
			if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
				$colspan++;
			}

			print '<tr class="liste_titre">';
			print '<td class="liste_titre"><input type="text" class="flat maxwidth125" name="search_soc" value="' . $search_soc . '"></td>';
			print '<td class="liste_titre" colspan="' . $colspan . '">&nbsp;</td>';
			// Print the search button
			print '<td class="liste_titre maxwidthsearch">';
			$searchpicto = $form->showFilterAndCheckAddButtons(0);
			print $searchpicto;
			print '</td>';
			print '</tr>';
		}

		print '<tr class="liste_titre">';
		print '<td>' . $langs->trans("ThirdParty") . '</td>';
		print '<td>' . $langs->trans('RefCustomer') . '</td>';
		print '<td>' . $langs->trans("AppliedPricesFrom") . '</td>';
		print '<td class="center">' . $langs->trans("PriceBase") . '</td>';
		print '<td class="right">' . $langs->trans("DefaultTaxRate") . '</td>';
		print '<td class="right">' . $langs->trans("HT") . '</td>';
		print '<td class="right">' . $langs->trans("TTC") . '</td>';
		if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
			print '<td class="right">' . $langs->trans("INCT") . '</td>';
		}
		print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("HT") . '</td>';
		print '<td class="right">' . $langs->trans("MinPrice") . ' ' . $langs->trans("TTC") . '</td>';
		print '<td class="right">' . $langs->trans("ChangedBy") . '</td>';
		print '<td></td>';
		print '</tr>';

		// Line for default price
		if ($object->price_base_type == 'HT') {
			$pu = $object->price;
		} else {
			$pu = $object->price_ttc;
		}

		// Local tax was not saved into table llx_product on old version. So we will use value linked to VAT code.
		$localtaxarray = getLocalTaxesFromRate($object->tva_tx . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), 0, $mysoc, $mysoc);
		// Define part of HT, VAT, TTC
		$resultarray = calcul_price_total(1, $pu, 0, $object->tva_tx, 1, 1, 0, $object->price_base_type, $object->recuperableonly, $object->type, $mysoc, $localtaxarray);
		// Calcul du total ht sans remise
		$total_ht = $resultarray[0];
		$total_vat = $resultarray[1];
		$total_localtax1 = $resultarray[9];
		$total_localtax2 = $resultarray[10];
		$total_ttc = $resultarray[2];

		print '<tr class="oddeven">';
		print '<td colspan="3">' . $langs->trans('Default') . '</td>';

		print '<td class="center">' . $langs->trans($object->price_base_type) . "</td>";

		// VAT Rate
		print '<td class="right">';

		$positiverates = '';
		if (price2num($object->tva_tx)) {
			$positiverates .= ($positiverates ? '/' : '') . price2num($object->tva_tx);
		}
		if (price2num($object->localtax1_type)) {
			$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax1_tx);
		}
		if (price2num($object->localtax2_type)) {
			$positiverates .= ($positiverates ? '/' : '') . price2num($object->localtax2_tx);
		}
		if (empty($positiverates)) {
			$positiverates = '0';
		}
		echo vatrate($positiverates . ($object->default_vat_code ? ' (' . $object->default_vat_code . ')' : ''), '%', $object->tva_npr);

		//print vatrate($object->tva_tx, true, $object->tva_npr);
		//print $object->default_vat_code?' ('.$object->default_vat_code.')':'';
		print "</td>";

		print '<td class="right"><span class="amount">' . price($object->price) . "</span></td>";

		print '<td class="right"><span class="amount">' . price($object->price_ttc) . "</span></td>";
		if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
			//print '<td class="right">' . price($object->price_ttc) . "</td>";
			print '<td class="right"><span class="amount">' . price($resultarray[2]) . '</span></td>';
		}

		print '<td class="right">' . price($object->price_min) . '</td>';
		print '<td class="right">' . price($object->price_min_ttc) . '</td>';
		print '<td class="right">';
		print '</td>';
		if ($user->rights->produit->supprimer || $user->rights->service->supprimer) {
			print '<td class="nowraponall">';
			print '<a class="marginleftonly marginrightonly" href="' . $_SERVER["PHP_SELF"] . '?action=showlog_default_price&token=' . newToken() . '&id=' . $object->id . '">';
			print img_info($langs->trans('PriceByCustomerLog'));
			print '</a>';
			print ' ';
			print '<a class="marginleftonly marginrightonly editfielda" href="' . $_SERVER["PHP_SELF"] . '?action=edit_price&token=' . newToken() . '&id=' . $object->id . '">';
			print img_edit('default', 0, 'style="vertical-align: middle;"');
			print '</a>';
			print '</td>';
		}
		print "</tr>\n";

		if (count($prodcustprice->lines) > 0) {
			foreach ($prodcustprice->lines as $line) {
				// Date
				$staticsoc = new Societe($db);
				$staticsoc->fetch($line->fk_soc);

				$tva_tx = $line->default_vat_code ? $line->tva_tx . ' (' . $line->default_vat_code . ')' : $line->tva_tx;

				// Line for default price
				if ($line->price_base_type == 'HT') {
					$pu = $line->price;
				} else {
					$pu = $line->price_ttc;
				}

				// Local tax is not saved into table of product. We use value linked to VAT code.
				$localtaxarray = getLocalTaxesFromRate($line->tva_tx . ($line->default_vat_code ? ' (' . $line->default_vat_code . ')' : ''), 0, $staticsoc, $mysoc);
				// Define part of HT, VAT, TTC
				$resultarray = calcul_price_total(1, $pu, 0, $line->tva_tx, 1, 1, 0, $line->price_base_type, $line->recuperableonly, $object->type, $mysoc, $localtaxarray);
				// Calcul du total ht sans remise
				$total_ht = $resultarray[0];
				$total_vat = $resultarray[1];
				$total_localtax1 = $resultarray[9];
				$total_localtax2 = $resultarray[10];
				$total_ttc = $resultarray[2];

				print '<tr class="oddeven">';

				print "<td>" . $staticsoc->getNomUrl(1) . "</td>";
				print '<td>' . dol_escape_htmltag($line->ref_customer) . '</td>';
				print "<td>" . dol_print_date($line->datec, "dayhour", 'tzuserrel') . "</td>";
				print '<td class="center">' . $langs->trans($line->price_base_type) . "</td>";
				// VAT Rate
				print '<td class="right">';

				$positiverates = '';
				if (price2num($line->tva_tx)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->tva_tx);
				}
				if (price2num($line->localtax1_type)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->localtax1_tx);
				}
				if (price2num($line->localtax2_type)) {
					$positiverates .= ($positiverates ? '/' : '') . price2num($line->localtax2_tx);
				}
				if (empty($positiverates)) {
					$positiverates = '0';
				}

				echo vatrate($positiverates . ($line->default_vat_code ? ' (' . $line->default_vat_code . ')' : ''), '%', ($line->tva_npr ? $line->tva_npr : $line->recuperableonly));

				print "</td>";

				print '<td class="right"><span class="amount">' . price($line->price) . "</span></td>";

				print '<td class="right"><span class="amount">' . price($line->price_ttc) . "</span></td>";
				if ($mysoc->localtax1_assuj == "1" || $mysoc->localtax2_assuj == "1") {
					//print '<td class="right">' . price($line->price_ttc) . "</td>";
					print '<td class="right"><span class="amount">' . price($resultarray[2]) . '</span></td>';
				}

				print '<td class="right">' . price($line->price_min) . '</td>';
				print '<td class="right">' . price($line->price_min_ttc) . '</td>';

				// User
				$userstatic = new User($db);
				$userstatic->fetch($line->fk_user);
				print '<td class="right">';
				print $userstatic->getNomUrl(1, '', 0, 0, 24, 0, 'login');
				print '</td>';

				// Todo Edit or delete button
				// Action
				if ($user->rights->produit->supprimer || $user->rights->service->supprimer) {
					print '<td class="right nowraponall">';
					print '<a href="' . $_SERVER["PHP_SELF"] . '?action=showlog_customer_price&token=' . newToken() . '&id=' . $object->id . '&socid=' . $line->fk_soc . '">';
					print img_info($langs->trans('PriceByCustomerLog'));
					print '</a>';
					print ' ';
					print '<a class="marginleftonly editfielda" href="' . $_SERVER["PHP_SELF"] . '?action=edit_customer_price&token=' . newToken() . '&id=' . $object->id . '&lineid=' . $line->id . '">';
					print img_edit('default', 0, 'style="vertical-align: middle;"');
					print '</a>';
					print ' ';
					print '<a class="marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=delete_customer_price&token=' . newToken() . '&id=' . $object->id . '&lineid=' . $line->id . '">';
					print img_delete('default', 'style="vertical-align: middle;"');
					print '</a>';
					print '</td>';
				}

				print "</tr>\n";
			}
		}

		print "</table>";
		print '</div>';

		print "</form>";
	}
}

// End of page
llxFooter();
$db->close();
