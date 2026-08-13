<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
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
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */

/**
 * \file    kreaproducts/lib/kreaproducts.lib.php
 * \ingroup kreaproducts
 * \brief   Library files with common functions for KreaProducts
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function kreaproductsAdminPrepareHead()
{
	global $db, $langs, $conf, $user;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("kreaproducts@kreaproducts");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/kreaproducts/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/kreaproducts/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['myobject']['label']) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/kreaproducts/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;


	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@kreaproducts:/kreaproducts/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreaproducts@kreaproducts');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreaproducts@kreaproducts', 'remove');

	return $head;
}

/**
 * Normalize a Dolibarr product weight-unit scale.
 *
 * Dolibarr stores weight units as powers around kilograms, so scale 0 is a
 * valid kilogram value. The native measuring-unit selector currently submits
 * an empty value for kilograms because CUnits::fetchAll() maps scale 0 to null.
 *
 * @param mixed $weightUnit    Submitted or stored unit scale
 * @param mixed $fallbackScale Unit scale used when the value is missing or invalid
 * @return int
 */
function kreaproducts_normalize_weight_unit_scale($weightUnit, $fallbackScale = 0)
{
	$fallbackScale = is_numeric($fallbackScale) ? (int) $fallbackScale : 0;
	if (!is_scalar($weightUnit)) {
		return $fallbackScale;
	}

	$weightUnit = trim((string) $weightUnit);
	if ($weightUnit === '') {
		return 0;
	}
	if (!preg_match('/^-?\d+$/D', $weightUnit)) {
		return $fallbackScale;
	}

	return (int) $weightUnit;
}

/**
 * Resolve the value expected by Dolibarr's scale-based weight selector.
 *
 * @param mixed $weightUnit Stored or submitted unit scale
 * @return string
 */
function kreaproducts_weight_unit_select_value($weightUnit)
{
	$weightUnit = kreaproducts_normalize_weight_unit_scale($weightUnit);

	return $weightUnit === 0 ? '' : (string) $weightUnit;
}

/**
 * Resolve the unified nutrition and allergen mode.
 *
 * Empty legacy values are manual because that is how the product workspace
 * historically rendered them. Mixed values resolve conservatively to the
 * least permissive mode until the unified selector synchronizes both fields.
 *
 * @param mixed $nutritionMode Saved nutrition calculation mode
 * @param mixed $allergenMode  Saved allergen calculation mode
 * @param mixed $isFood        Whether the product is food
 * @return int 0 for manual, 1 for calculated, or 2 for non-food/invalid
 */
function kreaproducts_resolve_nutrition_allergen_mode($nutritionMode, $allergenMode, $isFood = 1)
{
	if ((int) $isFood !== 1) {
		return 2;
	}

	$modes = array();
	foreach (array($nutritionMode, $allergenMode) as $mode) {
		if ($mode === null || trim((string) $mode) === '') {
			$modes[] = 0;
			continue;
		}

		$mode = trim((string) $mode);
		if (!in_array($mode, array('0', '1', '2'), true)) {
			return 2;
		}
		$modes[] = (int) $mode;
	}

	return max($modes);
}

/**
 * Normalize legacy rich text and submitted content to predictable plain text.
 *
 * Existing HTML line and list breaks are preserved as text line breaks. Script
 * and style blocks are discarded, all remaining tags are removed, and repeated
 * blank lines are collapsed. The returned value is safe to persist as plain
 * text and must still be escaped at the HTML output boundary.
 *
 * @param mixed $value Stored or submitted text
 * @return string
 */
function kreaproducts_normalize_plain_text($value)
{
	if (!is_scalar($value)) {
		return '';
	}

	$text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
	$text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
	$text = preg_replace('#<\s*li\b[^>]*>#i', '- ', $text);
	$text = preg_replace('#<\s*/\s*(p|div|li|h[1-6]|tr)\s*>#i', "\n", $text);
	$text = strip_tags($text);
	$text = str_replace("\xC2\xA0", ' ', $text);
	$text = preg_replace("/\r\n?|\x{2028}|\x{2029}/u", "\n", $text);
	$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
	$text = preg_replace('/[ \t]+\n/u', "\n", $text);
	$text = preg_replace('/\n{3,}/u', "\n\n", $text);

	return trim((string) $text);
}

/**
 * Normalize Markdown and convert legacy rich text into Markdown syntax.
 *
 * Raw HTML is never retained. Common legacy formatting is converted before
 * remaining tags are stripped, while Markdown syntax submitted by the user is
 * preserved. Rendering must use Dolibarr's dolMd2Html() safe-mode boundary.
 *
 * @param mixed $value Stored or submitted Markdown
 * @return string
 */
function kreaproducts_normalize_markdown($value)
{
	if (!is_scalar($value)) {
		return '';
	}

	$text = (string) $value;
	$text = preg_replace("/\r\n?|\x{2028}|\x{2029}/u", "\n", $text);

	// Some historical imports encoded the complete CKEditor fragment once or
	// twice. Decode only when doing so reveals real HTML, leaving ordinary
	// Markdown entities untouched.
	$htmlPattern = '#</?(?!(?:https?|mailto):)[a-z][a-z0-9]*(?:\s[^>]*)?>#i';
	$decodedCandidate = (string) $text;
	for ($decodePass = 0; $decodePass < 3; $decodePass++) {
		$decodedCandidate = html_entity_decode($decodedCandidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (preg_match($htmlPattern, $decodedCandidate)) {
			$text = $decodedCandidate;
			break;
		}
	}
	$text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $text);

	// Detect every real HTML tag, while preserving Markdown autolinks such as <https://example.com>.
	if (preg_match($htmlPattern, (string) $text)) {
		$text = preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', static function ($matches) {
			$code = preg_replace('#^<code\b[^>]*>|</code>$#is', '', (string) $matches[1]);
			$code = html_entity_decode(strip_tags((string) $code), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$fence = strpos($code, '```') === false ? '```' : '````';

			return "\n\n".$fence."\n".trim($code, "\n")."\n".$fence."\n\n";
		}, (string) $text);
		$text = preg_replace_callback('#<code\b[^>]*>(.*?)</code>#is', static function ($matches) {
			$code = html_entity_decode(strip_tags((string) $matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$fence = strpos($code, '`') === false ? '`' : '``';

			return $fence.trim($code).$fence;
		}, (string) $text);
		$text = preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function ($matches) {
			$url = html_entity_decode(trim((string) $matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$label = trim(strip_tags((string) $matches[3]));

			return kreaproducts_is_http_url($url) ? '['.$label.']('.$url.')' : $label;
		}, (string) $text);
		$text = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', static function ($matches) {
			return "\n\n".str_repeat('#', (int) $matches[1]).' '.trim(strip_tags((string) $matches[2]))."\n\n";
		}, (string) $text);
		$text = preg_replace_callback('#<blockquote\b[^>]*>(.*?)</blockquote>#is', static function ($matches) {
			$quote = kreaproducts_normalize_plain_text($matches[1]);

			return "\n\n> ".str_replace("\n", "\n> ", $quote)."\n\n";
		}, (string) $text);
		$text = preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', (string) $text);
		$text = preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '*$2*', (string) $text);
		$text = preg_replace('#<del\b[^>]*>(.*?)</del>#is', '~~$1~~', (string) $text);
		$text = preg_replace('#<\s*li\b[^>]*>#i', "\n- ", (string) $text);
		$text = preg_replace('#<\s*/\s*li\s*>#i', '', (string) $text);
		$text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", (string) $text);
		$text = preg_replace('#<\s*/\s*(p|div|ul|ol)\s*>#i', "\n\n", (string) $text);
		$text = preg_replace('#<\s*(p|div|ul|ol)\b[^>]*>#i', '', (string) $text);
		$text = strip_tags((string) $text);
		$text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	$text = str_replace("\xC2\xA0", ' ', (string) $text);
	$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text);
	$text = preg_replace('/[ \t]+\n/u', "\n", (string) $text);
	$text = preg_replace('/\n{3,}/u', "\n\n", (string) $text);

	return trim((string) $text);
}

/**
 * Import product-characteristic database values into their UI storage format.
 *
 * This is the mandatory boundary between Product::fetch_optionals() and every
 * controller, hook, renderer, and editor consumer. Legacy HTML is converted
 * here so raw markup can never reach the edit textarea.
 *
 * @param array<string,mixed> $arrayOptions Raw Product::array_options values
 * @param array<string,array<string,mixed>> $fieldDefinitions UI field definitions
 * @return array<string,mixed> Imported values with Markdown fields normalized
 */
function kreaproducts_import_characteristic_database_values($arrayOptions, $fieldDefinitions)
{
	if (!is_array($arrayOptions)) {
		$arrayOptions = array();
	}
	if (!is_array($fieldDefinitions)) {
		return $arrayOptions;
	}

	foreach ($fieldDefinitions as $fieldName => $definition) {
		if (!array_key_exists($fieldName, $arrayOptions)) {
			continue;
		}

		$arrayOptions[$fieldName] = (($definition['format'] ?? '') === 'markdown')
			? kreaproducts_normalize_markdown($arrayOptions[$fieldName])
			: kreaproducts_normalize_plain_text($arrayOptions[$fieldName]);
	}

	return $arrayOptions;
}

/**
 * Check whether a value is a complete HTTP or HTTPS URL.
 *
 * @param mixed $value URL candidate
 * @return bool
 */
function kreaproducts_is_http_url($value)
{
	if (!is_scalar($value)) {
		return false;
	}

	$value = trim((string) $value);
	$parts = parse_url($value);
	$scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';

	return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array($scheme, array('http', 'https'), true);
}
