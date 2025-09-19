<?php
/**
 * Test script for ProductUpdater class
 *
 * This script helps debug and test the ProductUpdater functionality
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once __DIR__ . '/class/ProductUpdater.class.php';

// Set debug mode
ProductUpdater::setDebug(true);

echo "<h1>ProductUpdater Test Script</h1>\n";

// Run self-test first
echo "<h2>1. Self-Test Results</h2>\n";
$testResults = ProductUpdater::runSelfTest();

echo "<pre>";
print_r($testResults);
echo "</pre>";

if (!empty($testResults['errors'])) {
    echo "<p style='color:red;'><strong>Issues found:</strong></p>";
    foreach ($testResults['errors'] as $error) {
        echo "<p style='color:red;'>- $error</p>";
    }
    echo "<p><strong>Please fix these issues before proceeding.</strong></p>";
    exit;
}

echo "<p style='color:green;'><strong>Self-test passed!</strong></p>";

// Test with a specific product ID (you can change this)
$testProductId = 1; // Change this to a valid product ID in your system

echo "<h2>2. Testing with Sample Product</h2>\n";
echo "<form method='GET'>";
echo "Test Product ID: <input type='number' name='test_id' value='" . (isset($_GET['test_id']) ? (int)$_GET['test_id'] : $testProductId) . "' />";
echo "<input type='submit' value='Test' />";
echo "</form>";

if (isset($_GET['test_id'])) {
    $testProductId = (int)$_GET['test_id'];
}

echo "<h2>2. Testing Product Cost Price Update</h2>\n";
echo "<p>Testing with Product ID: $testProductId</p>";

// Get product hierarchy first
echo "<h3>Product Hierarchy (including BOM relationships)</h3>";
$hierarchy = ProductUpdater::getProductHierarchy($testProductId);
echo "<pre>";
print_r($hierarchy);
echo "</pre>";

// Check if product has BOM-based relationships
echo "<h3>BOM Relationship Analysis</h3>";
// First load the product map so we can check relationships
ProductUpdater::getProductHierarchy($testProductId); // This loads the map

// Now check for BOM module status
global $conf;
if (!empty($conf->bom->enabled)) {
    echo "<p style='color:green;'>✓ BOM module is enabled</p>";
} else {
    echo "<p style='color:orange;'>⚠ BOM module is not enabled - only product associations will be used</p>";
}

echo "<p><strong>Relationship Summary:</strong></p>";
if (!empty($hierarchy['children'])) {
    echo "<p>Product has " . count($hierarchy['children']) . " children:</p>";
    echo "<ul>";
    foreach ($hierarchy['children'] as $child) {
        $source = isset($child['qty']) ? 'relationship found' : 'unknown';
        if (isset($child['product'])) {
            echo "<li>" . $child['product']['ref'] . " (" . $child['product']['label'] . ")" .
                 " - Qty: " . $child['qty'] . "</li>";
        }
    }
    echo "</ul>";
} else {
    echo "<p>Product has no child relationships (neither associations nor BOM).</p>";
}

// Test extrafield functionality first
echo "<h3>Extrafield Sync Test</h3>";
$product = new Product($db);
if ($product->fetch($testProductId) > 0) {
    echo "<p>Testing kreap_syncprice extrafield for product: " . $product->ref . "</p>";

    // Load extrafields
    require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
    $extrafields = new ExtraFields($db);
    $product->fetch_optionals($testProductId, $extrafields);

    echo "<p>Product extrafields:</p>";
    echo "<pre>";
    print_r($product->array_options);
    echo "</pre>";

    // Check if kreap_syncprice is set
    $syncFieldName = 'options_kreap_syncprice';
    $syncEnabled = !empty($product->array_options[$syncFieldName]);
    echo "<p>kreap_syncprice field status: " . ($syncEnabled ? 'ENABLED' : 'DISABLED') . "</p>";

    if (!isset($product->array_options[$syncFieldName])) {
        echo "<p style='color:orange;'><strong>Note:</strong> kreap_syncprice extrafield not found. Make sure the module is properly activated and extrafields are created.</p>";
        echo "<p><strong>Solutions:</strong></p>";
        echo "<ul>";
        echo "<li>1. Disable and re-enable the KreaProducts module to recreate extrafields</li>";
        echo "<li>2. Check if translations are properly added to language files</li>";
        echo "<li>3. Clear Dolibarr cache and check extrafield setup</li>";
        echo "</ul>";
    } else {
        echo "<p style='color:green;'><strong>Success:</strong> kreap_syncprice extrafield is properly configured and available!</p>";
    }
} else {
    echo "<p style='color:red;'>Failed to load product ID: $testProductId</p>";
}

// Test cost price update
echo "<h3>Cost Price Update Results</h3>";
$results = ProductUpdater::updateProductCostPrice($testProductId);
echo "<pre>";
print_r($results);
echo "</pre>";

// Test onProductModified method
echo "<h3>On Product Modified Results</h3>";
$results2 = ProductUpdater::onProductModified($testProductId);
echo "<pre>";
print_r($results2);
echo "</pre>";

echo "<h2>3. Done!</h2>";
echo "<p>Check the debug logs in your Dolibarr log files for detailed information.</p>";