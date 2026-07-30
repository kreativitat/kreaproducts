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
 */

/**
 * Setup script to convert inventory.date_inventory to DATETIME
 */

// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php'))    $res = @include __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = @include __DIR__ . '/../../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../master.inc.php'))  $res = @include __DIR__ . '/../master.inc.php';
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) $res = @include __DIR__ . '/../../master.inc.php';
if (!$res) die('Failed to include main.inc.php');

$langs->loadLangs(array('kreaproducts@kreaproducts', 'other'));

echo "<h1>" . $langs->trans('KreapInventoryDatetimeSetupTitle') . "</h1>\n";

// Check if user has admin rights
if (empty($user->admin)) {
	echo "<p style='color:red;'><strong>" . $langs->trans('KreapInventoryDatetimeAdminRequired') . "</strong></p>";
	exit;
}

$action = GETPOST('action', 'aZ09');
$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
if ($isPost && (empty(GETPOST('token', 'alphanohtml'))
	|| (!hash_equals((string) currentToken(), (string) GETPOST('token', 'alphanohtml'))
		&& !hash_equals((string) newToken(), (string) GETPOST('token', 'alphanohtml'))))
) {
	accessforbidden($langs->trans('ErrorBadToken'));
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
	if ($isPost && $action === 'convert_inventory_datetime') {
		$sql = "ALTER TABLE " . $table . " MODIFY date_inventory DATETIME DEFAULT NULL";
		$resql = $db->query($sql);
		if ($resql) {
			echo "<p style='color:green;'>✓ " . $langs->trans('KreapInventoryDatetimeConverted') . "</p>";
		} else {
			echo "<p style='color:red;'>✗ " . $langs->trans('KreapInventoryDatetimeConvertFailed', dol_escape_htmltag($db->lasterror())) . "</p>";
		}
	} else {
		echo '<form method="POST">';
		echo '<input type="hidden" name="token" value="' . newToken() . '">';
		echo '<input type="hidden" name="action" value="convert_inventory_datetime">';
		echo '<button type="submit" class="button">' . $langs->trans('Modify') . '</button>';
		echo '</form>';
	}
}

echo "<h2>" . $langs->trans('KreapInventoryDatetimeDone') . "</h2>\n";
echo "<p>" . $langs->trans('KreapInventoryDatetimeDoneText') . "</p>";
