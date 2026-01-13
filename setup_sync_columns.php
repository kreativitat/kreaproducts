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

$langs->loadLangs(array('kreaproducts@kreaproducts', 'other'));

echo "<h1>" . $langs->trans('KreapSetupSyncTitle') . "</h1>\n";

// Check if user has admin rights
if (empty($user->admin)) {
    echo "<p style='color:red;'><strong>" . $langs->trans('KreapAdminRightsRequired') . "</strong></p>";
    exit;
}

echo "<h2>" . $langs->trans('KreapSetupCurrentTableStructure') . "</h2>\n";

// Check current table structure
$sql = "DESCRIBE " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);

$existingColumns = [];
if ($resql) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>" . $langs->trans('KreapSetupColumn') . "</th><th>" . $langs->trans('KreapSetupType') . "</th><th>" . $langs->trans('KreapSetupNull') . "</th><th>" . $langs->trans('KreapSetupKey') . "</th><th>" . $langs->trans('KreapSetupDefault') . "</th></tr>";
    while ($obj = $db->fetch_object($resql)) {
        $existingColumns[] = $obj->Field;
        echo "<tr><td>" . $obj->Field . "</td><td>" . $obj->Type . "</td><td>" . $obj->Null . "</td><td>" . $obj->Key . "</td><td>" . $obj->Default . "</td></tr>";
    }
    echo "</table>";
    $db->free($resql);
} else {
    echo "<p style='color:red;'>" . $langs->trans('KreapSetupCannotReadStructure') . "</p>";
    exit;
}

echo "<h2>" . $langs->trans('KreapSetupAddingColumns') . "</h2>\n";

$syncColumns = [
    'syncprice' => 'int(11) DEFAULT 0 COMMENT "Enable price sync for this association"'
];

$columnsAdded = 0;
$errors = [];

foreach ($syncColumns as $column => $definition) {
    if (in_array($column, $existingColumns)) {
        echo "<p style='color:green;'>✓ " . $langs->trans('KreapSetupColumnExists', dol_escape_htmltag($column)) . "</p>";
    } else {
        echo "<p style='color:orange;'>" . $langs->trans('KreapSetupAddingColumn', dol_escape_htmltag($column)) . "</p>";

        $sql = "ALTER TABLE " . MAIN_DB_PREFIX . "product_association ADD COLUMN $column $definition";
        $resql = $db->query($sql);

        if ($resql) {
            echo "<p style='color:green;'>✓ " . $langs->trans('KreapSetupColumnAdded', dol_escape_htmltag($column)) . "</p>";
            $columnsAdded++;
        } else {
            $error = $langs->trans('KreapSetupColumnAddFailed', dol_escape_htmltag($column), dol_escape_htmltag($db->lasterror()));
            echo "<p style='color:red;'>✗ $error</p>";
            $errors[] = $error;
        }
    }
}

echo "<h2>" . $langs->trans('KreapSetupAddingIndexes') . "</h2>\n";

// Add index for syncprice (most important)
$sql = "CREATE INDEX idx_product_association_syncprice ON " . MAIN_DB_PREFIX . "product_association(syncprice)";
$resql = $db->query($sql);

if ($resql) {
    echo "<p style='color:green;'>✓ " . $langs->trans('KreapSetupIndexAdded') . "</p>";
} else {
    $error = $db->lasterror();
    if (strpos($error, 'Duplicate key name') !== false) {
        echo "<p style='color:green;'>✓ " . $langs->trans('KreapSetupIndexExists') . "</p>";
    } else {
        $error = $langs->trans('KreapSetupIndexFailed', dol_escape_htmltag($error));
        echo "<p style='color:red;'>✗ $error</p>";
        $errors[] = $error;
    }
}

echo "<h2>" . $langs->trans('KreapSetupSummary') . "</h2>\n";

if ($columnsAdded > 0) {
    echo "<p style='color:green;'><strong>✓ " . $langs->trans('KreapSetupColumnsAdded', (int) $columnsAdded) . "</strong></p>";
}

if (empty($errors)) {
    echo "<p style='color:green;'><strong>✓ " . $langs->trans('KreapSetupCompleted') . "</strong></p>";
    echo "<p>" . $langs->trans('KreapSetupNextStepsIntro') . "</p>";
    echo "<ul>";
    echo "<li>" . $langs->trans('KreapSetupTestScript', "<a href='test_product_updater.php'>test_product_updater.php</a>") . "</li>";
    echo "<li>" . $langs->trans('KreapSetupCheckDatabase', "<a href='check_database.php'>check_database.php</a>") . "</li>";
    echo "<li>" . $langs->trans('KreapSetupUpdateProductsVia', "<a href='purchasePrice.php'>purchasePrice.php</a>") . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color:red;'><strong>✗ " . $langs->trans('KreapSetupCompletedWithErrors') . "</strong></p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li style='color:red;'>$error</li>";
    }
    echo "</ul>";
}

echo "<h2>" . $langs->trans('KreapSetupEnableSyncSection') . "</h2>\n";

if (isset($_POST['enable_sync'])) {
    $sql = "UPDATE " . MAIN_DB_PREFIX . "product_association SET syncprice = 1 WHERE syncprice = 0";
    $resql = $db->query($sql);

    if ($resql) {
        $affected = $db->affected_rows($resql);
        echo "<p style='color:green;'>✓ " . $langs->trans('KreapSetupSyncEnabled', (int) $affected) . "</p>";
    } else {
        echo "<p style='color:red;'>✗ " . $langs->trans('KreapSetupSyncEnableFailed', dol_escape_htmltag($db->lasterror())) . "</p>";
    }
}

// Show count of associations
$sql = "SELECT COUNT(*) as total, SUM(syncprice) as sync_enabled FROM " . MAIN_DB_PREFIX . "product_association";
$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    echo "<p>" . $langs->trans('KreapSetupTotalAssociations', '<strong>' . (int) $obj->total . '</strong>') . "</p>";
    echo "<p>" . $langs->trans('KreapSetupSyncEnabledCount', '<strong>' . (int) $obj->sync_enabled . '</strong>') . "</p>";
    $db->free($resql);

    if ($obj->total > 0 && $obj->sync_enabled == 0) {
        echo "<form method='POST'>";
        echo "<input type='hidden' name='token' value='" . newToken() . "'>";
        echo "<p style='color:orange;'><strong>" . $langs->trans('Warning') . ":</strong> " . $langs->trans('KreapSetupSyncWarning') . "</p>";
        echo "<button type='submit' name='enable_sync' onclick='return confirm(\"" . dol_escape_js($langs->trans('KreapSetupEnableSyncConfirm')) . "\")'>" . $langs->trans('KreapSetupEnableSyncButton') . "</button>";
        echo "</form>";
    }
}

echo "<h2>" . $langs->trans('KreapSetupNextSteps') . "</h2>\n";
echo "<p>" . $langs->trans('KreapSetupNextStep1', "<a href='test_product_updater.php' target='_blank'>" . $langs->trans('KreapSetupRunTestScript') . "</a>") . "</p>";
echo "<p>" . $langs->trans('KreapSetupNextStep2', "<a href='check_database.php' target='_blank'>" . $langs->trans('KreapSetupDatabaseCheck') . "</a>") . "</p>";
echo "<p>" . $langs->trans('KreapSetupNextStep3') . "</p>";
