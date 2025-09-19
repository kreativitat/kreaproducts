<?php
/**
 * Database structure check for ProductUpdater
 *
 * This script checks if the required database structure exists
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

echo "<h1>Database Structure Check for ProductUpdater</h1>\n";

echo "<h2>1. Checking Tables</h2>\n";

// Check if product_association table exists
$sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "product_association'";
$resql = $db->query($sql);
if ($resql && $db->num_rows($resql) > 0) {
    echo "<p style='color:green;'>✓ product_association table exists</p>";
} else {
    echo "<p style='color:red;'>✗ product_association table NOT found</p>";
    echo "<p><strong>This is critical! You need to create the product_association table.</strong></p>";
    exit;
}
$db->free($resql);

echo "<h2>2. Checking Columns in product_association</h2>\n";

// Get table structure
$sql = "DESCRIBE " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);

$columns = [];
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $columns[] = $obj->Field;
    }
    $db->free($resql);
}

$requiredColumns = [
    'fk_product_pere',
    'fk_product_fils',
    'qty',
    'syncbuyprice',
    'syncprice',
    'syncweight',
    'synclength',
    'syncsurface',
    'syncvolume'
];

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Column</th><th>Status</th><th>Action</th></tr>";

foreach ($requiredColumns as $col) {
    $exists = in_array($col, $columns);
    $status = $exists ? "<span style='color:green;'>✓ EXISTS</span>" : "<span style='color:red;'>✗ MISSING</span>";
    $action = $exists ? "OK" : "ALTER TABLE " . MAIN_DB_PREFIX . "product_association ADD COLUMN " . $col . " int DEFAULT 0;";

    echo "<tr><td>$col</td><td>$status</td><td>$action</td></tr>";
}

echo "</table>";

echo "<h2>3. Current product_association Data</h2>\n";

$sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    echo "<p>Total associations: " . $obj->total . "</p>";
    $db->free($resql);

    if ($obj->total > 0) {
        // Show sample data
        $sql = "SELECT fk_product_pere, fk_product_fils, qty, syncbuyprice FROM " . MAIN_DB_PREFIX . "product_association LIMIT 5";
        $resql = $db->query($sql);
        if ($resql) {
            echo "<h3>Sample Data:</h3>";
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Parent ID</th><th>Child ID</th><th>Qty</th><th>Sync Buy Price</th></tr>";
            while ($obj = $db->fetch_object($resql)) {
                echo "<tr><td>" . $obj->fk_product_pere . "</td><td>" . $obj->fk_product_fils . "</td><td>" . $obj->qty . "</td><td>" . $obj->syncbuyprice . "</td></tr>";
            }
            echo "</table>";
            $db->free($resql);
        }
    } else {
        echo "<p style='color:orange;'><strong>No product associations found!</strong> The ProductUpdater will not work without product associations.</p>";
    }
}

echo "<h2>4. SQL to Fix Missing Columns</h2>\n";

$missingColumns = array_diff($requiredColumns, $columns);
if (!empty($missingColumns)) {
    echo "<p style='color:red;'><strong>Run these SQL commands to fix missing columns:</strong></p>";
    echo "<pre>";
    foreach ($missingColumns as $col) {
        echo "ALTER TABLE " . MAIN_DB_PREFIX . "product_association ADD COLUMN " . $col . " int DEFAULT 0;\n";
    }
    echo "</pre>";
} else {
    echo "<p style='color:green;'><strong>All required columns exist!</strong></p>";
}

echo "<h2>5. Done!</h2>";
echo "<p>If you fixed missing columns, the ProductUpdater should now work properly.</p>";