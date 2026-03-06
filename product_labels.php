<?php
/* Copyright (C) 2026       Kreativitat             <mail@kreativitat.com>
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
		$html .= '<div class="kreaLabelViewerPage">';
		$html .= '<div class="small opacitymedium kreaLabelViewerPageMeta">';
		$html .= dol_escape_htmltag(!empty($page['label']) ? $page['label'] : '');
		if (!empty($page['size_text'])) {
			$html .= ' · ' . dol_escape_htmltag($page['size_text']);
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
		$label = (!empty($field['label']) ? (string) $field['label'] : $source);
		$type = (!empty($field['type']) ? strtolower((string) $field['type']) : 'text');
		$value = (isset($field['input_value']) ? (string) $field['input_value'] : (isset($field['value']) ? (string) $field['value'] : ''));
		$placeholder = (!empty($field['placeholder']) ? (string) $field['placeholder'] : '');
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
			$resetTitle = $langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB_HELP');

			$html .= '<div class="kreaTemplateFieldCard">';
			$html .= '<div class="kreaTemplateFieldHeader">';
			$html .= '<label for="' . dol_escape_htmltag($inputId) . '">' . dol_escape_htmltag($label) . '</label>';
		if ($canReset) {
				$html .= '<button type="button" class="kreaTemplateFieldReset classfortooltip"';
				$html .= ' data-target-id="' . dol_escape_htmltag($inputTargetId) . '"';
				$html .= ' data-reset-value="' . dol_escape_htmltag($resetInputValue) . '"';
				if ($previewId !== '') {
					$html .= ' data-preview-id="' . dol_escape_htmltag($previewId) . '"';
					$html .= ' data-reset-preview-url="' . dol_escape_htmltag($resetPreviewUrl) . '"';
				}
				$html .= ' title="' . dol_escape_htmltag($resetTitle) . '"';
				$html .= ' aria-label="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB')) . '">';
				$html .= img_picto($langs->trans('KREAPRODUCTS_LABELS_FIELD_RESET_DB'), 'refresh');
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
			$html .= '<option value="" data-preview-url="">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_ASSET_PICKER_EMPTY')) . '</option>';
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
			$html .= '<div class="opacitymedium small" style="margin-top:4px;">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_ASSET_PICKER_HELP')) . '</div>';

			$html .= '<div class="kreaTemplateImagePreviewWrap">';
			$html .= '<img id="' . dol_escape_htmltag($previewId) . '" class="kreaTemplateImagePreview"' . ($assetPreviewUrl !== '' ? '' : ' style="display:none;"');
			$html .= ' src="' . dol_escape_htmltag($assetPreviewUrl) . '" alt="' . dol_escape_htmltag($label) . '">';
			$html .= '</div>';
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

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
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

if (empty($conf->global->KREAPRODUCTS_LABELS_TAB_ENABLED)) {
	accessforbidden();
}

$permissiontoread = ($user->admin || $user->hasRight('kreaproducts', 'labels', 'read'));
$permissiontowrite = ($user->admin || $user->hasRight('kreaproducts', 'labels', 'write'));

if (!$permissiontoread) {
	accessforbidden();
}

$form = new Form($db);
$formfile = new FormFile($db);
$currentEntityId = (int) $conf->entity;
$templateAssetOptions = KreaProductsLabelService::listTemplateAssetReferences($currentEntityId);

$formatOptions = KreaProductsLabelService::getFormatOptions($db);
$formatDetails = KreaProductsLabelService::getFormatDetails($db);
$formatSummaryMap = array();
foreach ($formatDetails as $formatCode => $detail) {
	$formatSummaryMap[$formatCode] = KreaProductsLabelService::buildFormatSummaryText($detail, $langs);
}
$standardPreviewData = KreaProductsLabelService::buildStandardPreviewData($db, $object, $langs);
$fieldOptions = KreaProductsLabelService::getAvailableFields($langs);
$labelTemplates = KreaProductsLabelService::listLabelTemplates($currentEntityId);
$hasLabelTemplates = !empty($labelTemplates);
$templateOptions = array('' => $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD'));
foreach ($labelTemplates as $templateCode => $templateMeta) {
	$optionLabel = (!empty($templateMeta['filename']) ? (string) $templateMeta['filename'] : ((string) $templateCode . '.json'));
	$templateOptions[$templateCode] = $optionLabel;
}

$selectedTemplate = GETPOST('label_template', 'alphanohtml');
if ($selectedTemplate !== '' && empty($templateOptions[$selectedTemplate])) {
	$selectedTemplate = '';
}
$isStandardMode = ($selectedTemplate === '');
$selectedTemplateMeta = (!$isStandardMode && !empty($labelTemplates[$selectedTemplate]) ? $labelTemplates[$selectedTemplate] : array());
$selectedTemplateReadOnly = (!$isStandardMode && !empty($selectedTemplateMeta['is_readonly']));
$selectedTemplateEditable = (!$isStandardMode && !$selectedTemplateReadOnly && KreaProductsLabelService::isTemplateEditable($selectedTemplate, $currentEntityId));
$templateDescriptionRaw = GETPOST('label_template_description', 'restricthtml');
$templateDescriptionProvided = GETPOSTISSET('label_template_description');
$selectedTemplateDescription = '';
if (!$isStandardMode) {
	if ($templateDescriptionProvided) {
		$selectedTemplateDescription = trim((string) $templateDescriptionRaw);
	} else {
		$selectedTemplateDescription = (!empty($selectedTemplateMeta['description']) ? (string) $selectedTemplateMeta['description'] : '');
	}
}
$forceRefreshData = (GETPOSTINT('krea_refresh') > 0);
$rawTemplateInputValues = GETPOST('label_template_values', 'array');
$templateInputValuesByCode = array();
if (!$forceRefreshData && !$isStandardMode && is_array($rawTemplateInputValues)) {
	$templateInputValuesByCode[$selectedTemplate] = $rawTemplateInputValues;
}
$templateViewerMap = KreaProductsLabelService::buildLabelTemplateViewerMap($object, $langs, $currentEntityId, $templateInputValuesByCode);
$templateEditableFieldMap = KreaProductsLabelService::buildTemplateEditableFieldMap($object, $langs, $currentEntityId, $templateInputValuesByCode);
$templateFieldsEmptyHtml = '<span class="opacitymedium small">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_FIELDS_NONE')) . '</span>';
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
$templateInputValues = array();
foreach ($selectedTemplateEditableFields as $editableField) {
	if (!empty($editableField['source'])) {
		$templateInputValues[$editableField['source']] = (isset($editableField['value']) ? (string) $editableField['value'] : '');
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
	$selectedFormatSummary = $selectedTemplateViewer['template_format_summary'];
} elseif (!empty($formatSummaryMap[$selectedFormat])) {
	$selectedFormatSummary = $formatSummaryMap[$selectedFormat];
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
$urlSourceWithTemplate = $urlSource . ($selectedTemplate !== '' ? '&label_template=' . urlencode($selectedTemplate) : '');

if ($action === 'generate_labels') {
	if (!$permissiontowrite) {
		accessforbidden();
	}
	if ($isStandardMode && empty($formatOptions)) {
		setEventMessages('', array($langs->trans('KREAPRODUCTS_LABELS_NO_FORMATS')), 'errors');
	} else {
		$templateInputValuesToGenerate = $templateInputValues;
		if (!$isStandardMode) {
			$uploadErrors = array();
			$templateInputValuesToGenerate = kreaProductsMergeTemplateUploadedFiles($currentEntityId, $templateInputValuesToGenerate, $uploadErrors);
			if (!empty($uploadErrors)) {
				setEventMessages('', $uploadErrors, 'errors');
			}

				if ($selectedTemplateReadOnly) {
					$copyResult = KreaProductsLabelService::createEditableTemplateCopyFromBundled(
						$selectedTemplate,
						$currentEntityId,
						$templateInputValuesToGenerate,
						$langs,
						$selectedTemplateDescription
					);
					if (!empty($copyResult['error'])) {
						setEventMessages('', array($copyResult['error']), 'errors');
						$copyRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&label_template=' . urlencode($selectedTemplate) . '&krea_refresh=' . urlencode((string) dol_now());
						header('Location: ' . $copyRedirectUrl);
						exit;
					}
				} elseif ($selectedTemplateEditable) {
					$saveTemplateDescriptionResult = KreaProductsLabelService::saveTemplateInputDefaults(
						$selectedTemplate,
						$currentEntityId,
						array(),
						$langs,
						$selectedTemplateDescription
					);
					if (!empty($saveTemplateDescriptionResult['error'])) {
						setEventMessages('', array($saveTemplateDescriptionResult['error']), 'errors');
					}
				}
		}

		$result = KreaProductsLabelService::generateProductLabels($db, $object, $currentEntityId, $selectedFormat, $effectiveSelectedFields, $effectiveQuantity, $langs, $selectedTemplate, $useTemplateSize, $templateInputValuesToGenerate);
		if (!empty($result['error'])) {
			setEventMessages('', array($result['error']), 'errors');
		} else {
			if (!$isStandardMode && $selectedTemplateReadOnly) {
				setEventMessages($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_COPY_CREATED', $selectedTemplate), null, 'mesgs');
			}
			setEventMessages($langs->trans('KREAPRODUCTS_LABELS_GENERATED', $result['filename']), null, 'mesgs');
			$successRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
			if (!$isStandardMode && $selectedTemplate !== '') {
				$successRedirectUrl .= '&label_template=' . urlencode($selectedTemplate);
			}
			$successRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
			header('Location: ' . $successRedirectUrl);
			exit;
		}
	}
} elseif ($action === 'save_template_values') {
	if (!$permissiontowrite) {
		accessforbidden();
	}

	if ($isStandardMode || $selectedTemplate === '') {
		setEventMessages('', array($langs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_UNAVAILABLE')), 'errors');
	} elseif (!$selectedTemplateEditable) {
		setEventMessages('', array($langs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY')), 'errors');
	} else {
		$templateInputValuesToSave = (is_array($rawTemplateInputValues) ? $rawTemplateInputValues : array());
		$uploadErrors = array();
		$templateInputValuesToSave = kreaProductsMergeTemplateUploadedFiles($currentEntityId, $templateInputValuesToSave, $uploadErrors);
		if (!empty($uploadErrors)) {
			setEventMessages('', $uploadErrors, 'errors');
		}

		$saveResult = KreaProductsLabelService::saveTemplateInputDefaults(
				$selectedTemplate,
				$currentEntityId,
				$templateInputValuesToSave,
				$langs,
				$selectedTemplateDescription
		);
		if (!empty($saveResult['error'])) {
			setEventMessages('', array($saveResult['error']), 'errors');
		} else {
			setEventMessages($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_VALUES_SAVED'), null, 'mesgs');
		}
	}

	$refreshRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
	if ($selectedTemplate !== '') {
		$refreshRedirectUrl .= '&label_template=' . urlencode($selectedTemplate);
	}
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

	$uploadAssetRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
	if ($selectedTemplate !== '') {
		$uploadAssetRedirectUrl .= '&label_template=' . urlencode($selectedTemplate);
	}
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

	$removeRedirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
	if ($selectedTemplate !== '') {
		$removeRedirectUrl .= '&label_template=' . urlencode($selectedTemplate);
	}
	$removeRedirectUrl .= '&krea_refresh=' . urlencode((string) dol_now());
	header('Location: ' . $removeRedirectUrl);
	exit;
}

$objectTypeLabel = ($object->type == Product::TYPE_SERVICE ? $langs->trans('Service') : $langs->trans('Product'));
$title = $objectTypeLabel . ' ' . dol_trunc($object->label, 16) . ' - ' . $langs->trans('KREAPRODUCTS_LABELS_TAB');
$helpurl = 'EN:Module_Products|FR:Module_Produits|ES:Módulo_Productos';
$previewHasContent = (!$isStandardMode ? !empty($selectedTemplateViewer) : !empty($formatDetails[$selectedFormat]));
$previewName = (!$isStandardMode ? (!empty($selectedTemplateViewer['label']) ? $selectedTemplateViewer['label'] : '') : $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD'));
$previewDescription = (!$isStandardMode ? (!empty($selectedTemplateViewer['description']) ? $selectedTemplateViewer['description'] : '') : $langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_DESC'));
$previewSizeText = (!$isStandardMode ? (!empty($selectedTemplateViewer['size_text']) ? $selectedTemplateViewer['size_text'] : '') : $selectedFormatSummary);
$previewEmptyMessage = (!$isStandardMode ? $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE') : $langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_UNAVAILABLE'));

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
print '.kreaTemplateFieldReset{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 5px;border:1px solid #d8dee9;border-radius:4px;background:#ffffff;cursor:pointer;}';
print '.kreaTemplateFieldReset:hover{background:#f3f5f8;border-color:#c5cfda;}';
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

dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref');

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . $object->id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" id="krea-label-form-action" name="action" value="generate_labels">';

print load_fiche_titre($langs->trans('KREAPRODUCTS_LABELS_TITLE'));
print '<div class="opacitymedium marginbottomonly">' . $langs->trans('KREAPRODUCTS_LABELS_INTRO') . '</div>';

print '<table class="border centpercent tableforfield kreaLabelConfigTable">';
print '<tr><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE') . '</td><td>';
print '<div class="kreaLabelTemplateSelectorWrap">';
print $form->selectarray('label_template', $templateOptions, $selectedTemplate, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '<button type="button" id="krea-label-refresh-data" class="kreaLabelRefreshButton classfortooltip" title="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_REFRESH_DATA_HELP')) . '" aria-label="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_REFRESH_DATA')) . '">';
print img_picto($langs->trans('KREAPRODUCTS_LABELS_REFRESH_DATA'), 'refresh');
print '</button>';
print '</div>';
	if (!$hasLabelTemplates) {
		print '<div class="opacitymedium small">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE')) . '</div>';
	} elseif (!empty($selectedTemplateViewer['size_text'])) {
		print '<div class="opacitymedium small">' . dol_escape_htmltag($selectedTemplateViewer['size_text']) . '</div>';
	}
	if (!$isStandardMode && $selectedTemplateReadOnly) {
		print '<div class="opacitymedium small">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_READONLY_HELP')) . '</div>';
	}
	print '</td></tr>';

	print '<tr id="krea-label-template-description-row"' . ($isStandardMode ? ' style="display:none;"' : '') . '><td class="titlefield">' . $langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION') . '</td><td>';
	print '<textarea id="krea-label-template-description" class="minwidth300" name="label_template_description" rows="3" maxlength="1200" placeholder="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION_PLACEHOLDER')) . '">' . dol_escape_htmltag($selectedTemplateDescription) . '</textarea>';
	print '<div class="opacitymedium small">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_DESCRIPTION_HELP')) . '</div>';
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
	print '<input type="submit" class="button button-save" value="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_GENERATE_BUTTON')) . '">';
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
	print '<label class="small opacitymedium" for="krea-template-json-upload">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_UPLOAD_LABEL')) . '</label><br>';
	print '<input id="krea-template-json-upload" type="file" name="label_template_upload" accept=".json,application/json" required>';
	print ' <input type="submit" class="button button-small" value="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_UPLOAD_ACTION')) . '">';
	print '</form>';

	print '<form method="POST" enctype="multipart/form-data" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . $object->id . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="upload_template_asset">';
	if ($selectedTemplate !== '') {
		print '<input type="hidden" name="label_template" value="' . dol_escape_htmltag($selectedTemplate) . '">';
	}
	print '<label class="small opacitymedium" for="krea-template-asset-upload">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_ASSET_UPLOAD_LABEL')) . '</label><br>';
	print '<input id="krea-template-asset-upload" type="file" name="label_template_asset_upload" accept="image/*" required>';
	print ' <input type="submit" class="button button-small" value="' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_ASSET_UPLOAD_ACTION')) . '">';
	print '</form>';
} else {
	print '<span class="opacitymedium small">' . dol_escape_htmltag($langs->trans('ReadPermissionNotAllowed')) . '</span>';
}
print '<div class="opacitymedium small" style="margin-top:8px;">' . dol_escape_htmltag($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_LIBRARY_HELP')) . '</div>';
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
	print 'var standardPreviewTitle = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_STANDARD'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewDescription = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_DESC'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var standardPreviewUnavailable = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_STANDARD_PREVIEW_UNAVAILABLE'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var templatePreviewUnavailable = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_NONE'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var templateFieldsEmpty = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_TEMPLATE_FIELDS_NONE'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var quantityTemplate = ' . json_encode($langs->trans('KREAPRODUCTS_LABELS_PREVIEW_QUANTITY', '__VALUE__'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var refreshBasePath = ' . json_encode($_SERVER['PHP_SELF'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';';
	print 'var currentProductId = ' . ((int) $object->id) . ';';
	print 'var canWriteTemplateValues = ' . ($permissiontowrite && $selectedTemplateEditable ? 'true' : 'false') . ';';
	print 'var selector = document.getElementById("label_template");';
	print 'var templateDescriptionInput = document.getElementById("krea-label-template-description");';
	print 'var formatSelector = document.getElementById("label_format");';
	print 'var quantityInput = document.querySelector(\'input[name="label_qty"]\');';
	print 'var fieldInputs = Array.prototype.slice.call(document.querySelectorAll(\'input[name="label_fields[]"]\'));';
	print 'var refreshButton = document.getElementById("krea-label-refresh-data");';
	print 'var formActionInput = document.getElementById("krea-label-form-action");';
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
	print 'function formatSvgNumber(value) { var num = Number(value || 0); if (!isFinite(num)) { num = 0; } return num.toFixed(3).replace(/\\.0+$/, "").replace(/(\\.\\d*?)0+$/, "$1"); }';
	print 'function quantityText(value) { return quantityTemplate.replace("__VALUE__", String(value || 1)); }';
	print 'function renderTemplateEditableFields(code) {';
	print '  if (!templateFieldsCell) { return; }';
	print '  var templateCode = String(code || "");';
	print '  var renderedHtml = (templateCode !== "" && Object.prototype.hasOwnProperty.call(templateEditableHtmlMap, templateCode)) ? String(templateEditableHtmlMap[templateCode] || "") : "";';
	print '  if (renderedHtml.trim() === "") {';
	print '    renderedHtml = \'<span class="opacitymedium small">\' + escapeHtml(templateFieldsEmpty) + \'</span>\';';
	print '  }';
	print '  templateFieldsCell.innerHTML = renderedHtml;';
	print '}';
	print 'function setPreviewEmpty(message) { if (emptyMessageNode) { emptyMessageNode.textContent = message || ""; } setVisible(emptyState, true); setVisible(contentState, false); if (pagesNode) { pagesNode.innerHTML = ""; } }';
	print 'function setPreviewContent(name, description, sizeText, pagesHtml) { if (nameNode) { nameNode.textContent = name || ""; } if (descriptionNode) { descriptionNode.textContent = description || ""; } if (sizeNode) { sizeNode.textContent = sizeText || ""; } if (pagesNode) { pagesNode.innerHTML = pagesHtml || ""; } setVisible(emptyState, false); setVisible(contentState, true); }';
	print 'function renderTemplatePages(template) {';
	print '  if (!pagesNode) { return; }';
	print '  if (!template || !template.pages || !template.pages.length) { pagesNode.innerHTML = ""; return; }';
	print '  pagesNode.innerHTML = template.pages.map(function (page) {';
	print '    var meta = [page.label || "", page.size_text || ""].filter(Boolean).join(" · ");';
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
	print '  if (selectedTemplateCode !== "") {';
	print '    params.push("label_template=" + encodeURIComponent(selectedTemplateCode));';
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
	print '  setVisible(templateFieldsRow, true);';
	print '  setVisible(formatRow, standardMode);';
	print '  setVisible(quantityRow, standardMode);';
	print '  setVisible(fieldsRow, standardMode);';
	print '  setVisible(noFormatWarning, standardMode && !(formatSelector && formatSelector.value && formatDetailMap[formatSelector.value]));';
	print '  if (formatSummaryNode) { formatSummaryNode.textContent = standardMode ? (formatSelector && formatSummaryMap[formatSelector.value] ? formatSummaryMap[formatSelector.value] : "") : ""; setVisible(formatSummaryNode, standardMode && !!formatSummaryNode.textContent); }';
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
	print 'if (quantityInput) { quantityInput.addEventListener("input", updateMode); }';
	print 'fieldInputs.forEach(function (input) { input.addEventListener("change", updateMode); });';
	print 'if (refreshButton) { refreshButton.addEventListener("click", function () {';
	print '  var standardMode = !selector || !selector.value;';
	print '  if (!standardMode && canWriteTemplateValues) {';
	print '    var formNode = refreshButton.form || (selector ? selector.form : null);';
	print '    if (formNode) {';
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
