<?php
/**
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 * Setup script to convert inventory.date_inventory to DATETIME
 */

// Include Dolibarr configuration
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include_once "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = include_once "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = include_once "../../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

$langs->loadLangs(array('kreaproducts@kreaproducts', 'other'));

echo "<h1>" . $langs->trans('KreapInventoryDatetimeSetupTitle') . "</h1>\n";

// Check if user has admin rights
if (empty($user->admin)) {
	echo "<p style='color:red;'><strong>" . $langs->trans('KreapInventoryDatetimeAdminRequired') . "</strong></p>";
	exit;
}

$table = MAIN_DB_PREFIX . "inventory";

echo "<h2>" . $langs->trans('KreapInventoryDatetimeCurrentColumn') . "</h2>\n";
$sql = "SHOW COLUMNS FROM " . $table . " LIKE 'date_inventory'";
$resql = $db->query($sql);
if (!$resql) {
	echo "<p style='color:red;'>" . $langs->trans('KreapInventoryDatetimeError', dol_escape_htmltag($db->lasterror())) . "</p>";
	exit;
}

$col = $db->fetch_object($resql);
if (!$col) {
	echo "<p style='color:red;'>" . $langs->trans('KreapInventoryDatetimeColumnNotFound', dol_escape_htmltag($table)) . "</p>";
	exit;
}
echo "<p>" . $langs->trans('KreapInventoryDatetimeTypeLabel', '<strong>' . dol_escape_htmltag($col->Type) . '</strong>') . "</p>";

echo "<h2>" . $langs->trans('KreapInventoryDatetimeConvert') . "</h2>\n";
if (stripos($col->Type, 'datetime') !== false) {
	echo "<p style='color:green;'>✓ " . $langs->trans('KreapInventoryDatetimeAlready') . "</p>";
} else {
	$sql = "ALTER TABLE " . $table . " MODIFY date_inventory DATETIME DEFAULT NULL";
	$resql = $db->query($sql);
	if ($resql) {
		echo "<p style='color:green;'>✓ " . $langs->trans('KreapInventoryDatetimeConverted') . "</p>";
	} else {
		echo "<p style='color:red;'>✗ " . $langs->trans('KreapInventoryDatetimeConvertFailed', dol_escape_htmltag($db->lasterror())) . "</p>";
	}
}

echo "<h2>" . $langs->trans('KreapInventoryDatetimeDone') . "</h2>\n";
echo "<p>" . $langs->trans('KreapInventoryDatetimeDoneText') . "</p>";
