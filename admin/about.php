<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
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
 * \file    kreaproducts/admin/about.php
 * \ingroup kreaproducts
 * \brief   About page of module KreaProducts.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../lib/kreaproducts.lib.php';

// Translations
$langs->loadLangs(array("errors", "admin", "kreaproducts@kreaproducts"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

/**
 * Parse latest module changelog release entry.
 *
 * @param string $fullPath Absolute path to ChangeLog.md
 * @return array{version:string,date:string,sections:array}
 */
function kreaproductsGetLatestChangeLogEntry($fullPath)
{
	$result = array(
		'version' => '',
		'date' => '',
		'sections' => array(),
	);
	if (empty($fullPath) || !is_readable($fullPath)) {
		return $result;
	}

	$lines = @file($fullPath, FILE_IGNORE_NEW_LINES);
	if (!is_array($lines) || empty($lines)) {
		return $result;
	}

	$inEntry = false;
	$currentSection = '';
	foreach ($lines as $line) {
		$line = trim((string) $line);
		if ($line === '') {
			continue;
		}

		if (preg_match('/^##\s+\[([^\]]+)\]\s*-\s*([0-9]{4}-[0-9]{2}-[0-9]{2})$/', $line, $matches)) {
			if ($inEntry) {
				break;
			}

			$result['version'] = (string) $matches[1];
			$result['date'] = (string) $matches[2];
			$inEntry = true;
			continue;
		}

		if (!$inEntry) {
			continue;
		}

		if (preg_match('/^###\s+(.+)$/', $line, $matches)) {
			$currentSection = trim((string) $matches[1]);
			if ($currentSection === '') {
				$currentSection = 'Changes';
			}
			if (empty($result['sections'][$currentSection]) || !is_array($result['sections'][$currentSection])) {
				$result['sections'][$currentSection] = array();
			}
			continue;
		}

		if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
			if ($currentSection === '') {
				$currentSection = 'Changes';
				if (empty($result['sections'][$currentSection]) || !is_array($result['sections'][$currentSection])) {
					$result['sections'][$currentSection] = array();
				}
			}
			$result['sections'][$currentSection][] = trim((string) $matches[1]);
		}
	}

	return $result;
}


/*
 * Actions
 */

// None


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "KreaProductsAbout";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-kreaproducts page-admin_about');

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = kreaproductsAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, 'kreaproducts@kreaproducts');

dol_include_once('/kreaproducts/core/modules/modKreaProducts.class.php');
$tmpmodule = new modKreaProducts($db);
print $tmpmodule->getDescLong();

// Custom about block with branding and key facts
$logoUrl = dol_buildpath('/custom/kreaproducts/img/logo.png', 1);
$moduleName = $tmpmodule->getName();
$moduleVersion = $tmpmodule->getVersion();
$editorName = $tmpmodule->editor_name;
$editorUrl = $tmpmodule->editor_url;
$supportEmail = 'mail@kreativitat.com';
$latestRelease = kreaproductsGetLatestChangeLogEntry(dirname(__DIR__) . '/ChangeLog.md');

print '<div class="fichecenter" style="margin-top: 18px; display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';

print '<div style="flex:1 1 260px; max-width:320px; text-align:center;">';
print '<img src="' . $logoUrl . '" alt="' . dol_escape_htmltag($moduleName) . '" style="max-width: 260px; height: auto;">';
print '</div>';

print '<div style="flex:2 1 340px; min-width:320px;">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutModuleLabel') . '</td><td>' . dol_escape_htmltag($moduleName) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutVersionLabel') . '</td><td>' . dol_escape_htmltag($moduleVersion) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutEditorLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorName) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutLicenseLabel') . '</td><td>' . $langs->trans('KreapAboutLicenseValue') . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutSupportLabel') . '</td><td><a href="mailto:' . dol_escape_htmltag($supportEmail) . '">' . dol_escape_htmltag($supportEmail) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutWebsiteLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorUrl) . '</a></td></tr>';
print '</table>';

print '<div style="margin-top: 14px;">';
print '<div class="bold" style="margin-bottom: 6px;">' . $langs->trans('KreapAboutChangeLogLabel') . '</div>';
if (!empty($latestRelease['version'])) {
	print '<table class="noborder centpercent">';
	print '<tr><td class="titlefield" style="width: 180px;">' . $langs->trans('KreapAboutLatestReleaseLabel') . '</td><td>' . dol_escape_htmltag((string) $latestRelease['version']) . '</td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans('KreapAboutReleaseDateLabel') . '</td><td>' . dol_escape_htmltag((string) $latestRelease['date']) . '</td></tr>';
	print '</table>';

	if (!empty($latestRelease['sections']) && is_array($latestRelease['sections'])) {
		foreach ($latestRelease['sections'] as $sectionTitle => $items) {
			if (!is_array($items) || empty($items)) {
				continue;
			}
			print '<div class="opacitymedium" style="margin-top: 8px; margin-bottom: 2px;">' . dol_escape_htmltag((string) $sectionTitle) . '</div>';
			print '<ul style="margin-top: 0; margin-bottom: 0;">';
			foreach ($items as $item) {
				print '<li>' . dol_escape_htmltag((string) $item) . '</li>';
			}
			print '</ul>';
		}
	}
} else {
	print '<div class="opacitymedium">' . $langs->trans('KreapAboutNoReleaseNotes') . '</div>';
}
print '</div>';
print '</div>';

print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
