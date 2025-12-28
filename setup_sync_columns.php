<?php
/**
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 * Setup script to add sync columns to product_association table
 *
 * This script safely adds the necessary columns for ProductUpdater
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

echo "<h1>ProductUpdater Database Setup</h1>\n";

// Check if user has admin rights
if (empty($user->admin)) {
    echo "<p style='color:red;'><strong>Error: Admin rights required!</strong></p>";
    exit;
}

echo "<h2>1. Current Table Structure</h2>\n";

// Check current table structure
$sql = "DESCRIBE " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);

$existingColumns = [];
if ($resql) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($obj = $db->fetch_object($resql)) {
        $existingColumns[] = $obj->Field;
        echo "<tr><td>" . $obj->Field . "</td><td>" . $obj->Type . "</td><td>" . $obj->Null . "</td><td>" . $obj->Key . "</td><td>" . $obj->Default . "</td></tr>";
    }
    echo "</table>";
    $db->free($resql);
} else {
    echo "<p style='color:red;'>Error: Cannot read product_association table structure!</p>";
    exit;
}

echo "<h2>2. Adding Missing Sync Columns</h2>\n";

$syncColumns = [
    'syncprice' => 'int(11) DEFAULT 0 COMMENT "Enable price sync for this association"'
];

$columnsAdded = 0;
$errors = [];

foreach ($syncColumns as $column => $definition) {
    if (in_array($column, $existingColumns)) {
        echo "<p style='color:green;'>✓ Column '$column' already exists</p>";
    } else {
        echo "<p style='color:orange;'>Adding column '$column'...</p>";

        $sql = "ALTER TABLE " . MAIN_DB_PREFIX . "product_association ADD COLUMN $column $definition";
        $resql = $db->query($sql);

        if ($resql) {
            echo "<p style='color:green;'>✓ Successfully added column '$column'</p>";
            $columnsAdded++;
        } else {
            $error = "Failed to add column '$column': " . $db->lasterror();
            echo "<p style='color:red;'>✗ $error</p>";
            $errors[] = $error;
        }
    }
}

echo "<h2>3. Adding Indexes</h2>\n";

// Add index for syncprice (most important)
$sql = "CREATE INDEX idx_product_association_syncprice ON " . MAIN_DB_PREFIX . "product_association(syncprice)";
$resql = $db->query($sql);

if ($resql) {
    echo "<p style='color:green;'>✓ Added index for syncprice</p>";
} else {
    $error = $db->lasterror();
    if (strpos($error, 'Duplicate key name') !== false) {
        echo "<p style='color:green;'>✓ Index for syncprice already exists</p>";
    } else {
        echo "<p style='color:red;'>✗ Failed to add index: $error</p>";
        $errors[] = $error;
    }
}

echo "<h2>4. Setup Summary</h2>\n";

if ($columnsAdded > 0) {
    echo "<p style='color:green;'><strong>✓ Added $columnsAdded new columns successfully!</strong></p>";
}

if (empty($errors)) {
    echo "<p style='color:green;'><strong>✓ Database setup completed successfully!</strong></p>";
    echo "<p>Your ProductUpdater should now work properly. You can:</p>";
    echo "<ul>";
    echo "<li>Test it with: <a href='test_product_updater.php'>test_product_updater.php</a></li>";
    echo "<li>Check database: <a href='check_database.php'>check_database.php</a></li>";
    echo "<li>Update products via: <a href='purchasePrice.php'>purchasePrice.php</a></li>";
    echo "</ul>";
} else {
    echo "<p style='color:red;'><strong>✗ Setup completed with errors:</strong></p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li style='color:red;'>$error</li>";
    }
    echo "</ul>";
}

echo "<h2>5. Enable Sync for Existing Associations (Optional)</h2>\n";

if (isset($_POST['enable_sync'])) {
    $sql = "UPDATE " . MAIN_DB_PREFIX . "product_association SET syncprice = 1 WHERE syncprice = 0";
    $resql = $db->query($sql);

    if ($resql) {
        $affected = $db->affected_rows($resql);
        echo "<p style='color:green;'>✓ Enabled price sync for $affected associations</p>";
    } else {
        echo "<p style='color:red;'>✗ Failed to enable sync: " . $db->lasterror() . "</p>";
    }
}

// Show count of associations
$sql = "SELECT COUNT(*) as total, SUM(syncprice) as sync_enabled FROM " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    echo "<p>Total associations: <strong>" . $obj->total . "</strong></p>";
    echo "<p>Sync enabled: <strong>" . $obj->sync_enabled . "</strong></p>";
    $db->free($resql);

    if ($obj->total > 0 && $obj->sync_enabled == 0) {
        echo "<form method='POST'>";
        echo "<p style='color:orange;'><strong>Warning:</strong> No associations have sync enabled. ProductUpdater won't work until you enable sync for some associations.</p>";
        echo "<button type='submit' name='enable_sync' onclick='return confirm(\"Enable cost price sync for ALL existing associations?\")'>Enable Sync for All Associations</button>";
        echo "</form>";
    }
}

echo "<h2>6. Next Steps</h2>\n";
echo "<p>1. Test the ProductUpdater: <a href='test_product_updater.php' target='_blank'>Run Test Script</a></p>";
echo "<p>2. Check your setup: <a href='check_database.php' target='_blank'>Database Check</a></p>";
echo "<p>3. Try updating a product cost price through the Dolibarr interface</p>";