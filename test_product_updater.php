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
echo "<h3>Product Hierarchy</h3>";
$hierarchy = ProductUpdater::getProductHierarchy($testProductId);
echo "<pre>";
print_r($hierarchy);
echo "</pre>";

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