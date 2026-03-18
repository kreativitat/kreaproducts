<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php')) {
	$res = @include __DIR__ . '/../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) {
	$res = @include __DIR__ . '/../../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../master.inc.php')) {
	$res = @include __DIR__ . '/../master.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) {
	$res = @include __DIR__ . '/../../master.inc.php';
}
if (!$res) {
	die('Failed to include main.inc.php');
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bomline.class.php';

$langs->loadLangs(array('products', 'mrp', 'other', 'kreaproducts@kreaproducts'));

if (!function_exists('kreaproducts_bomhelper_get_accessible_entities')) {
	function kreaproducts_bomhelper_get_accessible_entities()
	{
		global $conf, $mc, $user;

		$entityIds = array((int) $conf->entity);
		if (isModEnabled('multicompany') && is_object($mc)) {
			$canAccessAll = (!empty($user->admin) && empty($user->entity));
			if (!empty($conf->global->MULTICOMPANY_TRANSVERSE_MODE) || $canAccessAll) {
				$list = $mc->getEntitiesList(false, false, true);
				if (!empty($list)) {
					$entityIds = array_map('intval', array_keys($list));
				}
			}
		}

		return array_values(array_unique(array_filter($entityIds, 'is_numeric')));
	}
}

if (!function_exists('kreaproducts_bomhelper_select_products')) {
	function kreaproducts_bomhelper_select_products($form, $selected, $htmlname, $entityList, $langs, $morecss = 'minwidth300', $selectedInputValue = '')
	{
		$entityList = array_values(array_unique(array_filter($entityList, 'is_numeric')));
		$method = new ReflectionMethod($form, 'select_produits');
		$args = array();

		foreach ($method->getParameters() as $param) {
			switch ($param->getName()) {
				case 'selected':
					$args[] = $selected;
					break;
				case 'htmlname':
					$args[] = $htmlname;
					break;
				case 'filtertype':
					$args[] = '';
					break;
				case 'limit':
					$args[] = 0;
					break;
				case 'price_level':
					$args[] = 0;
					break;
				case 'status':
					$args[] = -1;
					break;
				case 'finished':
					$args[] = 2;
					break;
				case 'selected_input_value':
					$args[] = $selectedInputValue;
					break;
				case 'hidelabel':
					$args[] = 0;
					break;
				case 'ajaxoptions':
					$args[] = array();
					break;
				case 'socid':
					$args[] = 0;
					break;
				case 'showempty':
					$args[] = $langs->trans("RefOrLabel");
					break;
				case 'forcecombo':
					$args[] = 0;
					break;
				case 'morecss':
					$args[] = $morecss;
					break;
				case 'hidepriceinlabel':
					$args[] = 0;
					break;
				case 'warehouseStatus':
					$args[] = '';
					break;
				case 'selected_combinations':
					$args[] = null;
					break;
				case 'nooutput':
					$args[] = 1;
					break;
				case 'status_purchase':
					$args[] = -1;
					break;
				case 'warehouseId':
					$args[] = 0;
					break;
				case 'entitylist':
				case 'entityList':
					$args[] = $entityList;
					break;
				default:
					$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
					break;
			}
		}

		return $method->invokeArgs($form, $args);
	}
}

if (!function_exists('kreaproducts_bomhelper_resolve_product_id')) {
	function kreaproducts_bomhelper_resolve_product_id($db, $searchTerm)
	{
		global $conf;

		$searchTerm = trim((string) $searchTerm);
		if ($searchTerm === '') {
			return 0;
		}

		$sql = "SELECT rowid, entity FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE ref = '" . $db->escape($searchTerm) . "'";
		$sql .= " AND entity IN (0," . getEntity('product') . ")";
		$sql .= " ORDER BY CASE";
		$sql .= " WHEN entity = " . (int) $conf->entity . " THEN 0";
		$sql .= " WHEN entity = 0 THEN 1";
		$sql .= " ELSE 2 END, rowid ASC";
		$sql .= $db->plimit(1);

		$resql = $db->query($sql);
		if ($resql) {
			if ($obj = $db->fetch_object($resql)) {
				$db->free($resql);
				return (int) $obj->rowid;
			}
			$db->free($resql);
		} else {
			dol_syslog(__METHOD__ . " SQL error: " . $db->lasterror(), LOG_ERR);
		}

		if (!preg_match('/^[0-9]+$/', $searchTerm)) {
			return 0;
		}

		$productId = (int) $searchTerm;
		if ($productId <= 0) {
			return 0;
		}

		if (kreaproducts_bomhelper_is_product_in_scope($db, $productId)) {
			return $productId;
		}

		return 0;
	}
}

if (!function_exists('kreaproducts_bomhelper_is_product_in_scope')) {
	function kreaproducts_bomhelper_is_product_in_scope($db, $productId)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return false;
		}

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE rowid = " . $productId;
		$sql .= " AND entity IN (0," . getEntity('product') . ")";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " SQL error: " . $db->lasterror(), LOG_ERR);
			return false;
		}

		$isInScope = ($db->num_rows($resql) > 0);
		$db->free($resql);
		return $isInScope;
	}
}

if (!function_exists('kreaproducts_bomhelper_get_draft_bom_id_for_product')) {
	function kreaproducts_bomhelper_get_draft_bom_id_for_product($db, $productId)
	{
		global $conf;

		$productId = (int) $productId;
		if ($productId <= 0) {
			return 0;
		}

		$sqlBom = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom";
		$sqlBom .= " WHERE fk_product = " . $productId;
		$sqlBom .= " AND bomtype = 0";
		$sqlBom .= " AND status = " . (int) BOM::STATUS_DRAFT;
		$sqlBom .= " AND entity = " . (int) $conf->entity;
		$sqlBom .= " ORDER BY rowid DESC";
		$sqlBom .= $db->plimit(1);

		$resBom = $db->query($sqlBom);
		if (!$resBom) {
			dol_syslog(__METHOD__ . " SQL error: " . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$bomId = 0;
		if ($objBom = $db->fetch_object($resBom)) {
			$bomId = (int) $objBom->rowid;
		}
		$db->free($resBom);

		return $bomId;
	}
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$sourceProductId = GETPOSTINT('source_product_id_for_bom');
$targetProductId = GETPOSTINT('target_product_id_for_bom');
$sourceProductSearchInput = trim(GETPOST('search_source_product_id_for_bom', 'alphanohtml'));
$targetProductSearchInput = trim(GETPOST('search_target_product_id_for_bom', 'alphanohtml'));
$requestedBomLabel = trim(GETPOST('bom_label_for_target', 'restricthtml'));
$requestedBomQtyRaw = trim(GETPOST('bom_qty_for_target', 'alphanohtml'));
$showStickySuccess = (GETPOSTINT('success_saved') === 1);
$successBomId = GETPOSTINT('success_bom_id');

$canManageProducts = ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'));
$canWriteBom = ($user->hasRight('bom', 'write') || $user->hasRight('bom', 'creer'));

if (!$canManageProducts || !$canWriteBom) {
	accessforbidden();
}

if ($action === 'copy_associations_to_bom') {
	$redirectUrl = $_SERVER["PHP_SELF"];
	$query = array();
	if ($sourceProductId > 0) {
		$query[] = 'source_product_id_for_bom=' . ((int) $sourceProductId);
	}
	if ($targetProductId > 0) {
		$query[] = 'target_product_id_for_bom=' . ((int) $targetProductId);
	}
	if ($sourceProductId <= 0 && $sourceProductSearchInput !== '') {
		$query[] = 'search_source_product_id_for_bom=' . rawurlencode($sourceProductSearchInput);
	}
	if ($targetProductId <= 0 && $targetProductSearchInput !== '') {
		$query[] = 'search_target_product_id_for_bom=' . rawurlencode($targetProductSearchInput);
	}
	if ($id > 0) {
		$query[] = 'id=' . ((int) $id);
	}
	if ($requestedBomLabel !== '') {
		$query[] = 'bom_label_for_target=' . rawurlencode($requestedBomLabel);
	}
	if ($requestedBomQtyRaw !== '') {
		$query[] = 'bom_qty_for_target=' . rawurlencode($requestedBomQtyRaw);
	}
	if (!empty($query)) {
		$redirectUrl .= '?' . implode('&', $query);
	}

	if (empty($conf->bom->enabled)) {
		setEventMessages($langs->trans("KreaProductsAssocToBomModuleDisabled"), null, 'errors');
		header("Location: " . $redirectUrl);
		exit;
	}

	if ($sourceProductId <= 0 && $sourceProductSearchInput !== '') {
		$sourceProductId = kreaproducts_bomhelper_resolve_product_id($db, $sourceProductSearchInput);
	}
	if ($targetProductId <= 0 && $targetProductSearchInput !== '') {
		$targetProductId = kreaproducts_bomhelper_resolve_product_id($db, $targetProductSearchInput);
	}

	if ($sourceProductId <= 0 || $targetProductId <= 0) {
		setEventMessages($langs->trans("Error"), null, 'errors');
		header("Location: " . $redirectUrl);
		exit;
	}
	if ($requestedBomQtyRaw !== '') {
		$requestedBomQty = (float) price2num($requestedBomQtyRaw, 'MS');
		if ($requestedBomQty <= 0) {
			setEventMessages($langs->trans("KreaProductsAssocToBomInvalidQty"), null, 'errors');
			header("Location: " . $redirectUrl);
			exit;
		}
	}
	if (!kreaproducts_bomhelper_is_product_in_scope($db, $sourceProductId) || !kreaproducts_bomhelper_is_product_in_scope($db, $targetProductId)) {
		setEventMessages($langs->trans("Error"), null, 'errors');
		header("Location: " . $redirectUrl);
		exit;
	}

	$sourceProduct = new Product($db);
	$targetProduct = new Product($db);
	if ($sourceProduct->fetch($sourceProductId) <= 0 || $targetProduct->fetch($targetProductId) <= 0) {
		setEventMessages($langs->trans("Error"), null, 'errors');
		header("Location: " . $redirectUrl);
		exit;
	}

	$error = 0;
	$errors = array();
	$createdLines = 0;
	$updatedLines = 0;
	$transactionStarted = false;

	$assocRows = array();
	$sqlAssoc = "SELECT pa.fk_product_fils, pa.qty, pa.incdec";
	$sqlAssoc .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
	$sqlAssoc .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS pparent ON pparent.rowid = pa.fk_product_pere";
	$sqlAssoc .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS pchild ON pchild.rowid = pa.fk_product_fils";
	$sqlAssoc .= " WHERE pa.fk_product_pere = " . (int) $sourceProductId;
	$sqlAssoc .= " AND pparent.entity IN (0," . getEntity('product') . ")";
	$sqlAssoc .= " AND pchild.entity IN (0," . getEntity('product') . ")";
	$sqlAssoc .= " ORDER BY pa.rang ASC, pa.rowid ASC";
	$resAssoc = $db->query($sqlAssoc);
	if (!$resAssoc) {
		$error++;
		$errors[] = $db->lasterror();
	} else {
		while ($objAssoc = $db->fetch_object($resAssoc)) {
			$assocRows[] = array(
				'fk_product_fils' => (int) $objAssoc->fk_product_fils,
				'qty' => $objAssoc->qty,
				'incdec' => (int) $objAssoc->incdec,
			);
		}
		$db->free($resAssoc);
	}

	if (!$error && empty($assocRows)) {
		$error++;
		$errors[] = $langs->trans("KreaProductsAssocToBomNoAssociations");
	}

	if (!$error) {
		$db->begin();
		$transactionStarted = true;
	}

	$bom = new BOM($db);
	$bomId = 0;
	$defaultBomLabel = $langs->trans("KreaProductsAssocToBomBomLabel", $sourceProduct->ref);
	$defaultBomQty = 1.0;
	$targetBomLabel = $defaultBomLabel;
	$targetBomQty = $defaultBomQty;

	if (!$error) {
		$bomId = kreaproducts_bomhelper_get_draft_bom_id_for_product($db, $targetProductId);
		if ($bomId < 0) {
			$error++;
			$errors[] = $db->lasterror();
		}
	}

	if (!$error && $bomId > 0) {
		if ($bom->fetch($bomId) <= 0) {
			$error++;
			$errors[] = $bom->error ?: $db->lasterror();
		} else {
			if ($bom->label !== '') {
				$defaultBomLabel = $bom->label;
			}
			if ((float) $bom->qty > 0) {
				$defaultBomQty = (float) $bom->qty;
			}
		}
	}

	$targetBomLabel = ($requestedBomLabel !== '' ? $requestedBomLabel : $defaultBomLabel);
	if ($requestedBomQtyRaw !== '') {
		$targetBomQty = (float) price2num($requestedBomQtyRaw, 'MS');
	} else {
		$targetBomQty = $defaultBomQty;
	}
	if ($targetBomQty <= 0) {
		$targetBomQty = $defaultBomQty;
	}

	if (!$error && $bomId <= 0) {
		$bom->ref = '(PROV)';
		$bom->label = $targetBomLabel;
		$bom->bomtype = 0;
		$bom->fk_product = (int) $targetProductId;
		$bom->qty = $targetBomQty;
		$bom->entity = (int) $conf->entity;
		$bom->status = BOM::STATUS_DRAFT;

		$createBomResult = $bom->create($user);
		if ($createBomResult <= 0) {
			$error++;
			$errors[] = $bom->error ?: $db->lasterror();
		} else {
			$bomId = (int) $createBomResult;
			if ($bom->fetch($bomId) <= 0) {
				$error++;
				$errors[] = $bom->error ?: $db->lasterror();
			}
		}
	}

	if (!$error && $bomId > 0) {
		$needsBomUpdate = false;
		if ((string) $bom->label !== (string) $targetBomLabel) {
			$bom->label = $targetBomLabel;
			$needsBomUpdate = true;
		}
		if (abs(((float) $bom->qty) - ((float) $targetBomQty)) > 0.0000001) {
			$bom->qty = $targetBomQty;
			$needsBomUpdate = true;
		}

		if ($needsBomUpdate) {
			$updateBomResult = $bom->update($user);
			if ($updateBomResult <= 0) {
				$error++;
				$errors[] = $bom->error ?: $db->lasterror();
			} else {
				$bom->fetch((int) $bom->id);
			}
		}
	}

	if (!$error) {
		$existingByProduct = array();
		$maxPosition = 0;
		foreach ((array) $bom->lines as $line) {
			$linePosition = (int) $line->position;
			if ($linePosition > $maxPosition) {
				$maxPosition = $linePosition;
			}

			$lineProductId = (int) $line->fk_product;
			$lineId = (int) (!empty($line->id) ? $line->id : $line->rowid);
			if ($lineProductId > 0 && empty($line->fk_bom_child) && $lineId > 0 && !isset($existingByProduct[$lineProductId])) {
				$existingByProduct[$lineProductId] = $lineId;
			}
		}

		foreach ($assocRows as $assocRow) {
			$childProductId = (int) $assocRow['fk_product_fils'];
			$qty = (float) price2num($assocRow['qty'], 'MS');
			$disableStockChange = ((int) $assocRow['incdec'] > 0 ? 0 : 1);

			if ($childProductId <= 0 || $qty <= 0) {
				continue;
			}

			if (isset($existingByProduct[$childProductId])) {
				$lineId = (int) $existingByProduct[$childProductId];
				$line = new BOMLine($db);
				if ($line->fetch($lineId) <= 0) {
					$error++;
					$errors[] = $line->error ?: $db->lasterror();
					break;
				}
				$line->qty = $qty;
				$line->qty_frozen = 0;
				$line->disable_stock_change = $disableStockChange;
				$resultUpdate = $line->update($user);
				if ($resultUpdate <= 0) {
					$error++;
					$errors[] = $line->error ?: $db->lasterror();
					break;
				}
				$updatedLines++;
			} else {
				$line = new BOMLine($db);
				$line->fk_bom = (int) $bom->id;
				$line->fk_product = $childProductId;
				$line->qty = $qty;
				$line->qty_frozen = 0;
				$line->disable_stock_change = $disableStockChange;
				$line->efficiency = 1;
				$line->position = ++$maxPosition;

				$resultCreate = $line->create($user);
				if ($resultCreate <= 0) {
					$error++;
					$errors[] = $line->error ?: $db->lasterror();
					break;
				}
				$existingByProduct[$childProductId] = (int) $resultCreate;
				$createdLines++;
			}
		}
	}

	if ($error) {
		if ($transactionStarted) {
			$db->rollback();
		}
		if (empty($errors)) {
			$errors[] = $langs->trans("Error");
		}
		setEventMessages($langs->trans("Error"), $errors, 'errors');
	} else {
		if ($transactionStarted) {
			$db->commit();
		}
		$bom->fetch((int) $bom->id);
		$bom->calculateCosts();
		$successBomUrl = dol_buildpath('/bom/bom_card.php?id=' . ((int) $bom->id), 1);
		$successMessage = $langs->trans("RecordSaved");
		$successMessage .= ' <a href="' . $successBomUrl . '" target="_blank" rel="noopener noreferrer">' . $langs->trans("KreaProductsAssocToBomOpenBom") . '</a>';
		setEventMessages($successMessage, null, 'mesgs');
		$query[] = 'success_saved=1';
		$query[] = 'success_bom_id=' . (int) $bom->id;
		$redirectUrl = $_SERVER["PHP_SELF"] . '?' . implode('&', $query);
	}

	header("Location: " . $redirectUrl);
	exit;
}

$form = new Form($db);
$entityList = kreaproducts_bomhelper_get_accessible_entities();
$selectedSourceProductId = ($sourceProductId > 0 ? $sourceProductId : (int) $id);
$selectedTargetProductId = ($targetProductId > 0 ? $targetProductId : 0);
$sourceSelectedInputValue = ($selectedSourceProductId > 0 ? '' : $sourceProductSearchInput);
$targetSelectedInputValue = ($selectedTargetProductId > 0 ? '' : $targetProductSearchInput);
$bomLabelPlaceholder = $langs->trans("KreaProductsAssocToBomBomLabel", $langs->trans("KreaProductsAssocToBomSource"));
$bomQtyPlaceholder = '1';

if ($selectedSourceProductId > 0) {
	$selectedSourceProduct = new Product($db);
	if ($selectedSourceProduct->fetch((int) $selectedSourceProductId) > 0) {
		$bomLabelPlaceholder = $langs->trans("KreaProductsAssocToBomBomLabel", $selectedSourceProduct->ref);
	}
}
if ($selectedTargetProductId > 0) {
	$selectedTargetBomId = kreaproducts_bomhelper_get_draft_bom_id_for_product($db, (int) $selectedTargetProductId);
	if ($selectedTargetBomId > 0) {
		$selectedTargetBom = new BOM($db);
		if ($selectedTargetBom->fetch((int) $selectedTargetBomId) > 0) {
			if ($selectedTargetBom->label !== '') {
				$bomLabelPlaceholder = $selectedTargetBom->label;
			}
			if ((float) $selectedTargetBom->qty > 0) {
				$bomQtyPlaceholder = (string) price2num($selectedTargetBom->qty, 'MS');
			}
		}
	}
}
$bomLabelValue = $requestedBomLabel;
$bomQtyValue = $requestedBomQtyRaw;

$title = $langs->trans("KreaProductsAssocToBomTitle");
llxHeader('', $title);

print load_fiche_titre($langs->trans("KreaProductsAssocToBomTitle"), '', 'product');
if ($showStickySuccess && $successBomId > 0) {
	$successBomUrl = dol_buildpath('/bom/bom_card.php?id=' . ((int) $successBomId), 1);
	print '<div style="position: sticky; top: 8px; z-index: 1100; margin-bottom: 10px; padding: 10px 12px; border: 1px solid #6aa84f; background: #eaf7e8; color: #1f5f2c; border-radius: 4px;">';
	print '<strong>' . $langs->trans("RecordSaved") . '</strong>';
	print ' <a href="' . $successBomUrl . '" target="_blank" rel="noopener noreferrer" style="color: #1f5f2c; text-decoration: underline;">' . $langs->trans("KreaProductsAssocToBomOpenBom") . '</a>';
	print '</div>';
}
print '<div class="opacitymedium" style="margin-bottom: 8px;">';
print $langs->trans("KreaProductsAssocToBomStandaloneInfo");
print '</div>';

if (empty($conf->bom->enabled)) {
	print '<div class="error">' . $langs->trans("KreaProductsAssocToBomModuleDisabled") . '</div>';
} else {
	$sourceSelectHtml = kreaproducts_bomhelper_select_products($form, $selectedSourceProductId, 'source_product_id_for_bom', $entityList, $langs, 'minwidth300', $sourceSelectedInputValue);
	$targetSelectHtml = kreaproducts_bomhelper_select_products($form, $selectedTargetProductId, 'target_product_id_for_bom', $entityList, $langs, 'minwidth300', $targetSelectedInputValue);

	print '<form method="post" action="' . $_SERVER['PHP_SELF'] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="copy_associations_to_bom">';
	if ($id > 0) {
		print '<input type="hidden" name="id" value="' . (int) $id . '">';
	}
	print '<table class="noborder centpercent">';
	print '<tr><td class="titlefield">' . $langs->trans("KreaProductsAssocToBomSource") . '</td><td>' . $sourceSelectHtml . '</td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans("KreaProductsAssocToBomTarget") . '</td><td>' . $targetSelectHtml . '</td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans("KreaProductsAssocToBomBomLabelField") . '</td><td><input type="text" class="flat minwidth300" name="bom_label_for_target" value="' . dol_escape_htmltag($bomLabelValue) . '" placeholder="' . dol_escape_htmltag($bomLabelPlaceholder) . '"></td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans("KreaProductsAssocToBomProducedQty") . '</td><td><input type="text" class="flat width100 right" name="bom_qty_for_target" value="' . dol_escape_htmltag($bomQtyValue) . '" placeholder="' . dol_escape_htmltag($bomQtyPlaceholder) . '"></td></tr>';
	print '</table>';
	print '<div class="center" style="margin-top: 14px;">';
	print '<input type="submit" class="button button-save" value="' . $langs->trans("KreaProductsAssocToBomButton") . '">';
	print '</div>';
	print '</form>';
}

llxFooter();
$db->close();
