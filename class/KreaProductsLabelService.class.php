<?php
/* Copyright (C) 2026       Kreativität Works       <mail@kreativitat.com>
 *
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
 * Commercial support and integration services are available from Kreativität Works.
 */

/**
 * Service helpers for product label generation.
 */
class KreaProductsLabelService
{
	/**
	 * Return selectable label fields.
	 *
	 * @param Translate $langs Output language
	 * @return array
	 */
	public static function getAvailableFields($langs)
	{
		return array(
			'ref' => $langs->trans('Ref'),
			'label' => $langs->trans('Label'),
			'barcode' => $langs->trans('BarCode'),
			'price_ht' => $langs->trans('KREAPRODUCTS_LABELS_PRICE_HT'),
			'price_ttc' => $langs->trans('KREAPRODUCTS_LABELS_PRICE_TTC'),
		);
	}

	/**
	 * Sanitize selected field codes.
	 *
	 * @param array $selected Raw selected field codes
	 * @return array
	 */
	public static function sanitizeSelectedFields($selected)
	{
		$allowed = array('ref', 'label', 'barcode', 'price_ht', 'price_ttc');
		if (!is_array($selected)) {
			return array();
		}

		$selected = array_values(array_unique(array_map('strval', $selected)));
		return array_values(array_intersect($selected, $allowed));
	}

	/**
	 * Return active Dolibarr label formats.
	 *
	 * @param DoliDB $db Database handler
	 * @return array
	 */
	public static function getFormatOptions($db)
	{
		$options = array();

		$sql = "SELECT code, name, paper_size, metric, nx, ny, width, height, custom_x, custom_y";
		$sql .= " FROM " . MAIN_DB_PREFIX . "c_format_cards";
		$sql .= " WHERE active = 1";
		$sql .= " ORDER BY code ASC";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$pageLabel = ($obj->paper_size === 'custom' ? price2num($obj->custom_x) . 'x' . price2num($obj->custom_y) . ' ' . $obj->metric : $obj->paper_size);
				$options[$obj->code] = $obj->name . ' (' . $pageLabel . ' - ' . ((int) $obj->nx) . 'x' . ((int) $obj->ny) . ')';
			}
			$db->free($resql);
		}

		return $options;
	}

	/**
	 * Return active format details indexed by code.
	 *
	 * @param DoliDB $db Database handler
	 * @return array
	 */
	public static function getFormatDetails($db)
	{
		$details = array();

		$sql = "SELECT code, name, paper_size, metric, nx, ny, width, height, leftmargin, topmargin, spacex, spacey, custom_x, custom_y";
		$sql .= " FROM " . MAIN_DB_PREFIX . "c_format_cards";
		$sql .= " WHERE active = 1";
		$sql .= " ORDER BY code ASC";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$details[$obj->code] = array(
					'code' => $obj->code,
					'name' => $obj->name,
					'paper_size' => $obj->paper_size,
					'metric' => $obj->metric,
					'nx' => (int) $obj->nx,
					'ny' => (int) $obj->ny,
					'width' => (float) $obj->width,
					'height' => (float) $obj->height,
					'leftmargin' => (float) $obj->leftmargin,
					'topmargin' => (float) $obj->topmargin,
					'spacex' => (float) $obj->spacex,
					'spacey' => (float) $obj->spacey,
					'custom_x' => (float) $obj->custom_x,
					'custom_y' => (float) $obj->custom_y,
				);
			}
			$db->free($resql);
		}

		return $details;
	}

	/**
	 * Get default format code.
	 *
	 * @param array $formatOptions Format options
	 * @return string
	 */
	public static function getDefaultFormatCode($formatOptions)
	{
		if (empty($formatOptions)) {
			return '';
		}

		foreach ($formatOptions as $code => $label) {
			return (string) $code;
		}

		return '';
	}

	/**
	 * Build relative documents path for an entity and product.
	 *
	 * @param int $entityId  Current entity id
	 * @param int $productId Product id
	 * @return string
	 */
	public static function getDocumentModuleSubdir($entityId, $productId)
	{
		return (int) $entityId . '/labels/product/' . (int) $productId;
	}

	/**
	 * Build absolute documents directory.
	 *
	 * @param int $entityId  Current entity id
	 * @param int $productId Product id
	 * @return string
	 */
	public static function getDocumentDir($entityId, $productId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . self::getDocumentModuleSubdir($entityId, $productId);
	}

	/**
	 * Return the directory containing bundled label templates shipped with the module.
	 *
	 * @return string
	 */
	public static function getBundledLabelTemplateDir()
	{
		return dirname(__DIR__) . '/labels';
	}

	/**
	 * Return the legacy bundled template directory used before 2.6.4.
	 *
	 * @return string
	 */
	private static function getLegacyBundledLabelTemplateDir()
	{
		return dirname(__DIR__) . '/templates/labels';
	}

	/**
	 * Return the directory intended for entity-scoped custom label templates.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	public static function getCustomLabelTemplateDir($entityId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . ((int) $entityId) . '/labels/templates';
	}

	/**
	 * Return the legacy custom template directory used before 2.6.4.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	private static function getLegacyCustomLabelTemplateDir($entityId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . ((int) $entityId) . '/templates/labels';
	}

	/**
	 * Return the previous custom template directory used before 2.11.2.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	private static function getPreviousCustomLabelTemplateDir($entityId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . ((int) $entityId) . '/labels';
	}

	/**
	 * Return the modulepart subdir used to store custom template JSON files.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	public static function getTemplateModuleSubdir($entityId)
	{
		return (int) $entityId . '/labels/templates';
	}

	/**
	 * Return absolute directory for custom template JSON files.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	public static function getTemplateDir($entityId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . self::getTemplateModuleSubdir($entityId);
	}

	/**
	 * Return modulepart subdir for uploaded template asset images.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	public static function getTemplateAssetModuleSubdir($entityId)
	{
		return self::getTemplateModuleSubdir($entityId) . '/assets';
	}

	/**
	 * Return absolute directory for uploaded template asset images.
	 *
	 * @param int $entityId Current entity id
	 * @return string
	 */
	public static function getTemplateAssetDir($entityId)
	{
		return DOL_DATA_ROOT . '/kreaproducts/' . self::getTemplateAssetModuleSubdir($entityId);
	}

	/**
	 * List available template asset references stored in module documents.
	 *
	 * @param int $entityId Current entity id
	 * @return array<string,string> Map asset reference => display label
	 */
	public static function listTemplateAssetReferences($entityId)
	{
		$references = array();
		$assetDir = self::getTemplateAssetDir($entityId);
		if (!is_dir($assetDir)) {
			return $references;
		}

		$entries = scandir($assetDir);
		if ($entries === false) {
			return $references;
		}

		$allowedExtensions = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$fullPath = $assetDir . '/' . $entry;
			if (!is_file($fullPath) || !is_readable($fullPath)) {
				continue;
			}

			$extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
			if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
				continue;
			}

			$safeName = dol_sanitizeFileName($entry);
			if ($safeName === '' || $safeName !== $entry) {
				continue;
			}

			$assetReference = 'templates/assets/' . $safeName;
			$references[$assetReference] = $safeName;
		}

		asort($references, SORT_NATURAL | SORT_FLAG_CASE);
		return $references;
	}

	/**
	 * Build a web URL to a bundled label template asset.
	 *
	 * @param string $relativePath Relative path from labels/
	 * @return string
	 */
	public static function getBundledLabelTemplateAssetUrl($relativePath)
	{
		$relativePath = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
		if ($relativePath === '' || strpos($relativePath, '..') !== false) {
			return '';
		}

		return dol_buildpath('/kreaproducts/labels/' . $relativePath, 1);
	}

	/**
	 * Build a public preview URL for one template asset reference.
	 *
	 * @param string $value Asset reference
	 * @return string
	 */
	public static function getTemplateAssetPreviewUrl($value)
	{
		return self::buildTemplateAssetPreviewUrl($value);
	}

	/**
	 * Sanitize a label template code.
	 *
	 * @param string $templateCode Template code
	 * @return string
	 */
	private static function sanitizeTemplateCode($templateCode)
	{
		return preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $templateCode);
	}

	/**
	 * Tell whether one template originates from a bundled-copy workflow.
	 *
	 * @param array $template Template definition
	 * @return bool
	 */
	private static function isBundledCopyTemplate($template)
	{
		if (empty($template['source']) || !is_array($template['source'])) {
			return false;
		}

		$type = strtolower(trim((string) (!empty($template['source']['type']) ? $template['source']['type'] : '')));
		return ($type === 'bundled_copy');
	}

	/**
	 * Check if one custom template code already exists in entity custom dirs.
	 *
	 * @param string $templateCode Template code
	 * @param int    $entityId     Current entity id
	 * @return bool
	 */
	private static function customTemplateCodeExists($templateCode, $entityId)
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return false;
		}

		$fileName = $templateCode . '.json';
		$dirs = array(
			self::getCustomLabelTemplateDir($entityId),
			self::getPreviousCustomLabelTemplateDir($entityId),
			self::getLegacyCustomLabelTemplateDir($entityId),
		);
		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			if (is_file($dir . '/' . $fileName)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build an available custom template code based on a preferred base code.
	 *
	 * @param string $baseCode Preferred base code
	 * @param int    $entityId Current entity id
	 * @return string
	 */
	private static function buildUniqueCustomTemplateCode($baseCode, $entityId)
	{
		$baseCode = self::sanitizeTemplateCode($baseCode);
		if ($baseCode === '') {
			$baseCode = 'template_copy';
		}

		$candidate = $baseCode;
		$index = 2;
		while (!empty(self::loadBundledLabelTemplate($candidate)) || self::customTemplateCodeExists($candidate, $entityId)) {
			$candidate = $baseCode . '_' . $index;
			$index++;
			if ($index > 9999) {
				return '';
			}
		}

		return $candidate;
	}

	/**
	 * Migrate a colliding bundled-copy custom template to a unique custom code.
	 *
	 * This prevents bundled templates from being shadowed by legacy bundled-copy
	 * files that reused the same template_code.
	 *
	 * @param array  $template Loaded template data
	 * @param string $fullPath Absolute path to current file
	 * @param int    $entityId Current entity id
	 * @return array{template: array, path: string}
	 */
	private static function migrateCollidingBundledCopyTemplate($template, $fullPath, $entityId)
	{
		$result = array(
			'template' => (is_array($template) ? $template : array()),
			'path' => (string) $fullPath,
		);
		if (!is_array($template) || $fullPath === '') {
			return $result;
		}
		if (!self::isBundledCopyTemplate($template)) {
			return $result;
		}

		$currentCode = self::sanitizeTemplateCode(!empty($template['template_code']) ? $template['template_code'] : '');
		if ($currentCode === '') {
			return $result;
		}
		if (empty(self::loadBundledLabelTemplate($currentCode))) {
			return $result;
		}

		$targetCode = self::buildUniqueCustomTemplateCode($currentCode . '_copy', $entityId);
		if ($targetCode === '' || $targetCode === $currentCode) {
			return $result;
		}

		$template['template_code'] = $targetCode;
		if (empty($template['source']) || !is_array($template['source'])) {
			$template['source'] = array();
		}
		$template['source']['migrated_from_code'] = $currentCode;
		$template['source']['migrated_on'] = dol_print_date(dol_now(), '%Y-%m-%d', 'gmt');

		$targetPath = dirname($fullPath) . '/' . $targetCode . '.json';
		$encoded = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			dol_syslog(__METHOD__ . ' json_encode failed for migration: ' . $fullPath, LOG_WARNING);
			return $result;
		}

		$writeResult = @file_put_contents($targetPath, $encoded . "\n", LOCK_EX);
		if ($writeResult === false) {
			dol_syslog(__METHOD__ . ' failed to write migrated template: ' . $targetPath, LOG_WARNING);
			return $result;
		}

		$realOld = realpath($fullPath);
		$realNew = realpath($targetPath);
		if ($realOld !== false && $realNew !== false && $realOld !== $realNew) {
			@unlink($fullPath);
		}

		$result['template'] = $template;
		$result['path'] = $targetPath;
		return $result;
	}

	/**
	 * Load and decode a label template from disk.
	 *
	 * @param string $fullPath Absolute JSON file path
	 * @return array
	 */
	private static function loadLabelTemplateFile($fullPath)
	{
		if ($fullPath === '' || !is_readable($fullPath)) {
			return array();
		}

		$raw = file_get_contents($fullPath);
		if ($raw === false || trim($raw) === '') {
			return array();
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			dol_syslog(__METHOD__ . ' invalid template JSON: ' . $fullPath, LOG_WARNING);
			return array();
		}

		return $decoded;
	}

	/**
	 * Build a normalized template index entry.
	 *
	 * @param array  $template Template definition
	 * @param string $path     Absolute JSON file path
	 * @param string $source   Template source
	 * @return array
	 */
	private static function buildTemplateIndexEntry($template, $path, $source)
	{
		$isReadOnly = ($source === 'bundled');
		$filename = basename((string) $path);
		if ($filename === '') {
			$filename = (!empty($template['template_code']) ? ((string) $template['template_code']) . '.json' : 'template.json');
		}

		return array(
			'code' => (string) $template['template_code'],
			'label' => $filename,
			'description' => (!empty($template['description']) ? (string) $template['description'] : ''),
			'format_code' => (!empty($template['format_code']) ? (string) $template['format_code'] : ''),
			'label_size_mm' => (!empty($template['label_size_mm']) && is_array($template['label_size_mm']) ? $template['label_size_mm'] : array()),
			'preview' => array(
				'front_url' => (!empty($template['preview']['front']) && $source === 'bundled' ? self::getBundledLabelTemplateAssetUrl($template['preview']['front']) : ''),
				'back_url' => (!empty($template['preview']['back']) && $source === 'bundled' ? self::getBundledLabelTemplateAssetUrl($template['preview']['back']) : ''),
			),
			'path' => $path,
			'filename' => $filename,
			'source' => $source,
			'is_readonly' => $isReadOnly,
		);
	}

	/**
	 * List bundled JSON templates available in the module.
	 *
	 * @return array
	 */
	public static function listBundledLabelTemplates()
	{
		$templates = array();
		$templateDirs = array(
			self::getLegacyBundledLabelTemplateDir(),
			self::getBundledLabelTemplateDir(),
		);

		foreach ($templateDirs as $templateDir) {
			if (!is_dir($templateDir)) {
				continue;
			}

			$entries = scandir($templateDir);
			if ($entries === false) {
				continue;
			}

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.json') {
					continue;
				}

				$template = self::loadLabelTemplateFile($templateDir . '/' . $entry);
				if (empty($template['template_code'])) {
					continue;
				}

				$templates[$template['template_code']] = self::buildTemplateIndexEntry($template, $templateDir . '/' . $entry, 'bundled');
			}
		}

		ksort($templates);
		return $templates;
	}

	/**
	 * List entity-scoped JSON templates available in documents storage.
	 *
	 * @param int $entityId Current entity id
	 * @return array
	 */
	public static function listCustomLabelTemplates($entityId)
	{
		$templates = array();
		$templateDirs = array(
			self::getCustomLabelTemplateDir($entityId),
			self::getPreviousCustomLabelTemplateDir($entityId),
			self::getLegacyCustomLabelTemplateDir($entityId),
		);

		foreach ($templateDirs as $templateDir) {
			if (!is_dir($templateDir)) {
				continue;
			}

			$entries = scandir($templateDir);
			if ($entries === false) {
				continue;
			}

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.json') {
					continue;
				}

				$fullPath = $templateDir . '/' . $entry;
				$template = self::loadLabelTemplateFile($fullPath);
				if (empty($template['template_code'])) {
					continue;
				}

				$migrated = self::migrateCollidingBundledCopyTemplate($template, $fullPath, $entityId);
				if (!empty($migrated['template']) && is_array($migrated['template'])) {
					$template = $migrated['template'];
				}
				if (!empty($migrated['path']) && is_string($migrated['path'])) {
					$fullPath = $migrated['path'];
				}
				if (empty($template['template_code'])) {
					continue;
				}

				$templates[$template['template_code']] = self::buildTemplateIndexEntry($template, $fullPath, 'custom');
			}
		}

		ksort($templates);
		return $templates;
	}

	/**
	 * List all available JSON templates for the current entity.
	 *
	 * Entity-scoped templates override bundled templates with the same code.
	 *
	 * @param int $entityId Current entity id
	 * @return array
	 */
	public static function listLabelTemplates($entityId)
	{
		$templates = self::listBundledLabelTemplates();
		foreach (self::listCustomLabelTemplates($entityId) as $templateCode => $templateMeta) {
			$templates[$templateCode] = $templateMeta;
		}

		ksort($templates);
		return $templates;
	}

	/**
	 * Return template metadata from merged list.
	 *
	 * @param string $templateCode Template code
	 * @param int    $entityId     Current entity id
	 * @return array
	 */
	public static function getTemplateMeta($templateCode, $entityId)
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return array();
		}

		$templates = self::listLabelTemplates($entityId);
		if (empty($templates[$templateCode]) || !is_array($templates[$templateCode])) {
			return array();
		}

		return $templates[$templateCode];
	}

	/**
	 * Check whether one template is editable (custom template only).
	 *
	 * @param string $templateCode Template code
	 * @param int    $entityId     Current entity id
	 * @return bool
	 */
	public static function isTemplateEditable($templateCode, $entityId)
	{
		$templateMeta = self::getTemplateMeta($templateCode, $entityId);
		if (empty($templateMeta)) {
			return false;
		}

		return (empty($templateMeta['is_readonly']) && !empty($templateMeta['source']) && (string) $templateMeta['source'] === 'custom');
	}

	/**
	 * Load a bundled label template by code.
	 *
	 * @param string $templateCode Template code without extension
	 * @return array
	 */
	public static function loadBundledLabelTemplate($templateCode)
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return array();
		}

		$candidates = array(
			self::getBundledLabelTemplateDir() . '/' . $templateCode . '.json',
			self::getLegacyBundledLabelTemplateDir() . '/' . $templateCode . '.json',
		);

		foreach ($candidates as $fullPath) {
			$template = self::loadLabelTemplateFile($fullPath);
			if (empty($template)) {
				continue;
			}

			$resolvedCode = self::sanitizeTemplateCode(!empty($template['template_code']) ? $template['template_code'] : '');
			if ($resolvedCode !== '' && $resolvedCode !== $templateCode) {
				continue;
			}

			return $template;
		}

		return array();
	}

	/**
	 * Load an entity-scoped label template by code.
	 *
	 * @param string $templateCode Template code without extension
	 * @param int    $entityId     Current entity id
	 * @return array
	 */
	public static function loadCustomLabelTemplate($templateCode, $entityId)
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return array();
		}

		$candidates = array(
			self::getCustomLabelTemplateDir($entityId) . '/' . $templateCode . '.json',
			self::getPreviousCustomLabelTemplateDir($entityId) . '/' . $templateCode . '.json',
			self::getLegacyCustomLabelTemplateDir($entityId) . '/' . $templateCode . '.json',
		);

		foreach ($candidates as $fullPath) {
			$template = self::loadLabelTemplateFile($fullPath);
			if (!empty($template)) {
				return $template;
			}
		}

		return array();
	}

	/**
	 * Load a label template, preferring the entity-scoped version when present.
	 *
	 * @param string $templateCode Template code without extension
	 * @param int    $entityId     Current entity id
	 * @return array
	 */
	public static function loadLabelTemplate($templateCode, $entityId)
	{
		$template = self::loadCustomLabelTemplate($templateCode, $entityId);
		if (!empty($template)) {
			return $template;
		}

		return self::loadBundledLabelTemplate($templateCode);
	}

	/**
	 * Save editable template input values as JSON defaults for the current entity.
	 *
	 * This writes into the entity-scoped custom template folder so updates are
	 * multicompany-safe and never overwrite bundled module files.
	 *
	 * @param string    $templateCode  Template code
	 * @param int       $entityId      Current entity id
	 * @param array     $inputValues   Raw input values
	 * @param Translate $outputlangs   Output language
	 * @param string    $templateDescription Optional template description
	 * @return array
	 */
	public static function saveTemplateInputDefaults($templateCode, $entityId, $inputValues, $outputlangs, $templateDescription = '')
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_UNAVAILABLE'));
		}
		if (!self::isTemplateEditable($templateCode, $entityId)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY'));
		}

		$template = self::loadCustomLabelTemplate($templateCode, $entityId);
		if (empty($template) || !is_array($template)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_UNAVAILABLE'));
		}
		$sanitizedTemplateDescription = self::sanitizeTemplateDescription($templateDescription);

		$sourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);

		$sanitizedValues = self::sanitizeTemplateInputValues($inputValues, array_keys($sourceMeta), $sourceMeta);
		if (!isset($template['inputs']) || !is_array($template['inputs'])) {
			$template['inputs'] = array();
		}

		$existingInputBySource = array();
		foreach ($template['inputs'] as $index => $input) {
			if (!is_array($input)) {
				continue;
			}

			$source = self::sanitizeTemplateSource(!empty($input['source']) ? $input['source'] : '');
			if ($source === '' || !self::isTemplateSourceEditable($source)) {
				continue;
			}
			if (array_key_exists('editable', $input) && !(bool) $input['editable']) {
				continue;
			}

			$existingInputBySource[$source] = true;
			if (!array_key_exists($source, $sanitizedValues)) {
				continue;
			}

			if ((string) $sanitizedValues[$source] !== '') {
				$template['inputs'][$index]['default_value'] = (string) $sanitizedValues[$source];
			} else {
				unset($template['inputs'][$index]['default_value']);
			}
		}

		foreach ($sourceMeta as $source => $meta) {
			if (!empty($existingInputBySource[$source])) {
				continue;
			}

			$newInput = array(
				'source' => $source,
				'label' => (!empty($meta['label']) ? (string) $meta['label'] : self::humanizeTemplateSource($source)),
				'type' => (!empty($meta['type']) ? (string) $meta['type'] : 'text'),
			);

			if (!empty($meta['placeholder'])) {
				$newInput['placeholder'] = (string) $meta['placeholder'];
			}
			if (!empty($meta['output_format']) && in_array($newInput['type'], array('date', 'datetime'), true)) {
				$newInput['output_format'] = (string) $meta['output_format'];
			}
			if ($newInput['type'] === 'textarea' && !empty($meta['rows'])) {
				$newInput['rows'] = max(2, (int) $meta['rows']);
			}
			if ($newInput['type'] === 'select' && !empty($meta['options']) && is_array($meta['options'])) {
				$newInput['options'] = $meta['options'];
			}
			if ($newInput['type'] === 'number') {
				if (isset($meta['min']) && (string) $meta['min'] !== '') {
					$newInput['min'] = (string) $meta['min'];
				}
				if (isset($meta['max']) && (string) $meta['max'] !== '') {
					$newInput['max'] = (string) $meta['max'];
				}
				if (!empty($meta['step'])) {
					$newInput['step'] = (string) $meta['step'];
				}
			}

			if (array_key_exists($source, $sanitizedValues) && (string) $sanitizedValues[$source] !== '') {
				$newInput['default_value'] = (string) $sanitizedValues[$source];
			} elseif (isset($meta['default_value']) && (string) $meta['default_value'] !== '') {
				$newInput['default_value'] = (string) $meta['default_value'];
			}

			$template['inputs'][] = $newInput;
			}
		unset($template['label']);
		if ($sanitizedTemplateDescription !== '') {
			$template['description'] = $sanitizedTemplateDescription;
		} else {
			unset($template['description']);
		}

		$customDir = self::getCustomLabelTemplateDir($entityId);
		if (!is_dir($customDir) && !@mkdir($customDir, 0775, true) && !is_dir($customDir)) {
			dol_syslog(__METHOD__ . ' failed to create dir: ' . $customDir, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_FAILED'));
		}

		$targetPath = $customDir . '/' . $templateCode . '.json';
		$encoded = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			dol_syslog(__METHOD__ . ' json_encode failed for template: ' . $templateCode, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_FAILED'));
		}

		$writeResult = @file_put_contents($targetPath, $encoded . "\n", LOCK_EX);
		if ($writeResult === false) {
			dol_syslog(__METHOD__ . ' failed to write template: ' . $targetPath, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_FAILED'));
		}

		return array(
			'success' => true,
			'path' => $targetPath,
		);
	}

	/**
	 * Create an editable custom copy from a bundled read-only template.
	 *
	 * The copy is written into entity documents and then updated with provided
	 * field defaults so generation from read-only templates can persist user edits.
	 *
	 * @param string    $templateCode  Template code
	 * @param int       $entityId      Current entity id
	 * @param array     $inputValues   Editable field values
	 * @param Translate $outputlangs   Output language
	 * @param string    $templateDescription Optional template description
	 * @return array
	 */
	public static function createEditableTemplateCopyFromBundled($templateCode, $entityId, $inputValues, $outputlangs, $templateDescription = '')
	{
		$templateCode = self::sanitizeTemplateCode($templateCode);
		if ($templateCode === '') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_SAVE_UNAVAILABLE'));
		}

		if (self::isTemplateEditable($templateCode, $entityId)) {
			return array(
				'success' => true,
				'template_code' => $templateCode,
				'created' => false,
			);
		}

		$preferredCopyCode = self::sanitizeTemplateCode($templateCode . '_copy');
		if ($preferredCopyCode !== '' && self::isTemplateEditable($preferredCopyCode, $entityId)) {
			$sanitizedTemplateDescription = self::sanitizeTemplateDescription($templateDescription);
			$saveResult = self::saveTemplateInputDefaults($preferredCopyCode, $entityId, $inputValues, $outputlangs, $sanitizedTemplateDescription);
			if (!empty($saveResult['error'])) {
				return $saveResult;
			}

			return array(
				'success' => true,
				'template_code' => $preferredCopyCode,
				'created' => false,
			);
		}

		$templateMeta = self::getTemplateMeta($templateCode, $entityId);
		if (empty($templateMeta) || empty($templateMeta['source']) || (string) $templateMeta['source'] !== 'bundled') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}

		$bundledTemplate = self::loadBundledLabelTemplate($templateCode);
		if (empty($bundledTemplate) || !is_array($bundledTemplate)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}
		$copyTemplateCode = self::buildUniqueCustomTemplateCode($preferredCopyCode, $entityId);
		if ($copyTemplateCode === '') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}
		$bundledTemplate['template_code'] = $copyTemplateCode;
		if (empty($bundledTemplate['source']) || !is_array($bundledTemplate['source'])) {
			$bundledTemplate['source'] = array();
		}
		$bundledTemplate['source']['type'] = 'bundled_copy';
		$bundledTemplate['source']['copied_from'] = $templateCode;
		$bundledTemplate['source']['copied_on'] = dol_print_date(dol_now(), '%Y-%m-%d', 'gmt');
		unset($bundledTemplate['label']);
		$sanitizedTemplateDescription = self::sanitizeTemplateDescription($templateDescription);
		if ($sanitizedTemplateDescription !== '') {
			$bundledTemplate['description'] = $sanitizedTemplateDescription;
		}

		$customDir = self::getCustomLabelTemplateDir($entityId);
		if (!is_dir($customDir) && !@mkdir($customDir, 0775, true) && !is_dir($customDir)) {
			dol_syslog(__METHOD__ . ' failed to create dir: ' . $customDir, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}

		$targetPath = $customDir . '/' . $copyTemplateCode . '.json';
		$encoded = json_encode($bundledTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			dol_syslog(__METHOD__ . ' json_encode failed for bundled copy: ' . $copyTemplateCode, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}
		$writeResult = @file_put_contents($targetPath, $encoded . "\n", LOCK_EX);
		if ($writeResult === false) {
			dol_syslog(__METHOD__ . ' failed to write bundled copy: ' . $targetPath, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_READONLY_COPY'));
		}

		$saveResult = self::saveTemplateInputDefaults($copyTemplateCode, $entityId, $inputValues, $outputlangs, $sanitizedTemplateDescription);
		if (!empty($saveResult['error'])) {
			return $saveResult;
		}

		return array(
			'success' => true,
			'template_code' => $copyTemplateCode,
			'created' => true,
			'path' => $targetPath,
		);
	}

	/**
	 * Build a copy label by appending "(Copy)" when missing.
	 *
	 * @param string $label    Base label
	 * @param string $fallback Fallback label when base is empty
	 * @return string
	 */
	public static function buildTemplateCopyLabel($label, $fallback = 'Template')
	{
		$base = self::sanitizeTemplateDisplayLabel($label, $fallback);
		if ($base === '') {
			$base = 'Template';
		}
		if (preg_match('/\(\s*copy\s*\)$/i', $base)) {
			return $base;
		}

		return $base . ' (Copy)';
	}

	/**
	 * Sanitize one template display label.
	 *
	 * @param string $label    Raw label
	 * @param string $fallback Fallback label when raw is empty
	 * @return string
	 */
	private static function sanitizeTemplateDisplayLabel($label, $fallback = '')
	{
		$clean = self::cleanText((string) $label);
		if ($clean === '' && $fallback !== '') {
			$clean = self::cleanText((string) $fallback);
		}
		if ($clean === '') {
			return '';
		}

		if (function_exists('mb_substr')) {
			return (string) mb_substr($clean, 0, 190, 'UTF-8');
		}

		return (string) substr($clean, 0, 190);
	}

	/**
	 * Sanitize template description text.
	 *
	 * @param string $description Raw description
	 * @return string
	 */
	private static function sanitizeTemplateDescription($description)
	{
		$clean = self::cleanText((string) $description);
		if ($clean === '') {
			return '';
		}

		if (function_exists('mb_substr')) {
			return (string) mb_substr($clean, 0, 1200, 'UTF-8');
		}

		return (string) substr($clean, 0, 1200);
	}

	/**
	 * Import one uploaded template JSON file into entity documents storage.
	 *
	 * @param int       $entityId   Current entity id
	 * @param array     $uploadFile One $_FILES item
	 * @param Translate $outputlangs Output language
	 * @return array
	 */
	public static function importTemplateUploadedJsonFile($entityId, $uploadFile, $outputlangs)
	{
		if (!is_array($uploadFile) || empty($uploadFile['tmp_name']) || !isset($uploadFile['error'])) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD'));
		}

		$errorCode = (int) $uploadFile['error'];
		if ($errorCode !== UPLOAD_ERR_OK) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD'));
		}

		$tmpName = (string) $uploadFile['tmp_name'];
		$isValidUploadedTmp = ($tmpName !== '' && is_uploaded_file($tmpName));
		if (!$isValidUploadedTmp && ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName))) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD'));
		}

		$originalName = (!empty($uploadFile['name']) ? (string) $uploadFile['name'] : '');
		$sanitizedOriginalName = dol_sanitizeFileName($originalName);
		if ($sanitizedOriginalName === '') {
			$sanitizedOriginalName = 'template.json';
		}
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		if ($extension !== 'json') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD_TYPE'));
		}

		$raw = @file_get_contents($tmpName);
		if ($raw === false || trim($raw) === '') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD_INVALID'));
		}

		$template = json_decode($raw, true);
		if (!is_array($template)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD_INVALID'));
		}

		$templateCode = self::sanitizeTemplateCode(pathinfo($sanitizedOriginalName, PATHINFO_FILENAME));
		if ($templateCode === '') {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD_CODE'));
		}

		$template['template_code'] = $templateCode;
		unset($template['label']);
		if (empty($template['source']) || !is_array($template['source'])) {
			$template['source'] = array();
		}
		$template['source']['type'] = 'uploaded';
		$template['source']['uploaded_name'] = $sanitizedOriginalName;
		$template['source']['uploaded_on'] = dol_print_date(dol_now(), '%Y-%m-%d', 'gmt');

		$targetDir = self::getTemplateDir($entityId);
		if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
			dol_syslog(__METHOD__ . ' failed to create dir: ' . $targetDir, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD'));
		}

		$targetPath = $targetDir . '/' . $templateCode . '.json';
		$encoded = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			dol_syslog(__METHOD__ . ' json_encode failed for uploaded template: ' . $templateCode, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD_INVALID'));
		}

		$writeResult = @file_put_contents($targetPath, $encoded . "\n", LOCK_EX);
		if ($writeResult === false) {
			dol_syslog(__METHOD__ . ' failed to write uploaded template: ' . $targetPath, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_UPLOAD'));
		}

		return array(
			'success' => true,
			'template_code' => $templateCode,
			'filename' => basename($targetPath),
			'path' => $targetPath,
		);
	}

	/**
	 * Import one uploaded template asset image into entity documents storage.
	 *
	 * @param int       $entityId    Current entity id
	 * @param array     $uploadFile  One $_FILES item
	 * @param Translate $outputlangs Output language
	 * @return array
	 */
	public static function importTemplateUploadedAssetFile($entityId, $uploadFile, $outputlangs)
	{
		if (!is_array($uploadFile) || empty($uploadFile['tmp_name']) || !isset($uploadFile['error'])) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', 'asset'));
		}

		$errorCode = (int) $uploadFile['error'];
		if ($errorCode !== UPLOAD_ERR_OK) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', 'asset'));
		}

		$tmpName = (string) $uploadFile['tmp_name'];
		if ($tmpName === '' || !is_uploaded_file($tmpName)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', 'asset'));
		}

		$originalName = (!empty($uploadFile['name']) ? (string) $uploadFile['name'] : '');
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
		$allowedExtensions = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');
		if (!in_array($extension, $allowedExtensions, true)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD_TYPE', 'asset'));
		}

		$targetDir = self::getTemplateAssetDir($entityId);
		if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
			dol_syslog(__METHOD__ . ' failed to create dir: ' . $targetDir, LOG_ERR);
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', 'asset'));
		}

		$baseName = dol_sanitizeFileName(pathinfo($originalName, PATHINFO_FILENAME));
		if ($baseName === '') {
			$baseName = 'asset';
		}
		$timestamp = dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt');
		$targetName = $baseName . '_' . $timestamp . '_' . mt_rand(1000, 9999) . '.' . $extension;
		$targetPath = $targetDir . '/' . $targetName;
		if (!@move_uploaded_file($tmpName, $targetPath)) {
			return array('error' => $outputlangs->trans('KREAPRODUCTS_LABELS_ERROR_ASSET_UPLOAD', 'asset'));
		}

		return array(
			'success' => true,
			'filename' => $targetName,
			'path' => $targetPath,
			'asset_reference' => 'templates/assets/' . $targetName,
		);
	}

	/**
	 * Delete a template JSON or template asset file by modulepart-relative path.
	 *
	 * @param int    $entityId     Current entity id
	 * @param string $relativeFile Relative file path under modulepart
	 * @return bool
	 */
	public static function deleteTemplateLibraryFile($entityId, $relativeFile)
	{
		$relativeFile = str_replace('\\', '/', trim((string) $relativeFile));
		if ($relativeFile === '' || strpos($relativeFile, '..') !== false) {
			return false;
		}

		$allowedPrefixes = array(
			self::getTemplateModuleSubdir($entityId) . '/',
			self::getTemplateAssetModuleSubdir($entityId) . '/',
		);
		$allowedByPrefix = false;
		foreach ($allowedPrefixes as $prefix) {
			if (strpos($relativeFile, $prefix) === 0) {
				$allowedByPrefix = true;
				break;
			}
		}
		if (!$allowedByPrefix) {
			return false;
		}

		$fullPath = DOL_DATA_ROOT . '/kreaproducts/' . $relativeFile;
		$realFile = realpath($fullPath);
		if ($realFile === false || !is_file($realFile)) {
			return false;
		}

		$allowedRoots = array();
		foreach (array(self::getTemplateDir($entityId), self::getTemplateAssetDir($entityId)) as $rootDir) {
			$realRoot = realpath($rootDir);
			if ($realRoot !== false) {
				$allowedRoots[] = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			}
		}
		if (empty($allowedRoots)) {
			return false;
		}

		$insideAllowedRoot = false;
		foreach ($allowedRoots as $realRootPrefix) {
			if (strpos($realFile, $realRootPrefix) === 0) {
				$insideAllowedRoot = true;
				break;
			}
		}
		if (!$insideAllowedRoot) {
			return false;
		}

		return self::deleteFileCompat($realFile);
	}

	/**
	 * Build SVG viewer data for available label templates.
	 *
	 * @param Product   $product                  Product object
	 * @param Translate $outputlangs              Output language
	 * @param int       $entityId                 Current entity id
	 * @param array     $templateInputValuesByCode Optional input values indexed by template code
	 * @return array
	 */
	public static function buildLabelTemplateViewerMap($product, $outputlangs, $entityId, $templateInputValuesByCode = array())
	{
		$viewerMap = array();
		$templateIndex = self::listLabelTemplates($entityId);

		foreach ($templateIndex as $templateCode => $templateMeta) {
			$template = self::loadLabelTemplate($templateCode, $entityId);
			if (empty($template)) {
				continue;
			}
			$contextOverrides = array();
			if (!empty($templateInputValuesByCode[$templateCode]) && is_array($templateInputValuesByCode[$templateCode])) {
				$templateSourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);
				$contextOverrides = self::sanitizeTemplateInputValues($templateInputValuesByCode[$templateCode], array_keys($templateSourceMeta), $templateSourceMeta);
			}

			$formatDetails = self::getTemplateOutputFormatDetails($template);
			$pages = array();
			if (!empty($template['pages']) && is_array($template['pages'])) {
				foreach ($template['pages'] as $page) {
					if (!is_array($page)) {
						continue;
					}

					$pageSize = self::getTemplatePageSizeForPdfOutput($template, $page);
					if (empty($pageSize['width']) || empty($pageSize['height'])) {
						continue;
					}

					$pages[] = array(
						'code' => (!empty($page['code']) ? (string) $page['code'] : 'page_' . (count($pages) + 1)),
						'label' => (!empty($page['label']) ? (string) $page['label'] : $outputlangs->trans('Page')),
						'size_text' => $outputlangs->trans(
							'KREAPRODUCTS_LABELS_TEMPLATE_SIZE',
							price2num($pageSize['width']),
							price2num($pageSize['height'])
						),
						'svg' => self::renderTemplatePageSvg($template, $page, $product, $outputlangs, $contextOverrides),
					);
				}
			}

			$sizeText = '';
			if (!empty($templateMeta['label_size_mm']['width']) && !empty($templateMeta['label_size_mm']['height'])) {
				$sizeText = $outputlangs->trans(
					'KREAPRODUCTS_LABELS_TEMPLATE_SIZE',
					price2num($templateMeta['label_size_mm']['width']),
					price2num($templateMeta['label_size_mm']['height'])
				);
			}

				$viewerMap[$templateCode] = array(
					'label' => (!empty($templateMeta['label']) ? (string) $templateMeta['label'] : (string) $templateCode),
					'description' => (!empty($templateMeta['description']) ? (string) $templateMeta['description'] : ''),
					'size_text' => $sizeText,
					'can_use_template_format' => (!empty($formatDetails)),
					'template_format_summary' => (!empty($formatDetails) ? self::buildFormatSummaryText($formatDetails, $outputlangs) : ''),
					'source' => (!empty($templateMeta['source']) ? (string) $templateMeta['source'] : ''),
					'read_only' => !empty($templateMeta['is_readonly']),
					'pages' => $pages,
				);
			}

		return $viewerMap;
	}

	/**
	 * Build editable input metadata for each available template.
	 *
	 * @param Product   $product                  Product object
	 * @param Translate $outputlangs              Output language
	 * @param int       $entityId                 Current entity id
	 * @param array     $templateInputValuesByCode Optional input values indexed by template code
	 * @return array
	 */
	public static function buildTemplateEditableFieldMap($product, $outputlangs, $entityId, $templateInputValuesByCode = array())
	{
		$fieldMap = array();
		$templateIndex = self::listLabelTemplates($entityId);
		foreach ($templateIndex as $templateCode => $templateMeta) {
			$template = self::loadLabelTemplate($templateCode, $entityId);
			if (empty($template)) {
				continue;
			}

			$inputValues = array();
			if (!empty($templateInputValuesByCode[$templateCode]) && is_array($templateInputValuesByCode[$templateCode])) {
				$inputValues = $templateInputValuesByCode[$templateCode];
			}
			$fieldMap[$templateCode] = self::getTemplateEditableFields($template, $product, $outputlangs, $inputValues);
		}

		return $fieldMap;
	}

	/**
	 * Build editable fields for one template.
	 *
	 * @param array     $template            Template definition
	 * @param Product   $product             Product object
	 * @param Translate $outputlangs         Output language
	 * @param array     $templateInputValues User-provided values
	 * @return array
	 */
	public static function getTemplateEditableFields($template, $product, $outputlangs, $templateInputValues = array())
	{
		$sourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);
		if (empty($sourceMeta)) {
			return array();
		}
		$orderedSources = self::orderEditableSourcesByTemplateFlow($template, $sourceMeta);

		$sanitizedValues = self::sanitizeTemplateInputValues($templateInputValues, array_keys($sourceMeta), $sourceMeta);
		$baseContext = self::buildTemplatePreviewContext($product, $outputlangs, array(), array());
		$context = self::buildTemplatePreviewContext($product, $outputlangs, $template, $sanitizedValues);
		$fields = array();
		foreach ($orderedSources as $source) {
			if (!isset($sourceMeta[$source])) {
				continue;
			}
			$meta = $sourceMeta[$source];
			$resolvedValue = (isset($context[$source]) ? (string) $context[$source] : '');
			if ($resolvedValue === '' && isset($meta['default_value'])) {
				$resolvedValue = (string) $meta['default_value'];
			}
			if ($resolvedValue === '' && !empty($meta['placeholder'])) {
				$resolvedValue = (string) $meta['placeholder'];
			}

			$hasResetValue = false;
			$resetValue = '';
			if (array_key_exists($source, $baseContext)) {
				$hasResetValue = true;
				$resetValue = (string) $baseContext[$source];
			} elseif (array_key_exists('default_value', $meta)) {
				$hasResetValue = true;
				$resetValue = (string) $meta['default_value'];
			}
			$fieldType = (!empty($meta['type']) ? self::normalizeTemplateInputType((string) $meta['type']) : 'text');
			$fields[] = array(
				'source' => $source,
				'label' => (!empty($meta['label']) ? (string) $meta['label'] : self::humanizeTemplateSource($source)),
				'type' => (!empty($meta['type']) ? (string) $meta['type'] : 'text'),
				'rows' => (!empty($meta['rows']) ? (int) $meta['rows'] : 3),
				'min' => (isset($meta['min']) ? (string) $meta['min'] : ''),
				'max' => (isset($meta['max']) ? (string) $meta['max'] : ''),
				'step' => (!empty($meta['step']) ? (string) $meta['step'] : ''),
				'placeholder' => (!empty($meta['placeholder']) ? (string) $meta['placeholder'] : ''),
				'options' => (!empty($meta['options']) && is_array($meta['options']) ? $meta['options'] : array()),
				'value' => $resolvedValue,
				'input_value' => self::formatTemplateFieldValueForInput($resolvedValue, $meta),
				'can_reset' => $hasResetValue,
				'reset_value' => ($hasResetValue ? $resetValue : ''),
				'reset_input_value' => ($hasResetValue ? self::formatTemplateFieldValueForInput($resetValue, $meta) : ''),
				'asset_preview_url' => (
					($fieldType === 'image')
					? self::buildTemplateAssetPreviewUrl($resolvedValue)
					: ''
				),
				'reset_asset_preview_url' => (
					($fieldType === 'image' && $hasResetValue)
					? self::buildTemplateAssetPreviewUrl($resetValue)
					: ''
				),
			);
		}

		return $fields;
	}

	/**
	 * Order editable sources to follow visual template flow (front-to-back, top-to-bottom).
	 *
	 * @param array $template   Template definition
	 * @param array $sourceMeta Editable source metadata indexed by source
	 * @return array
	 */
	private static function orderEditableSourcesByTemplateFlow($template, $sourceMeta)
	{
		$sources = array_values(array_filter(array_keys((array) $sourceMeta), 'strlen'));
		if (count($sources) <= 1) {
			return $sources;
		}

		$originalIndexBySource = array();
		foreach ($sources as $index => $source) {
			$originalIndexBySource[$source] = (int) $index;
		}

		$blockSourcePosition = array();
		if (!empty($template['pages']) && is_array($template['pages'])) {
			foreach ($template['pages'] as $pageIndex => $page) {
				if (empty($page['blocks']) || !is_array($page['blocks'])) {
					continue;
				}

				foreach ($page['blocks'] as $blockIndex => $block) {
					if (!is_array($block) || empty($block['content_mode'])) {
						continue;
					}

					$source = '';
					$contentMode = (string) $block['content_mode'];
					if ($contentMode === 'dynamic') {
						$source = self::sanitizeTemplateSource(!empty($block['source']) ? $block['source'] : '');
					} elseif ($contentMode === 'asset') {
						$source = self::sanitizeTemplateSource('asset.' . (!empty($block['asset_key']) ? $block['asset_key'] : ''));
					}
					if ($source === '') {
						continue;
					}

					$x = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
					$y = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
					$position = (((int) $pageIndex) * 1000000000)
						+ (((int) round(max(0, $y) * 1000)) * 100000)
						+ (((int) round(max(0, $x) * 1000)) * 100)
						+ ((int) $blockIndex);

					if (!isset($blockSourcePosition[$source]) || $position < $blockSourcePosition[$source]) {
						$blockSourcePosition[$source] = $position;
					}
				}
			}
		}

		$scoreBySource = array();
		foreach ($sources as $source) {
			if (isset($blockSourcePosition[$source])) {
				$scoreBySource[$source] = (int) $blockSourcePosition[$source];
			}
		}

		if (!empty($template['computed_fields']) && is_array($template['computed_fields'])) {
			foreach ($template['computed_fields'] as $rule) {
				if (!is_array($rule)) {
					continue;
				}
				$operation = strtolower(trim((string) (!empty($rule['operation']) ? $rule['operation'] : '')));
				if ($operation !== 'add_days') {
					continue;
				}

				$daysSource = self::sanitizeTemplateSource(!empty($rule['days_source']) ? $rule['days_source'] : '');
				$targetSource = self::sanitizeTemplateSource(!empty($rule['target_source']) ? $rule['target_source'] : '');
				if ($daysSource === '' || $targetSource === '' || !isset($sourceMeta[$daysSource])) {
					continue;
				}
				if (isset($scoreBySource[$daysSource])) {
					continue;
				}
				if (isset($blockSourcePosition[$targetSource])) {
					$scoreBySource[$daysSource] = (int) $blockSourcePosition[$targetSource] + 1;
				}
			}
		}

		usort($sources, function ($a, $b) use ($scoreBySource, $originalIndexBySource) {
			$aHasScore = array_key_exists($a, $scoreBySource);
			$bHasScore = array_key_exists($b, $scoreBySource);
			if ($aHasScore && $bHasScore) {
				if ((int) $scoreBySource[$a] === (int) $scoreBySource[$b]) {
					return ((int) $originalIndexBySource[$a] <=> (int) $originalIndexBySource[$b]);
				}
				return ((int) $scoreBySource[$a] <=> (int) $scoreBySource[$b]);
			}
			if ($aHasScore !== $bHasScore) {
				return ($aHasScore ? -1 : 1);
			}

			return ((int) $originalIndexBySource[$a] <=> (int) $originalIndexBySource[$b]);
		});

		// Keep brand image right after company address for a clearer form flow.
		$addressSource = 'company.address_singleline';
		$brandSource = 'asset.brand_badge';
		$addressIndex = array_search($addressSource, $sources, true);
		$brandIndex = array_search($brandSource, $sources, true);
		if ($addressIndex !== false && $brandIndex !== false) {
			array_splice($sources, (int) $brandIndex, 1);
			$addressIndex = array_search($addressSource, $sources, true);
			if ($addressIndex === false) {
				$sources[] = $brandSource;
			} else {
				array_splice($sources, ((int) $addressIndex) + 1, 0, array($brandSource));
			}
		}

		return $sources;
	}

	/**
	 * Sanitize template input values map.
	 *
	 * @param array $values         Raw values
	 * @param array $allowedSources Optional allowed source keys
	 * @return array
	 */
	public static function sanitizeTemplateInputValues($values, $allowedSources = array(), $sourceMetaBySource = array())
	{
		$clean = array();
		if (!is_array($values)) {
			return $clean;
		}

		$allowedMap = array();
		$normalizedMetaMap = array();
		if (!empty($allowedSources)) {
			foreach ($allowedSources as $source) {
				$sanitizedSource = '';
				if (is_string($source)) {
					$sanitizedSource = self::sanitizeTemplateSource($source);
				} elseif (is_array($source) && !empty($source['source'])) {
					$sanitizedSource = self::sanitizeTemplateSource($source['source']);
				}
				if ($sanitizedSource !== '') {
					$allowedMap[$sanitizedSource] = true;
				}
			}
		}
		if (is_array($sourceMetaBySource)) {
			foreach ($sourceMetaBySource as $metaSource => $meta) {
				$sanitizedSource = self::sanitizeTemplateSource($metaSource);
				if ($sanitizedSource === '' || !is_array($meta)) {
					continue;
				}
				$normalizedMetaMap[$sanitizedSource] = self::normalizeTemplateInputMeta($sanitizedSource, $meta);
			}
		}

		foreach ($values as $source => $value) {
			$sanitizedSource = self::sanitizeTemplateSource($source);
			if ($sanitizedSource === '') {
				continue;
			}
			if (!empty($allowedMap) && empty($allowedMap[$sanitizedSource])) {
				continue;
			}
			if (is_array($value) || is_object($value)) {
				continue;
			}

			$meta = (!empty($normalizedMetaMap[$sanitizedSource]) ? $normalizedMetaMap[$sanitizedSource] : array());
			$clean[$sanitizedSource] = self::sanitizeTemplateInputValue((string) $value, $meta);
		}

		return $clean;
	}

	/**
	 * Normalize template input metadata for one source.
	 *
	 * @param string $source Source key
	 * @param array  $meta   Raw metadata
	 * @return array
	 */
	private static function normalizeTemplateInputMeta($source, $meta)
	{
		$type = self::normalizeTemplateInputType(!empty($meta['type']) ? (string) $meta['type'] : self::guessTemplateInputType($source));

		return array(
			'type' => $type,
			'label' => (!empty($meta['label']) ? self::cleanText($meta['label']) : self::humanizeTemplateSource($source)),
			'placeholder' => (!empty($meta['placeholder']) ? self::cleanText($meta['placeholder']) : ''),
			'default_value' => (isset($meta['default_value']) ? self::cleanText((string) $meta['default_value']) : ''),
			'rows' => (!empty($meta['rows']) ? max(2, (int) $meta['rows']) : 3),
			'min' => (isset($meta['min']) && $meta['min'] !== '' ? self::sanitizeTemplateNumericValue((string) $meta['min']) : ''),
			'max' => (isset($meta['max']) && $meta['max'] !== '' ? self::sanitizeTemplateNumericValue((string) $meta['max']) : ''),
			'step' => (!empty($meta['step']) ? self::sanitizeTemplateNumericValue((string) $meta['step']) : ''),
			'options' => self::normalizeTemplateInputOptions(!empty($meta['options']) && is_array($meta['options']) ? $meta['options'] : array()),
			'output_format' => self::resolveTemplateInputOutputFormat($type, (!empty($meta['output_format']) ? (string) $meta['output_format'] : '')),
		);
	}

	/**
	 * Normalize select input options.
	 *
	 * @param array $options Raw option definitions
	 * @return array
	 */
	private static function normalizeTemplateInputOptions($options)
	{
		$clean = array();
		if (!is_array($options)) {
			return $clean;
		}

		foreach ($options as $option) {
			if (is_array($option)) {
				$value = (isset($option['value']) ? self::sanitizeTemplateTextValue((string) $option['value']) : '');
				$label = (isset($option['label']) ? self::cleanText((string) $option['label']) : $value);
			} else {
				$value = self::sanitizeTemplateTextValue((string) $option);
				$label = $value;
			}
			if ($value === '') {
				continue;
			}

			$clean[] = array(
				'value' => $value,
				'label' => ($label !== '' ? $label : $value),
			);
		}

		return $clean;
	}

	/**
	 * Normalize template input type.
	 *
	 * @param string $rawType Raw type name
	 * @return string
	 */
	private static function normalizeTemplateInputType($rawType)
	{
		$type = strtolower(trim((string) $rawType));
		if (in_array($type, array('text', 'textarea', 'date', 'datetime', 'number', 'image', 'select'), true)) {
			return $type;
		}
		if ($type === 'datetime-local') {
			return 'datetime';
		}

		return 'text';
	}

	/**
	 * Guess a template input type from a source key.
	 *
	 * @param string $source Source key
	 * @param array  $block  Optional block metadata
	 * @return string
	 */
	private static function guessTemplateInputType($source, $block = array())
	{
		$source = self::sanitizeTemplateSource($source);
		if ($source === '') {
			return 'text';
		}
		if (strpos($source, 'asset.') === 0) {
			return 'image';
		}

		if (!empty($block['style']['multiline']) || strpos($source, 'label.') === 0 || strpos($source, 'section') !== false) {
			return 'textarea';
		}
		if (strpos($source, 'validity') !== false || substr($source, -5) === '_days' || strpos($source, '.qty') !== false) {
			return 'number';
		}
		if (strpos($source, 'datetime') !== false || substr($source, -9) === '_datetime') {
			return 'datetime';
		}
		if (strpos($source, 'date') !== false || substr($source, -3) === '_at' || preg_match('/_on$/', $source)) {
			return 'date';
		}

		return 'text';
	}

	/**
	 * Resolve default output format for one typed input.
	 *
	 * @param string $type         Input type
	 * @param string $outputFormat Optional output format
	 * @return string
	 */
	private static function resolveTemplateInputOutputFormat($type, $outputFormat = '')
	{
		$outputFormat = trim((string) $outputFormat);
		if ($outputFormat !== '') {
			return $outputFormat;
		}
		if ($type === 'datetime') {
			return 'd/m/Y H:i';
		}
		if ($type === 'date') {
			return 'd/m/Y';
		}

		return '';
	}

	/**
	 * Sanitize one template input value according to field metadata.
	 *
	 * @param string $value Raw input value
	 * @param array  $meta  Field metadata
	 * @return string
	 */
	private static function sanitizeTemplateInputValue($value, $meta = array())
	{
		$type = (!empty($meta['type']) ? self::normalizeTemplateInputType($meta['type']) : 'text');
		if ($type === 'image') {
			return self::sanitizeTemplateAssetReference($value);
		}
		if ($type === 'number') {
			return self::sanitizeTemplateNumericValue($value);
		}
		if ($type === 'select') {
			$value = self::sanitizeTemplateTextValue($value);
			$options = (!empty($meta['options']) && is_array($meta['options']) ? self::normalizeTemplateInputOptions($meta['options']) : array());
			if (empty($options)) {
				return $value;
			}
			foreach ($options as $option) {
				if ((string) $option['value'] === $value) {
					return $value;
				}
			}

			return '';
		}
		if ($type === 'date' || $type === 'datetime') {
			$timestamp = self::parseTemplateDateTimeToTimestamp($value);
			if ($timestamp === null) {
				return '';
			}

			$format = self::resolveTemplateInputOutputFormat($type, (!empty($meta['output_format']) ? (string) $meta['output_format'] : ''));
			if ($format === '') {
				$format = ($type === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y');
			}

			return self::formatTimestampWithPattern($timestamp, $format);
		}

		return self::sanitizeTemplateTextValue($value, ($type === 'textarea'));
	}

	/**
	 * Sanitize one numeric template value.
	 *
	 * @param string $value Raw value
	 * @return string
	 */
	private static function sanitizeTemplateNumericValue($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		$value = str_replace(',', '.', $value);
		if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
			return '';
		}

		$number = (float) $value;
		$formatted = rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
		if ($formatted === '-0') {
			$formatted = '0';
		}

		return $formatted;
	}

	/**
	 * Sanitize one text template value.
	 *
	 * @param string $value              Raw value
	 * @param bool   $preserveLineBreaks Keep line breaks
	 * @return string
	 */
	private static function sanitizeTemplateTextValue($value, $preserveLineBreaks = false)
	{
		$value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
		$value = dol_string_nohtmltag($value);
		$value = str_replace(array("\r\n", "\r"), "\n", $value);
		if (!$preserveLineBreaks) {
			$value = preg_replace('/\s+/', ' ', $value);
		} else {
			$value = preg_replace('/[ \t]+/', ' ', $value);
			$value = preg_replace('/\n{3,}/', "\n\n", $value);
		}

		return trim((string) $value);
	}

	/**
	 * Sanitize one template asset reference (relative path or http(s) URL).
	 *
	 * @param string $value Raw value
	 * @return string
	 */
	private static function sanitizeTemplateAssetReference($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$value = str_replace('\\', '/', $value);
		$value = ltrim($value, '/');
		if ($value === '' || strpos($value, '..') !== false) {
			return '';
		}
		if (preg_match('/^[A-Za-z0-9_\\/.-]+$/', $value) !== 1) {
			return '';
		}
		if (strpos($value, 'templates/assets/') !== 0) {
			return '';
		}

		$filename = substr($value, strlen('templates/assets/'));
		if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
			return '';
		}
		if ($filename !== dol_sanitizeFileName($filename)) {
			return '';
		}

		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$allowedExtensions = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');
		if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
			return '';
		}

		return 'templates/assets/' . $filename;
	}

	/**
	 * Parse a date/datetime string into a timestamp.
	 *
	 * @param string $value Date value
	 * @return int|null
	 */
	private static function parseTemplateDateTimeToTimestamp($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		$formats = array(
			'Y-m-d\TH:i:s',
			'Y-m-d\TH:i',
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd-m-Y H:i:s',
			'd-m-Y H:i',
			'YmdHis',
			'YmdHi',
			'Y-m-d',
			'd/m/Y',
			'd-m-Y',
			'Ymd',
		);

		foreach ($formats as $format) {
			$date = DateTime::createFromFormat($format, $value);
			if ($date instanceof DateTime) {
				$errors = DateTime::getLastErrors();
				if ($errors === false || (empty($errors['warning_count']) && empty($errors['error_count']))) {
					return (int) $date->getTimestamp();
				}
			}
		}

		$fallback = strtotime($value);
		if ($fallback !== false) {
			return (int) $fallback;
		}

		return null;
	}

	/**
	 * Format a timestamp using a date pattern.
	 *
	 * @param int    $timestamp Unix timestamp
	 * @param string $format    Date format
	 * @return string
	 */
	private static function formatTimestampWithPattern($timestamp, $format)
	{
		$timestamp = (int) $timestamp;
		$format = trim((string) $format);
		if ($timestamp <= 0) {
			return '';
		}
		if ($format === '') {
			return date('d/m/Y', $timestamp);
		}

		return date($format, $timestamp);
	}

	/**
	 * Convert a resolved context value into a control-friendly input value.
	 *
	 * @param string $value Resolved value
	 * @param array  $meta  Field metadata
	 * @return string
	 */
	private static function formatTemplateFieldValueForInput($value, $meta = array())
	{
		$type = (!empty($meta['type']) ? self::normalizeTemplateInputType($meta['type']) : 'text');
		$value = (string) $value;
		if ($value === '') {
			return '';
		}
		if ($type === 'image') {
			return self::sanitizeTemplateAssetReference($value);
		}

		if ($type === 'number') {
			return self::sanitizeTemplateNumericValue($value);
		}
		if ($type === 'date') {
			$timestamp = self::parseTemplateDateTimeToTimestamp($value);
			return ($timestamp !== null ? date('Y-m-d', $timestamp) : '');
		}
		if ($type === 'datetime') {
			$timestamp = self::parseTemplateDateTimeToTimestamp($value);
			return ($timestamp !== null ? date('Y-m-d\TH:i', $timestamp) : '');
		}

		return $value;
	}

	/**
	 * Build a human-readable summary for a Dolibarr label format.
	 *
	 * @param array     $detail      Format details
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	public static function buildFormatSummaryText($detail, $outputlangs)
	{
		if (empty($detail) || !is_array($detail)) {
			return '';
		}

		$pageLabel = (
			isset($detail['paper_size']) && $detail['paper_size'] === 'custom'
			? price2num($detail['custom_x']) . 'x' . price2num($detail['custom_y']) . ' ' . $detail['metric']
			: $detail['paper_size']
		);

		return $outputlangs->trans(
			'KREAPRODUCTS_LABELS_FORMAT_SUMMARY',
			$pageLabel,
			price2num($detail['width']) . 'x' . price2num($detail['height']) . ' ' . $detail['metric'],
			((int) $detail['nx']) . 'x' . ((int) $detail['ny'])
		);
	}

	/**
	 * Build the standard preview dataset used by the page-side SVG renderer.
	 *
	 * @param DoliDB    $db          Database handler
	 * @param Product   $product     Product object
	 * @param Translate $outputlangs Output language
	 * @return array
	 */
	public static function buildStandardPreviewData($db, $product, $outputlangs)
	{
		global $conf;

		$currencyCode = (!empty($conf->currency) ? $conf->currency : '');
		$barcode = self::resolveBarcode($db, $product);

		return array(
			'fields' => array(
				'ref' => array(
					'type' => 'ref',
					'text' => self::cleanText($product->ref),
				),
				'label' => array(
					'type' => 'label',
					'text' => self::cleanText($product->label),
				),
				'price_ht' => array(
					'type' => 'price',
					'text' => $outputlangs->trans('KREAPRODUCTS_LABELS_PRICE_HT') . ': ' . price($product->price, 0, $outputlangs, 1, -1, -1, $currencyCode),
				),
				'price_ttc' => array(
					'type' => 'price',
					'text' => $outputlangs->trans('KREAPRODUCTS_LABELS_PRICE_TTC') . ': ' . price($product->price_ttc, 0, $outputlangs, 1, -1, -1, $currencyCode),
				),
			),
			'barcode' => array(
				'value' => (!empty($barcode['value']) ? $barcode['value'] : ''),
				'encoding' => (!empty($barcode['encoding']) ? $barcode['encoding'] : ''),
				'is2d' => !empty($barcode['is2d']),
			),
		);
	}

	/**
	 * Generate and save product label PDF.
	 *
	 * @param DoliDB    $db                 Database handler
	 * @param Product   $product            Product object
	 * @param int       $entityId           Current entity id
	 * @param string    $formatCode         Label format code
	 * @param array     $selectedFields     Selected field codes
	 * @param int       $quantity           Number of labels
	 * @param Translate $outputlangs        Output language
	 * @param string    $templateCode       Selected template code
	 * @param bool      $useTemplateSize    Use template size as output format
	 * @param array     $templateInputValues User-provided template field values
	 * @return array
	 */
	public static function generateProductLabels($db, $product, $entityId, $formatCode, $selectedFields, $quantity, $outputlangs, $templateCode = '', $useTemplateSize = false, $templateInputValues = array())
	{
		global $langs;

		try {
			$quantity = max(1, (int) $quantity);
			$useTemplateSize = (bool) $useTemplateSize;
			$template = array();
			if ($templateCode !== '') {
				$template = self::loadLabelTemplate($templateCode, $entityId);
			}
			$useTemplateRenderer = (!empty($template['pages']) && is_array($template['pages']));

			$effectiveFormatCode = self::resolveOutputFormatCode($db, $formatCode, $templateCode, $useTemplateSize, $langs, $entityId);
			if ($effectiveFormatCode === '') {
				return array(
					'error' => (
						$useTemplateSize
						? $langs->trans('KREAPRODUCTS_LABELS_ERROR_TEMPLATE_BAD_FORMAT')
						: $langs->trans('KREAPRODUCTS_LABELS_ERROR_BAD_FORMAT')
					),
				);
			}

			if ($useTemplateRenderer) {
				$templateSourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);
				$templateInputValues = self::sanitizeTemplateInputValues($templateInputValues, array_keys($templateSourceMeta), $templateSourceMeta);
				$records = self::buildTemplateRecords($product, $template, $quantity, $outputlangs, $templateInputValues);
				if (empty($records)) {
					return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_GENERATION_FAILED'));
				}
			} else {
				$selectedFields = self::sanitizeSelectedFields($selectedFields);
				if (empty($selectedFields)) {
					return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_NO_FIELDS'));
				}

				$records = array();
				$record = self::buildLabelRecord($db, $product, $selectedFields, $outputlangs);
				for ($i = 0; $i < $quantity; $i++) {
					$records[] = $record;
				}
			}

			$outputDir = self::getDocumentDir($entityId, $product->id);
			$filename = self::buildFilename($product, $effectiveFormatCode);

			self::loadPdfGeneratorClass($db);
			$generator = new KreaProductsProductLabelPdf($db);
			$result = $generator->write_file($records, $outputlangs, $effectiveFormatCode, $outputDir, $filename);
			if ($result <= 0) {
				$error = (!empty($generator->error) ? $generator->error : $langs->trans('ErrorFailToGenerateFile', $filename));
				return array('error' => $error);
			}

			return array(
				'filename' => $filename,
				'fullpath' => $generator->result['fullpath'],
				'modulesubdir' => self::getDocumentModuleSubdir($entityId, $product->id),
				'relativefile' => self::getDocumentModuleSubdir($entityId, $product->id) . '/' . $filename,
			);
		} catch (Throwable $e) {
			dol_syslog(__METHOD__ . ' failed: ' . $e->getMessage(), LOG_ERR);
			return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_GENERATION_FAILED'));
		}
	}

	/**
	 * Generate TSPL content for product labels.
	 *
	 * This is an additive feature and does not affect existing PDF generation.
	 * It reuses the same record-building flow (standard fields or template blocks)
	 * and serializes supported block types to TSPL commands.
	 *
	 * @param DoliDB    $db                 Database handler
	 * @param Product   $product            Product object
	 * @param int       $entityId           Current entity id
	 * @param array     $selectedFields     Selected field codes
	 * @param int       $quantity           Number of labels
	 * @param Translate $outputlangs        Output language
	 * @param string    $templateCode       Selected template code
	 * @param array     $templateInputValues User-provided template field values
	 * @param array     $tsplOptions        Optional TSPL overrides
	 * @return array
	 */
	public static function generateProductLabelsTspl($db, $product, $entityId, $selectedFields, $quantity, $outputlangs, $templateCode = '', $templateInputValues = array(), $tsplOptions = array())
	{
		global $langs;

		try {
			$quantity = max(1, (int) $quantity);
			$template = array();
			if ($templateCode !== '') {
				$template = self::loadLabelTemplate($templateCode, $entityId);
			}
			$useTemplateRenderer = (!empty($template['pages']) && is_array($template['pages']));

			if ($useTemplateRenderer) {
				$templateSourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);
				$templateInputValues = self::sanitizeTemplateInputValues($templateInputValues, array_keys($templateSourceMeta), $templateSourceMeta);
				$records = self::buildTemplateRecords($product, $template, $quantity, $outputlangs, $templateInputValues);
				if (empty($records)) {
					return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_GENERATION_FAILED'));
				}
			} else {
				$selectedFields = self::sanitizeSelectedFields($selectedFields);
				if (empty($selectedFields)) {
					return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_NO_FIELDS'));
				}

				$records = array();
				$record = self::buildLabelRecord($db, $product, $selectedFields, $outputlangs);
				for ($i = 0; $i < $quantity; $i++) {
					$records[] = $record;
				}
			}

			$content = self::generateTsplContent($records, $tsplOptions);
			if ($content === '') {
				return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_GENERATION_FAILED'));
			}

			return array(
				'filename' => self::buildTsplFilename($product),
				'content' => $content,
			);
		} catch (Throwable $e) {
			dol_syslog(__METHOD__ . ' failed: ' . $e->getMessage(), LOG_ERR);
			return array('error' => $langs->trans('KREAPRODUCTS_LABELS_ERROR_GENERATION_FAILED'));
		}
	}

	/**
	 * Serialize records into raw TSPL commands.
	 *
	 * Supported shapes:
	 * - Standard records from buildLabelRecord (lines + barcode)
	 * - Template records from buildTemplateRecords (template_blocks)
	 *
	 * @param array $records     Label records
	 * @param array $tsplOptions Optional TSPL settings
	 * @return string
	 */
	public static function generateTsplContent($records, $tsplOptions = array())
	{
		if (empty($records) || !is_array($records)) {
			return '';
		}

		$labelWidthMm = max(10.0, (float) (isset($tsplOptions['label_width_mm']) ? $tsplOptions['label_width_mm'] : 50.0));
		$labelHeightMm = max(10.0, (float) (isset($tsplOptions['label_height_mm']) ? $tsplOptions['label_height_mm'] : 30.0));
		$gapMm = max(0.0, (float) (isset($tsplOptions['gap_mm']) ? $tsplOptions['gap_mm'] : 3.0));
		$direction = ((int) (isset($tsplOptions['direction']) ? $tsplOptions['direction'] : 0) > 0 ? 1 : 0);
		$dpi = (int) (isset($tsplOptions['dpi']) ? $tsplOptions['dpi'] : 203);
		if ($dpi <= 0) {
			$dpi = 203;
		}
		$dotsPerMm = ((float) $dpi) / 25.4;
		if ($dotsPerMm <= 0) {
			$dotsPerMm = 8.0;
		}

		$baseX = max(6, self::toTsplDots(1.2, $dotsPerMm));
		$baseY = max(6, self::toTsplDots(1.2, $dotsPerMm));
		$lineStepDots = max(20, self::toTsplDots(3.2, $dotsPerMm));
		$barcodeHeightDots = max(45, self::toTsplDots(10.0, $dotsPerMm));

		$commands = array();
		foreach ($records as $record) {
			if (!is_array($record)) {
				continue;
			}

			$currentWidthMm = $labelWidthMm;
			$currentHeightMm = $labelHeightMm;
			if (!empty($record['template_width_mm'])) {
				$currentWidthMm = max(10.0, (float) $record['template_width_mm']);
			}
			if (!empty($record['template_height_mm'])) {
				$currentHeightMm = max(10.0, (float) $record['template_height_mm']);
			}

			$commands[] = 'SIZE ' . self::formatTsplNumber($currentWidthMm) . ' mm,' . self::formatTsplNumber($currentHeightMm) . ' mm';
			$commands[] = 'GAP ' . self::formatTsplNumber($gapMm) . ' mm,0 mm';
			$commands[] = 'DIRECTION ' . $direction;
			$commands[] = 'REFERENCE 0,0';
			$commands[] = 'CLS';

			if (!empty($record['template_blocks']) && is_array($record['template_blocks'])) {
				$labelHeightDots = self::toTsplDots($currentHeightMm, $dotsPerMm);
				self::appendTemplateBlocksTsplCommands($commands, $record['template_blocks'], $dotsPerMm, $baseX, $baseY, $labelHeightDots);
			} else {
				self::appendStandardRecordTsplCommands($commands, $record, $baseX, $baseY, $lineStepDots, $barcodeHeightDots);
			}

			$commands[] = 'PRINT 1';
			$commands[] = 'CLS';
		}

		if (empty($commands)) {
			return '';
		}

		return implode("\r\n", $commands) . "\r\n";
	}

	/**
	 * Delete a generated label PDF after validating the path.
	 *
	 * @param int    $entityId      Current entity id
	 * @param int    $productId     Product id
	 * @param string $relativeFile  Relative file path under modulepart
	 * @return bool
	 */
	public static function deleteGeneratedFile($entityId, $productId, $relativeFile)
	{
		$relativeFile = str_replace('\\', '/', trim((string) $relativeFile));
		if ($relativeFile === '' || strpos($relativeFile, '..') !== false) {
			return false;
		}

		$expectedPrefix = self::getDocumentModuleSubdir($entityId, $productId) . '/';
		if (strpos($relativeFile, $expectedPrefix) !== 0) {
			return false;
		}

		$baseDir = self::getDocumentDir($entityId, $productId);
		$fullPath = DOL_DATA_ROOT . '/kreaproducts/' . $relativeFile;
		$realBase = realpath($baseDir);
		$realFile = realpath($fullPath);
		if ($realBase === false || $realFile === false) {
			return false;
		}

		if (strpos($realFile, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
			return false;
		}

		return self::deleteFileCompat($realFile);
	}

	/**
	 * Delete one file using Dolibarr helper when available.
	 *
	 * Some API contexts do not preload files.lib.php. In that case, fallback
	 * to unlink to keep cleanup non-fatal for endpoint execution.
	 *
	 * @param string $filePath Absolute file path
	 * @return bool
	 */
	private static function deleteFileCompat($filePath)
	{
		$filePath = (string) $filePath;
		if ($filePath === '') {
			return false;
		}

		if (function_exists('dol_delete_file')) {
			return (bool) dol_delete_file($filePath, 0, 0, 0);
		}

		return @unlink($filePath);
	}

	/**
	 * Load the PDF generator class only when needed.
	 *
	 * The Dolibarr sticker generator pulls `format_cards.lib.php` at include time and
	 * expects a live database handler in the current include scope.
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	private static function loadPdfGeneratorClass($db)
	{
		if (class_exists('KreaProductsProductLabelPdf', false)) {
			return;
		}

		require_once __DIR__ . '/KreaProductsProductLabelPdf.class.php';
	}

	/**
	 * Build a deterministic TSPL filename for API responses.
	 *
	 * @param Product $product Product object
	 * @return string
	 */
	private static function buildTsplFilename($product)
	{
		$safeRef = self::sanitizeFilenameFragment(!empty($product->ref) ? (string) $product->ref : '');
		if ($safeRef === '') {
			$safeRef = (string) (!empty($product->id) ? (int) $product->id : 'label');
		}

		return 'labels-' . $safeRef . '-' . dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '.tspl';
	}

	/**
	 * Append TSPL commands for one standard (non-template) record.
	 *
	 * @param array $commands           TSPL command lines
	 * @param array $record             Label record
	 * @param int   $baseX              Initial X coordinate (dots)
	 * @param int   $baseY              Initial Y coordinate (dots)
	 * @param int   $lineStepDots       Text line step (dots)
	 * @param int   $barcodeHeightDots  Barcode height (dots)
	 * @return void
	 */
	private static function appendStandardRecordTsplCommands(&$commands, $record, $baseX, $baseY, $lineStepDots, $barcodeHeightDots)
	{
		$y = (int) $baseY;
		$printedAnyText = false;

		if (!empty($record['lines']) && is_array($record['lines'])) {
			foreach ($record['lines'] as $line) {
				$text = '';
				if (is_array($line)) {
					$text = (!empty($line['text']) ? (string) $line['text'] : '');
				} else {
					$text = (string) $line;
				}

				$text = self::sanitizeTsplText($text);
				if ($text === '') {
					continue;
				}

				$commands[] = 'TEXT ' . ((int) $baseX) . ',' . ((int) $y) . ',"2",0,1,1,"' . self::escapeTsplText($text) . '"';
				$y += (int) $lineStepDots;
				$printedAnyText = true;
			}
		}

		$barcodeValue = self::sanitizeTsplText(!empty($record['barcode_value']) ? (string) $record['barcode_value'] : '');
		if ($barcodeValue === '') {
			return;
		}

		if ($printedAnyText) {
			$y += 6;
		}

		$encoding = self::mapBarcodeEncodingToTspl(!empty($record['barcode_encoding']) ? (string) $record['barcode_encoding'] : '');
		$is2d = !empty($record['barcode_is_2d']);
		if ($is2d) {
			$commands[] = 'QRCODE ' . ((int) $baseX) . ',' . ((int) $y) . ',L,4,A,0,"' . self::escapeTsplText($barcodeValue) . '"';
			return;
		}

		$commands[] = 'BARCODE ' . ((int) $baseX) . ',' . ((int) $y) . ',"' . $encoding . '",' . ((int) $barcodeHeightDots) . ',1,0,2,2,"' . self::escapeTsplText($barcodeValue) . '"';
	}

	/**
	 * Append TSPL commands for resolved template blocks.
	 *
	 * @param array $commands TSPL command lines
	 * @param array $blocks   Resolved template blocks
	 * @param float $dotsPerMm Printer density in dots per millimeter
	 * @param int   $baseX   Initial X coordinate fallback (dots)
	 * @param int   $baseY   Initial Y coordinate fallback (dots)
	 * @param int   $labelHeightDots Label height in dots
	 * @return void
	 */
	private static function appendTemplateBlocksTsplCommands(&$commands, $blocks, $dotsPerMm, $baseX, $baseY, $labelHeightDots = 0)
	{
		$flowSectionNextY = null;
		foreach ($blocks as $block) {
			if (!is_array($block) || empty($block['type'])) {
				continue;
			}

			$type = strtolower(trim((string) $block['type']));
			$value = self::sanitizeTsplText(!empty($block['value']) ? (string) $block['value'] : '');

			$x = (!empty($block['x_mm']) ? self::toTsplDots((float) $block['x_mm'], $dotsPerMm) : (int) $baseX);
			$y = (!empty($block['y_mm']) ? self::toTsplDots((float) $block['y_mm'], $dotsPerMm) : (int) $baseY);

			if ($type === 'text') {
				if ($value === '') {
					continue;
				}

				$fontSizePt = 8.0;
				$fontWeight = '';
				$align = 'left';
				if (!empty($block['style']) && is_array($block['style']) && !empty($block['style']['font_size_pt'])) {
					$fontSizePt = max(4.0, (float) $block['style']['font_size_pt']);
				}
				if (!empty($block['style']) && is_array($block['style']) && !empty($block['style']['font_weight'])) {
					$fontWeight = (string) $block['style']['font_weight'];
				}
				if (!empty($block['style']) && is_array($block['style']) && !empty($block['style']['align'])) {
					$align = strtolower(trim((string) $block['style']['align']));
				}
				$fontSpec = self::resolveTsplTextFontSpec($fontSizePt, $fontWeight);
				$lineHeightDots = max(12, (int) $fontSpec['line_height']);
				$blockWidthDots = (!empty($block['w_mm']) ? max(1, self::toTsplDots((float) $block['w_mm'], $dotsPerMm)) : 0);
				$blockHeightDots = (!empty($block['h_mm']) ? max(1, self::toTsplDots((float) $block['h_mm'], $dotsPerMm)) : 0);
				$isFlowSectionBlock = self::isTsplFlowSectionBlock($block);
				if ($isFlowSectionBlock) {
					if ($flowSectionNextY !== null) {
						$y = (int) $flowSectionNextY;
					} else {
						$flowSectionNextY = (int) $y;
					}
				}

				$maxLines = 0;
				if ($isFlowSectionBlock) {
					$availableDots = ($labelHeightDots > 0 ? max(0, ((int) $labelHeightDots) - (int) $y) : 0);
					if ($availableDots > 0) {
						$maxLines = max(1, (int) floor($availableDots / max(1, $lineHeightDots)));
					}
				} elseif (!empty($block['h_mm'])) {
					$maxLines = max(1, (int) floor(self::toTsplDots((float) $block['h_mm'], $dotsPerMm) / max(1, $lineHeightDots)));
				}
				$maxCharsPerLine = 0;
				if ($blockWidthDots > 0) {
					$charWidthDots = max(4.0, (float) $fontSpec['char_width'] * 1.18);
					$maxCharsPerLine = max(4, (int) floor($blockWidthDots / $charWidthDots));
				}
				$lines = self::wrapTsplTextLines($value, $maxCharsPerLine, $maxLines, true);
				if (empty($lines)) {
					continue;
				}

				$lineOffset = 0;
				if ($align === 'center' && count($lines) === 1 && $blockHeightDots > 0) {
					$glyphHeightDots = max(1, (int) (!empty($fontSpec['char_height']) ? $fontSpec['char_height'] : $lineHeightDots));
					if ($blockHeightDots > $glyphHeightDots) {
						$lineOffset = (int) floor(((float) ($blockHeightDots - $glyphHeightDots)) / 2.0);
					}
				}
				foreach ($lines as $lineText) {
					$lineText = self::sanitizeTsplText($lineText);
					if ($lineText === '') {
						continue;
					}

					$drawX = (int) $x;
					if ($blockWidthDots > 0) {
						$estimatedLineWidth = (int) ceil(strlen($lineText) * (float) $fontSpec['char_width'] * 1.05);
						if ($align === 'center' && $estimatedLineWidth < $blockWidthDots) {
							$drawX += (int) floor(($blockWidthDots - $estimatedLineWidth) / 2);
						} elseif ($align === 'right' && $estimatedLineWidth < $blockWidthDots) {
							$drawX += ($blockWidthDots - $estimatedLineWidth);
						}
					}

					$commands[] = 'TEXT ' . $drawX . ',' . ((int) ($y + $lineOffset)) . ',"' . $fontSpec['font'] . '",0,' . ((int) $fontSpec['xmul']) . ',' . ((int) $fontSpec['ymul']) . ',"' . self::escapeTsplText($lineText) . '"';
					$lineOffset += $lineHeightDots;
				}
				if ($isFlowSectionBlock) {
					$flowSectionNextY = (int) ($y + ($lineHeightDots * count($lines)) + $lineHeightDots);
				}
				continue;
			}

			if ($type === 'rect') {
				$wDots = max(1, (!empty($block['w_mm']) ? self::toTsplDots((float) $block['w_mm'], $dotsPerMm) : 1));
				$hDots = max(1, (!empty($block['h_mm']) ? self::toTsplDots((float) $block['h_mm'], $dotsPerMm) : 1));
				$x2 = $x + $wDots;
				$y2 = $y + $hDots;
				$thickness = 1;
				if (!empty($block['style']) && is_array($block['style']) && !empty($block['style']['stroke_width_mm'])) {
					$thickness = max(1, self::toTsplDots((float) $block['style']['stroke_width_mm'], $dotsPerMm));
				}
				$commands[] = 'BOX ' . ((int) $x) . ',' . ((int) $y) . ',' . ((int) $x2) . ',' . ((int) $y2) . ',' . ((int) $thickness);
				continue;
			}

			if ($type === 'image') {
				if ($value === '') {
					continue;
				}

				$bitmapSegment = self::buildTsplBitmapCommandSegmentFromTemplateBlock($block, $x, $y, $dotsPerMm, $value);
				if ($bitmapSegment !== '') {
					$commands[] = $bitmapSegment;
				}
				continue;
			}

			if ($type !== 'barcode') {
				continue;
			}
			if ($value === '') {
				continue;
			}

			$symbology = (!empty($block['symbology']) ? (string) $block['symbology'] : '');
			$showHuman = (!empty($block['show_human_readable']) ? 1 : 0);
			$widthDots = (!empty($block['w_mm']) ? self::toTsplDots((float) $block['w_mm'], $dotsPerMm) : 0);
			$heightDots = (!empty($block['h_mm']) ? self::toTsplDots((float) $block['h_mm'], $dotsPerMm) : 0);
			if ($heightDots <= 0) {
				$heightDots = 50;
			}

			if (self::isQrCodeSymbology($symbology)) {
				$qrCell = 4;
				if ($widthDots > 0 && $heightDots > 0) {
					$qrCell = max(2, min(8, (int) floor((float) min($widthDots, $heightDots) / 30.0)));
				}
				$commands[] = 'QRCODE ' . ((int) $x) . ',' . ((int) $y) . ',L,' . $qrCell . ',A,0,"' . self::escapeTsplText($value) . '"';
				continue;
			}

			if ($widthDots > 0 && $heightDots > 0) {
				$bitmap = self::buildTsplBitmapDataFromLinearBarcode($value, $symbology, $widthDots, $heightDots);
				if (!empty($bitmap['data']) && !empty($bitmap['width_bytes']) && !empty($bitmap['height'])) {
					$commands[] = 'BITMAP ' . ((int) $x) . ',' . ((int) $y) . ',' . ((int) $bitmap['width_bytes']) . ',' . ((int) $bitmap['height']) . ',0,' . $bitmap['data'];
					continue;
				}
			}

			$barcodeType = self::mapBarcodeEncodingToTspl($symbology);
			$commands[] = 'BARCODE ' . ((int) $x) . ',' . ((int) $y) . ',"' . $barcodeType . '",' . ((int) $heightDots) . ',' . $showHuman . ',0,2,2,"' . self::escapeTsplText($value) . '"';
		}
	}

	/**
	 * Build packed monochrome bitmap bytes from a linear barcode value.
	 *
	 * @param string $value Barcode value
	 * @param string $symbology Barcode symbology
	 * @param int    $targetWidthDots Target width in dots
	 * @param int    $targetHeightDots Target height in dots
	 * @return array{width_bytes:int,height:int,data:string}
	 */
	private static function buildTsplBitmapDataFromLinearBarcode($value, $symbology, $targetWidthDots, $targetHeightDots)
	{
		$value = self::sanitizeTsplText($value);
		$targetWidthDots = max(1, (int) $targetWidthDots);
		$targetHeightDots = max(1, (int) $targetHeightDots);
		if ($value === '' || $targetWidthDots <= 0 || $targetHeightDots <= 0) {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$encoding = self::mapTemplateBarcodeSymbologyForPreview($symbology);
		$barcodeArray = self::buildPreviewBarcodeArrayFromTcpdf($value, $encoding);
		if (empty($barcodeArray['bcode']) || empty($barcodeArray['maxw'])) {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$maxw = max(1.0, (float) $barcodeArray['maxw']);
		$unitWidth = ((float) $targetWidthDots) / $maxw;
		$intervals = array();
		$currentX = 0.0;
		foreach ($barcodeArray['bcode'] as $segment) {
			$segmentWidth = max(0.01, ((float) (!empty($segment['w']) ? $segment['w'] : 0.0)) * $unitWidth);
			if (!empty($segment['t'])) {
				$start = (int) floor($currentX);
				$end = (int) ceil($currentX + $segmentWidth) - 1;
				if ($start < $targetWidthDots && $end >= 0) {
					$start = max(0, $start);
					$end = min($targetWidthDots - 1, $end);
					if ($end >= $start) {
						$intervals[] = array($start, $end);
					}
				}
			}
			$currentX += $segmentWidth;
		}
		if (empty($intervals)) {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$minBlack = $targetWidthDots - 1;
		$maxBlack = 0;
		foreach ($intervals as $interval) {
			$minBlack = min($minBlack, (int) $interval[0]);
			$maxBlack = max($maxBlack, (int) $interval[1]);
		}
		if ($maxBlack >= $minBlack) {
			$effectiveWidth = max(1, ($maxBlack - $minBlack + 1));
			if ($effectiveWidth < $targetWidthDots) {
				$scale = ((float) $targetWidthDots) / ((float) $effectiveWidth);
				$stretchedIntervals = array();
				foreach ($intervals as $interval) {
					$start = (int) floor((((int) $interval[0]) - $minBlack) * $scale);
					$end = (int) ceil((((int) $interval[1]) - $minBlack + 1) * $scale) - 1;
					$start = max(0, min($targetWidthDots - 1, $start));
					$end = max(0, min($targetWidthDots - 1, $end));
					if ($end >= $start) {
						$stretchedIntervals[] = array($start, $end);
					}
				}
				if (!empty($stretchedIntervals)) {
					$intervals = $stretchedIntervals;
				}
			}
		}

		$widthBytes = (int) ceil(((float) $targetWidthDots) / 8.0);
		$rowBytes = array_fill(0, $widthBytes, 0);
		foreach ($intervals as $interval) {
			$start = (int) $interval[0];
			$end = (int) $interval[1];
			for ($x = $start; $x <= $end; $x++) {
				$byteIndex = (int) floor(((float) $x) / 8.0);
				$bit = 7 - ($x % 8);
				$rowBytes[$byteIndex] |= (1 << $bit);
			}
		}

		$rowBinary = '';
		foreach ($rowBytes as $rowByte) {
			$rowByte = (~((int) $rowByte)) & 0xFF;
			$rowBinary .= chr($rowByte);
		}

		return array(
			'width_bytes' => $widthBytes,
			'height' => $targetHeightDots,
			'data' => str_repeat($rowBinary, $targetHeightDots),
		);
	}

	/**
	 * Build a TSPL BITMAP command segment for one template image block.
	 *
	 * @param array  $block      Template block
	 * @param int    $x          X position in dots
	 * @param int    $y          Y position in dots
	 * @param float  $dotsPerMm  Printer density
	 * @param string $assetValue Resolved asset reference
	 * @return string
	 */
	private static function buildTsplBitmapCommandSegmentFromTemplateBlock($block, $x, $y, $dotsPerMm, $assetValue)
	{
		$targetWidthDots = max(1, (!empty($block['w_mm']) ? self::toTsplDots((float) $block['w_mm'], $dotsPerMm) : 1));
		$targetHeightDots = max(1, (!empty($block['h_mm']) ? self::toTsplDots((float) $block['h_mm'], $dotsPerMm) : 1));
		$bitmap = self::buildTsplBitmapDataFromAssetReference($assetValue, $targetWidthDots, $targetHeightDots);
		if (empty($bitmap['data']) || empty($bitmap['width_bytes']) || empty($bitmap['height'])) {
			return '';
		}

		return 'BITMAP ' . ((int) $x) . ',' . ((int) $y) . ',' . ((int) $bitmap['width_bytes']) . ',' . ((int) $bitmap['height']) . ',0,' . $bitmap['data'];
	}

	/**
	 * Build packed monochrome bitmap bytes from one template asset reference.
	 *
	 * @param string $assetReference Asset reference/path
	 * @param int    $targetWidthDots  Target width in dots
	 * @param int    $targetHeightDots Target height in dots
	 * @return array{width_bytes:int,height:int,data:string}
	 */
	private static function buildTsplBitmapDataFromAssetReference($assetReference, $targetWidthDots, $targetHeightDots)
	{
		$assetReference = self::sanitizeTemplateAssetReference($assetReference);
		if ($assetReference === '') {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$fullPath = self::resolveTemplateAssetLocalPath($assetReference);
		if ($fullPath === '') {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		return self::buildTsplBitmapDataFromImageFile($fullPath, $targetWidthDots, $targetHeightDots);
	}

	/**
	 * Build packed monochrome bitmap bytes from one local image file.
	 *
	 * @param string $fullPath Absolute image path
	 * @param int    $targetWidthDots  Target width in dots
	 * @param int    $targetHeightDots Target height in dots
	 * @return array{width_bytes:int,height:int,data:string}
	 */
	private static function buildTsplBitmapDataFromImageFile($fullPath, $targetWidthDots, $targetHeightDots)
	{
		$targetWidthDots = max(1, (int) $targetWidthDots);
		$targetHeightDots = max(1, (int) $targetHeightDots);
		$image = self::loadImageResourceForTspl($fullPath);
		if (!is_resource($image) && !(is_object($image) && get_class($image) === 'GdImage')) {
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$sourceWidth = (int) @imagesx($image);
		$sourceHeight = (int) @imagesy($image);
		if ($sourceWidth <= 0 || $sourceHeight <= 0) {
			@imagedestroy($image);
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$canvas = @imagecreatetruecolor($targetWidthDots, $targetHeightDots);
		if (!is_resource($canvas) && !(is_object($canvas) && get_class($canvas) === 'GdImage')) {
			@imagedestroy($image);
			return array('width_bytes' => 0, 'height' => 0, 'data' => '');
		}

		$white = imagecolorallocate($canvas, 255, 255, 255);
		imagefilledrectangle($canvas, 0, 0, $targetWidthDots, $targetHeightDots, $white);
		@imagealphablending($canvas, true);
		@imagesavealpha($canvas, false);
		@imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidthDots, $targetHeightDots, $sourceWidth, $sourceHeight);
		@imagedestroy($image);

		$widthBytes = (int) ceil(((float) $targetWidthDots) / 8.0);
		$data = '';
		for ($y = 0; $y < $targetHeightDots; $y++) {
			for ($byteIndex = 0; $byteIndex < $widthBytes; $byteIndex++) {
				$byte = 0;
				for ($bit = 0; $bit < 8; $bit++) {
					$x = ($byteIndex * 8) + $bit;
					if ($x >= $targetWidthDots) {
						continue;
					}

					$rgba = imagecolorat($canvas, $x, $y);
					$alpha = (($rgba & 0x7F000000) >> 24);
					if ($alpha >= 120) {
						continue;
					}
					$r = (($rgba >> 16) & 0xFF);
					$g = (($rgba >> 8) & 0xFF);
					$b = ($rgba & 0xFF);
					$luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
					if ($luminance < 180) {
						$byte |= (1 << (7 - $bit));
					}
				}
				// XP-365B expects BITMAP bits with inverted polarity versus the
				// straightforward black=1 map used above.
				$byte = (~$byte) & 0xFF;
				$data .= chr($byte);
			}
		}
		@imagedestroy($canvas);

		return array(
			'width_bytes' => $widthBytes,
			'height' => $targetHeightDots,
			'data' => $data,
		);
	}

	/**
	 * Load a local image into a GD resource for TSPL bitmap conversion.
	 *
	 * @param string $fullPath Absolute image path
	 * @return mixed
	 */
	private static function loadImageResourceForTspl($fullPath)
	{
		$fullPath = (string) $fullPath;
		if ($fullPath === '' || !is_file($fullPath) || !is_readable($fullPath)) {
			return null;
		}
		if (!function_exists('imagecreatefromstring')) {
			return null;
		}

		$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
		if ($ext === 'svg') {
			$pngBlob = self::convertSvgToPngBinaryForTspl($fullPath);
			if ($pngBlob === '') {
				return null;
			}
			return @imagecreatefromstring($pngBlob);
		}

		if (in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp'), true)) {
			$binary = @file_get_contents($fullPath);
			if ($binary === false || $binary === '') {
				return null;
			}
			return @imagecreatefromstring($binary);
		}

		return null;
	}

	/**
	 * Convert one SVG file to PNG binary for TSPL bitmap conversion.
	 *
	 * @param string $svgPath Absolute SVG file path
	 * @return string
	 */
	private static function convertSvgToPngBinaryForTspl($svgPath)
	{
		$svgPath = (string) $svgPath;
		if ($svgPath === '' || !is_file($svgPath) || !is_readable($svgPath)) {
			return '';
		}

		// Prefer pre-rendered PNG siblings when shipped with the module.
		$pngSiblingPath = preg_replace('/\.svg$/i', '.png', $svgPath);
		if (is_string($pngSiblingPath) && $pngSiblingPath !== '' && is_file($pngSiblingPath) && is_readable($pngSiblingPath)) {
			$pngSiblingBinary = @file_get_contents($pngSiblingPath);
			if ($pngSiblingBinary !== false && $pngSiblingBinary !== '') {
				return $pngSiblingBinary;
			}
		}

		// Prefer Imagick when available.
		if (class_exists('Imagick')) {
			try {
				$image = new Imagick();
				$image->setBackgroundColor(new ImagickPixel('white'));
				$image->readImage($svgPath);
				$image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
				$image->setImageFormat('png');
				$blob = (string) $image->getImageBlob();
				$image->clear();
				$image->destroy();
				if ($blob !== '') {
					return $blob;
				}
			} catch (Throwable $e) {
				dol_syslog(__METHOD__ . ' imagick svg conversion failed: ' . $e->getMessage(), LOG_DEBUG);
			}
		}

		// Fallback to rsvg-convert when available.
		$rsvgCmd = trim((string) @shell_exec('command -v rsvg-convert 2>/dev/null'));
		if ($rsvgCmd !== '') {
			$command = escapeshellcmd($rsvgCmd) . ' -f png ' . escapeshellarg($svgPath) . ' 2>/dev/null';
			$blob = (string) @shell_exec($command);
			if ($blob !== '') {
				return $blob;
			}
		}

		return '';
	}

	/**
	 * Wrap text for TSPL text blocks.
	 *
	 * @param string $text Source text
	 * @param int    $maxCharsPerLine Max chars per line (0 = no wrap)
	 * @param int    $maxLines Max lines (0 = no limit)
	 * @return array
	 */
	private static function wrapTsplTextLines($text, $maxCharsPerLine, $maxLines, $truncate = false)
	{
		$text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
		$parts = preg_split('/\n/', $text);
		if (!is_array($parts)) {
			$parts = array($text);
		}

		$lines = array();
		foreach ($parts as $part) {
			$part = trim((string) $part);
			if ($part === '') {
				continue;
			}

			if ($maxCharsPerLine > 0) {
				$wrapped = wordwrap($part, $maxCharsPerLine, "\n", true);
				$wrappedParts = preg_split('/\n/', (string) $wrapped);
				if (is_array($wrappedParts)) {
					foreach ($wrappedParts as $wrappedLine) {
						$wrappedLine = trim((string) $wrappedLine);
						if ($wrappedLine !== '') {
							$lines[] = $wrappedLine;
						}
					}
				}
			} else {
				$lines[] = $part;
			}
		}

		if ($maxLines > 0 && count($lines) > $maxLines) {
			$lines = array_slice($lines, 0, $maxLines);
			if ($truncate && !empty($lines)) {
				$lastIndex = count($lines) - 1;
				$line = (string) $lines[$lastIndex];
				if (strlen($line) > 3) {
					$line = rtrim(substr($line, 0, strlen($line) - 1));
				}
				$lines[$lastIndex] = rtrim($line) . '...';
			}
		}

		return $lines;
	}

	/**
	 * Convert millimeters to TSPL dots.
	 *
	 * @param float $mm        Length in millimeters
	 * @param float $dotsPerMm Printer density in dots per millimeter
	 * @return int
	 */
	private static function toTsplDots($mm, $dotsPerMm)
	{
		$mm = max(0.0, (float) $mm);
		$dotsPerMm = max(0.1, (float) $dotsPerMm);
		return (int) max(0, round($mm * $dotsPerMm));
	}

	/**
	 * Format TSPL numeric values with stable decimal separator.
	 *
	 * @param float $value Numeric value
	 * @return string
	 */
	private static function formatTsplNumber($value)
	{
		$formatted = number_format((float) $value, 2, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');
		return ($formatted !== '' ? $formatted : '0');
	}

	/**
	 * Sanitize text before TSPL serialization.
	 *
	 * @param string $text Raw text
	 * @return string
	 */
	private static function sanitizeTsplText($text)
	{
		$text = (string) $text;
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$text = strtr($text, array(
			'º' => 'o',
			'°' => 'o',
			'ª' => 'a',
			'€' => 'EUR',
			'–' => '-',
			'—' => '-',
			'−' => '-',
			'“' => '"',
			'”' => '"',
			'‘' => "'",
			'’' => "'",
			"\xC2\xA0" => ' ',
		));
		if (function_exists('iconv')) {
			$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
			if ($ascii !== false) {
				$text = (string) $ascii;
			}
		}
		$text = str_replace("\t", ' ', $text);
		$text = preg_replace('/[^\x0A\x20-\x7E]/', '', $text);
		$text = preg_replace('/[ ]{2,}/', ' ', (string) $text);

		$lines = preg_split('/\n/', (string) $text);
		if (!is_array($lines)) {
			return trim((string) $text);
		}

		$cleanLines = array();
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line !== '') {
				$cleanLines[] = $line;
			}
		}

		return trim(implode("\n", $cleanLines));
	}

	/**
	 * Escape text for TSPL quoted string literals.
	 *
	 * @param string $text Sanitized text
	 * @return string
	 */
	private static function escapeTsplText($text)
	{
		$text = str_replace('"', "'", (string) $text);
		return str_replace('\\', '/', $text);
	}

	/**
	 * Determine whether one text block belongs to the flowing second-label sections.
	 *
	 * Ingredients, allergens, and nutrition sections must be placed one after another
	 * with one blank line between sections, based on rendered text height.
	 *
	 * @param array $block Template block
	 * @return bool
	 */
	private static function isTsplFlowSectionBlock($block)
	{
		if (!is_array($block)) {
			return false;
		}

		$source = strtolower(trim((string) (!empty($block['source']) ? $block['source'] : '')));
		if (in_array($source, array('label.ingredients_section', 'label.allergens_section', 'label.nutrition_section'), true)) {
			return true;
		}

		$blockId = strtolower(trim((string) (!empty($block['id']) ? $block['id'] : '')));
		return in_array($blockId, array('back_ingredients_section', 'back_allergens_section', 'back_nutrition_section'), true);
	}

	/**
	 * Map common barcode encodings/symbologies to TSPL names.
	 *
	 * @param string $encoding Source encoding
	 * @return string
	 */
	private static function mapBarcodeEncodingToTspl($encoding)
	{
		$encoding = strtoupper(trim((string) $encoding));
		$map = array(
			'128' => '128',
			'C128' => '128',
			'CODE128' => '128',
			'39' => '39',
			'C39' => '39',
			'CODE39' => '39',
			'EAN13' => 'EAN13',
			'EAN8' => 'EAN8',
			'UPCA' => 'UPCA',
			'UPC-A' => 'UPCA',
			'UPCE' => 'UPCE',
			'UPC-E' => 'UPCE',
			'CODABAR' => 'CODA',
			'CODA' => 'CODA',
			'ITF14' => 'ITF14',
			'ITF' => 'ITF14',
		);

		return (!empty($map[$encoding]) ? $map[$encoding] : '128');
	}

	/**
	 * Resolve whether a symbology should be printed as QRCode in TSPL.
	 *
	 * @param string $symbology Symbology identifier
	 * @return bool
	 */
	private static function isQrCodeSymbology($symbology)
	{
		$symbology = strtoupper(trim((string) $symbology));
		return in_array($symbology, array('QR', 'QRCODE', 'QR-CODE', 'QR_CODE'), true);
	}

	/**
	 * Map text point size to TSPL text multipliers.
	 *
	 * @param float $fontSizePt Font size in points
	 * @return int
	 */
	private static function resolveTsplTextFontSpec($fontSizePt, $fontWeight = '')
	{
		$fontSizePt = max(4.0, (float) $fontSizePt);
		$fontWeight = strtolower(trim((string) $fontWeight));
		$isBold = ($fontWeight === 'bold' || $fontWeight === '700' || $fontWeight === '800' || $fontWeight === '900');

		// Font metrics are conservative to avoid inter-block overlap on thermal labels.
		$font = '1';
		$xmul = 1;
		$ymul = 1;
		$charWidth = 8.0;
		$charHeight = 12.0;

		if ($fontSizePt > 7.2 && $fontSizePt <= 10.4) {
			$font = '2';
			$charWidth = 12.0;
			$charHeight = 20.0;
		} elseif ($fontSizePt > 10.4 && $fontSizePt <= 13.2) {
			$font = '3';
			$charWidth = 16.0;
			$charHeight = 24.0;
		} elseif ($fontSizePt > 13.2 && $fontSizePt <= 17.0) {
			$font = '4';
			$charWidth = 24.0;
			$charHeight = 32.0;
		} elseif ($fontSizePt > 17.0) {
			$font = '5';
			$charWidth = 32.0;
			$charHeight = 48.0;
		}

		if ($isBold && $font === '1') {
			$xmul = 2;
			$charWidth *= 2.0;
		}

		$lineHeight = ((int) ceil($charHeight * $ymul)) + 3;

		return array(
			'font' => $font,
			'xmul' => $xmul,
			'ymul' => $ymul,
			'char_width' => $charWidth,
			'char_height' => ($charHeight * $ymul),
			'line_height' => $lineHeight,
		);
	}

	/**
	 * Sanitize filename fragment used for generated exports.
	 *
	 * @param string $value Raw value
	 * @return string
	 */
	private static function sanitizeFilenameFragment($value)
	{
		$value = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $value);
		$value = trim((string) $value, '._-');
		return substr($value, 0, 64);
	}

	/**
	 * Build one label record payload.
	 *
	 * @param DoliDB    $db             Database handler
	 * @param Product   $product        Product object
	 * @param array     $selectedFields Selected field codes
	 * @param Translate $outputlangs    Output language
	 * @return array
	 */
	private static function buildLabelRecord($db, $product, $selectedFields, $outputlangs)
	{
		$selectedMap = array_fill_keys($selectedFields, true);
		$lines = array();

		if (!empty($selectedMap['ref'])) {
			$lines[] = array(
				'type' => 'ref',
				'text' => self::cleanText($product->ref),
			);
		}

		if (!empty($selectedMap['label'])) {
			$lines[] = array(
				'type' => 'label',
				'text' => self::cleanText($product->label),
			);
		}

		if (!empty($selectedMap['price_ht'])) {
			$lines[] = array(
				'type' => 'price',
				'text' => $outputlangs->trans('KREAPRODUCTS_LABELS_PRICE_HT') . ': ' . price($product->price, 0, $outputlangs, 1, -1, -1, !empty($GLOBALS['conf']->currency) ? $GLOBALS['conf']->currency : ''),
			);
		}

		if (!empty($selectedMap['price_ttc'])) {
			$lines[] = array(
				'type' => 'price',
				'text' => $outputlangs->trans('KREAPRODUCTS_LABELS_PRICE_TTC') . ': ' . price($product->price_ttc, 0, $outputlangs, 1, -1, -1, !empty($GLOBALS['conf']->currency) ? $GLOBALS['conf']->currency : ''),
			);
		}

		$barcode = self::resolveBarcode($db, $product);
		if (empty($selectedMap['barcode'])) {
			$barcode = array('value' => '', 'encoding' => '', 'is2d' => false);
		}

		return array(
			'lines' => $lines,
			'barcode_value' => $barcode['value'],
			'barcode_encoding' => $barcode['encoding'],
			'barcode_is_2d' => $barcode['is2d'],
		);
	}

	/**
	 * Build template-driven label records for PDF generation.
	 *
	 * @param Product   $product             Product object
	 * @param array     $template            Template definition
	 * @param int       $quantity            Number of labels
	 * @param Translate $outputlangs         Output language
	 * @param array     $templateInputValues User-provided template field values
	 * @return array
	 */
	private static function buildTemplateRecords($product, $template, $quantity, $outputlangs, $templateInputValues = array())
	{
		$records = array();
		$context = self::buildTemplatePreviewContext($product, $outputlangs, $template, $templateInputValues);
		$skipCompositionBackPage = self::shouldSkipTemplateCompositionBackPage($context);
		$pages = (!empty($template['pages']) && is_array($template['pages']) ? $template['pages'] : array());
		if (empty($pages)) {
			return $records;
		}

		$pageRecords = array();
		foreach ($pages as $page) {
			if (!is_array($page)) {
				continue;
			}
			if ($skipCompositionBackPage && self::isTemplateCompositionBackPage($page)) {
				continue;
			}

			$pageSize = self::getTemplatePageSizeForPdfOutput($template, $page);
			if (empty($pageSize['width']) || empty($pageSize['height'])) {
				continue;
			}

			$pageRecords[] = array(
				'template_page_code' => (!empty($page['code']) ? (string) $page['code'] : ''),
				'template_page_label' => (!empty($page['label']) ? (string) $page['label'] : ''),
				'template_width_mm' => (float) $pageSize['width'],
				'template_height_mm' => (float) $pageSize['height'],
				'template_blocks' => self::normalizeTemplateBlocksForPdf($page, $context),
				'template_svg' => self::renderTemplatePageSvgFromContext($template, $page, $context, $outputlangs),
			);
		}
		if (empty($pageRecords)) {
			return $records;
		}

		for ($copy = 0; $copy < $quantity; $copy++) {
			foreach ($pageRecords as $pageRecord) {
				$records[] = $pageRecord;
			}
		}

		return $records;
	}

	/**
	 * Tell whether one template page is the composition back page.
	 *
	 * A page is considered a composition back page when it includes the three
	 * dynamic section sources used for ingredients, allergens, and nutrition.
	 *
	 * @param array $page Template page definition
	 * @return bool
	 */
	private static function isTemplateCompositionBackPage($page)
	{
		if (empty($page['blocks']) || !is_array($page['blocks'])) {
			return false;
		}

		$requiredSources = array(
			'label.ingredients_section' => false,
			'label.allergens_section' => false,
			'label.nutrition_section' => false,
		);

		foreach ($page['blocks'] as $block) {
			if (!is_array($block)) {
				continue;
			}
			$source = self::sanitizeTemplateSource(!empty($block['source']) ? (string) $block['source'] : '');
			if ($source !== '' && array_key_exists($source, $requiredSources)) {
				$requiredSources[$source] = true;
			}
		}

		foreach ($requiredSources as $present) {
			if (!$present) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decide whether the composition back page should be skipped.
	 *
	 * When ingredients, allergens, and nutrition have no data, only the first
	 * page should be printed.
	 *
	 * @param array $context Resolved template context
	 * @return bool
	 */
	private static function shouldSkipTemplateCompositionBackPage($context)
	{
		$hasIngredients = self::readTemplateContextBooleanFlag($context, 'meta.label.ingredients_has_data', true);
		$hasAllergens = self::readTemplateContextBooleanFlag($context, 'meta.label.allergens_has_data', true);
		$hasNutrition = self::readTemplateContextBooleanFlag($context, 'meta.label.nutrition_has_data', true);

		return (!$hasIngredients && !$hasAllergens && !$hasNutrition);
	}

	/**
	 * Read one boolean-like flag from template context.
	 *
	 * @param array  $context      Resolved template context
	 * @param string $key          Context key
	 * @param bool   $defaultValue Fallback when key is missing
	 * @return bool
	 */
	private static function readTemplateContextBooleanFlag($context, $key, $defaultValue = false)
	{
		if (!is_array($context) || !array_key_exists($key, $context)) {
			return (bool) $defaultValue;
		}

		return self::parseTemplateBooleanFlag($context[$key], (bool) $defaultValue);
	}

	/**
	 * Normalize template blocks for PDF rendering.
	 *
	 * @param array $page    Template page
	 * @param array $context Resolved source context
	 * @return array
	 */
	private static function normalizeTemplateBlocksForPdf($page, $context)
	{
		$blocks = array();
		if (empty($page['blocks']) || !is_array($page['blocks'])) {
			return $blocks;
		}

		foreach ($page['blocks'] as $block) {
			if (!is_array($block) || empty($block['type'])) {
				continue;
			}

			$blocks[] = array(
				'id' => (!empty($block['id']) ? (string) $block['id'] : ''),
				'type' => (string) $block['type'],
				'source' => (!empty($block['source']) ? (string) $block['source'] : ''),
				'value' => self::resolveTemplateBlockValue($block, $context),
				'x_mm' => (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0),
				'y_mm' => (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0),
				'w_mm' => (float) (!empty($block['w_mm']) ? $block['w_mm'] : 0),
				'h_mm' => (float) (!empty($block['h_mm']) ? $block['h_mm'] : 0),
				'style' => (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array()),
				'show_human_readable' => !empty($block['show_human_readable']),
				'symbology' => (!empty($block['symbology']) ? (string) $block['symbology'] : ''),
			);
		}

		return $blocks;
	}

	/**
	 * Resolve barcode value and encoding for a product.
	 *
	 * @param DoliDB  $db      Database handler
	 * @param Product $product Product object
	 * @return array
	 */
	private static function resolveBarcode($db, $product)
	{
		static $barcodeTypeCache = array();

		$value = self::cleanText(!empty($product->barcode) ? $product->barcode : $product->ref);
		if ($value === '') {
			return array('value' => '', 'encoding' => '', 'is2d' => false);
		}

		$encoding = 'C128';
		$is2d = false;
		$typeId = (int) $product->barcode_type;
		if ($typeId > 0) {
			if (!array_key_exists($typeId, $barcodeTypeCache)) {
				$sql = "SELECT code FROM " . MAIN_DB_PREFIX . "c_barcode_type WHERE rowid = " . $typeId;
				$resql = $db->query($sql);
				$barcodeTypeCache[$typeId] = '';
				if ($resql && ($obj = $db->fetch_object($resql))) {
					$barcodeTypeCache[$typeId] = (string) $obj->code;
				}
				if ($resql) {
					$db->free($resql);
				}
			}

			$code = strtoupper((string) $barcodeTypeCache[$typeId]);
			$map = array(
				'EAN13' => array('encoding' => 'EAN13', 'is2d' => false),
				'EAN8' => array('encoding' => 'EAN8', 'is2d' => false),
				'UPC' => array('encoding' => 'UPC-A', 'is2d' => false),
				'UPC-A' => array('encoding' => 'UPC-A', 'is2d' => false),
				'UPC-E' => array('encoding' => 'UPC-E', 'is2d' => false),
				'C39' => array('encoding' => 'C39', 'is2d' => false),
				'CODE39' => array('encoding' => 'C39', 'is2d' => false),
				'C128' => array('encoding' => 'C128', 'is2d' => false),
				'CODE128' => array('encoding' => 'C128', 'is2d' => false),
				'ISBN' => array('encoding' => 'EAN13', 'is2d' => false),
				'QRCODE' => array('encoding' => 'QRCODE,H', 'is2d' => true),
				'DATAMATRIX' => array('encoding' => 'DATAMATRIX', 'is2d' => true),
			);
			if (isset($map[$code])) {
				$encoding = $map[$code]['encoding'];
				$is2d = $map[$code]['is2d'];
			}
		}

		return array(
			'value' => $value,
			'encoding' => $encoding,
			'is2d' => $is2d,
		);
	}

	/**
	 * Build output filename.
	 *
	 * @param Product $product    Product object
	 * @param string  $formatCode Format code
	 * @return string
	 */
	private static function buildFilename($product, $formatCode)
	{
		$timestamp = dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt');
		return 'labels_' . dol_sanitizeFileName($product->ref) . '_' . dol_sanitizeFileName($formatCode) . '_' . $timestamp . '.pdf';
	}

	/**
	 * Clean text before printing.
	 *
	 * @param string $text Source text
	 * @return string
	 */
	private static function cleanText($text)
	{
		$text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
		$text = dol_string_nohtmltag($text);
		$text = preg_replace('/\s+/', ' ', trim($text));
		return (string) $text;
	}

	/**
	 * Resolve effective output format code, including virtual template-sized formats.
	 *
	 * @param DoliDB    $db              Database handler
	 * @param string    $formatCode      Selected format code
	 * @param string    $templateCode    Selected template code
	 * @param bool      $useTemplateSize Use template size
	 * @param Translate $outputlangs     Output language
	 * @param int       $entityId        Current entity id
	 * @return string
	 */
	private static function resolveOutputFormatCode($db, $formatCode, $templateCode, $useTemplateSize, $outputlangs, $entityId)
	{
		if ($useTemplateSize) {
			$template = self::loadLabelTemplate($templateCode, $entityId);
			if (empty($template)) {
				return '';
			}

			self::loadPdfGeneratorClass($db);
			return self::registerTemplateOutputFormat($template);
		}

		$formatOptions = self::getFormatOptions($db);
		if (empty($formatOptions[$formatCode])) {
			return '';
		}

		return (string) $formatCode;
	}

	/**
	 * Register a virtual Dolibarr label format from a bundled template hint.
	 *
	 * @param array $template Template definition
	 * @return string
	 */
	private static function registerTemplateOutputFormat($template)
	{
		global $_Avery_Labels;

		$details = self::getTemplateOutputFormatDetails($template);
		if (empty($details)) {
			return '';
		}

		$code = self::buildTemplateVirtualFormatCode($template);
		$_Avery_Labels[$code] = array(
			'name' => (!empty($details['name']) ? $details['name'] : $code),
			'paper-size' => $details['paper_size'],
			'orientation' => $details['orientation'],
			'metric' => $details['metric'],
			'marginLeft' => $details['leftmargin'],
			'marginTop' => $details['topmargin'],
			'NX' => $details['nx'],
			'NY' => $details['ny'],
			'SpaceX' => $details['spacex'],
			'SpaceY' => $details['spacey'],
			'width' => $details['width'],
			'height' => $details['height'],
			'font-size' => 8,
			'custom_x' => $details['custom_x'],
			'custom_y' => $details['custom_y'],
		);

		return $code;
	}

	/**
	 * Extract Dolibarr output format details from a bundled template.
	 *
	 * @param array $template Template definition
	 * @return array
	 */
	private static function getTemplateOutputFormatDetails($template)
	{
		$hint = (!empty($template['dolibarr_format_hint']) && is_array($template['dolibarr_format_hint']) ? $template['dolibarr_format_hint'] : array());
		$labelSize = self::getTemplatePageSize($template);
		$width = (float) (!empty($labelSize['width']) ? $labelSize['width'] : 0);
		$height = (float) (!empty($labelSize['height']) ? $labelSize['height'] : 0);
		$customX = (float) (!empty($hint['custom_x_mm']) ? $hint['custom_x_mm'] : $width);
		$customY = (float) (!empty($hint['custom_y_mm']) ? $hint['custom_y_mm'] : $height);

		if ($width <= 0 || $height <= 0 || $customX <= 0 || $customY <= 0) {
			return array();
		}

		$paperSize = (!empty($hint['paper_size']) ? (string) $hint['paper_size'] : 'custom');
		if ($paperSize !== 'custom') {
			$paperSize = 'custom';
		}

		$orientation = strtoupper(!empty($hint['orientation']) ? (string) $hint['orientation'] : 'P');
		if (!in_array($orientation, array('P', 'L'), true)) {
			$orientation = 'P';
		}
		if ($paperSize === 'custom') {
			$orientation = ($customX > $customY ? 'L' : 'P');
		}

		return array(
			'code' => self::buildTemplateVirtualFormatCode($template),
			'name' => (!empty($template['template_code']) ? (string) $template['template_code'] : 'template'),
			'paper_size' => $paperSize,
			'metric' => 'mm',
			'nx' => max(1, (int) (!empty($hint['nx']) ? $hint['nx'] : 1)),
			'ny' => max(1, (int) (!empty($hint['ny']) ? $hint['ny'] : 1)),
			'width' => $width,
			'height' => $height,
			'leftmargin' => max(0, (float) (!empty($hint['leftmargin_mm']) ? $hint['leftmargin_mm'] : 0)),
			'topmargin' => max(0, (float) (!empty($hint['topmargin_mm']) ? $hint['topmargin_mm'] : 0)),
			'spacex' => max(0, (float) (!empty($hint['spacex_mm']) ? $hint['spacex_mm'] : 0)),
			'spacey' => max(0, (float) (!empty($hint['spacey_mm']) ? $hint['spacey_mm'] : 0)),
			'custom_x' => $customX,
			'custom_y' => $customY,
			'orientation' => $orientation,
		);
	}

	/**
	 * Build a deterministic virtual code for a bundled template output format.
	 *
	 * @param array $template Template definition
	 * @return string
	 */
	private static function buildTemplateVirtualFormatCode($template)
	{
		$templateCode = (!empty($template['template_code']) ? (string) $template['template_code'] : 'template');
		$templateCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $templateCode));
		return 'KREATPL_' . substr($templateCode, 0, 42);
	}

	/**
	 * Return the effective page size for a template or page.
	 *
	 * @param array $template Template definition
	 * @param array $page     Optional page definition
	 * @return array{width: float, height: float}
	 */
	private static function getTemplatePageSize($template, $page = array())
	{
		$pageSize = array('width' => 0.0, 'height' => 0.0);

		if (!empty($page['size_mm']) && is_array($page['size_mm'])) {
			$pageSize['width'] = (float) (!empty($page['size_mm']['width']) ? $page['size_mm']['width'] : 0);
			$pageSize['height'] = (float) (!empty($page['size_mm']['height']) ? $page['size_mm']['height'] : 0);
		}

		if ($pageSize['width'] <= 0 && !empty($template['label_size_mm']['width'])) {
			$pageSize['width'] = (float) $template['label_size_mm']['width'];
		}
		if ($pageSize['height'] <= 0 && !empty($template['label_size_mm']['height'])) {
			$pageSize['height'] = (float) $template['label_size_mm']['height'];
		}

		if ($pageSize['width'] <= 0 && !empty($template['dolibarr_format_hint']['custom_x_mm'])) {
			$pageSize['width'] = (float) $template['dolibarr_format_hint']['custom_x_mm'];
		}
		if ($pageSize['height'] <= 0 && !empty($template['dolibarr_format_hint']['custom_y_mm'])) {
			$pageSize['height'] = (float) $template['dolibarr_format_hint']['custom_y_mm'];
		}

		return $pageSize;
	}

	/**
	 * Return the effective page size used for generated/previewed template pages.
	 *
	 * Extracted source PDFs can produce minor drift between page-level sizes.
	 * Normalize near-identical pages to the template base size to keep thermal
	 * printer scaling stable across front/back pages.
	 *
	 * @param array $template Template definition
	 * @param array $page     Optional page definition
	 * @return array{width: float, height: float}
	 */
	private static function getTemplatePageSizeForPdfOutput($template, $page = array())
	{
		$pageSize = self::getTemplatePageSize($template, $page);
		$baseSize = self::getTemplatePageSize($template);
		$baseSize = self::normalizePreferredTemplatePageSize($template, $baseSize);

		if ($pageSize['width'] <= 0 || $pageSize['height'] <= 0 || $baseSize['width'] <= 0 || $baseSize['height'] <= 0) {
			return $pageSize;
		}

		$deltaWidth = abs((float) $pageSize['width'] - (float) $baseSize['width']);
		$deltaHeight = abs((float) $pageSize['height'] - (float) $baseSize['height']);
		if ($deltaWidth <= 2.0 && $deltaHeight <= 2.0) {
			return array(
				'width' => (float) $baseSize['width'],
				'height' => (float) $baseSize['height'],
			);
		}

		return $pageSize;
	}

	/**
	 * Normalize template base size for known legacy DeGema labels.
	 *
	 * Historical production PDFs used by EPSON TM-L90 are 75.6 x 49.9 mm.
	 * Keep this exact physical size for DeGema templates whenever the declared
	 * size is already in the same neighborhood (for backward compatibility).
	 *
	 * @param array $template Template definition
	 * @param array $baseSize Base size from template metadata
	 * @return array{width: float, height: float}
	 */
	private static function normalizePreferredTemplatePageSize($template, $baseSize)
	{
		$templateCode = self::sanitizeTemplateCode(!empty($template['template_code']) ? (string) $template['template_code'] : '');
		if (!in_array($templateCode, array('degema_normal', 'degema_congelado'), true)) {
			return $baseSize;
		}

		$targetWidth = 75.6;
		$targetHeight = 49.9;
		if (empty($baseSize['width']) || empty($baseSize['height'])) {
			return array('width' => $targetWidth, 'height' => $targetHeight);
		}

		$deltaWidth = abs((float) $baseSize['width'] - $targetWidth);
		$deltaHeight = abs((float) $baseSize['height'] - $targetHeight);
		if ($deltaWidth <= 1.0 && $deltaHeight <= 1.0) {
			return array('width' => $targetWidth, 'height' => $targetHeight);
		}

		return $baseSize;
	}

	/**
	 * Render one template page as inline SVG.
	 *
	 * @param array     $template         Template definition
	 * @param array     $page             Page definition
	 * @param Product   $product          Product object
	 * @param Translate $outputlangs      Output language
	 * @param array     $contextOverrides Optional context overrides
	 * @return string
	 */
	private static function renderTemplatePageSvg($template, $page, $product, $outputlangs, $contextOverrides = array())
	{
		$context = self::buildTemplatePreviewContext($product, $outputlangs, $template, $contextOverrides);
		return self::renderTemplatePageSvgFromContext($template, $page, $context, $outputlangs);
	}

	/**
	 * Render one template page as inline SVG using a prebuilt data context.
	 *
	 * @param array     $template    Template definition
	 * @param array     $page        Page definition
	 * @param array     $context     Resolved context values
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	private static function renderTemplatePageSvgFromContext($template, $page, $context, $outputlangs)
	{
		$pageSize = self::getTemplatePageSizeForPdfOutput($template, $page);
		$width = max(1.0, (float) $pageSize['width']);
		$height = max(1.0, (float) $pageSize['height']);
		$svg = array();
		$svg[] = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 ' . self::formatSvgNumber($width) . ' ' . self::formatSvgNumber($height) . '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . self::escapeSvgText(!empty($page['label']) ? $page['label'] : $outputlangs->trans('KREAPRODUCTS_LABELS_TEMPLATE_PREVIEW')) . '">';
		$svg[] = '<rect x="0" y="0" width="' . self::formatSvgNumber($width) . '" height="' . self::formatSvgNumber($height) . '" fill="#ffffff" stroke="#cfd6df" stroke-width="0.35" rx="1.1" ry="1.1"/>';

		if (!empty($page['blocks']) && is_array($page['blocks'])) {
			foreach ($page['blocks'] as $block) {
				if (!is_array($block) || empty($block['type'])) {
					continue;
				}

				if ($block['type'] === 'text') {
					$svg[] = self::renderTemplateTextBlockSvg($block, self::resolveTemplateBlockValue($block, $context));
				} elseif ($block['type'] === 'rect') {
					$svg[] = self::renderTemplateRectBlockSvg($block);
				} elseif ($block['type'] === 'barcode') {
					$svg[] = self::renderTemplateBarcodeBlockSvg($block, self::resolveTemplateBlockValue($block, $context));
				} elseif ($block['type'] === 'image') {
					$svg[] = self::renderTemplateImageBlockSvg($block, self::resolveTemplateBlockValue($block, $context));
				}
			}
		}

		$svg[] = '</svg>';
		return implode('', $svg);
	}

	/**
	 * Build the data context used by the SVG preview renderer.
	 *
	 * @param Product   $product          Product object
	 * @param Translate $outputlangs      Output language
	 * @param array     $template         Template definition
	 * @param array     $contextOverrides Optional context overrides
	 * @return array
	 */
	private static function buildTemplatePreviewContext($product, $outputlangs, $template = array(), $contextOverrides = array())
	{
		global $mysoc, $conf, $db;

		$currencyCode = (!empty($conf->currency) ? $conf->currency : '');
		$companyVat = '';
		foreach (array('tva_intra', 'idprof1', 'vat_number') as $property) {
			if (!empty($mysoc->{$property})) {
				$companyVat = self::cleanText($mysoc->{$property});
				break;
			}
		}

		$companyName = self::cleanText(!empty($mysoc->name) ? $mysoc->name : '');
		$companyNameWithVat = $companyName;
		if ($companyName !== '' && $companyVat !== '') {
			$companyNameWithVat .= ' - VAT ' . $companyVat;
		}

		$context = array(
			'product.ref' => self::cleanText($product->ref),
			'product.label' => self::cleanText($product->label),
			'product.barcode' => self::cleanText(!empty($product->barcode) ? $product->barcode : $product->ref),
			'product.internal_code_barcode' => self::cleanText($product->ref),
			'product.price_ht' => price($product->price, 0, $outputlangs, 1, -1, -1, $currencyCode),
			'product.price_ttc' => price($product->price_ttc, 0, $outputlangs, 1, -1, -1, $currencyCode),
			'company.name' => $companyName,
			'company.name_with_vat' => $companyNameWithVat,
			'company.address_singleline' => self::buildCompanyAddressSingleLine($mysoc),
		);

		if (is_object($product)) {
			foreach (get_object_vars($product) as $property => $value) {
				if (isset($context['product.' . $property]) || is_array($value) || is_object($value)) {
					continue;
				}
				$context['product.' . $property] = self::cleanText((string) $value);
			}
		}
		if (is_object($mysoc)) {
			foreach (get_object_vars($mysoc) as $property => $value) {
				if (isset($context['company.' . $property]) || is_array($value) || is_object($value)) {
					continue;
				}
				$context['company.' . $property] = self::cleanText((string) $value);
			}
		}

		foreach (self::buildTemplateDatabaseDefaultContext($db, $product, $outputlangs) as $source => $value) {
			if ($source === '') {
				continue;
			}
			$context[$source] = (string) $value;
		}

		// Apply input defaults from template JSON, including non-editable system assets.
		foreach (self::buildTemplateInputDefaultContext($template) as $source => $value) {
			if ($source === '') {
				continue;
			}
			$context[$source] = (string) $value;
		}

		$sourceMeta = self::getTemplateEditableSourceMeta($template, $outputlangs);
		foreach ($sourceMeta as $source => $meta) {
			if (array_key_exists('default_value', $meta) && (string) $meta['default_value'] !== '') {
				$context[$source] = (string) $meta['default_value'];
			}
		}
		$productLifetimeDays = self::resolveProductLifetimeDays($product);
		if ($productLifetimeDays !== null) {
			$context['batch.validity_days'] = $productLifetimeDays;
		}
		$context = self::applyTemplateRuntimeDateDefaults($context, $sourceMeta);
		if (empty($context['asset.green_dot_symbol'])) {
			$context['asset.green_dot_symbol'] = 'templates/assets/green_dot_symbol.svg';
		}
		if (empty($context['asset.eu_food_contact_material_symbol'])) {
			$context['asset.eu_food_contact_material_symbol'] = 'templates/assets/eu_food_contact_material_symbol.svg';
		}
		$sanitizedOverrides = self::sanitizeTemplateInputValues($contextOverrides, array_keys($sourceMeta), $sourceMeta);
		foreach ($sanitizedOverrides as $source => $value) {
			$context[$source] = $value;
		}
		$context = self::applyTemplateSectionDataPresenceOverrides($context, $sanitizedOverrides);

		$context = self::applyDerivedTemplateContextValues($context, $template);

		return $context;
	}

	/**
	 * Resolve product lifetime as validity days value.
	 *
	 * @param Product $product Product object
	 * @return string|null
	 */
	private static function resolveProductLifetimeDays($product)
	{
		if (!is_object($product) || !isset($product->lifetime)) {
			return null;
		}

		$lifetime = self::sanitizeTemplateNumericValue((string) $product->lifetime);
		if ($lifetime === '') {
			return null;
		}

		$days = (int) round((float) $lifetime);
		if ($days < 0) {
			return null;
		}

		return (string) $days;
	}

	/**
	 * Update composition section data-presence flags from explicit user overrides.
	 *
	 * @param array $context   Current context
	 * @param array $overrides Sanitized template input overrides
	 * @return array
	 */
	private static function applyTemplateSectionDataPresenceOverrides($context, $overrides)
	{
		if (!is_array($context) || !is_array($overrides) || empty($overrides)) {
			return $context;
		}

		$sectionToFlag = array(
			'label.ingredients_section' => 'meta.label.ingredients_has_data',
			'label.allergens_section' => 'meta.label.allergens_has_data',
			'label.nutrition_section' => 'meta.label.nutrition_has_data',
		);

		foreach ($sectionToFlag as $sectionSource => $flagSource) {
			if (!array_key_exists($sectionSource, $overrides)) {
				continue;
			}

			$hasData = (trim((string) $overrides[$sectionSource]) !== '');
			$context[$flagSource] = ($hasData ? '1' : '0');
		}

		return $context;
	}

	/**
	 * Replace persisted date/datetime defaults with runtime generation date.
	 *
	 * This prevents stale template defaults saved in database from leaking into
	 * newly generated labels.
	 *
	 * @param array $context    Current context
	 * @param array $sourceMeta Editable source metadata
	 * @return array
	 */
	private static function applyTemplateRuntimeDateDefaults($context, $sourceMeta)
	{
		if (!is_array($context) || !is_array($sourceMeta) || empty($sourceMeta)) {
			return $context;
		}

		$nowTs = dol_now();
		foreach ($sourceMeta as $source => $meta) {
			if (empty($source) || !is_array($meta)) {
				continue;
			}

			$type = strtolower(trim((string) (!empty($meta['type']) ? $meta['type'] : '')));
			if ($type !== 'date' && $type !== 'datetime') {
				continue;
			}

			$format = trim((string) (!empty($meta['output_format']) ? $meta['output_format'] : ''));
			if ($format === '') {
				$format = ($type === 'datetime' ? 'd/m/Y H:i' : 'd/m/Y');
			}

			$context[$source] = self::formatTimestampWithPattern($nowTs, $format);
		}

		return $context;
	}

	/**
	 * Build a one-line company address.
	 *
	 * @param object $company Company object
	 * @return string
	 */
	private static function buildCompanyAddressSingleLine($company)
	{
		$parts = array();
		if (!empty($company->address)) {
			$parts[] = self::cleanText($company->address);
		}

		$locality = trim(trim((string) (!empty($company->zip) ? $company->zip : '')) . ' ' . trim((string) (!empty($company->town) ? $company->town : '')));
		if ($locality !== '') {
			$parts[] = self::cleanText($locality);
		}

		return implode(' - ', array_filter($parts));
	}

	/**
	 * Build default context values loaded from module database tables.
	 *
	 * @param DoliDB    $db          Database handler
	 * @param Product   $product     Product object
	 * @param Translate $outputlangs Output language
	 * @return array
	 */
	private static function buildTemplateDatabaseDefaultContext($db, $product, $outputlangs)
	{
		$defaults = array();
		if (!is_object($db) || !is_object($product) || empty($product->id)) {
			return $defaults;
		}

		$productId = (int) $product->id;
		if ($productId <= 0) {
			return $defaults;
		}

		$ingredientsHasData = false;
		$allergensHasData = false;
		$nutritionHasData = false;

		$defaults['label.ingredients_section'] = self::buildIngredientsSectionFromAssociations($db, $productId, $outputlangs, $ingredientsHasData);
		$defaults['label.allergens_section'] = self::buildAllergensSectionFromDatabase($db, $productId, $outputlangs, $allergensHasData);
		$defaults['label.nutrition_section'] = self::buildNutritionSectionFromDatabase($db, $productId, $outputlangs, $nutritionHasData);
		$defaults['meta.label.ingredients_has_data'] = ($ingredientsHasData ? '1' : '0');
		$defaults['meta.label.allergens_has_data'] = ($allergensHasData ? '1' : '0');
		$defaults['meta.label.nutrition_has_data'] = ($nutritionHasData ? '1' : '0');

		return $defaults;
	}

	/**
	 * Build default context values declared in template inputs.
	 *
	 * This includes non-editable inputs so mandatory fixed assets can be
	 * injected by the template without exposing extra UI fields.
	 *
	 * @param array $template Template definition
	 * @return array
	 */
	private static function buildTemplateInputDefaultContext($template)
	{
		$defaults = array();
		if (empty($template['inputs']) || !is_array($template['inputs'])) {
			return $defaults;
		}

		foreach ($template['inputs'] as $input) {
			if (!is_array($input) || !array_key_exists('default_value', $input)) {
				continue;
			}

			$source = self::sanitizeTemplateSource(!empty($input['source']) ? $input['source'] : '');
			if ($source === '') {
				continue;
			}

			$meta = self::normalizeTemplateInputMeta($source, $input);
			$defaultValue = self::sanitizeTemplateInputValue((string) $input['default_value'], $meta);
			if ($defaultValue === '') {
				continue;
			}

			$defaults[$source] = $defaultValue;
		}

		return $defaults;
	}

	/**
	 * Build ingredients section text from llx_product_association.
	 *
	 * @param DoliDB    $db          Database handler
	 * @param int       $productId   Product id
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	private static function buildIngredientsSectionFromAssociations($db, $productId, $outputlangs, &$hasData = null)
	{
		$hasData = false;
		$sql = "SELECT pc.label, pc.ref";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_association AS pa";
		$sql .= " JOIN " . MAIN_DB_PREFIX . "product AS pp ON pp.rowid = pa.fk_product_pere";
		$sql .= " JOIN " . MAIN_DB_PREFIX . "product AS pc ON pc.rowid = pa.fk_product_fils";
		$sql .= " WHERE pa.fk_product_pere = " . (int) $productId;
		$sql .= " AND pp.entity IN (" . getEntity('product') . ")";
		$sql .= " AND pc.entity IN (" . getEntity('product') . ")";
		$sql .= " ORDER BY pa.rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' failed: ' . $db->lasterror(), LOG_WARNING);
			$hasData = true; // Keep back-page printing on transient query errors.
			return '';
		}

		$ingredients = array();
		while ($obj = $db->fetch_object($resql)) {
			$ingredientName = self::cleanText(!empty($obj->label) ? $obj->label : $obj->ref);
			if ($ingredientName === '') {
				continue;
			}

			$ingredients[] = $ingredientName;
		}
		$db->free($resql);

		$prefix = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_INGREDIENTS_PREFIX', 'INGREDIENTES');
		if (empty($ingredients)) {
			$noneText = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_INGREDIENTS_NONE', 'Sem ingredientes declarados');
			return $prefix . ': ' . $noneText;
		}
		$hasData = true;

		$items = array_values($ingredients);
		return $prefix . ': ' . implode(', ', $items) . '.';
	}

	/**
	 * Build allergens section text from llx_kreaproducts_productallergens.
	 *
	 * @param DoliDB    $db          Database handler
	 * @param int       $productId   Product id
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	private static function buildAllergensSectionFromDatabase($db, $productId, $outputlangs, &$hasData = null)
	{
		$hasData = false;
		$sql = "SELECT pa.traces, c.code, c.label";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreaproducts_productallergens AS pa";
		$sql .= " JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = pa.fk_product";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_kreaproducts AS c ON c.rowid = pa.fk_allergen";
		$sql .= " WHERE pa.fk_product = " . (int) $productId;
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " ORDER BY pa.rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' failed: ' . $db->lasterror(), LOG_WARNING);
			$hasData = true; // Keep back-page printing on transient query errors.
			return '';
		}

		$contains = array();
		$traces = array();
		while ($obj = $db->fetch_object($resql)) {
			$allergen = self::resolveAllergenLabelForCurrentLanguage(
				$outputlangs,
				(!empty($obj->code) ? (string) $obj->code : ''),
				(!empty($obj->label) ? (string) $obj->label : '')
			);
			if ($allergen === '') {
				continue;
			}

			if ((int) $obj->traces > 0) {
				$traces[$allergen] = $allergen;
			} else {
				$contains[$allergen] = $allergen;
			}
		}
		$db->free($resql);

		$prefix = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_ALLERGENS_PREFIX', 'ALERGENIOS');
		$parts = array();
		$hasData = (!empty($contains) || !empty($traces));
		if (!empty($contains)) {
			$parts[] = $outputlangs->trans('KREAPRODUCTS_LABELS_SECTION_ALLERGENS_CONTAINS', implode(', ', array_values($contains)));
		}
		if (!empty($traces)) {
			$parts[] = $outputlangs->trans('KREAPRODUCTS_LABELS_SECTION_ALLERGENS_TRACES', implode(', ', array_values($traces)));
		}
		if (empty($parts)) {
			$parts[] = $outputlangs->trans('KREAPRODUCTS_LABELS_SECTION_ALLERGENS_NONE');
		}

		return $prefix . ': ' . implode('. ', array_filter($parts, 'strlen')) . '.';
	}

	/**
	 * Resolve one allergen label in the current system language.
	 *
	 * @param Translate $outputlangs   Output language
	 * @param string    $allergenCode  Allergen dictionary code
	 * @param string    $fallbackLabel Fallback label from database
	 * @return string
	 */
	private static function resolveAllergenLabelForCurrentLanguage($outputlangs, $allergenCode, $fallbackLabel)
	{
		$fallback = self::cleanText((string) $fallbackLabel);
		$code = strtoupper(trim((string) $allergenCode));
		if ($code === '' || preg_match('/^[A-Z0-9_]+$/', $code) !== 1 || !is_object($outputlangs)) {
			return $fallback;
		}

		$translated = self::cleanText($outputlangs->trans($code));
		if ($translated !== '' && $translated !== $code) {
			return $translated;
		}

		return $fallback;
	}

	/**
	 * Build nutrition declaration section text from llx_kreaproducts_nutritional.
	 *
	 * @param DoliDB    $db          Database handler
	 * @param int       $productId   Product id
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	private static function buildNutritionSectionFromDatabase($db, $productId, $outputlangs, &$hasData = null)
	{
		$hasData = false;
		$sql = "SELECT n.energy_kj, n.energy_kcal, n.fat, n.saturates, n.carbohydrates, n.sugars, n.protein, n.salt, n.fiber";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreaproducts_nutritional AS n";
		$sql .= " JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = n.fk_product";
		$sql .= " WHERE n.fk_product = " . (int) $productId;
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " ORDER BY n.tms DESC, n.rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' failed: ' . $db->lasterror(), LOG_WARNING);
			$hasData = true; // Keep back-page printing on transient query errors.
			return '';
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			$prefix = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_NUTRITION_PREFIX_SHORT', 'DECLARACAO NUTRICIONAL');
			$noneText = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_NUTRITION_NONE', 'Sem valores nutricionais declarados');
			return $prefix . ': ' . $noneText;
		}
		$hasData = true;

		$prefix = self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_SECTION_NUTRITION_PREFIX', 'DECLARACAO NUTRICIONAL (por 100g)');
		$energyKj = self::formatTemplateNutritionNumber($obj->energy_kj, 0);
		$energyKcal = self::formatTemplateNutritionNumber($obj->energy_kcal, 0);
		$fat = self::formatTemplateNutritionNumber($obj->fat, 2);
		$saturates = self::formatTemplateNutritionNumber($obj->saturates, 2);
		$carbohydrates = self::formatTemplateNutritionNumber($obj->carbohydrates, 2);
		$sugars = self::formatTemplateNutritionNumber($obj->sugars, 2);
		$protein = self::formatTemplateNutritionNumber($obj->protein, 2);
		$salt = self::formatTemplateNutritionNumber($obj->salt, 2);
		$fiber = self::formatTemplateNutritionNumber($obj->fiber, 2);

		$line = $outputlangs->trans(
			'KREAPRODUCTS_LABELS_SECTION_NUTRITION_LINE_A',
			$energyKj,
			$energyKcal,
			$fat,
			$saturates
		);
		$line .= ' | ' . $outputlangs->trans(
			'KREAPRODUCTS_LABELS_SECTION_NUTRITION_LINE_B',
			$carbohydrates,
			$sugars,
			$protein,
			$salt
		);
		$line .= ' | ' . $outputlangs->trans(
			'KREAPRODUCTS_LABELS_SECTION_NUTRITION_LINE_C',
			$fiber
		);

		return $prefix . ': ' . $line;
	}

	/**
	 * Format nutrition numbers for section text.
	 *
	 * @param mixed $value     Numeric value
	 * @param int   $precision Precision
	 * @return string
	 */
	private static function formatTemplateNutritionNumber($value, $precision = 2)
	{
		if ($value === null || $value === '') {
			return '0';
		}

		$precision = max(0, (int) $precision);
		if ($precision === 0) {
			return (string) ((int) round((float) $value));
		}
		$formatted = number_format((float) $value, $precision, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');
		return ($formatted !== '' ? $formatted : '0');
	}

	/**
	 * Translate one template label key with fallback.
	 *
	 * @param Translate $outputlangs Output language
	 * @param string    $key         Translation key
	 * @param string    $fallback    Fallback value
	 * @return string
	 */
	private static function translateTemplateLabelText($outputlangs, $key, $fallback = '')
	{
		if (is_object($outputlangs)) {
			$translated = $outputlangs->trans($key);
			if ($translated !== $key && $translated !== '') {
				return (string) $translated;
			}
		}

		return ($fallback !== '' ? $fallback : $key);
	}

	/**
	 * Resolve the preview value for a template block.
	 *
	 * @param array $block   Block definition
	 * @param array $context Resolved source context
	 * @return string
	 */
	private static function resolveTemplateBlockValue($block, $context)
	{
		$contentMode = (!empty($block['content_mode']) ? (string) $block['content_mode'] : 'static');
		if ($contentMode === 'static') {
			return self::cleanText(!empty($block['text']) ? $block['text'] : '');
		}

		if ($contentMode === 'dynamic') {
			$source = (!empty($block['source']) ? (string) $block['source'] : '');
			if ($source !== '' && array_key_exists($source, $context) && (string) $context[$source] !== '') {
				return self::sanitizeTemplateTextValue((string) $context[$source], true);
			}

			if (!empty($block['placeholder'])) {
				return self::sanitizeTemplateTextValue($block['placeholder'], true);
			}

			return self::cleanText($source);
		}

		if ($contentMode === 'asset') {
			$assetSource = self::sanitizeTemplateSource('asset.' . (!empty($block['asset_key']) ? $block['asset_key'] : ''));
			if ($assetSource !== '' && !empty($context[$assetSource])) {
				return self::sanitizeTemplateAssetReference((string) $context[$assetSource]);
			}

			return self::cleanText(!empty($block['asset_key']) ? $block['asset_key'] : 'asset');
		}

		return self::cleanText(!empty($block['placeholder']) ? $block['placeholder'] : '');
	}

	/**
	 * Sanitize one template source key.
	 *
	 * @param string $source Source key
	 * @return string
	 */
	private static function sanitizeTemplateSource($source)
	{
		$source = strtolower(trim((string) $source));
		if ($source === '' || preg_match('/^[a-z0-9_.-]+$/', $source) !== 1) {
			return '';
		}

		return $source;
	}

	/**
	 * Check if one dynamic source should be editable by users.
	 *
	 * @param string $source Source key
	 * @return bool
	 */
	private static function isTemplateSourceEditable($source)
	{
		$source = self::sanitizeTemplateSource($source);
		if ($source === '') {
			return false;
		}
		if (substr($source, -9) === '_yyyymmdd') {
			return false;
		}

		$nonEditable = array(
			'product.ref',
			'product.label',
			'product.barcode',
			'product.internal_code',
			'product.internal_code_barcode',
			'product.price_ht',
			'product.price_ttc',
		);

		return !in_array($source, $nonEditable, true);
	}

	/**
	 * Get computed target sources that should not be manually edited.
	 *
	 * @param array $template Template definition
	 * @return array
	 */
	private static function getTemplateComputedTargetSourceMap($template)
	{
		$targets = array();
		if (empty($template['computed_fields']) || !is_array($template['computed_fields'])) {
			return $targets;
		}

		foreach ($template['computed_fields'] as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			foreach (array('target_source', 'output_source', 'result_source') as $targetKey) {
				$source = self::sanitizeTemplateSource(!empty($rule[$targetKey]) ? $rule[$targetKey] : '');
				if ($source !== '') {
					$targets[$source] = true;
				}
			}
		}

		return $targets;
	}

	/**
	 * Extract editable source keys from one template.
	 *
	 * @param array $template Template definition
	 * @return array
	 */
	private static function getTemplateEditableSourceList($template)
	{
		$sources = array();
		$computedTargetSources = self::getTemplateComputedTargetSourceMap($template);
		if (!empty($template['inputs']) && is_array($template['inputs'])) {
			foreach ($template['inputs'] as $input) {
				if (!is_array($input)) {
					continue;
				}
				$source = self::sanitizeTemplateSource(!empty($input['source']) ? $input['source'] : '');
				if ($source === '' || !self::isTemplateSourceEditable($source)) {
					continue;
				}
				if (isset($computedTargetSources[$source])) {
					continue;
				}
				if (array_key_exists('editable', $input) && !(bool) $input['editable']) {
					continue;
				}
				$sources[$source] = $source;
			}

		}

		if (empty($template['pages']) || !is_array($template['pages'])) {
			return $sources;
		}

		foreach ($template['pages'] as $page) {
			if (empty($page['blocks']) || !is_array($page['blocks'])) {
				continue;
			}
			foreach ($page['blocks'] as $block) {
				if (!is_array($block) || (empty($block['content_mode']) || (string) $block['content_mode'] !== 'dynamic')) {
					continue;
				}

					$source = self::sanitizeTemplateSource(!empty($block['source']) ? $block['source'] : '');
					if ($source === '' || !self::isTemplateSourceEditable($source)) {
						continue;
					}
					if (isset($computedTargetSources[$source])) {
						continue;
					}
					$sources[$source] = $source;
				}
			}

		return array_values($sources);
	}

	/**
	 * Build editable source metadata for one template.
	 *
	 * @param array     $template    Template definition
	 * @param Translate $outputlangs Output language
	 * @return array
	 */
	private static function getTemplateEditableSourceMeta($template, $outputlangs)
	{
		$meta = array();
		$computedTargetSources = self::getTemplateComputedTargetSourceMap($template);
		if (!empty($template['inputs']) && is_array($template['inputs'])) {
			foreach ($template['inputs'] as $input) {
				if (!is_array($input)) {
					continue;
				}
				$source = self::sanitizeTemplateSource(!empty($input['source']) ? $input['source'] : '');
				if ($source === '' || !self::isTemplateSourceEditable($source)) {
					continue;
				}
				if (isset($computedTargetSources[$source])) {
					continue;
				}
				if (array_key_exists('editable', $input) && !(bool) $input['editable']) {
					continue;
				}

				if (empty($input['label'])) {
					$input['label'] = self::resolveTemplateEditableSourceLabel($source, array(), array(), $outputlangs);
				}
				$normalized = self::normalizeTemplateInputMeta($source, $input);
				$meta[$source] = $normalized;
			}

		}

		if (empty($template['pages']) || !is_array($template['pages'])) {
			return $meta;
		}

		foreach ($template['pages'] as $page) {
			if (empty($page['blocks']) || !is_array($page['blocks'])) {
				continue;
			}
			foreach ($page['blocks'] as $block) {
				if (!is_array($block) || (empty($block['content_mode']) || (string) $block['content_mode'] !== 'dynamic')) {
					continue;
				}

					$source = self::sanitizeTemplateSource(!empty($block['source']) ? $block['source'] : '');
					if ($source === '' || !self::isTemplateSourceEditable($source)) {
						continue;
					}
					if (isset($computedTargetSources[$source])) {
						continue;
					}

					if (!isset($meta[$source])) {
						$meta[$source] = self::normalizeTemplateInputMeta($source, array(
							'label' => self::resolveTemplateEditableSourceLabel($source, $page, $block, $outputlangs),
						'placeholder' => self::cleanText(!empty($block['placeholder']) ? $block['placeholder'] : ''),
						'type' => self::guessTemplateInputType($source, $block),
					));
				} elseif ($meta[$source]['placeholder'] === '' && !empty($block['placeholder'])) {
					$meta[$source]['placeholder'] = self::cleanText($block['placeholder']);
				}
			}
		}

		return $meta;
	}

	/**
	 * Resolve display label for one editable template source.
	 *
	 * @param string    $source      Source key
	 * @param array     $page        Page definition
	 * @param array     $block       Dynamic block
	 * @param Translate $outputlangs Output language
	 * @return string
	 */
	private static function resolveTemplateEditableSourceLabel($source, $page, $block, $outputlangs)
	{
		$hint = self::findTemplateFieldStaticLabelHint($page, $block);
		if ($hint !== '') {
			return $hint;
		}

		$map = array(
			'batch.packaged_at' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_PACKAGED_AT', 'Packed on'),
			'batch.frozen_at' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_FROZEN_AT', 'Frozen on'),
			'batch.expiry_at' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_EXPIRY_AT', 'Expiry date'),
			'batch.validity_days' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_VALIDITY_DAYS', 'Validity (days)'),
			'batch.lot_number' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_LOT_NUMBER', 'Lot number'),
			'label.ingredients_section' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_INGREDIENTS_SECTION', 'Ingredients section'),
			'label.allergens_section' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_ALLERGENS_SECTION', 'Allergens section'),
			'label.nutrition_section' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_NUTRITION_SECTION', 'Nutrition section'),
			'company.name_with_vat' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_COMPANY_IDENTITY', 'Company identity'),
			'company.address_singleline' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_COMPANY_ADDRESS', 'Company address'),
			'label.storage_text' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_STORAGE_TEXT', 'Storage note'),
			'asset.brand_badge' => self::translateTemplateLabelText($outputlangs, 'KREAPRODUCTS_LABELS_FIELD_BRAND_BADGE', 'Brand badge'),
		);

		if (!empty($map[$source])) {
			return (string) $map[$source];
		}

		return self::humanizeTemplateSource($source);
	}

	/**
	 * Find nearby static label text for one dynamic block.
	 *
	 * @param array $page  Page definition
	 * @param array $block Dynamic block
	 * @return string
	 */
	private static function findTemplateFieldStaticLabelHint($page, $block)
	{
		if (empty($page['blocks']) || !is_array($page['blocks'])) {
			return '';
		}

		$targetX = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
		$targetY = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
		$bestLabel = '';
		$bestScore = 999999.0;

		foreach ($page['blocks'] as $candidate) {
			if (!is_array($candidate) || (empty($candidate['type']) || (string) $candidate['type'] !== 'text')) {
				continue;
			}
			if (empty($candidate['content_mode']) || (string) $candidate['content_mode'] !== 'static') {
				continue;
			}

			$text = self::cleanText(!empty($candidate['text']) ? $candidate['text'] : '');
			if ($text === '') {
				continue;
			}

			$candidateX = (float) (!empty($candidate['x_mm']) ? $candidate['x_mm'] : 0);
			$candidateY = (float) (!empty($candidate['y_mm']) ? $candidate['y_mm'] : 0);
			$deltaY = abs($targetY - $candidateY);
			if ($deltaY > 3.5) {
				continue;
			}

			$deltaX = $targetX - $candidateX;
			if ($deltaX < -2.0) {
				continue;
			}

			$score = ($deltaY * 10.0) + max(0, $deltaX);
			if ($score < $bestScore) {
				$bestScore = $score;
				$bestLabel = $text;
			}
		}

		return $bestLabel;
	}

	/**
	 * Build a readable label from a source key.
	 *
	 * @param string $source Source key
	 * @return string
	 */
	private static function humanizeTemplateSource($source)
	{
		$source = self::sanitizeTemplateSource($source);
		if ($source === '') {
			return '';
		}

		$lastSegment = $source;
		$lastDotPos = strrpos($source, '.');
		if ($lastDotPos !== false) {
			$lastSegment = substr($source, $lastDotPos + 1);
		}

		$label = str_replace(array('_', '-'), ' ', $lastSegment);
		$label = preg_replace('/\s+/', ' ', trim((string) $label));
		return ucfirst($label);
	}

	/**
	 * Normalize date string into YYYYMMDD.
	 *
	 * @param string $value Source value
	 * @return string
	 */
	private static function normalizeDateToYmd($value)
	{
		$timestamp = self::parseTemplateDateTimeToTimestamp($value);
		if ($timestamp === null) {
			return '';
		}

		return date('Ymd', $timestamp);
	}

	/**
	 * Apply derived context values used by templates.
	 *
	 * @param array $context Source context
	 * @param array $template Template definition
	 * @return array
	 */
	private static function applyDerivedTemplateContextValues($context, $template = array())
	{
		$context = self::applyTemplateComputedFields($context, $template);

		if (!empty($context['batch.validity_days']) && empty($context['batch.expiry_at'])) {
			$daysValue = self::sanitizeTemplateNumericValue((string) $context['batch.validity_days']);
			if ($daysValue !== '') {
				$days = (int) round((float) $daysValue);
				$baseDateValue = '';
				if (!empty($context['batch.frozen_at'])) {
					$baseDateValue = (string) $context['batch.frozen_at'];
				} elseif (!empty($context['batch.packaged_at'])) {
					$baseDateValue = (string) $context['batch.packaged_at'];
				}

				$baseTimestamp = self::parseTemplateDateTimeToTimestamp($baseDateValue);
				if ($baseTimestamp !== null) {
					$context['batch.expiry_at'] = self::formatTimestampWithPattern(strtotime(($days >= 0 ? '+' : '') . $days . ' days', $baseTimestamp), 'd/m/Y');
				}
			}
		}

		if (!empty($context['batch.packaged_at']) && empty($context['batch.packaged_at_yyyymmdd'])) {
			$context['batch.packaged_at_yyyymmdd'] = self::normalizeDateToYmd($context['batch.packaged_at']);
		}
		if (!empty($context['batch.frozen_at']) && empty($context['batch.frozen_at_yyyymmdd'])) {
			$context['batch.frozen_at_yyyymmdd'] = self::normalizeDateToYmd($context['batch.frozen_at']);
		}
		if (!empty($context['batch.expiry_at']) && empty($context['batch.expiry_at_yyyymmdd'])) {
			$context['batch.expiry_at_yyyymmdd'] = self::normalizeDateToYmd($context['batch.expiry_at']);
		}
		if (!empty($context['label.ingredients_section'])) {
			$context['label.ingredients_section'] = self::normalizeIngredientsSectionText($context['label.ingredients_section']);
		}

		return $context;
	}

	/**
	 * Build an EAN-13 variable-measure barcode with embedded weight.
	 *
	 * @param string $productCode Product reference or barcode
	 * @param string $weightValue Weight value
	 * @param string $weightUnit  Weight unit
	 * @param string $prefix      Variable-measure prefix
	 * @return string
	 */
	private static function buildWeightEmbeddedEan13($productCode, $weightValue, $weightUnit, $prefix = '27')
	{
		$prefixDigits = preg_replace('/\D+/', '', (string) $prefix);
		$prefixDigits = substr(str_pad((string) $prefixDigits, 2, '0', STR_PAD_LEFT), 0, 2);
		if ($prefixDigits === '') {
			$prefixDigits = '27';
		}

		$productDigits = preg_replace('/\D+/', '', (string) $productCode);
		if ($productDigits === '') {
			$productDigits = '0';
		}
		$productDigits = substr(str_pad((string) $productDigits, 5, '0', STR_PAD_LEFT), -5);

		$weight = self::sanitizeTemplateNumericValue($weightValue);
		$weightKg = ($weight !== '' ? (float) $weight : 0.0);
		$unit = strtolower(trim((string) $weightUnit));
		if ($unit === 'g') {
			$weightKg /= 1000;
		} elseif ($unit === 'mg') {
			$weightKg /= 1000000;
		} elseif ($unit === 'oz' || $unit === 'onça' || $unit === 'onca') {
			$weightKg *= 0.028349523125;
		}

		$weightGrams = (int) round(max(0, $weightKg) * 1000);
		$weightGrams = min(99999, $weightGrams);
		$body = $prefixDigits . $productDigits . str_pad((string) $weightGrams, 5, '0', STR_PAD_LEFT);

		return $body . self::calculateEan13CheckDigit($body);
	}

	/**
	 * Calculate EAN-13 check digit for the first 12 digits.
	 *
	 * @param string $body Twelve-digit EAN body
	 * @return string
	 */
	private static function calculateEan13CheckDigit($body)
	{
		$digits = preg_replace('/\D+/', '', (string) $body);
		$digits = substr(str_pad((string) $digits, 12, '0', STR_PAD_LEFT), 0, 12);
		$sum = 0;
		for ($i = 0; $i < 12; $i++) {
			$digit = (int) $digits[$i];
			$sum += (($i % 2) === 0 ? $digit : $digit * 3);
		}

		return (string) ((10 - ($sum % 10)) % 10);
	}

	/**
	 * Normalize ingredients text for labels.
	 *
	 * @param string $value Ingredients text
	 * @return string
	 */
	private static function normalizeIngredientsSectionText($value)
	{
		$text = trim((string) $value);
		if ($text === '') {
			return '';
		}

		// Remove percentage fragments like "(47.8%)" from persisted editable values.
		$text = (string) preg_replace('/\s*\(\s*\d+(?:[.,]\d+)?\s*%\s*\)/u', '', $text);
		$text = (string) preg_replace('/\s+,/', ',', $text);
		$text = (string) preg_replace('/\s+\./', '.', $text);
		$text = (string) preg_replace('/\s{2,}/', ' ', $text);
		$text = (string) preg_replace('/,\s*,+/', ', ', $text);

		return trim($text);
	}

	/**
	 * Tell if one template text block should be clipped to its height.
	 *
	 * @param array $block Block definition
	 * @return bool
	 */
	private static function shouldTruncateTemplateTextBlock($block)
	{
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		if (array_key_exists('truncate', $style)) {
			return self::parseTemplateBooleanFlag($style['truncate'], true);
		}

		$source = self::sanitizeTemplateSource(!empty($block['source']) ? $block['source'] : '');
		if ($source === 'label.ingredients_section') {
			return false;
		}

		return true;
	}

	/**
	 * Parse a loose boolean flag from template JSON values.
	 *
	 * @param mixed $value   Raw value
	 * @param bool  $default Fallback value
	 * @return bool
	 */
	private static function parseTemplateBooleanFlag($value, $default = false)
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return ((int) $value) !== 0;
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
				return true;
			}
			if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
				return false;
			}
		}

		return (bool) $default;
	}

	/**
	 * Apply template-defined computed context rules.
	 *
	 * @param array $context  Source context
	 * @param array $template Template definition
	 * @return array
	 */
	private static function applyTemplateComputedFields($context, $template)
	{
		if (empty($template['computed_fields']) || !is_array($template['computed_fields'])) {
			return $context;
		}

		foreach ($template['computed_fields'] as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$operation = strtolower(trim((string) (!empty($rule['operation']) ? $rule['operation'] : '')));
			if ($operation === 'weight_ean13') {
				$targetSource = self::sanitizeTemplateSource(!empty($rule['target_source']) ? $rule['target_source'] : '');
				if ($targetSource === '') {
					continue;
				}
				$weightSource = self::sanitizeTemplateSource(!empty($rule['weight_source']) ? $rule['weight_source'] : 'label.weight_value');
				$unitSource = self::sanitizeTemplateSource(!empty($rule['unit_source']) ? $rule['unit_source'] : 'label.weight_unit');
				$productCodeSource = self::sanitizeTemplateSource(!empty($rule['product_code_source']) ? $rule['product_code_source'] : 'product.ref');
				$productCodeValue = (!empty($context[$productCodeSource]) ? (string) $context[$productCodeSource] : '');
				if (preg_replace('/\D+/', '', $productCodeValue) === '' && !empty($rule['product_code_fallback_sources']) && is_array($rule['product_code_fallback_sources'])) {
					foreach ($rule['product_code_fallback_sources'] as $fallbackSource) {
						$fallbackSource = self::sanitizeTemplateSource($fallbackSource);
						if ($fallbackSource === '' || empty($context[$fallbackSource])) {
							continue;
						}
						$fallbackValue = (string) $context[$fallbackSource];
						if (preg_replace('/\D+/', '', $fallbackValue) !== '') {
							$productCodeValue = $fallbackValue;
							break;
						}
					}
				}
				$prefix = (!empty($rule['prefix']) ? (string) $rule['prefix'] : '27');
				$context[$targetSource] = self::buildWeightEmbeddedEan13(
					$productCodeValue,
					(!empty($context[$weightSource]) ? (string) $context[$weightSource] : ''),
					(!empty($context[$unitSource]) ? (string) $context[$unitSource] : 'kg'),
					$prefix
				);
				continue;
			}
			if ($operation === 'weight_line') {
				$targetSource = self::sanitizeTemplateSource(!empty($rule['target_source']) ? $rule['target_source'] : 'label.weight_line');
				if ($targetSource === '') {
					continue;
				}
				$weightSource = self::sanitizeTemplateSource(!empty($rule['weight_source']) ? $rule['weight_source'] : 'label.weight_value');
				$unitSource = self::sanitizeTemplateSource(!empty($rule['unit_source']) ? $rule['unit_source'] : 'label.weight_unit');
				$weightValue = (!empty($context[$weightSource]) ? self::sanitizeTemplateNumericValue((string) $context[$weightSource]) : '');
				$weight = ($weightValue !== '' ? (float) $weightValue : 0.0);
				$unit = (!empty($context[$unitSource]) ? self::sanitizeTemplateTextValue((string) $context[$unitSource]) : 'kg');
				if ($unit === '') {
					$unit = 'kg';
				}
				$context[$targetSource] = 'Peso: ' . sprintf('%.3f', $weight) . ' ' . $unit;
				continue;
			}
			if ($operation !== 'add_days') {
				continue;
			}

			$targetSource = self::sanitizeTemplateSource(!empty($rule['target_source']) ? $rule['target_source'] : '');
			$daysSource = self::sanitizeTemplateSource(!empty($rule['days_source']) ? $rule['days_source'] : '');
			if ($targetSource === '' || $daysSource === '' || !isset($context[$daysSource])) {
				continue;
			}

			$daysValue = self::sanitizeTemplateNumericValue((string) $context[$daysSource]);
			if ($daysValue === '') {
				continue;
			}
			$days = (int) round((float) $daysValue);

			$baseSources = array();
			$primaryBase = self::sanitizeTemplateSource(!empty($rule['base_source']) ? $rule['base_source'] : '');
			if ($primaryBase !== '') {
				$baseSources[] = $primaryBase;
			}
			if (!empty($rule['base_fallback_sources']) && is_array($rule['base_fallback_sources'])) {
				foreach ($rule['base_fallback_sources'] as $fallbackSource) {
					$sanitizedFallback = self::sanitizeTemplateSource($fallbackSource);
					if ($sanitizedFallback !== '' && !in_array($sanitizedFallback, $baseSources, true)) {
						$baseSources[] = $sanitizedFallback;
					}
				}
			}
			if (empty($baseSources)) {
				$baseSources = array('batch.frozen_at', 'batch.packaged_at');
			}

			$baseTimestamp = null;
			foreach ($baseSources as $baseSource) {
				if (empty($context[$baseSource])) {
					continue;
				}
				$baseTimestamp = self::parseTemplateDateTimeToTimestamp((string) $context[$baseSource]);
				if ($baseTimestamp !== null) {
					break;
				}
			}
			if ($baseTimestamp === null) {
				continue;
			}

			$outputFormat = (!empty($rule['output_format']) ? (string) $rule['output_format'] : 'd/m/Y');
			$computedTimestamp = strtotime(($days >= 0 ? '+' : '') . $days . ' days', $baseTimestamp);
			if ($computedTimestamp === false) {
				continue;
			}

			$context[$targetSource] = self::formatTimestampWithPattern($computedTimestamp, $outputFormat);
		}

		return $context;
	}

	/**
	 * Render a template text block as SVG.
	 *
	 * @param array  $block Block definition
	 * @param string $text  Resolved text
	 * @return string
	 */
	private static function renderTemplateTextBlockSvg($block, $text)
	{
		$x = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
		$y = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
		$w = max(1.0, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 1));
		$h = max(1.0, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 1));
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		$fontPt = (float) (!empty($style['font_size_pt']) ? $style['font_size_pt'] : 7.5);
		$minFontPt = (float) (!empty($style['min_font_size_pt']) ? $style['min_font_size_pt'] : 3.8);
		$minFontMm = max(0.9, $minFontPt * 0.352778);
		$fontMm = max($minFontMm, $fontPt * 0.352778);
		$autoFit = self::parseTemplateBooleanFlag(!empty($style['auto_fit']) ? $style['auto_fit'] : false, false);
		if ($autoFit) {
			for ($guard = 0; $guard < 28; $guard++) {
				$lineHeightCandidate = $fontMm * 1.18;
				$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeightCandidate)));
				$candidateLines = self::wrapTemplatePreviewText($text, $fontMm, $w, 0, false);
				if (count($candidateLines) <= $maxLinesByHeight || $fontMm <= ($minFontMm + 0.001)) {
					break;
				}
				$fontMm = max($minFontMm, $fontMm * 0.95);
			}
		}
		$lineHeight = $fontMm * 1.18;
		$shouldTruncate = self::shouldTruncateTemplateTextBlock($block);
		if ($shouldTruncate) {
			$hardFloorMm = 0.85;
			for ($guard = 0; $guard < 48; $guard++) {
				$lineHeight = max(0.8, $fontMm * 1.18);
				$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeight)));
				$candidateLines = self::wrapTemplatePreviewText($text, $fontMm, $w, 0, false);
				if (count($candidateLines) <= $maxLinesByHeight || $fontMm <= ($hardFloorMm + 0.001)) {
					break;
				}
				$fontMm = max($hardFloorMm, $fontMm * 0.95);
			}

			$lineHeight = max(0.8, $fontMm * 1.18);
			$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeight)));
			$candidateLines = self::wrapTemplatePreviewText($text, $fontMm, $w, 0, false);
			if (count($candidateLines) > $maxLinesByHeight) {
				// Never cut text: allow overflow instead of clipping/ellipsis.
				$shouldTruncate = false;
			}
		}

		$maxLines = ($shouldTruncate ? max(1, (int) floor($h / max(1.0, $lineHeight))) : 0);
		$lines = self::wrapTemplatePreviewText($text, $fontMm, $w, $maxLines, $shouldTruncate);
		$align = (!empty($style['align']) ? strtolower((string) $style['align']) : 'left');
		$textAnchor = 'start';
		$textX = $x;
		if ($align === 'center') {
			$textAnchor = 'middle';
			$textX = $x + ($w / 2);
		} elseif ($align === 'right') {
			$textAnchor = 'end';
			$textX = $x + $w;
		}

		$fontFamily = (!empty($style['font_family']) ? (string) $style['font_family'] : 'Helvetica');
		$fontWeight = (!empty($style['font_weight']) && strtolower((string) $style['font_weight']) === 'bold' ? '700' : '400');
		$textStartY = $y + $fontMm;
		$svg = array();
		$svg[] = '<text x="' . self::formatSvgNumber($textX) . '" y="' . self::formatSvgNumber($textStartY) . '" fill="#101828" font-family="' . self::escapeSvgText($fontFamily) . '" font-size="' . self::formatSvgNumber($fontMm) . '" font-weight="' . $fontWeight . '" text-anchor="' . $textAnchor . '">';

		foreach ($lines as $index => $line) {
			$svg[] = '<tspan x="' . self::formatSvgNumber($textX) . '" dy="' . ($index === 0 ? '0' : self::formatSvgNumber($lineHeight)) . '">' . self::escapeSvgText($line) . '</tspan>';
		}

		$svg[] = '</text>';
		return implode('', $svg);
	}

	/**
	 * Wrap preview text to fit approximately inside a block.
	 *
	 * @param string $text        Text to wrap
	 * @param float  $fontSizeMm  Font size in mm
	 * @param float  $maxWidthMm  Maximum width in mm
	 * @param int    $maxLines    Maximum number of lines (0 = unlimited)
	 * @param bool   $truncate    Add ellipsis when clipping
	 * @return array
	 */
	private static function wrapTemplatePreviewText($text, $fontSizeMm, $maxWidthMm, $maxLines, $truncate = true)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return array('');
		}

		$estimatedCharsPerLine = max(4, (int) floor($maxWidthMm / max(0.9, $fontSizeMm * 0.5)));
		$wrapped = preg_split('/\r\n|\r|\n/', wordwrap($text, $estimatedCharsPerLine, "\n", true));
		if (!is_array($wrapped) || empty($wrapped)) {
			$wrapped = array($text);
		}

		$wrappedLines = array_values(array_filter(array_map('trim', $wrapped), 'strlen'));
		$lines = $wrappedLines;
		if (empty($lines)) {
			$lines = array($text);
		}

		$maxLines = (int) $maxLines;
		if ($maxLines > 0 && count($lines) > $maxLines) {
			$lines = array_slice($lines, 0, $maxLines);
		}

		if ($truncate && $maxLines > 0 && count($wrappedLines) > $maxLines) {
			$lastIndex = count($lines) - 1;
			$lines[$lastIndex] = rtrim(substr($lines[$lastIndex], 0, max(0, $estimatedCharsPerLine - 1))) . '...';
		}

		return $lines;
	}

	/**
	 * Render a rectangle block as SVG.
	 *
	 * @param array $block Block definition
	 * @return string
	 */
	private static function renderTemplateRectBlockSvg($block)
	{
		$x = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
		$y = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
		$w = max(0.5, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 0.5));
		$h = max(0.5, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 0.5));
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		$strokeWidth = max(0.1, (float) (!empty($style['stroke_width_mm']) ? $style['stroke_width_mm'] : 0.25));

		return '<rect x="' . self::formatSvgNumber($x) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($w) . '" height="' . self::formatSvgNumber($h) . '" fill="none" stroke="#101828" stroke-width="' . self::formatSvgNumber($strokeWidth) . '"/>';
	}

	/**
	 * Render an asset/image block as a vector placeholder.
	 *
	 * @param array  $block Block definition
	 * @param string $value Resolved asset reference
	 * @return string
	 */
	private static function renderTemplateImageBlockSvg($block, $value = '')
	{
		$x = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
		$y = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
		$w = max(1.0, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 1));
		$h = max(1.0, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 1));
		$assetHref = self::buildTemplateImageHrefForSvg($value);
		if ($assetHref !== '') {
			$escapedHref = self::escapeSvgText($assetHref);
			return '<image x="' . self::formatSvgNumber($x) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($w) . '" height="' . self::formatSvgNumber($h) . '" href="' . $escapedHref . '" xlink:href="' . $escapedHref . '" preserveAspectRatio="xMidYMid meet"/>';
		}

		$label = (!empty($block['asset_key']) ? (string) $block['asset_key'] : 'asset');
		$fontSize = max(1.0, min(2.2, $h * 0.18));

		return '<g><rect x="' . self::formatSvgNumber($x) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($w) . '" height="' . self::formatSvgNumber($h) . '" fill="#f8fafc" stroke="#98a2b3" stroke-width="0.2" stroke-dasharray="0.9 0.6"/><line x1="' . self::formatSvgNumber($x) . '" y1="' . self::formatSvgNumber($y) . '" x2="' . self::formatSvgNumber($x + $w) . '" y2="' . self::formatSvgNumber($y + $h) . '" stroke="#cbd5e1" stroke-width="0.2"/><line x1="' . self::formatSvgNumber($x + $w) . '" y1="' . self::formatSvgNumber($y) . '" x2="' . self::formatSvgNumber($x) . '" y2="' . self::formatSvgNumber($y + $h) . '" stroke="#cbd5e1" stroke-width="0.2"/><text x="' . self::formatSvgNumber($x + ($w / 2)) . '" y="' . self::formatSvgNumber($y + ($h / 2) - ($fontSize / 2)) . '" fill="#667085" font-family="Helvetica" font-size="' . self::formatSvgNumber($fontSize) . '" text-anchor="middle" dominant-baseline="text-before-edge">' . self::escapeSvgText($label) . '</text></g>';
	}

	/**
	 * Build a public preview URL for one template asset reference.
	 *
	 * @param string $value Asset reference
	 * @return string
	 */
	private static function buildTemplateAssetPreviewUrl($value)
	{
		global $conf;

		$value = self::sanitizeTemplateAssetReference($value);
		if ($value === '') {
			return '';
		}

		$localPath = self::resolveTemplateAssetLocalPath($value);
		if ($localPath !== '') {
			$dataUri = self::buildTemplateAssetDataUriFromLocalPath($localPath);
			if ($dataUri !== '') {
				return $dataUri;
			}
		}

		$entityId = (int) (!empty($conf->entity) ? $conf->entity : 1);
		$fileRelative = $entityId . '/labels/' . ltrim($value, '/');
		return DOL_URL_ROOT . '/viewimage.php?modulepart=kreaproducts&entity=' . $entityId . '&file=' . urlencode($fileRelative);
	}

	/**
	 * Build image href for inline SVG preview.
	 *
	 * Prefer data URIs for local module assets to avoid browser auth/CSP edge cases
	 * when loading `/viewimage.php` inside nested SVG `<image>`.
	 *
	 * @param string $value Asset reference
	 * @return string
	 */
	private static function buildTemplateImageHrefForSvg($value)
	{
		$value = self::sanitizeTemplateAssetReference($value);
		if ($value === '') {
			return '';
		}

		$localPath = self::resolveTemplateAssetLocalPath($value);
		if ($localPath !== '') {
			$dataUri = self::buildTemplateAssetDataUriFromLocalPath($localPath);
			if ($dataUri !== '') {
				return $dataUri;
			}
		}

		return self::buildTemplateAssetPreviewUrl($value);
	}

	/**
	 * Resolve one sanitized asset reference to a local path.
	 *
	 * Resolution order:
	 * 1) Entity documents (`DOL_DATA_ROOT/.../labels/templates/assets`)
	 * 2) Bundled module assets in `/custom/kreaproducts/labels/`
	 *
	 * @param string $value Sanitized asset reference
	 * @return string
	 */
	private static function resolveTemplateAssetLocalPath($value)
	{
		global $conf;

		$assetReference = self::sanitizeTemplateAssetReference($value);
		if ($assetReference === '') {
			return '';
		}

		$fileName = substr($assetReference, strlen('templates/assets/'));
		if ($fileName === '' || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false) {
			return '';
		}

		$entityId = (int) (!empty($conf->entity) ? $conf->entity : 1);
		$assetRoot = self::getTemplateAssetDir($entityId);
		if (is_dir($assetRoot)) {
			$assetPath = $assetRoot . '/' . $fileName;
			$realAssetPath = realpath($assetPath);
			$realAssetRoot = realpath($assetRoot);
			if ($realAssetPath !== false && $realAssetRoot !== false && is_file($realAssetPath) && is_readable($realAssetPath)) {
				$rootPrefix = rtrim($realAssetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
				if (strpos($realAssetPath, $rootPrefix) === 0) {
					return $realAssetPath;
				}
			}
		}

		$bundledAssetPath = self::getBundledLabelTemplateDir() . '/' . $fileName;
		if (is_file($bundledAssetPath) && is_readable($bundledAssetPath)) {
			return $bundledAssetPath;
		}

		return '';
	}

	/**
	 * Build a data URI from one local image file.
	 *
	 * @param string $fullPath Absolute image path
	 * @return string
	 */
	private static function buildTemplateAssetDataUriFromLocalPath($fullPath)
	{
		$fullPath = (string) $fullPath;
		if ($fullPath === '' || !is_file($fullPath) || !is_readable($fullPath)) {
			return '';
		}

		$maxBytes = 2 * 1024 * 1024;
		$fileSize = @filesize($fullPath);
		if ($fileSize === false || $fileSize <= 0 || $fileSize > $maxBytes) {
			return '';
		}

		$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
		$mimeMap = array(
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
		);
		if (empty($mimeMap[$extension])) {
			return '';
		}
		$mime = $mimeMap[$extension];

		$binary = @file_get_contents($fullPath);
		if ($binary === false || $binary === '') {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode($binary);
	}

	/**
	 * Render a barcode block as a lightweight vector barcode preview.
	 *
	 * @param array  $block Block definition
	 * @param string $value Resolved barcode value
	 * @return string
	 */
	private static function renderTemplateBarcodeBlockSvg($block, $value)
	{
		$x = (float) (!empty($block['x_mm']) ? $block['x_mm'] : 0);
		$y = (float) (!empty($block['y_mm']) ? $block['y_mm'] : 0);
		$w = max(2.0, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 2));
		$h = max(2.0, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 2));
		$value = trim((string) $value);
		if ($value === '') {
			$value = '000000';
		}

		$showHumanReadable = !empty($block['show_human_readable']);
		$textHeight = ($showHumanReadable ? max(1.3, min(2.6, $h * 0.22)) : 0.0);
		$barHeight = max(1.0, $h - $textHeight);
		$barcodeEncoding = self::mapTemplateBarcodeSymbologyForPreview(!empty($block['symbology']) ? (string) $block['symbology'] : 'C128');
		$barcodeArray = self::buildPreviewBarcodeArrayFromTcpdf($value, $barcodeEncoding);
		$svg = array();
		$svg[] = '<g>';
		$svg[] = '<rect x="' . self::formatSvgNumber($x) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($w) . '" height="' . self::formatSvgNumber($barHeight) . '" fill="#ffffff"/>';

		if (!empty($barcodeArray['bcode']) && !empty($barcodeArray['maxw'])) {
			$maxw = max(1.0, (float) $barcodeArray['maxw']);
			$unitWidth = $w / $maxw;
			$currentX = $x;
			foreach ($barcodeArray['bcode'] as $segment) {
				$segmentWidth = max(0.01, ((float) (!empty($segment['w']) ? $segment['w'] : 0)) * $unitWidth);
				if (!empty($segment['t'])) {
					$svg[] = '<rect x="' . self::formatSvgNumber($currentX) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($segmentWidth) . '" height="' . self::formatSvgNumber($barHeight) . '" fill="#101828"/>';
				}
				$currentX += $segmentWidth;
			}
		} else {
			$pattern = self::buildPreviewBarcodePattern($value);
			$patternLength = max(1, strlen($pattern));
			$barWidth = $w / $patternLength;

			for ($i = 0; $i < $patternLength; $i++) {
				if ($pattern[$i] !== '1') {
					continue;
				}

				$currentX = $x + ($i * $barWidth);
				$svg[] = '<rect x="' . self::formatSvgNumber($currentX) . '" y="' . self::formatSvgNumber($y) . '" width="' . self::formatSvgNumber($barWidth + 0.02) . '" height="' . self::formatSvgNumber($barHeight) . '" fill="#101828"/>';
			}
		}

		if ($showHumanReadable) {
			$svg[] = '<text x="' . self::formatSvgNumber($x + ($w / 2)) . '" y="' . self::formatSvgNumber($y + $barHeight + 0.2) . '" fill="#101828" font-family="Helvetica" font-size="' . self::formatSvgNumber($textHeight * 0.82) . '" text-anchor="middle" dominant-baseline="text-before-edge">' . self::escapeSvgText($value) . '</text>';
		}

		$svg[] = '</g>';
		return implode('', $svg);
	}

	/**
	 * Map template symbology to the TCPDF 1D barcode encoder name used for preview.
	 *
	 * @param string $symbology Barcode symbology
	 * @return string
	 */
	private static function mapTemplateBarcodeSymbologyForPreview($symbology)
	{
		$symbology = strtoupper(trim((string) $symbology));
		$map = array(
			'EAN13' => 'EAN13',
			'EAN8' => 'EAN8',
			'UPC' => 'UPCA',
			'UPC-A' => 'UPCA',
			'UPC-E' => 'UPCE',
			'C39' => 'C39',
			'CODE39' => 'C39',
			'C128' => 'C128',
			'CODE128' => 'C128',
		);

		return (!empty($map[$symbology]) ? $map[$symbology] : 'C128');
	}

	/**
	 * Build a barcode array using TCPDF so preview dimensions match generated PDF barcodes.
	 *
	 * @param string $value    Barcode value
	 * @param string $encoding TCPDF 1D barcode encoding
	 * @return array
	 */
	private static function buildPreviewBarcodeArrayFromTcpdf($value, $encoding)
	{
		if (!class_exists('TCPDFBarcode')) {
			$barcodeClassPath = DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf_barcodes_1d.php';
			if (is_readable($barcodeClassPath)) {
				require_once $barcodeClassPath;
			}
		}
		if (!class_exists('TCPDFBarcode')) {
			return array();
		}

		try {
			$barcode = new TCPDFBarcode((string) $value, (string) $encoding);
			$barcodeArray = $barcode->getBarcodeArray();
			if (is_array($barcodeArray) && !empty($barcodeArray['bcode']) && !empty($barcodeArray['maxw'])) {
				return $barcodeArray;
			}
		} catch (Exception $e) {
			dol_syslog(__METHOD__ . ' tcpdf preview barcode fallback: ' . $e->getMessage(), LOG_DEBUG);
		}

		return array();
	}

	/**
	 * Build a deterministic preview barcode pattern.
	 *
	 * @param string $value Source value
	 * @return string
	 */
	private static function buildPreviewBarcodePattern($value)
	{
		$value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
		if ($value === '') {
			$value = '0';
		}

		$pattern = '101001';
		$chars = str_split($value);
		foreach ($chars as $index => $char) {
			$seed = ord($char) + $index;
			for ($bit = 0; $bit < 7; $bit++) {
				$pattern .= ((($seed >> ($bit % 6)) & 1) ? '11' : '1') . '0';
			}
			$pattern .= '10';
		}
		$pattern .= '101011';

		return $pattern;
	}

	/**
	 * Escape SVG text content.
	 *
	 * @param string $text Source text
	 * @return string
	 */
	private static function escapeSvgText($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Format a numeric value for SVG output.
	 *
	 * @param float $value Numeric value
	 * @return string
	 */
	private static function formatSvgNumber($value)
	{
		return rtrim(rtrim(sprintf('%.3F', (float) $value), '0'), '.');
	}
}
