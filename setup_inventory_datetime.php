<?php
/**
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

echo "<h1>Inventory date_inventory DATETIME setup</h1>\n";

// Check if user has admin rights
if (empty($user->admin)) {
	echo "<p style='color:red;'><strong>Error: Admin rights required!</strong></p>";
	exit;
}

$table = MAIN_DB_PREFIX . "inventory";

echo "<h2>1. Current Column Definition</h2>\n";
$sql = "SHOW COLUMNS FROM " . $table . " LIKE 'date_inventory'";
$resql = $db->query($sql);
if (!$resql) {
	echo "<p style='color:red;'>Error: " . $db->lasterror() . "</p>";
	exit;
}

$col = $db->fetch_object($resql);
if (!$col) {
	echo "<p style='color:red;'>Column date_inventory not found on " . $table . "</p>";
	exit;
}
echo "<p>date_inventory type: <strong>" . $col->Type . "</strong></p>";

echo "<h2>2. Convert to DATETIME (if needed)</h2>\n";
if (stripos($col->Type, 'datetime') !== false) {
	echo "<p style='color:green;'>✓ Column is already DATETIME</p>";
} else {
	$sql = "ALTER TABLE " . $table . " MODIFY date_inventory DATETIME DEFAULT NULL";
	$resql = $db->query($sql);
	if ($resql) {
		echo "<p style='color:green;'>✓ Column converted to DATETIME</p>";
	} else {
		echo "<p style='color:red;'>✗ Failed to alter column: " . $db->lasterror() . "</p>";
	}
}

echo "<h2>3. Done</h2>\n";
echo "<p>New inventories can now store time in date_inventory.</p>";
