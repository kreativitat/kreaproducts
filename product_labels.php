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
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
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

register_shutdown_function(function () {
	$error = error_get_last();
	if (empty($error)) {
		return;
	}

	$fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
	if (!in_array((int) $error['type'], $fatalTypes, true)) {
		return;
	}

	dol_syslog(
		'product_labels.php fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'],
		LOG_ERR
	);
});

require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
dol_include_once('/kreaproducts/class/KreaProductsLabelService.class.php');

$langs->loadLangs(array('main', 'products', 'other', 'kreaproducts@kreaproducts'));

/**
 * Render a lightweight info page when the labels page is opened without a usable product context.
 *
 * @param Translate $langs          Translator
 * @param string    $message        Message to display
 * @param string    $productListUrl Product list URL
 * @return never
 */
function kreaProductsRenderContextPage($langs, $message, $productListUrl)
{
	llxHeader('', $langs->trans('KREAPRODUCTS_LABELS_TAB'));

	print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_TAB'));
	print '<div class="opacitymedium marginbottomonly">' . dol_escape_htmltag($message) . '</div>';
	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . dol_escape_htmltag($productListUrl) . '">' . $langs->trans('BackToList') . '</a>';
	print '</div>';

	llxFooter();
	exit;
}

/**
 * Normalize UI text by decoding HTML entities before final escaping/rendering.
 *
 * @param string $text Raw text
 * @return string
 */
function kreaProductsNormalizeUiText($text)
{
	$text = (string) $text;
	if ($text === '') {
		return '';
	}

	return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Render SVG viewer cards for a selected bundled label template.
 *
 * @param array $templateViewer Template viewer payload
 * @return string
 */
function kreaProductsRenderTemplateViewerPages($templateViewer)
{
	if (empty($templateViewer['pages']) || !is_array($templateViewer['pages'])) {
		return '';
	}

	$html = '';
	foreach ($templateViewer['pages'] as $page) {
		$pageLabel = kreaProductsNormalizeUiText(!empty($page['label']) ? $page['label'] : '');
		$pageSizeText = kreaProductsNormalizeUiText(!empty($page['size_text']) ? $page['size_text'] : '');

		$html .= '<div class="kreaLabelViewerPage">';
		$html .= '<div class="small opacitymedium kreaLabelViewerPageMeta">';
		$html .= dol_escape_htmltag($pageLabel);
		if ($pageSizeText !== '') {
			$html .= ' · ' . dol_escape_htmltag($pageSizeText);
		}
		$html .= '</div>';
		$html .= '<div class="kreaLabelViewerCanvas">';
		$html .= (!empty($page['svg']) ? $page['svg'] : '');
		$html .= '</div>';
		$html .= '</div>';
	}

	return $html;
}

/**
 * Render editable fields for selected template values.
 *
 * @param array $editableFields         Field metadata list
 * @param array $availableTemplateAssets Available template asset references
 * @return string
 */
function kreaProductsRenderTemplateEditableFields($editableFields, $availableTemplateAssets = array())
{
	global $langs;

	if (empty($editableFields) || !is_array($editableFields)) {
		return '';
	}

	$html = '<div class="kreaTemplateFieldsList">';
	foreach ($editableFields as $index => $field) {
		$source = (!empty($field['source']) ? (string) $field['source'] : '');
		if ($source === '') {
			continue;
		}

		$inputId = 'krea-template-field-' . ((int) $index);
		$label = kreaProductsNormalizeUiText(!empty($field['label']) ? (string) $field['label'] : $source);
		$type = (!empty($field['type']) ? strtolower((string) $field['type']) : 'text');
		$value = (isset($field['input_value']) ? (string) $field['input_value'] : (isset($field['value']) ? (string) $field['value'] : ''));
		$placeholder = kreaProductsNormalizeUiText(!empty($field['placeholder']) ? (string) $field['placeholder'] : '');
		$rows = (!empty($field['rows']) ? max(2, (int) $field['rows']) : 3);
		$min = (isset($field['min']) ? (string) $field['min'] : '');
		$max = (isset($field['max']) ? (string) $field['max'] : '');
		$step = (!empty($field['step']) ? (string) $field['step'] : '');
		$inputName = 'label_template_values[' . $source . ']';
		$inputTargetId = $inputId;
		$previewId = ($type === 'image' ? $inputId . '-preview' : '');
		$canReset = !empty($field['can_reset']);
		$resetInputValue = (isset($field['reset_input_value']) ? (string) $field['reset_input_value'] : '');
		$resetPreviewUrl = ($canReset && !empty($field['reset_asset_preview_url']) ? (string) $field['reset_asset_preview_url'] : '');
		$resetTitle = kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB_HELP'));
		$saveTitle = kreaProductsNormalizeUiText($langs->trans('Save'));

		$html .= '<div class="kreaTemplateFieldCard">';
		$html .= '<div class="kreaTemplateFieldHeader">';
		$html .= '<label for="' . dol_escape_htmltag($inputId) . '">' . dol_escape_htmltag($label) . '</label>';
		$html .= '<button type="button" class="kreaTemplateFieldSave classfortooltip"';
		$html .= ' data-source="' . dol_escape_htmltag($source) . '"';
		$html .= ' title="' . dol_escape_htmltag($saveTitle) . '"';
		$html .= ' aria-label="' . dol_escape_htmltag($saveTitle) . '">';
		$html .= '<span class="fas fa-save" aria-hidden="true"></span>';
		$html .= '</button>';
		if ($canReset) {
			$html .= '<button type="button" class="kreaTemplateFieldReset classfortooltip"';
			$html .= ' data-target-id="' . dol_escape_htmltag($inputTargetId) . '"';
			$html .= ' data-reset-value="' . dol_escape_htmltag($resetInputValue) . '"';
				if ($previewId !== '') {
				$html .= ' data-preview-id="' . dol_escape_htmltag($previewId) . '"';
				$html .= ' data-reset-preview-url="' . dol_escape_htmltag($resetPreviewUrl) . '"';
				}
			$html .= ' title="' . dol_escape_htmltag($resetTitle) . '"';
			$html .= ' aria-label="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB'))) . '">';
			$html .= img_picto(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB')), 'refresh');
			$html .= '</button>';
			}
		$html .= '</div>';
		$html .= '<br>';

			if ($type === 'image') {
				$assetPreviewUrl = (!empty($field['asset_preview_url']) ? (string) $field['asset_preview_url'] : '');
				$assetOptions = (is_array($availableTemplateAssets) ? $availableTemplateAssets : array());
				$hasCurrentAssetOption = false;
				if ($value !== '' && isset($assetOptions[$value])) {
					$hasCurrentAssetOption = true;
				}

			$html .= '<select id="' . dol_escape_htmltag($inputId) . '" class="minwidth300 kreaTemplateImageSelect"';
			$html .= ' name="' . dol_escape_htmltag($inputName) . '"';
			$html .= ' data-preview-id="' . dol_escape_htmltag($previewId) . '">';
			$html .= '<option value="" data-preview-url="">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_ASSET_PICKER_EMPTY'))) . '</option>';
			foreach ($assetOptions as $assetReference => $assetLabel) {
				$assetReference = (string) $assetReference;
				if ($assetReference === '') {
					continue;
				}
				$assetPreviewOptionUrl = KreaProductsLabelService::getTemplateAssetPreviewUrl($assetReference);
				$html .= '<option value="' . dol_escape_htmltag($assetReference) . '"';
				$html .= ' data-preview-url="' . dol_escape_htmltag($assetPreviewOptionUrl) . '"';
				if ($assetReference === $value) {
					$html .= ' selected';
				}
				$html .= '>' . dol_escape_htmltag((string) $assetLabel) . '</option>';
			}
			if ($value !== '' && !$hasCurrentAssetOption) {
				$html .= '<option value="' . dol_escape_htmltag($value) . '" selected data-preview-url="' . dol_escape_htmltag($assetPreviewUrl) . '">' . dol_escape_htmltag($value) . '</option>';
			}
			$html .= '</select>';
			$html .= '<div class="opacitymedium small" style="margin-top:4px;">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_ASSET_PICKER_HELP'))) . '</div>';

			$html .= '<div class="kreaTemplateImagePreviewWrap">';
			$html .= '<img id="' . dol_escape_htmltag($previewId) . '" class="kreaTemplateImagePreview"' . ($assetPreviewUrl !== '' ? '' : ' style="display:none;"');
			$html .= ' src="' . dol_escape_htmltag($assetPreviewUrl) . '" alt="" aria-hidden="true">';
			$html .= '</div>';
		} elseif ($type === 'select') {
			$options = (!empty($field['options']) && is_array($field['options']) ? $field['options'] : array());
			$html .= '<select id="' . dol_escape_htmltag($inputId) . '" class="flat minwidth75 maxwidth125"';
			$html .= ' name="' . dol_escape_htmltag($inputName) . '">';
			foreach ($options as $option) {
				if (!is_array($option)) {
					continue;
				}
				$optionValue = (isset($option['value']) ? (string) $option['value'] : '');
				$optionLabel = (isset($option['label']) ? (string) $option['label'] : $optionValue);
				$html .= '<option value="' . dol_escape_htmltag($optionValue) . '"';
				if ($optionValue === $value) {
					$html .= ' selected';
				}
				$html .= '>' . dol_escape_htmltag($optionLabel) . '</option>';
			}
			$html .= '</select>';
		} elseif ($type === 'textarea') {
			$html .= '<textarea id="' . dol_escape_htmltag($inputId) . '" class="minwidth300"';
			$html .= ' name="' . dol_escape_htmltag($inputName) . '"';
			$html .= ' rows="' . ((int) $rows) . '"';
			if ($placeholder !== '') {
				$html .= ' placeholder="' . dol_escape_htmltag($placeholder) . '"';
			}
			$html .= '>' . dol_escape_htmltag($value) . '</textarea>';
		} else {
			$inputType = 'text';
			if ($type === 'date') {
				$inputType = 'date';
			} elseif ($type === 'datetime') {
				$inputType = 'datetime-local';
			} elseif ($type === 'number') {
				$inputType = 'number';
			}

			$html .= '<input id="' . dol_escape_htmltag($inputId) . '" class="minwidth300" type="' . dol_escape_htmltag($inputType) . '"';
			$html .= ' name="' . dol_escape_htmltag($inputName) . '"';
			$html .= ' value="' . dol_escape_htmltag($value) . '"';
			if ($placeholder !== '' && $inputType !== 'date' && $inputType !== 'datetime-local') {
				$html .= ' placeholder="' . dol_escape_htmltag($placeholder) . '"';
			}
			if ($inputType === 'number') {
				if ($min !== '') {
					$html .= ' min="' . dol_escape_htmltag($min) . '"';
				}
				if ($max !== '') {
					$html .= ' max="' . dol_escape_htmltag($max) . '"';
				}
				if ($step !== '') {
					$html .= ' step="' . dol_escape_htmltag($step) . '"';
				}
			}
			$html .= '>';
		}
		$html .= '</div>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Merge uploaded template image assets into editable template values.
 *
 * @param int   $entityId            Current entity id
 * @param array $templateInputValues Raw editable values
 * @param array $uploadErrors        Collected upload error messages
 * @return array
 */
function kreaProductsMergeTemplateUploadedFiles($entityId, $templateInputValues, &$uploadErrors)
{
	global $langs;

	$merged = (is_array($templateInputValues) ? $templateInputValues : array());
	$uploadErrors = (is_array($uploadErrors) ? $uploadErrors : array());

	if (empty($_FILES['label_template_files']) || !is_array($_FILES['label_template_files'])) {
		return $merged;
	}

	$fileBag = $_FILES['label_template_files'];
	if (empty($fileBag['name']) || !is_array($fileBag['name'])) {
		return $merged;
	}

	$assetDir = KreaProductsLabelService::getTemplateAssetDir($entityId);
	$allowedExtensions = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');

	foreach ($fileBag['name'] as $source => $originalName) {
		$source = strtolower(trim((string) $source));
		if ($source === '' || preg_match('/^[a-z0-9_.-]+$/', $source) !== 1) {
			continue;
		}

		$errorCode = (isset($fileBag['error'][$source]) ? (int) $fileBag['error'][$source] : UPLOAD_ERR_NO_FILE);
		if ($errorCode === UPLOAD_ERR_NO_FILE) {
			continue;
		}
		if ($errorCode !== UPLOAD_ERR_OK) {
			$uploadErrors[] = $langs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', $source);
			continue;
		}

		$tmpName = (isset($fileBag['tmp_name'][$source]) ? (string) $fileBag['tmp_name'][$source] : '');
		if ($tmpName === '' || !is_uploaded_file($tmpName)) {
			$uploadErrors[] = $langs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', $source);
			continue;
		}

		$extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
		if (!in_array($extension, $allowedExtensions, true)) {
			$uploadErrors[] = $langs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD_TYPE', $source);
			continue;
		}

		if (!is_dir($assetDir) && !@mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
			$uploadErrors[] = $langs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', $source);
			continue;
		}

		$timestamp = dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt');
		$targetName = dol_sanitizeFileName(str_replace('.', '_', $source) . '_' . $timestamp . '_' . mt_rand(1000, 9999) . '.' . $extension);
		$targetPath = $assetDir . '/' . $targetName;
		if (!@move_uploaded_file($tmpName, $targetPath)) {
			$uploadErrors[] = $langs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', $source);
			continue;
		}

			$merged[$source] = 'templates/assets/' . $targetName;
		}

	return $merged;
}

/**
 * Sanitize one template source key.
 *
 * @param string $source Source key
 * @return string
 */
function kreaProductsSanitizeTemplateSource($source)
{
	$source = strtolower(trim((string) $source));
	if ($source === '' || preg_match('/^[a-z0-9_.-]+$/', $source) !== 1) {
		return '';
	}

	return $source;
}

/**
 * Sanitize one template code key.
 *
 * @param string $templateCode Template code
 * @return string
 */
function kreaProductsSanitizeTemplateCode($templateCode)
{
	$templateCode = strtolower(trim((string) $templateCode));
	if ($templateCode === '' || preg_match('/^[a-z0-9_.-]+$/', $templateCode) !== 1) {
		return '';
	}

	return $templateCode;
}

/**
 * Parse product-level label storage payload.
 *
 * @param string $rawValue Raw extrafield value
 * @return array
 */
function kreaProductsParseLabelStoragePayload($rawValue)
{
	$payload = array(
		'default_label_layout' => '',
		'template_values' => array(),
		'template_descriptions' => array(),
	);

	$rawValue = trim((string) $rawValue);
	if ($rawValue === '') {
		return $payload;
	}

	$decoded = json_decode($rawValue, true);
	if (!is_array($decoded)) {
		$legacyDefaultLayout = kreaProductsSanitizeTemplateCode($rawValue);
		if ($legacyDefaultLayout !== '') {
			$payload['default_label_layout'] = $legacyDefaultLayout;
		}
		return $payload;
	}

	$defaultLayoutRaw = '';
	if (isset($decoded['default_label_layout'])) {
		$defaultLayoutRaw = (string) $decoded['default_label_layout'];
	} elseif (isset($decoded['default_layout'])) {
		$defaultLayoutRaw = (string) $decoded['default_layout'];
	}
	$payload['default_label_layout'] = kreaProductsSanitizeTemplateCode($defaultLayoutRaw);

	if (!empty($decoded['template_values']) && is_array($decoded['template_values'])) {
		foreach ($decoded['template_values'] as $templateCode => $sourceValues) {
			$sanitizedTemplateCode = kreaProductsSanitizeTemplateCode($templateCode);
			if ($sanitizedTemplateCode === '' || !is_array($sourceValues)) {
				continue;
			}

			$cleanSourceValues = array();
			foreach ($sourceValues as $source => $value) {
				$sanitizedSource = kreaProductsSanitizeTemplateSource($source);
				if ($sanitizedSource === '' || is_array($value) || is_object($value)) {
					continue;
				}

				$cleanValue = (string) $value;
				if (trim($cleanValue) === '') {
					continue;
				}

				$cleanSourceValues[$sanitizedSource] = $cleanValue;
			}

			if (!empty($cleanSourceValues)) {
				$payload['template_values'][$sanitizedTemplateCode] = $cleanSourceValues;
			}
		}
	}

	if (!empty($decoded['template_descriptions']) && is_array($decoded['template_descriptions'])) {
		foreach ($decoded['template_descriptions'] as $templateCode => $description) {
			$sanitizedTemplateCode = kreaProductsSanitizeTemplateCode($templateCode);
			if ($sanitizedTemplateCode === '' || is_array($description) || is_object($description)) {
				continue;
			}

			$cleanDescription = trim((string) $description);
			if ($cleanDescription === '') {
				continue;
			}

			$payload['template_descriptions'][$sanitizedTemplateCode] = $cleanDescription;
		}
	}

	return $payload;
}

/**
 * Encode product-level label storage payload as JSON.
 *
 * @param array $storagePayload Payload array
 * @return string|false
 */
function kreaProductsEncodeLabelStoragePayload($storagePayload)
{
	$normalized = array(
		'version' => 1,
		'default_label_layout' => '',
		'template_values' => array(),
		'template_descriptions' => array(),
	);

	if (is_array($storagePayload)) {
		$normalized['default_label_layout'] = kreaProductsSanitizeTemplateCode(!empty($storagePayload['default_label_layout']) ? (string) $storagePayload['default_label_layout'] : '');
		if (!empty($storagePayload['template_values']) && is_array($storagePayload['template_values'])) {
			foreach ($storagePayload['template_values'] as $templateCode => $sourceValues) {
				$sanitizedTemplateCode = kreaProductsSanitizeTemplateCode($templateCode);
				if ($sanitizedTemplateCode === '' || !is_array($sourceValues)) {
					continue;
				}

				$cleanSourceValues = array();
				foreach ($sourceValues as $source => $value) {
					$sanitizedSource = kreaProductsSanitizeTemplateSource($source);
					if ($sanitizedSource === '' || is_array($value) || is_object($value)) {
						continue;
					}

					$cleanValue = (string) $value;
					if (trim($cleanValue) === '') {
						continue;
					}

					$cleanSourceValues[$sanitizedSource] = $cleanValue;
				}

				if (!empty($cleanSourceValues)) {
					$normalized['template_values'][$sanitizedTemplateCode] = $cleanSourceValues;
				}
			}
		}
		if (!empty($storagePayload['template_descriptions']) && is_array($storagePayload['template_descriptions'])) {
			foreach ($storagePayload['template_descriptions'] as $templateCode => $description) {
				$sanitizedTemplateCode = kreaProductsSanitizeTemplateCode($templateCode);
				if ($sanitizedTemplateCode === '' || is_array($description) || is_object($description)) {
					continue;
				}

				$cleanDescription = trim((string) $description);
				if ($cleanDescription === '') {
					continue;
				}

				$normalized['template_descriptions'][$sanitizedTemplateCode] = $cleanDescription;
			}
		}
	}

	return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Ensure label payload storage column supports JSON-length content.
 *
 * @param DoliDB    $db          Database handler
 * @param Translate $outputlangs Output language
 * @return array
 */
function kreaProductsEnsureLabelStorageColumnCapacity($db, $outputlangs)
{
	static $isChecked = false;
	static $lastError = '';

	if ($isChecked) {
		return ($lastError !== '' ? array('error' => $lastError) : array('success' => true));
	}

	if (!is_object($db)) {
		$isChecked = true;
		$lastError = 'Invalid database handler';
		return array('error' => $lastError);
	}

	$tableName = str_replace('`', '', MAIN_DB_PREFIX . 'product_extrafields');
	$columnName = 'kreap_default_label_layout';

	$sqlCheck = "SELECT DATA_TYPE";
	$sqlCheck .= " FROM information_schema.COLUMNS";
	$sqlCheck .= " WHERE TABLE_SCHEMA = DATABASE()";
	$sqlCheck .= " AND TABLE_NAME = '" . $db->escape($tableName) . "'";
	$sqlCheck .= " AND COLUMN_NAME = '" . $db->escape($columnName) . "'";
	$sqlCheck .= " LIMIT 1";

	$resql = $db->query($sqlCheck);
	if (!$resql) {
		$isChecked = true;
		$lastError = (!empty($db->lasterror()) ? $db->lasterror() : 'Failed to inspect label storage column');
		dol_syslog(__FUNCTION__ . ' SQL check failed: ' . $lastError, LOG_ERR);
		return array('error' => $lastError);
	}

	if ($db->num_rows($resql) <= 0) {
		$db->free($resql);
		$isChecked = true;
		$lastError = 'Missing label storage column: ' . $columnName;
		dol_syslog(__FUNCTION__ . ' ' . $lastError, LOG_ERR);
		return array('error' => $lastError);
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);
	$currentType = strtolower((!empty($obj->DATA_TYPE) ? (string) $obj->DATA_TYPE : ''));
	$textTypes = array('text', 'mediumtext', 'longtext', 'json');
	if (in_array($currentType, $textTypes, true)) {
		$isChecked = true;
		return array('success' => true);
	}

	$sqlAlter = "ALTER TABLE `" . $tableName . "` MODIFY `" . $columnName . "` LONGTEXT NULL";
	if (!$db->query($sqlAlter)) {
		$isChecked = true;
		$lastError = (!empty($db->lasterror()) ? $db->lasterror() : 'Failed to alter label storage column');
		dol_syslog(__FUNCTION__ . ' SQL alter failed: ' . $lastError, LOG_ERR);
		return array('error' => $lastError);
	}

	$sqlUpdateExtrafieldDef = "UPDATE " . MAIN_DB_PREFIX . "extrafields";
	$sqlUpdateExtrafieldDef .= " SET type = 'text', size = '65535'";
	$sqlUpdateExtrafieldDef .= " WHERE elementtype = 'product'";
	$sqlUpdateExtrafieldDef .= " AND name = '" . $db->escape($columnName) . "'";
	$db->query($sqlUpdateExtrafieldDef);

	$isChecked = true;
	return array('success' => true);
}

/**
 * Persist product-level label storage payload into product extrafields.
 *
 * @param Product   $product       Product object
 * @param array     $storagePayload Storage payload
 * @param Translate $outputlangs   Output language
 * @return array
 */
function kreaProductsPersistLabelStoragePayload($product, $storagePayload, $outputlangs)
{
	if (!is_object($product) || empty($product->id) || !is_object($product->db)) {
		return array('error' => $outputlangs->trans('ErrorBadParameters'));
	}

	$columnCapacityResult = kreaProductsEnsureLabelStorageColumnCapacity($product->db, $outputlangs);
	if (!empty($columnCapacityResult['error'])) {
		return array('error' => $columnCapacityResult['error']);
	}

	$encodedPayload = kreaProductsEncodeLabelStoragePayload($storagePayload);
	if ($encodedPayload === false) {
		return array('error' => $outputlangs->trans('Error'));
	}

	require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($product->db);
	$extrafields->fetch_name_optionals_label($product->table_element);
	$product->fetch_optionals($product->id, $extrafields);
	$product->array_options['options_kreap_default_label_layout'] = $encodedPayload;
	$saveResult = $product->insertExtraFields();
	if ($saveResult < 0) {
		$saveErrors = (!empty($product->errors) && is_array($product->errors) ? $product->errors : array());
		if (empty($saveErrors) && !empty($product->error)) {
			$saveErrors[] = $product->error;
		}
		if (empty($saveErrors)) {
			$saveErrors[] = $outputlangs->trans('Error');
		}

		return array('error' => implode(', ', $saveErrors));
	}

	return array(
		'success' => true,
		'value' => $encodedPayload,
	);
}

/**
 * Build source metadata map from editable fields.
 *
 * @param array $editableFields Editable field list
 * @return array
 */
function kreaProductsBuildTemplateSourceMetaMap($editableFields)
{
	$sourceMetaBySource = array();
	if (empty($editableFields) || !is_array($editableFields)) {
		return $sourceMetaBySource;
	}

	foreach ($editableFields as $editableField) {
		if (empty($editableField['source'])) {
			continue;
		}

		$source = kreaProductsSanitizeTemplateSource($editableField['source']);
		if ($source === '') {
			continue;
		}

		$sourceMetaBySource[$source] = array(
			'type' => (!empty($editableField['type']) ? (string) $editableField['type'] : 'text'),
			'min' => (isset($editableField['min']) ? (string) $editableField['min'] : ''),
			'max' => (isset($editableField['max']) ? (string) $editableField['max'] : ''),
			'step' => (!empty($editableField['step']) ? (string) $editableField['step'] : ''),
			'rows' => (!empty($editableField['rows']) ? (int) $editableField['rows'] : 3),
			'options' => (!empty($editableField['options']) && is_array($editableField['options']) ? $editableField['options'] : array()),
		);
	}

	return $sourceMetaBySource;
}

/**
 * Synchronize template validity days input with llx_product.lifetime.
 *
 * @param DoliDB    $db                  Database handler
 * @param Product   $product             Product object
 * @param array     $templateInputValues Template input values
 * @param Translate $outputlangs         Output language
 * @return array
 */
function kreaProductsSyncProductLifetimeFromTemplateValues($db, $product, $templateInputValues, $outputlangs)
{
	global $conf;

	$result = array(
		'updated' => false,
		'has_input' => false,
		'value' => null,
	);

	if (!is_object($db) || !is_object($product) || empty($product->id) || !is_array($templateInputValues)) {
		return $result;
	}

	$source = 'batch.validity_days';
	if (!array_key_exists($source, $templateInputValues)) {
		return $result;
	}
	$result['has_input'] = true;

	$rawValue = trim((string) $templateInputValues[$source]);
	$parsedValue = null;
	if ($rawValue !== '') {
		$normalizedValue = str_replace(',', '.', $rawValue);
		if (preg_match('/^-?\d+(?:\.\d+)?$/', $normalizedValue) !== 1) {
			return array(
				'updated' => false,
				'has_input' => true,
				'value' => null,
				'error' => $outputlangs->trans('ErrorBadValueForParameter', $outputlangs->trans('KREAPRODUCTS_LABELS_FIELD_VALIDITY_DAYS')),
			);
		}

		$parsedValue = (int) round((float) $normalizedValue);
		if ($parsedValue < 0) {
			return array(
				'updated' => false,
				'has_input' => true,
				'value' => null,
				'error' => $outputlangs->trans('ErrorBadValueForParameter', $outputlangs->trans('KREAPRODUCTS_LABELS_FIELD_VALIDITY_DAYS')),
			);
		}
	}

	$entityList = trim((string) getEntity('product'));
	if ($entityList === '') {
		$entityList = (string) ((int) (!empty($product->entity) ? $product->entity : $conf->entity));
	}

	$sql = "UPDATE " . MAIN_DB_PREFIX . "product";
	$sql .= " SET lifetime = " . ($parsedValue === null ? "NULL" : (string) ((int) $parsedValue));
	$sql .= " WHERE rowid = " . ((int) $product->id);
	if ($entityList !== '') {
		$sql .= " AND entity IN (" . $db->sanitize($entityList) . ")";
	}

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__ . ' failed to sync product lifetime for product id=' . ((int) $product->id) . ': ' . $db->lasterror(), LOG_ERR);
		return array(
			'updated' => false,
			'has_input' => true,
			'value' => null,
			'error' => $outputlangs->trans('ErrorFailedToUpdateRecord'),
		);
	}

	$result['updated'] = true;
	$result['value'] = $parsedValue;

	return $result;
}

$id = GETPOSTINT('id');
$ref = trim((string) GETPOST('ref', 'alphanohtml'));
$action = GETPOST('action', 'aZ09');
if (GETPOSTINT('save_default_label_layout') > 0) {
	$action = 'save_default_label_layout';
}
$productListUrl = DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1';

if ($id <= 0 && $ref === '') {
	kreaProductsRenderContextPage($langs, $langs->trans('KREAPRODUCTS_LABELS_CONTEXT_REQUIRED'), $productListUrl);
}

$object = new Product($db);
$fetchResult = $object->fetch($id, $ref);

if ($fetchResult > 0 && $object->id > 0) {
	if ($object->type == Product::TYPE_PRODUCT) {
		restrictedArea($user, 'produit', $object->id, 'product&product');
	} else {
		restrictedArea($user, 'service', $object->id, 'product&product');
	}
} else {
	kreaProductsRenderContextPage($langs, $langs->trans('KREAPRODUCTS_LABELS_INVALID_PRODUCT'), $productListUrl);
}

if ($user->socid > 0) {
	accessforbidden();
}

// Read access is already enforced by restrictedArea() above, matching core product card flow.
$permissiontoread = true;
$permissiontowrite = (($object->type == Product::TYPE_PRODUCT && $user->hasRight('produit', 'creer')) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'creer')));

if (!$permissiontoread) {
	accessforbidden();
}

$form = new Form($db);
$formfile = new FormFile($db);
$currentEntityId = (int) $conf->entity;
$templateAssetOptions = KreaProductsLabelService::listTemplateAssetReferences($currentEntityId);
$object->fetch_optionals();
$productCardLabel = (string) $object->label;
$productAlias = trim((string) (!empty($object->array_options['options_kreap_alias']) ? $object->array_options['options_kreap_alias'] : ''));
if ($productAlias !== '') {
	$object->label = $productAlias;
}

$formatOptions = KreaProductsLabelService::getFormatOptions($db);
$formatDetails = KreaProductsLabelService::getFormatDetails($db);
$formatSummaryMap = array();
foreach ($formatDetails as $formatCode => $detail) {
	$formatSummaryMap[$formatCode] = kreaProductsNormalizeUiText(KreaProductsLabelService::buildFormatSummaryText($detail, $langs));
}
$standardPreviewData = KreaProductsLabelService::buildStandardPreviewData($db, $object, $langs);
$fieldOptions = KreaProductsLabelService::getAvailableFields($langs);
$labelTemplates = KreaProductsLabelService::listLabelTemplates($currentEntityId);
$hasLabelTemplates = !empty($labelTemplates);
$templateOptions = array('' => kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD')));
foreach ($labelTemplates as $templateCode => $templateMeta) {
	$optionLabel = (!empty($templateMeta['filename']) ? (string) $templateMeta['filename'] : ((string) $templateCode . '.json'));
	$templateOptions[$templateCode] = $optionLabel;
}
$rawLabelStoragePayload = (!empty($object->array_options['options_kreap_default_label_layout']) ? (string) $object->array_options['options_kreap_default_label_layout'] : '');
$labelStoragePayload = kreaProductsParseLabelStoragePayload($rawLabelStoragePayload);
$currentDefaultLabelLayout = (!empty($labelStoragePayload['default_label_layout']) ? (string) $labelStoragePayload['default_label_layout'] : '');
$currentDefaultLabelLayoutIsAvailable = ($currentDefaultLabelLayout !== '' && !empty($labelTemplates[$currentDefaultLabelLayout]));
if ($currentDefaultLabelLayout !== '' && empty($templateOptions[$currentDefaultLabelLayout])) {
	$templateOptions[$currentDefaultLabelLayout] = $currentDefaultLabelLayout;
}

$selectedTemplate = GETPOST('label_template', 'alphanohtml');
if ($selectedTemplate === '' && !GETPOSTISSET('label_template') && $currentDefaultLabelLayoutIsAvailable) {
	$selectedTemplate = $currentDefaultLabelLayout;
}
if ($selectedTemplate !== '' && empty($templateOptions[$selectedTemplate])) {
	$selectedTemplate = '';
}
$isStandardMode = ($selectedTemplate === '');
$selectedTemplateMeta = (!$isStandardMode && !empty($labelTemplates[$selectedTemplate]) ? $labelTemplates[$selectedTemplate] : array());
$selectedTemplateDataWritable = (!$isStandardMode);
$templateDescriptionRaw = GETPOST('label_template_description', 'restricthtml');
$templateDescriptionProvided = GETPOSTISSET('label_template_description');
$selectedTemplateDescription = '';
if (!$isStandardMode) {
	$selectedTemplateStorageCode = kreaProductsSanitizeTemplateCode($selectedTemplate);
	$storedTemplateDescription = '';
	if ($selectedTemplateStorageCode !== '' && !empty($labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode])) {
		$storedTemplateDescription = (string) $labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode];
	}

	if ($templateDescriptionProvided) {
		$selectedTemplateDescription = trim((string) $templateDescriptionRaw);
	} elseif ($storedTemplateDescription !== '') {
		$selectedTemplateDescription = $storedTemplateDescription;
	} else {
		$selectedTemplateDescription = (!empty($selectedTemplateMeta['description']) ? (string) $selectedTemplateMeta['description'] : '');
	}
}
$forceRefreshData = (GETPOSTINT('krea_refresh') > 0);
$rawTemplateInputValues = GETPOST('label_template_values', 'array');
$templateInputValuesByCode = (!empty($labelStoragePayload['template_values']) && is_array($labelStoragePayload['template_values']) ? $labelStoragePayload['template_values'] : array());
if (!$forceRefreshData && !$isStandardMode && is_array($rawTemplateInputValues)) {
	$selectedTemplateStorageCode = kreaProductsSanitizeTemplateCode($selectedTemplate);
	if ($selectedTemplateStorageCode !== '') {
		if (empty($templateInputValuesByCode[$selectedTemplateStorageCode]) || !is_array($templateInputValuesByCode[$selectedTemplateStorageCode])) {
			$templateInputValuesByCode[$selectedTemplateStorageCode] = array();
		}
		foreach ($rawTemplateInputValues as $source => $value) {
			$sanitizedSource = kreaProductsSanitizeTemplateSource($source);
			if ($sanitizedSource === '' || is_array($value) || is_object($value)) {
				continue;
			}
			$templateInputValuesByCode[$selectedTemplateStorageCode][$sanitizedSource] = (string) $value;
		}
	}
}
$templateViewerMap = KreaProductsLabelService::buildLabelTemplateViewerMap($object, $langs, $currentEntityId, $templateInputValuesByCode);
$templateEditableFieldMap = KreaProductsLabelService::buildTemplateEditableFieldMap($object, $langs, $currentEntityId, $templateInputValuesByCode);
$templateFieldsEmptyHtml = '<span class="opacitymedium small">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_FIELDS_NONE'))) . '</span>';
$templateEditableHtmlMap = array();
foreach ($templateEditableFieldMap as $templateCode => $templateFields) {
	if (!is_string($templateCode) || $templateCode === '') {
		continue;
	}

	$renderedFields = '';
	if (!empty($templateFields) && is_array($templateFields)) {
		$renderedFields = kreaProductsRenderTemplateEditableFields($templateFields, $templateAssetOptions);
	}
	if (trim($renderedFields) === '') {
		$renderedFields = $templateFieldsEmptyHtml;
	}

	$templateEditableHtmlMap[$templateCode] = $renderedFields;
}
$selectedTemplateEditableFields = (!$isStandardMode && !empty($templateEditableFieldMap[$selectedTemplate]) ? $templateEditableFieldMap[$selectedTemplate] : array());
$templateSourceMetaBySource = kreaProductsBuildTemplateSourceMetaMap($selectedTemplateEditableFields);
$templateInputValues = array();
foreach ($selectedTemplateEditableFields as $editableField) {
	if (!empty($editableField['source'])) {
		$templateInputValues[$editableField['source']] = (isset($editableField['input_value']) ? (string) $editableField['input_value'] : (isset($editableField['value']) ? (string) $editableField['value'] : ''));
	}
}
$selectedTemplateViewer = (!$isStandardMode && !empty($templateViewerMap[$selectedTemplate]) ? $templateViewerMap[$selectedTemplate] : array());

$selectedFormat = GETPOST('label_format', 'alphanohtml');
if ($selectedFormat === '' || empty($formatOptions[$selectedFormat])) {
	$selectedFormat = KreaProductsLabelService::getDefaultFormatCode($formatOptions);
}
$useTemplateSize = (!$isStandardMode && !empty($selectedTemplateViewer['can_use_template_format']));
$selectedFormatSummary = '';
if (!$isStandardMode && !empty($selectedTemplateViewer['template_format_summary'])) {
	$selectedFormatSummary = kreaProductsNormalizeUiText($selectedTemplateViewer['template_format_summary']);
} elseif (!empty($formatSummaryMap[$selectedFormat])) {
	$selectedFormatSummary = kreaProductsNormalizeUiText($formatSummaryMap[$selectedFormat]);
}

$selectedFields = GETPOST('label_fields', 'array');
if ($action !== 'generate_labels' && (!is_array($selectedFields) || empty($selectedFields))) {
	$selectedFields = array('ref', 'label', 'barcode');
}
$selectedFields = KreaProductsLabelService::sanitizeSelectedFields($selectedFields);
$quantity = GETPOSTINT('label_qty');
if ($quantity <= 0) {
	$quantity = 1;
}
$effectiveQuantity = ($isStandardMode ? $quantity : 1);
$effectiveSelectedFields = ($isStandardMode ? $selectedFields : array());

$moduleSubdir = KreaProductsLabelService::getDocumentModuleSubdir($currentEntityId, $object->id);
$fileDir = KreaProductsLabelService::getDocumentDir($currentEntityId, $object->id);
$templateModuleSubdir = KreaProductsLabelService::getTemplateModuleSubdir($currentEntityId);
$templateDir = KreaProductsLabelService::getTemplateDir($currentEntityId);
$templateAssetModuleSubdir = KreaProductsLabelService::getTemplateAssetModuleSubdir($currentEntityId);
$templateAssetDir = KreaProductsLabelService::getTemplateAssetDir($currentEntityId);
$urlSource = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
$selectedTemplateUrlParam = '&label_template=' . urlencode((string) $selectedTemplate);
$urlSourceWithTemplate = $urlSource . $selectedTemplateUrlParam;

if ($action === 'save_default_label_layout') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	$defaultLabelLayoutToSave = GETPOST('label_template', 'alphanohtml');
	if ($defaultLabelLayoutToSave !== '' && empty($templateOptions[$defaultLabelLayoutToSave])) {
		setEventMessages('', array($langs->trans('ErrorBadValueForParameter', 'label_template')), 'errors');
	} else {
		$labelStoragePayload['default_label_layout'] = kreaProductsSanitizeTemplateCode($defaultLabelLayoutToSave);
		$saveStorageResult = kreaProductsPersistLabelStoragePayload($object, $labelStoragePayload, $langs);
		if (!empty($saveStorageResult['error'])) {
			setEventMessages('', array($saveStorageResult['error']), 'errors');
		} else {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		}
	}

	$saveDefaultRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&label_template=' . urlencode((string) $defaultLabelLayoutToSave);
	$saveDefaultRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
	header('Location: ' . $saveDefaultRedirectUrl);
	exit;
} elseif ($action === 'generate_labels') {
	if (!$permissiontowrite) {
		accessforbidden();
	}
	if ($isStandardMode && empty($formatOptions)) {
		setEventMessages('', array($langs->trans('KREAPRODUCTS_LABELS_NO_FORMATS')), 'errors');
	} else {
		$templateInputValuesToGenerate = $templateInputValues;
		$allowGenerate = true;
		if (!$isStandardMode) {
			$uploadErrors = array();
			$templateInputValuesToGenerate = kreaProductsMergeTemplateUploadedFiles($currentEntityId, $templateInputValuesToGenerate, $uploadErrors);
			if (!empty($uploadErrors)) {
				setEventMessages('', $uploadErrors, 'errors');
			}

			$sanitizedTemplateInputValues = KreaProductsLabelService::sanitizeTemplateInputValues(
				$templateInputValuesToGenerate,
				array_keys($templateSourceMetaBySource),
				$templateSourceMetaBySource
			);
			$templateInputValuesToGenerate = $sanitizedTemplateInputValues;
			$storageTemplateInputValues = array();
			foreach ($sanitizedTemplateInputValues as $source => $value) {
				if ((string) $value !== '') {
					$storageTemplateInputValues[$source] = (string) $value;
				}
			}

			$lifetimeSyncResult = kreaProductsSyncProductLifetimeFromTemplateValues($db, $object, $sanitizedTemplateInputValues, $langs);
			if (!empty($lifetimeSyncResult['error'])) {
				setEventMessages('', array($lifetimeSyncResult['error']), 'errors');
				$allowGenerate = false;
			} elseif (!empty($lifetimeSyncResult['updated'])) {
				$object->lifetime = $lifetimeSyncResult['value'];
			}

			if ($allowGenerate) {
				$selectedTemplateStorageCode = kreaProductsSanitizeTemplateCode($selectedTemplate);
				if ($selectedTemplateStorageCode === '') {
					setEventMessages('', array($langs->trans('ErrorBadValueForParameter', 'label_template')), 'errors');
					$allowGenerate = false;
				} else {
					if (!isset($labelStoragePayload['template_values']) || !is_array($labelStoragePayload['template_values'])) {
						$labelStoragePayload['template_values'] = array();
					}
					if (!isset($labelStoragePayload['template_descriptions']) || !is_array($labelStoragePayload['template_descriptions'])) {
						$labelStoragePayload['template_descriptions'] = array();
					}

					if (!empty($storageTemplateInputValues)) {
						$labelStoragePayload['template_values'][$selectedTemplateStorageCode] = $storageTemplateInputValues;
					} else {
						unset($labelStoragePayload['template_values'][$selectedTemplateStorageCode]);
					}

					$sanitizedTemplateDescriptionForStorage = trim((string) $selectedTemplateDescription);
					if ($sanitizedTemplateDescriptionForStorage !== '') {
						$labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode] = $sanitizedTemplateDescriptionForStorage;
					} else {
						unset($labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode]);
					}

					$saveStorageResult = kreaProductsPersistLabelStoragePayload($object, $labelStoragePayload, $langs);
					if (!empty($saveStorageResult['error'])) {
						setEventMessages('', array($saveStorageResult['error']), 'errors');
						$allowGenerate = false;
					}
				}
			}
		}

		if ($allowGenerate) {
			$result = KreaProductsLabelService::generateProductLabels($db, $object, $currentEntityId, $selectedFormat, $effectiveSelectedFields, $effectiveQuantity, $langs, $selectedTemplate, $useTemplateSize, $templateInputValuesToGenerate);
			if (!empty($result['error'])) {
				setEventMessages('', array($result['error']), 'errors');
			} else {
				setEventMessages($langs->trans('KREAPRODUCTS_LABELS_GENERATED', $result['filename']), null, 'mesgs');
				$successRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . $selectedTemplateUrlParam;
				$successRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
				header('Location: ' . $successRedirectUrl);
				exit;
			}
		}
	}
} elseif ($action === 'save_template_values') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	if ($isStandardMode || $selectedTemplate === '') {
		setEventMessages('', array($langs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_UNAVAILABLE')), 'errors');
	} else {
		$templateInputValuesToSave = (is_array($rawTemplateInputValues) ? $rawTemplateInputValues : array());
		$uploadErrors = array();
		$templateInputValuesToSave = kreaProductsMergeTemplateUploadedFiles($currentEntityId, $templateInputValuesToSave, $uploadErrors);
		if (!empty($uploadErrors)) {
			setEventMessages('', $uploadErrors, 'errors');
		}
		$singleTemplateSourceToSave = kreaProductsSanitizeTemplateSource(GETPOST('label_template_single_source', 'alphanohtml'));
		$canSaveTemplateValues = true;
		if ($singleTemplateSourceToSave !== '') {
			$allowedEditableSourceMap = array();
			foreach ($selectedTemplateEditableFields as $editableField) {
				if (empty($editableField['source'])) {
					continue;
				}
				$editableSource = kreaProductsSanitizeTemplateSource($editableField['source']);
				if ($editableSource !== '') {
					$allowedEditableSourceMap[$editableSource] = true;
				}
			}

			if (empty($allowedEditableSourceMap[$singleTemplateSourceToSave])) {
				setEventMessages('', array($langs->trans('ErrorBadValueForParameter', 'label_template_single_source')), 'errors');
				$canSaveTemplateValues = false;
			} else {
				$templateInputValuesToSave = array(
					$singleTemplateSourceToSave => (array_key_exists($singleTemplateSourceToSave, $templateInputValuesToSave) ? $templateInputValuesToSave[$singleTemplateSourceToSave] : ''),
				);
			}
		}

		if ($canSaveTemplateValues) {
			$sanitizedTemplateInputValues = KreaProductsLabelService::sanitizeTemplateInputValues(
				$templateInputValuesToSave,
				array_keys($templateSourceMetaBySource),
				$templateSourceMetaBySource
			);
			$storageTemplateInputValues = array();
			foreach ($sanitizedTemplateInputValues as $source => $value) {
				if ((string) $value !== '') {
					$storageTemplateInputValues[$source] = (string) $value;
				}
			}

			$lifetimeSyncResult = kreaProductsSyncProductLifetimeFromTemplateValues($db, $object, $sanitizedTemplateInputValues, $langs);
			if (!empty($lifetimeSyncResult['error'])) {
				setEventMessages('', array($lifetimeSyncResult['error']), 'errors');
			} else {
				if (!empty($lifetimeSyncResult['updated'])) {
					$object->lifetime = $lifetimeSyncResult['value'];
				}

				$selectedTemplateStorageCode = kreaProductsSanitizeTemplateCode($selectedTemplate);
				if ($selectedTemplateStorageCode === '') {
					setEventMessages('', array($langs->trans('ErrorBadValueForParameter', 'label_template')), 'errors');
				} else {
					if (!isset($labelStoragePayload['template_values']) || !is_array($labelStoragePayload['template_values'])) {
						$labelStoragePayload['template_values'] = array();
					}
					if (!isset($labelStoragePayload['template_descriptions']) || !is_array($labelStoragePayload['template_descriptions'])) {
						$labelStoragePayload['template_descriptions'] = array();
					}

					$currentStoredTemplateValues = (!empty($labelStoragePayload['template_values'][$selectedTemplateStorageCode]) && is_array($labelStoragePayload['template_values'][$selectedTemplateStorageCode]) ? $labelStoragePayload['template_values'][$selectedTemplateStorageCode] : array());
					if ($singleTemplateSourceToSave !== '') {
						if (array_key_exists($singleTemplateSourceToSave, $sanitizedTemplateInputValues) && (string) $sanitizedTemplateInputValues[$singleTemplateSourceToSave] !== '') {
							$currentStoredTemplateValues[$singleTemplateSourceToSave] = (string) $sanitizedTemplateInputValues[$singleTemplateSourceToSave];
						} else {
							unset($currentStoredTemplateValues[$singleTemplateSourceToSave]);
						}
					} else {
						$currentStoredTemplateValues = $storageTemplateInputValues;
					}

					if (!empty($currentStoredTemplateValues)) {
						$labelStoragePayload['template_values'][$selectedTemplateStorageCode] = $currentStoredTemplateValues;
					} else {
						unset($labelStoragePayload['template_values'][$selectedTemplateStorageCode]);
					}

					$sanitizedTemplateDescriptionForStorage = trim((string) $selectedTemplateDescription);
					if ($sanitizedTemplateDescriptionForStorage !== '') {
						$labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode] = $sanitizedTemplateDescriptionForStorage;
					} else {
						unset($labelStoragePayload['template_descriptions'][$selectedTemplateStorageCode]);
					}

					$saveStorageResult = kreaProductsPersistLabelStoragePayload($object, $labelStoragePayload, $langs);
					if (!empty($saveStorageResult['error'])) {
						setEventMessages('', array($saveStorageResult['error']), 'errors');
					} else {
						setEventMessages($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_VALUES_SAVED'), null, 'mesgs');
					}
				}
			}
		}
	}

	$refreshRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . $selectedTemplateUrlParam;
	$refreshRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());

	header('Location: ' . $refreshRedirectUrl);
	exit;
} elseif ($action === 'upload_template_json') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	$uploadResult = KreaProductsLabelService::importTemplateUploadedJsonFile(
		$currentEntityId,
		(!empty($_FILES['label_template_upload']) && is_array($_FILES['label_template_upload']) ? $_FILES['label_template_upload'] : array()),
		$langs
	);
	if (!empty($uploadResult['error'])) {
		setEventMessages('', array($uploadResult['error']), 'errors');
	} else {
		setEventMessages($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_UPLOAD_OK', $uploadResult['filename']), null, 'mesgs');
	}

	$uploadRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
	if (!empty($uploadResult['template_code'])) {
		$uploadRedirectUrl .= '&label_template=' . urlencode((string) $uploadResult['template_code']);
	}
	$uploadRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
	header('Location: ' . $uploadRedirectUrl);
	exit;
} elseif ($action === 'upload_template_asset') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	$uploadResult = KreaProductsLabelService::importTemplateUploadedAssetFile(
		$currentEntityId,
		(!empty($_FILES['label_template_asset_upload']) && is_array($_FILES['label_template_asset_upload']) ? $_FILES['label_template_asset_upload'] : array()),
		$langs
	);
	if (!empty($uploadResult['error'])) {
		setEventMessages('', array($uploadResult['error']), 'errors');
	} else {
		setEventMessages($langs->trans('KREAPRODUCTS_LABELS_ASSET_UPLOAD_OK', $uploadResult['asset_reference']), null, 'mesgs');
	}

	$uploadAssetRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . $selectedTemplateUrlParam;
	$uploadAssetRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
	header('Location: ' . $uploadAssetRedirectUrl);
	exit;
} elseif ($action === 'remove_file') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	$file = GETPOST('file', 'alphanohtml');
	$isTemplateFile = (strpos($file, $templateModuleSubdir . '/') === 0 || strpos($file, $templateAssetModuleSubdir . '/') === 0);
	if ($isTemplateFile) {
		$deleted = KreaProductsLabelService::deleteTemplateLibraryFile($currentEntityId, $file);
	} else {
		$deleted = KreaProductsLabelService::deleteGeneratedFile($currentEntityId, $object->id, $file);
	}
	if ($deleted) {
		setEventMessages($langs->trans('FileWasRemoved', basename($file)), null, 'mesgs');
	} else {
		setEventMessages('', array($langs->trans('ErrorFailToDeleteFile', $file)), 'errors');
	}

	$removeRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . $selectedTemplateUrlParam;
	$removeRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
	header('Location: ' . $removeRedirectUrl);
	exit;
}

$objectTypeLabel = ($object->type == Product::TYPE_SERVICE ? $langs->trans('Service') : $langs->trans('Product'));
$title = $objectTypeLabel . ' ' . dol_trunc($productCardLabel, 16) . ' - ' . $langs->trans('KREAPRODUCTS_LABELS_TAB');
$helpurl = 'EN:Module_Products|FR:Module_Produits|ES:Módulo_Productos';
$previewHasContent = (!$isStandardMode ? !empty($selectedTemplateViewer) : !empty($formatDetails[$selectedFormat]));
$previewName = (!$isStandardMode ? (!empty($selectedTemplateViewer['label']) ? $selectedTemplateViewer['label'] : '') : $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD'));
$previewDescription = (!$isStandardMode ? (!empty($selectedTemplateViewer['description']) ? $selectedTemplateViewer['description'] : '') : $langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_DESC'));
$previewSizeText = (!$isStandardMode ? (!empty($selectedTemplateViewer['size_text']) ? $selectedTemplateViewer['size_text'] : '') : $selectedFormatSummary);
$previewEmptyMessage = (!$isStandardMode ? $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE') : $langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_UNAVAILABLE'));
$saveDefaultLayoutTitle = $langs->trans('Save') . ' ' . $langs->trans('kreap_default_label_layout');
$currentDefaultLabelLayoutLabel = $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD');
$previewName = kreaProductsNormalizeUiText($previewName);
$previewDescription = kreaProductsNormalizeUiText($previewDescription);
$previewSizeText = kreaProductsNormalizeUiText($previewSizeText);
$previewEmptyMessage = kreaProductsNormalizeUiText($previewEmptyMessage);
$saveDefaultLayoutTitle = kreaProductsNormalizeUiText($saveDefaultLayoutTitle);
$currentDefaultLabelLayoutLabel = kreaProductsNormalizeUiText($currentDefaultLabelLayoutLabel);
if ($currentDefaultLabelLayout !== '') {
	$currentDefaultLabelLayoutLabel = (!empty($templateOptions[$currentDefaultLabelLayout]) ? (string) $templateOptions[$currentDefaultLabelLayout] : $currentDefaultLabelLayout);
	$currentDefaultLabelLayoutLabel = kreaProductsNormalizeUiText($currentDefaultLabelLayoutLabel);
}

llxHeader('', $title, $helpurl, '', 0, 0, '', '', '', 'mod-kreaproducts page-product-labels');

print '<style nonce="' . getNonce() . '">';
print '.kreaLabelViewerPage{margin-top:12px;}';
print '.kreaLabelViewerPage:first-child{margin-top:0;}';
print '.kreaLabelViewerPageMeta{margin-bottom:6px;}';
print '.kreaLabelViewerCanvas{border:1px solid #d8dee9;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);padding:10px;border-radius:6px;}';
print '.kreaLabelViewerCanvas svg{display:block;width:100%;height:auto;max-width:320px;margin:0 auto;}';
print '.kreaLabelConfigTable td{vertical-align:top;}';
print '.kreaLabelConfigTable .titlefield{vertical-align:top;padding-top:10px;}';
print '.kreaLabelTemplateSelectorWrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}';
print '.kreaLabelRefreshButton{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border:1px solid #d8dee9;border-radius:6px;background:#ffffff;cursor:pointer;}';
print '.kreaLabelRefreshButton:hover{background:#f3f5f8;border-color:#c5cfda;}';
print '.kreaLabelRefreshButton:focus{outline:2px solid #87b4ff;outline-offset:1px;}';
print '.kreaLabelRefreshButton .fa,.kreaLabelRefreshButton .fas,.kreaLabelRefreshButton .far{font-size:14px;line-height:1;}';
print '.kreaTemplateFieldsList{display:flex;flex-direction:column;gap:10px;}';
print '.kreaTemplateFieldCard{border:1px solid #d8dee9;border-radius:6px;padding:10px;background:#fafbfd;}';
print '.kreaTemplateFieldHeader{display:flex;align-items:center;gap:8px;}';
print '.kreaTemplateFieldSave,.kreaTemplateFieldReset{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 5px;border:1px solid #d8dee9;border-radius:4px;background:#ffffff;cursor:pointer;}';
print '.kreaTemplateFieldSave:hover,.kreaTemplateFieldReset:hover{background:#f3f5f8;border-color:#c5cfda;}';
print '.kreaTemplateImagePreviewWrap{margin-top:6px;}';
print '.kreaTemplateImagePreview{display:block;max-width:180px;max-height:90px;border:1px solid #d8dee9;border-radius:4px;background:#fff;padding:2px;}';
print '</style>';

$head = product_prepare_head($object);
$titre = $langs->trans("CardProduct" . $object->type);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');

print dol_get_fiche_head($head, 'krea_labels', $titre, -1, $picto);

$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1&type=' . $object->type . '">' . $langs->trans('BackToList') . '</a>';
$object->next_prev_filter = 'fk_product_type = ' . ((int) $object->type);
$shownav = 1;
if ($user->socid && !in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
	$shownav = 0;
}

$labelGenerationLabel = (string) $object->label;
$object->label = $productCardLabel;
dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref');
$object->label = $labelGenerationLabel;

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . $object->id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" id="krea-label-form-action" name="action" value="generate_labels">';
print '<input type="hidden" id="krea-label-single-source" name="label_template_single_source" value="">';

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_TITLE'));
print '<div class="opacitymedium marginbottomonly">' . $langs->trans('KREAPRODUCTS_LABELS_INTRO') . '</div>';

print '<table class="border centpercent tableforfield kreaLabelConfigTable">';
print '<tr><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE') . '</td><td>';
print '<div class="kreaTemplateFieldCard">';
print '<div class="kreaTemplateFieldHeader"><label for="label_template">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE'))) . '</label></div>';
print '<br>';
print '<div class="kreaLabelTemplateSelectorWrap">';
print $form->selectarray('label_template', $templateOptions, $selectedTemplate, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
if ($permissiontowrite) {
	print '<button type="submit" id="krea-label-save-default-layout" name="save_default_label_layout" value="1" class="kreaLabelRefreshButton classfortooltip" title="' . dol_escape_htmltag($saveDefaultLayoutTitle) . '" aria-label="' . dol_escape_htmltag($saveDefaultLayoutTitle) . '">';
	print '<span class="fas fa-save" aria-hidden="true"></span>';
	print '</button>';
}
print '<button type="button" id="krea-label-refresh-data" class="kreaLabelRefreshButton classfortooltip" title="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_REFRESH_DATA_HELP'))) . '" aria-label="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_REFRESH_DATA'))) . '">';
print '<span class="fas fa-sync-alt" aria-hidden="true"></span>';
print '</button>';
print '</div>';
if (!$hasLabelTemplates) {
	print '<div class="opacitymedium small">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE'))) . '</div>';
} elseif (!empty($selectedTemplateViewer['size_text'])) {
	print '<div class="opacitymedium small">' . dol_escape_htmltag($selectedTemplateViewer['size_text']) . '</div>';
}
print '<div class="opacitymedium small" style="margin-top:6px;">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('kreap_default_label_layout'))) . ': ' . dol_escape_htmltag($currentDefaultLabelLayoutLabel) . '</div>';
print '</div>';
print '</td></tr>';

print '<tr id="krea-label-template-description-row"' . ($isStandardMode ? ' style="display:none;"' : '') . '><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION') . '</td><td>';
print '<div class="kreaTemplateFieldCard">';
print '<div class="kreaTemplateFieldHeader"><label for="krea-label-template-description">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION'))) . '</label></div>';
print '<br>';
print '<textarea id="krea-label-template-description" class="minwidth300" name="label_template_description" rows="3" maxlength="1200" placeholder="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION_PLACEHOLDER'))) . '">' . dol_escape_htmltag(kreaProductsNormalizeUiText($selectedTemplateDescription)) . '</textarea>';
print '<div class="opacitymedium small">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION_HELP'))) . '</div>';
print '</div>';
print '</td></tr>';

print '<tr id="krea-label-template-fields-row"><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_FIELDS') . '</td><td id="krea-label-template-fields-cell">';
if (!empty($templateEditableHtmlMap[$selectedTemplate])) {
	print $templateEditableHtmlMap[$selectedTemplate];
} else {
	print $templateFieldsEmptyHtml;
}
print '</td></tr>';

print '<tr id="krea-label-format-row"' . (!$isStandardMode ? ' style="display:none;"' : '') . '><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_FORMAT') . '</td><td>';
if (!empty($formatOptions)) {
	print $form->selectarray('label_format', $formatOptions, $selectedFormat, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
	print '<span class="warning" id="krea-label-no-format-warning">' . $langs->trans('KREAPRODUCTS_LABELS_NO_FORMATS') . '</span>';
}
print '<div class="opacitymedium small" id="krea-label-format-summary"' . ($selectedFormatSummary === '' ? ' style="display:none; margin-top:6px;"' : ' style="margin-top:6px;"') . '>' . dol_escape_htmltag($selectedFormatSummary) . '</div>';
print '</td></tr>';

print '<tr id="krea-label-quantity-row"' . (!$isStandardMode ? ' style="display:none;"' : '') . '><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_QUANTITY') . '</td><td>';
print '<input class="width75" type="number" min="1" step="1" name="label_qty" value="' . ((int) $quantity) . '">';
print '</td></tr>';

print '<tr id="krea-label-fields-row"' . (!$isStandardMode ? ' style="display:none;"' : '') . '><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_FIELDS') . '</td><td>';
foreach ($fieldOptions as $fieldCode => $fieldLabel) {
	$isChecked = in_array($fieldCode, $selectedFields);
	print '<label class="marginrightonly"><input type="checkbox" name="label_fields[]" value="' . dol_escape_htmltag($fieldCode) . '"' . ($isChecked ? ' checked' : '') . '> ' . dol_escape_htmltag($fieldLabel) . '</label> ';
}
print '<div class="opacitymedium small">';
if (!empty($object->barcode)) {
	print $langs->trans('KREAPRODUCTS_LABELS_BARCODE_INFO', $object->barcode);
} else {
	print $langs->trans('KREAPRODUCTS_LABELS_BARCODE_FALLBACK', $object->ref);
}
print '</div>';
print '</td></tr>';
print '</table>';

print '<div class="tabsAction">';
if ($permissiontowrite && (!$isStandardMode || !empty($formatOptions))) {
	print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_GENERATE_BUTTON'))) . '">';
} else {
	print '<span class="butActionRefused">' . $langs->trans('KREAPRODUCTS_LABELS_GENERATE_BUTTON') . '</span>';
}
print '</div>';
print '</form>';
print '</div><div class="fichehalfright">';

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_PREVIEW'));
print '<div id="krea-label-template-preview-box" class="border" style="padding: 12px; margin-bottom: 18px;">';
print '<div id="krea-label-template-preview-empty"' . ($previewHasContent ? ' style="display:none;"' : '') . '>';
print '<span class="warning" id="krea-label-template-preview-empty-message">' . dol_escape_htmltag($previewEmptyMessage) . '</span>';
print '</div>';
print '<div id="krea-label-template-preview-content"' . (!$previewHasContent ? ' style="display:none;"' : '') . '>';
print '<div class="small opacitymedium" id="krea-label-template-preview-name">' . dol_escape_htmltag($previewName) . '</div>';
print '<div class="opacitymedium small" id="krea-label-template-preview-description" style="margin-top: 6px;">' . dol_escape_htmltag($previewDescription) . '</div>';
print '<div class="opacitymedium small" id="krea-label-template-preview-size" style="margin-top: 4px;">' . dol_escape_htmltag($previewSizeText) . '</div>';
print '<div id="krea-label-template-preview-pages" style="margin-top: 12px;">' . (!$isStandardMode ? kreaProductsRenderTemplateViewerPages($selectedTemplateViewer) : '') . '</div>';
print '</div>';
print '</div>';

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_DOCUMENTS'));
$delallowed = $permissiontowrite;
print $formfile->showdocuments('kreaproducts', $moduleSubdir, $fileDir, $urlSource, 0, $delallowed, '', 0, 1, 0, 48, 1, '', '');

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_LIBRARY'));
print '<div class="border" style="padding: 12px; margin-bottom: 18px;">';
if ($permissiontowrite) {
	print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . $object->id . '" style="margin-bottom:10px;">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="upload_template_json">';
	if ($selectedTemplate !== '') {
		print '<input type="hidden" name="label_template" value="' . dol_escape_htmltag($selectedTemplate) . '">';
	}
	print '<label class="small opacitymedium" for="krea-template-json-upload">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_UPLOAD_LABEL'))) . '</label><br>';
	print '<input id="krea-template-json-upload" type="file" name="label_template_upload" accept=".json,application/json" required>';
	print ' <input type="submit" class="button button-small" value="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_UPLOAD_ACTION'))) . '">';
	print '</form>';

	print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . $object->id . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="upload_template_asset">';
	if ($selectedTemplate !== '') {
		print '<input type="hidden" name="label_template" value="' . dol_escape_htmltag($selectedTemplate) . '">';
	}
	print '<label class="small opacitymedium" for="krea-template-asset-upload">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_ASSET_UPLOAD_LABEL'))) . '</label><br>';
	print '<input id="krea-template-asset-upload" type="file" name="label_template_asset_upload" accept="image/*" required>';
	print ' <input type="submit" class="button button-small" value="' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_ASSET_UPLOAD_ACTION'))) . '">';
	print '</form>';
} else {
	print '<span class="opacitymedium small">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('ReadPermissionNotAllowed'))) . '</span>';
}
print '<div class="opacitymedium small" style="margin-top:8px;">' . dol_escape_htmltag(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_LIBRARY_HELP'))) . '</div>';
print '</div>';

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DOCUMENTS'));
print $formfile->showdocuments('kreaproducts', $templateModuleSubdir, $templateDir, $urlSourceWithTemplate, 0, $delallowed, '', 0, 1, 0, 48, 1, '', '');

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_ASSET_DOCUMENTS'));
print $formfile->showdocuments('kreaproducts', $templateAssetModuleSubdir, $templateAssetDir, $urlSourceWithTemplate, 0, $delallowed, '', 0, 1, 0, 48, 1, '', '');

print '</div></div>';

if (!empty($templateOptions) || !empty($formatDetails)) {
	print '<script nonce="' . getNonce() . '">';
	print 'jQuery(function () {';
	print 'var templatePreviewMap = ' . json_encode($templateViewerMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
	print 'var templateEditableHtmlMap = ' . json_encode($templateEditableHtmlMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
	print 'var formatSummaryMap = ' . json_encode($formatSummaryMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
	print 'var formatDetailMap = ' . json_encode($formatDetails, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewData = ' . json_encode($standardPreviewData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewTitle = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewDescription = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_DESC')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewUnavailable = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_UNAVAILABLE')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var templatePreviewUnavailable = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var templateFieldsEmpty = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_FIELDS_NONE')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var quantityTemplate = ' . json_encode(kreaProductsNormalizeUiText($langs->trans('KREAPRODUCTS_LABELS_PREVIEW_QUANTITY', '__VALUE__')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var refreshBasePath = ' . json_encode($_SERVER['PHP_SELF'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var currentProductId = ' . ((int) $object->id) . ';';
	print 'var canWriteTemplateValues = ' . ($permissiontowrite && $selectedTemplateDataWritable ? 'true' : 'false') . ';';
	print 'var selector = document.getElementById("label_template");';
	print 'var templateDescriptionInput = document.getElementById("krea-label-template-description");';
	print 'var formatSelector = document.getElementById("label_format");';
	print 'var quantityInput = document.querySelector(\'input[name="label_qty"]\');';
	print 'var fieldInputs = Array.prototype.slice.call(document.querySelectorAll(\'input[name="label_fields[]"]\'));';
	print 'var refreshButton = document.getElementById("krea-label-refresh-data");';
	print 'var formActionInput = document.getElementById("krea-label-form-action");';
	print 'var singleSourceInput = document.getElementById("krea-label-single-source");';
	print 'var templateDescriptionRow = document.getElementById("krea-label-template-description-row");';
	print 'var templateFieldsRow = document.getElementById("krea-label-template-fields-row");';
	print 'var templateFieldsCell = document.getElementById("krea-label-template-fields-cell");';
	print 'var formatRow = document.getElementById("krea-label-format-row");';
	print 'var quantityRow = document.getElementById("krea-label-quantity-row");';
	print 'var fieldsRow = document.getElementById("krea-label-fields-row");';
	print 'var noFormatWarning = document.getElementById("krea-label-no-format-warning");';
	print 'var formatSummaryNode = document.getElementById("krea-label-format-summary");';
	print 'var emptyState = document.getElementById("krea-label-template-preview-empty");';
	print 'var emptyMessageNode = document.getElementById("krea-label-template-preview-empty-message");';
	print 'var contentState = document.getElementById("krea-label-template-preview-content");';
	print 'var nameNode = document.getElementById("krea-label-template-preview-name");';
	print 'var descriptionNode = document.getElementById("krea-label-template-preview-description");';
	print 'var sizeNode = document.getElementById("krea-label-template-preview-size");';
	print 'var pagesNode = document.getElementById("krea-label-template-preview-pages");';
	print 'var selectorRenderedNode = document.getElementById("select2-label_template-container");';
	print 'var templateLabelToValueMap = {};';
	print 'if (selector && selector.options) { Array.prototype.forEach.call(selector.options, function (option) { var optionLabel = String((option.textContent || option.innerText || "")).trim(); if (optionLabel !== "" && !Object.prototype.hasOwnProperty.call(templateLabelToValueMap, optionLabel)) { templateLabelToValueMap[optionLabel] = String(option.value || ""); } }); }';
	print 'function setVisible(node, isVisible) { if (node) { node.style.display = isVisible ? "" : "none"; } }';
	print 'function escapeHtml(value) { var node = document.createElement("div"); node.textContent = value || ""; return node.innerHTML; }';
	print 'function decodeHtmlEntities(value) { var node = document.createElement("textarea"); node.innerHTML = String(value || ""); return node.value; }';
	print 'function formatSvgNumber(value) { var num = Number(value || 0); if (!isFinite(num)) { num = 0; } return num.toFixed(3).replace(/\\.0+$/, "").replace(/(\\.\\d*?)0+$/, "$1"); }';
	print 'function quantityText(value) { return decodeHtmlEntities(quantityTemplate.replace("__VALUE__", String(value || 1))); }';
	print 'function renderTemplateEditableFields(code) {';
	print '  if (!templateFieldsCell) { return; }';
	print '  var templateCode = String(code || "");';
	print '  var renderedHtml = (templateCode !== "" && Object.prototype.hasOwnProperty.call(templateEditableHtmlMap, templateCode)) ? String(templateEditableHtmlMap[templateCode] || "") : "";';
	print '  if (renderedHtml.trim() === "") {';
	print '    renderedHtml = \'<span class="opacitymedium small">\' + escapeHtml(templateFieldsEmpty) + \'</span>\';';
	print '  }';
	print '  templateFieldsCell.innerHTML = renderedHtml;';
	print '}';
	print 'function setPreviewEmpty(message) { if (emptyMessageNode) { emptyMessageNode.textContent = decodeHtmlEntities(message || ""); } setVisible(emptyState, true); setVisible(contentState, false); if (pagesNode) { pagesNode.innerHTML = ""; } }';
	print 'function setPreviewContent(name, description, sizeText, pagesHtml) { if (nameNode) { nameNode.textContent = decodeHtmlEntities(name || ""); } if (descriptionNode) { descriptionNode.textContent = decodeHtmlEntities(description || ""); } if (sizeNode) { sizeNode.textContent = decodeHtmlEntities(sizeText || ""); } if (pagesNode) { pagesNode.innerHTML = pagesHtml || ""; } setVisible(emptyState, false); setVisible(contentState, true); }';
	print 'function renderTemplatePages(template) {';
	print '  if (!pagesNode) { return; }';
	print '  if (!template || !template.pages || !template.pages.length) { pagesNode.innerHTML = ""; return; }';
	print '  pagesNode.innerHTML = template.pages.map(function (page) {';
	print '    var meta = [decodeHtmlEntities(page.label || ""), decodeHtmlEntities(page.size_text || "")].filter(Boolean).join(" · ");';
	print '    return \'<div class="kreaLabelViewerPage"><div class="small opacitymedium kreaLabelViewerPageMeta">\' + escapeHtml(meta) + \'</div><div class="kreaLabelViewerCanvas">\' + (page.svg || "") + \'</div></div>\';';
	print '  }).join("");';
	print '}';
	print 'function getSelectedFieldCodes() {';
	print '  return fieldInputs.filter(function (input) { return !!input.checked; }).map(function (input) { return input.value; });';
	print '}';
	print 'function buildRefreshUrl() {';
	print '  var params = [];';
	print '  var productId = parseInt(currentProductId, 10);';
	print '  if (!productId || productId < 1) { productId = 0; }';
	print '  params.push("id=" + encodeURIComponent(String(productId)));';
	print '  var selectedTemplateCode = selector ? String(selector.value || "") : "";';
	print '  params.push("label_template=" + encodeURIComponent(selectedTemplateCode));';
	print '  if (selectedTemplateCode !== "") {';
	print '    if (templateDescriptionInput) { params.push("label_template_description=" + encodeURIComponent(String(templateDescriptionInput.value || ""))); }';
	print '  } else {';
	print '    if (formatSelector && formatSelector.value) { params.push("label_format=" + encodeURIComponent(String(formatSelector.value))); }';
	print '    var qtyValue = quantityInput ? parseInt(quantityInput.value, 10) : 1;';
	print '    if (!qtyValue || qtyValue < 1) { qtyValue = 1; }';
	print '    params.push("label_qty=" + encodeURIComponent(String(qtyValue)));';
	print '    getSelectedFieldCodes().forEach(function (code) { if (code) { params.push("label_fields[]=" + encodeURIComponent(String(code))); } });';
	print '  }';
	print '  var path = String(refreshBasePath || window.location.pathname || "");';
	print '  path = path.replace(/\\?.*$/, "");';
	print '  params.push("krea_refresh=" + encodeURIComponent(String(Date.now())));';
	print '  return path + "?" + params.join("&");';
	print '}';
	print 'function applyFieldReset(buttonNode) {';
	print '  if (!buttonNode) { return; }';
	print '  var targetId = String(buttonNode.getAttribute("data-target-id") || "");';
	print '  if (!targetId) { return; }';
	print '  var target = document.getElementById(targetId);';
	print '  if (!target) { return; }';
	print '  target.value = String(buttonNode.getAttribute("data-reset-value") || "");';
	print '  var previewId = String(buttonNode.getAttribute("data-preview-id") || "");';
	print '  if (previewId) {';
	print '    var previewNode = document.getElementById(previewId);';
	print '    if (previewNode) {';
	print '      var resetPreviewUrl = String(buttonNode.getAttribute("data-reset-preview-url") || "");';
	print '      if (resetPreviewUrl) { previewNode.src = resetPreviewUrl; previewNode.style.display = ""; }';
	print '      else { previewNode.removeAttribute("src"); previewNode.style.display = "none"; }';
	print '    }';
	print '    var fileInputId = targetId.replace(/-value$/, "");';
	print '    var fileInputNode = document.getElementById(fileInputId);';
	print '    if (fileInputNode && fileInputNode.type === "file") { fileInputNode.value = ""; }';
	print '  }';
	print '  if (typeof Event === "function") {';
	print '    target.dispatchEvent(new Event("input", { bubbles: true }));';
	print '    target.dispatchEvent(new Event("change", { bubbles: true }));';
	print '  }';
	print '}';
	print 'function submitSingleTemplateFieldSave(buttonNode) {';
	print '  if (!buttonNode) { return; }';
	print '  var source = String(buttonNode.getAttribute("data-source") || "");';
	print '  if (!source) { return; }';
	print '  var formNode = (selector && selector.form) ? selector.form : null;';
	print '  if (!formNode) { return; }';
	print '  if (singleSourceInput) { singleSourceInput.value = source; }';
	print '  if (formActionInput) { formActionInput.value = "save_template_values"; }';
	print '  formNode.submit();';
	print '}';
	print 'function updateImagePreviewFromAssetSelect(inputNode) {';
	print '  if (!inputNode) { return; }';
	print '  var previewId = String(inputNode.getAttribute("data-preview-id") || "");';
	print '  if (!previewId) { return; }';
	print '  var previewNode = document.getElementById(previewId);';
	print '  if (!previewNode) { return; }';
	print '  var selectedOption = inputNode.options && inputNode.selectedIndex >= 0 ? inputNode.options[inputNode.selectedIndex] : null;';
	print '  var previewUrl = selectedOption ? String(selectedOption.getAttribute("data-preview-url") || "") : "";';
	print '  if (previewUrl !== "") { previewNode.src = previewUrl; previewNode.style.display = ""; }';
	print '  else { previewNode.removeAttribute("src"); previewNode.style.display = "none"; }';
	print '}';
	print 'function wrapText(text, fontSizeMm, maxWidthMm, maxLines) {';
	print '  var source = String(text || "").trim();';
	print '  if (!source) { return [""]; }';
	print '  var charsPerLine = Math.max(4, Math.floor(maxWidthMm / Math.max(0.9, fontSizeMm * 0.5)));';
	print '  var words = source.split(/\\s+/);';
	print '  var lines = [];';
	print '  var current = "";';
	print '  words.forEach(function (word) {';
	print '    var candidate = current ? (current + " " + word) : word;';
	print '    if (candidate.length <= charsPerLine) { current = candidate; return; }';
	print '    if (current) { lines.push(current); current = ""; }';
	print '    while (word.length > charsPerLine) { lines.push(word.slice(0, charsPerLine)); word = word.slice(charsPerLine); }';
	print '    current = word;';
	print '  });';
	print '  if (current) { lines.push(current); }';
	print '  if (!lines.length) { lines = [source]; }';
	print '  if (lines.length > maxLines) { lines = lines.slice(0, maxLines); var last = lines.length - 1; lines[last] = lines[last].slice(0, Math.max(0, charsPerLine - 3)).replace(/\\s+$/, "") + "..."; }';
	print '  return lines;';
	print '}';
	print 'function renderSvgText(x, y, width, height, fontSizeMm, fontWeight, align, text) {';
	print '  var lineHeight = fontSizeMm * 1.16;';
	print '  var maxLines = Math.max(1, Math.floor(height / Math.max(1, lineHeight)));';
	print '  var lines = wrapText(text, fontSizeMm, width, maxLines);';
	print '  var textAnchor = "start";';
	print '  var textX = x;';
	print '  if (align === "center") { textAnchor = "middle"; textX = x + (width / 2); } else if (align === "right") { textAnchor = "end"; textX = x + width; }';
	print '  var svg = \'<text x="\' + formatSvgNumber(textX) + \'" y="\' + formatSvgNumber(y) + \'" fill="#101828" font-family="Helvetica" font-size="\' + formatSvgNumber(fontSizeMm) + \'" font-weight="\' + fontWeight + \'" text-anchor="\' + textAnchor + \'" dominant-baseline="text-before-edge">\';';
	print '  lines.forEach(function (line, index) { svg += \'<tspan x="\' + formatSvgNumber(textX) + \'" dy="\' + (index === 0 ? "0" : formatSvgNumber(lineHeight)) + \'">\' + escapeHtml(line) + \'</tspan>\'; });';
	print '  svg += "</text>";';
	print '  return svg;';
	print '}';
	print 'function buildPreviewBarcodePattern(value) {';
	print '  var sanitized = String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "");';
	print '  if (!sanitized) { sanitized = "0"; }';
	print '  var pattern = "101001";';
	print '  sanitized.split("").forEach(function (ch, index) {';
	print '    var seed = ch.charCodeAt(0) + index;';
	print '    for (var bit = 0; bit < 7; bit++) { pattern += (((seed >> (bit % 6)) & 1) ? "11" : "1") + "0"; }';
	print '    pattern += "10";';
	print '  });';
	print '  pattern += "101011";';
	print '  return pattern;';
	print '}';
	print 'function renderPreviewBarcode(x, y, width, height, barcode, isCompact) {';
	print '  if (!barcode || !barcode.value) { return ""; }';
	print '  if (barcode.is2d) {';
	print '    var size = Math.min(width, height);';
	print '    return \'<g><rect x="\' + formatSvgNumber(x + ((width - size) / 2)) + \'" y="\' + formatSvgNumber(y) + \'" width="\' + formatSvgNumber(size) + \'" height="\' + formatSvgNumber(size) + \'" fill="#ffffff" stroke="#101828" stroke-width="0.3"/><text x="\' + formatSvgNumber(x + (width / 2)) + \'" y="\' + formatSvgNumber(y + size + 0.4) + \'" fill="#101828" font-family="Helvetica" font-size="\' + formatSvgNumber(isCompact ? 1.9 : 2.2) + \'" text-anchor="middle" dominant-baseline="text-before-edge">\' + escapeHtml(barcode.value) + \'</text></g>\';';
	print '  }';
	print '  var textHeight = isCompact ? 2.1 : 2.6;';
	print '  var barHeight = Math.max(1.0, height - textHeight);';
	print '  var pattern = buildPreviewBarcodePattern(barcode.value);';
	print '  var barWidth = width / Math.max(1, pattern.length);';
	print '  var svg = \'<g><rect x="\' + formatSvgNumber(x) + \'" y="\' + formatSvgNumber(y) + \'" width="\' + formatSvgNumber(width) + \'" height="\' + formatSvgNumber(barHeight) + \'" fill="#ffffff"/>\';';
	print '  for (var i = 0; i < pattern.length; i++) { if (pattern.charAt(i) !== "1") { continue; } svg += \'<rect x="\' + formatSvgNumber(x + (i * barWidth)) + \'" y="\' + formatSvgNumber(y) + \'" width="\' + formatSvgNumber(barWidth + 0.02) + \'" height="\' + formatSvgNumber(barHeight) + \'" fill="#101828"/>\'; }';
	print '  svg += \'<text x="\' + formatSvgNumber(x + (width / 2)) + \'" y="\' + formatSvgNumber(y + barHeight + 0.2) + \'" fill="#101828" font-family="Helvetica" font-size="\' + formatSvgNumber(isCompact ? 1.9 : 2.2) + \'" text-anchor="middle" dominant-baseline="text-before-edge">\' + escapeHtml(barcode.value) + \'</text></g>\';';
	print '  return svg;';
	print '}';
	print 'function renderStandardPreviewSvg(detail, selectedFields) {';
	print '  if (!detail) { return ""; }';
	print '  var width = Number(detail.width || 0);';
	print '  var height = Number(detail.height || 0);';
	print '  if (!width || !height) { return ""; }';
	print '  var paddingX = Math.min(2.2, Math.max(1.0, width * 0.05));';
	print '  var paddingY = Math.min(2.0, Math.max(1.0, height * 0.05));';
	print '  var contentX = paddingX;';
	print '  var contentY = paddingY;';
	print '  var contentWidth = Math.max(8.0, width - (2 * paddingX));';
	print '  var contentHeight = Math.max(8.0, height - (2 * paddingY));';
	print '  var isCompact = height <= 28;';
	print '  var styleMap = {';
	print '    ref: { fontMm: Math.max(1.1, (isCompact ? 8 : 10) * 0.352778), lineHeight: isCompact ? 3.6 : 4.5, weight: "700", align: "left" },';
	print '    label: { fontMm: Math.max(1.1, (isCompact ? 7 : 8.5) * 0.352778), lineHeight: isCompact ? 3.1 : 3.8, weight: "400", align: "left" },';
	print '    price: { fontMm: Math.max(1.1, (isCompact ? 7 : 8) * 0.352778), lineHeight: isCompact ? 3.0 : 3.6, weight: "700", align: "left" },';
	print '    meta: { fontMm: Math.max(1.1, (isCompact ? 6 : 7) * 0.352778), lineHeight: isCompact ? 2.8 : 3.2, weight: "400", align: "left" }';
	print '  };';
	print '  var lines = [];';
	print '  selectedFields.forEach(function (fieldCode) { if (standardPreviewData.fields && standardPreviewData.fields[fieldCode]) { lines.push(standardPreviewData.fields[fieldCode]); } });';
	print '  var hasBarcode = selectedFields.indexOf("barcode") !== -1 && standardPreviewData.barcode && standardPreviewData.barcode.value;';
	print '  var lineGap = isCompact ? 0.1 : 0.2;';
	print '  var barcodeGap = hasBarcode ? (isCompact ? 0.4 : 0.8) : 0;';
	print '  var barcodeBlockHeight = 0;';
	print '  if (hasBarcode) { barcodeBlockHeight = standardPreviewData.barcode.is2d ? Math.min(16.0, Math.max(8.5, contentHeight * 0.45)) : Math.min(14.0, Math.max(7.0, contentHeight * 0.34)); }';
	print '  var fixedTextHeight = 0;';
	print '  var labelLineIndex = -1;';
	print '  lines.forEach(function (line, index) { var type = (line && styleMap[line.type]) ? line.type : "meta"; if (type === "label" && labelLineIndex < 0) { labelLineIndex = index; return; } fixedTextHeight += styleMap[type].lineHeight + lineGap; });';
	print '  var availableTextHeight = Math.max(4.0, contentHeight - barcodeBlockHeight - barcodeGap);';
	print '  var labelMaxHeight = Math.max((isCompact ? 3.5 : 4.5), availableTextHeight - fixedTextHeight);';
	print '  var currentY = contentY;';
	print '  var svg = \'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 \' + formatSvgNumber(width) + \' \' + formatSvgNumber(height) + \'" preserveAspectRatio="xMidYMid meet" role="img" aria-label="\' + escapeHtml(standardPreviewTitle) + \'"><rect x="0" y="0" width="\' + formatSvgNumber(width) + \'" height="\' + formatSvgNumber(height) + \'" fill="#ffffff" stroke="#cfd6df" stroke-width="0.35" rx="1.1" ry="1.1"/></svg>\';';
	print '  var content = "";';
	print '  lines.forEach(function (line, index) {';
	print '    var text = line && line.text ? String(line.text).trim() : "";';
	print '    if (!text) { return; }';
	print '    var type = (line && styleMap[line.type]) ? line.type : "meta";';
	print '    var style = styleMap[type];';
	print '    var blockHeight = (index === labelLineIndex ? labelMaxHeight : style.lineHeight);';
	print '    content += renderSvgText(contentX, currentY, contentWidth, blockHeight, style.fontMm, style.weight, style.align, text);';
	print '    currentY += style.lineHeight + lineGap;';
	print '  });';
	print '  if (hasBarcode) { var barcodeY = height - paddingY - barcodeBlockHeight; content += renderPreviewBarcode(contentX, barcodeY, contentWidth, barcodeBlockHeight, standardPreviewData.barcode, isCompact); }';
	print '  svg = svg.replace("</svg>", content + "</svg>");';
	print '  return svg;';
	print '}';
	print 'function renderStandardPreview() {';
	print '  var formatCode = formatSelector ? formatSelector.value : "";';
	print '  var detail = (formatCode && formatDetailMap[formatCode]) ? formatDetailMap[formatCode] : null;';
	print '  if (!detail) { setPreviewEmpty(standardPreviewUnavailable); return; }';
	print '  var qty = quantityInput ? parseInt(quantityInput.value, 10) : 1;';
	print '  if (!qty || qty < 1) { qty = 1; }';
	print '  var selectedFields = getSelectedFieldCodes();';
	print '  var svg = renderStandardPreviewSvg(detail, selectedFields);';
	print '  var meta = [standardPreviewTitle, quantityText(qty)].join(" · ");';
	print '  var pagesHtml = \'<div class="kreaLabelViewerPage"><div class="small opacitymedium kreaLabelViewerPageMeta">\' + escapeHtml(meta) + \'</div><div class="kreaLabelViewerCanvas">\' + svg + \'</div></div>\';';
	print '  setPreviewContent(standardPreviewTitle, standardPreviewDescription, formatSummaryMap[formatCode] || "", pagesHtml);';
	print '}';
	print 'function updateTemplatePreview(code) {';
	print '  var template = templatePreviewMap[code] || null;';
	print '  if (!template) {';
	print '    setPreviewEmpty(templatePreviewUnavailable);';
	print '    return;';
	print '  }';
	print '  renderTemplatePages(template);';
	print '  setPreviewContent(template.label || "", template.description || "", template.size_text || "", pagesNode ? pagesNode.innerHTML : "");';
	print '}';
	print 'function syncSelectorFromRenderedLabel() {';
	print '  if (!selectorRenderedNode || !selector) { return; }';
	print '  var renderedLabel = String(selectorRenderedNode.textContent || "").trim();';
	print '  if (renderedLabel === "" || !Object.prototype.hasOwnProperty.call(templateLabelToValueMap, renderedLabel)) { return; }';
	print '  var mappedValue = String(templateLabelToValueMap[renderedLabel] || "");';
	print '  if (String(selector.value || "") === mappedValue) { updateMode(); return; }';
	print '  selector.value = mappedValue;';
	print '  if (window.jQuery) { window.jQuery(selector).trigger("change"); }';
	print '  else if (typeof Event === "function") { selector.dispatchEvent(new Event("change", { bubbles: true })); }';
	print '  else { updateMode(); }';
	print '}';
	print 'function updateMode() {';
	print '  var standardMode = !selector || !selector.value;';
	print '  setVisible(templateDescriptionRow, !standardMode);';
	print '  setVisible(templateFieldsRow, !standardMode);';
	print '  setVisible(formatRow, standardMode);';
	print '  setVisible(quantityRow, standardMode);';
	print '  setVisible(fieldsRow, standardMode);';
	print '  setVisible(noFormatWarning, standardMode && !(formatSelector && formatSelector.value && formatDetailMap[formatSelector.value]));';
	print '  if (formatSummaryNode) { formatSummaryNode.textContent = decodeHtmlEntities(standardMode ? (formatSelector && formatSummaryMap[formatSelector.value] ? formatSummaryMap[formatSelector.value] : "") : ""); setVisible(formatSummaryNode, standardMode && !!formatSummaryNode.textContent); }';
	print '  renderTemplateEditableFields(String(selector ? (selector.value || "") : ""));';
	print '  if (standardMode) {';
	print '    renderStandardPreview();';
	print '  } else {';
	print '    updateTemplatePreview(selector.value);';
	print '  }';
	print '}';
	print 'if (templateFieldsCell) {';
	print '  templateFieldsCell.addEventListener("click", function (event) {';
	print '    var node = event && event.target ? event.target : null;';
	print '    if (!node || !node.closest) { return; }';
	print '    var saveButton = node.closest(".kreaTemplateFieldSave");';
	print '    if (saveButton) {';
	print '      event.preventDefault();';
	print '      submitSingleTemplateFieldSave(saveButton);';
	print '      return;';
	print '    }';
	print '    var resetButton = node.closest(".kreaTemplateFieldReset");';
	print '    if (!resetButton) { return; }';
	print '    event.preventDefault();';
	print '    applyFieldReset(resetButton);';
	print '  });';
	print '  templateFieldsCell.addEventListener("change", function (event) {';
	print '    var node = event && event.target ? event.target : null;';
	print '    if (!node || !node.classList || !node.classList.contains("kreaTemplateImageSelect")) { return; }';
	print '    updateImagePreviewFromAssetSelect(node);';
	print '  });';
	print '}';
	print 'if (selector) {';
	print '  selector.addEventListener("change", function () { window.location.replace(buildRefreshUrl()); });';
	print '  if (window.jQuery) { window.jQuery(selector).on("select2:select select2:clear select2:close", syncSelectorFromRenderedLabel); }';
	print '}';
	print 'if (selectorRenderedNode && window.MutationObserver) {';
	print '  (new MutationObserver(function () { syncSelectorFromRenderedLabel(); })).observe(selectorRenderedNode, { childList: true, subtree: true, characterData: true });';
	print '}';
	print 'if (formatSelector) { formatSelector.addEventListener("change", updateMode); }';
	print 'if (formatSelector && window.jQuery) { window.jQuery(formatSelector).on("select2:select select2:clear select2:close", updateMode); }';
	print 'if (quantityInput) { quantityInput.addEventListener("input", updateMode); }';
	print 'fieldInputs.forEach(function (input) { input.addEventListener("change", updateMode); });';
	print 'if (refreshButton) { refreshButton.addEventListener("click", function () {';
	print '  var standardMode = !selector || !selector.value;';
	print '  if (!standardMode && canWriteTemplateValues) {';
	print '    var formNode = refreshButton.form || (selector ? selector.form : null);';
	print '    if (formNode) {';
	print '      if (singleSourceInput) { singleSourceInput.value = ""; }';
	print '      if (formActionInput) { formActionInput.value = "save_template_values"; }';
	print '      formNode.submit();';
	print '      return;';
	print '    }';
	print '  }';
	print '  window.location.replace(buildRefreshUrl());';
	print '}); }';
	print 'updateMode();';
	print '});';
	print '</script>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
