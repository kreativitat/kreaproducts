<?php
/**
 * Test script for ProductBOMPriceUpdater
 *
 * This script demonstrates how to use the ProductBOMPriceUpdater class
 * to update product prices based on BOM with most recent purchases.
 */

// Load Dolibarr environment
require '../../main.inc.php';

require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

// Check permissions
if (!$user->hasRight('produit', 'lire')) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');
$productId = GETPOST('product_id', 'int');

?>
<!DOCTYPE html>
<html>
<head>
    <title>BOM Price Updater Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
        .success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .info { background-color: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .number { text-align: right; }
        pre { background-color: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>

<h1>Product BOM Price Updater Test</h1>

<?php

// Initialize the updater
$bomUpdater = new ProductBOMPriceUpdater($db);
$bomUpdater->setDebug(true);

// Run self-test first
echo '<div class="section">';
echo '<h2>1. System Self-Test</h2>';

$testResults = $bomUpdater->runSelfTest();

if (empty($testResults['errors'])) {
    echo '<div class="success">✓ All system tests passed!</div>';
} else {
    echo '<div class="error">✗ System test errors:<ul>';
    foreach ($testResults['errors'] as $error) {
        echo '<li>' . htmlspecialchars($error) . '</li>';
    }
    echo '</ul></div>';
}

echo '<h3>Test Results:</h3>';
echo '<pre>' . print_r($testResults, true) . '</pre>';
echo '</div>';

// Show products with BOM parents
echo '<div class="section">';
echo '<h2>2. Products with BOM Parents</h2>';

// Get both multiple and all BOM products
$multiParentProducts = $bomUpdater->getProductsWithMultipleBOMParents();
$allBOMProducts = $bomUpdater->getProductsWithBOMParents(true, true, false); // All products with BOMs

echo '<div style="margin-bottom: 15px;">';
echo '<strong>Statistics:</strong>';
echo '<ul>';
echo '<li>Products with multiple BOM parents: ' . count($multiParentProducts) . '</li>';
echo '<li>Total products with BOMs: ' . count($allBOMProducts) . '</li>';
echo '<li>Products with single BOM: ' . (count($allBOMProducts) - count($multiParentProducts)) . '</li>';
echo '</ul>';
echo '</div>';

// Tab-like interface
$showAll = GETPOST('show', 'alpha') === 'all';
echo '<div style="margin-bottom: 15px;">';
echo '<a href="?" style="padding: 8px 16px; background-color: ' . (!$showAll ? '#007cba' : '#ddd') . '; color: ' . (!$showAll ? 'white' : 'black') . '; text-decoration: none; margin-right: 5px;">Multiple BOM Parents</a>';
echo '<a href="?show=all" style="padding: 8px 16px; background-color: ' . ($showAll ? '#007cba' : '#ddd') . '; color: ' . ($showAll ? 'white' : 'black') . '; text-decoration: none;">All BOM Products</a>';
echo '</div>';

$productsToShow = $showAll ? $allBOMProducts : $multiParentProducts;
$title = $showAll ? 'All Products with BOMs' : 'Products with Multiple BOM Parents';

echo '<h3>' . $title . '</h3>';

if (!empty($productsToShow)) {
    echo '<table>';
    echo '<tr><th>Product ID</th><th>Reference</th><th>Label</th><th>BOM Count</th><th>Type</th><th>BOM Details</th><th>Actions</th></tr>';

    foreach ($productsToShow as $product) {
        $bomType = $product['bom_count'] > 1 ? 'Multiple' : 'Single';
        $typeStyle = $product['bom_count'] > 1 ? 'background-color: #fff3cd; font-weight: bold;' : '';

        echo '<tr style="' . $typeStyle . '">';
        echo '<td>' . $product['product_id'] . '</td>';
        echo '<td>' . htmlspecialchars($product['ref']) . '</td>';
        echo '<td>' . htmlspecialchars($product['label']) . '</td>';
        echo '<td class="number">' . $product['bom_count'] . '</td>';
        echo '<td>' . $bomType . '</td>';
        echo '<td><small>' . htmlspecialchars($product['bom_details'] ?? 'N/A') . '</small></td>';
        echo '<td>';
        echo '<a href="?action=test&product_id=' . $product['product_id'] . ($showAll ? '&show=all' : '') . '">Test</a> | ';
        echo '<a href="?action=update_single&product_id=' . $product['product_id'] . ($showAll ? '&show=all' : '') . '">Update</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';

    // Batch update options
    echo '<div style="margin-top: 15px;">';
    if ($showAll) {
        echo '<p><a href="?action=batch_all" style="background-color: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">🚀 Batch Update ALL Products with BOMs</a></p>';
        echo '<p><small>This will update ALL ' . count($allBOMProducts) . ' products that have BOMs (single or multiple).</small></p>';
    } else {
        echo '<p><a href="?action=batch_update" style="background-color: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">🚀 Batch Update Multiple BOM Products</a></p>';
        echo '<p><small>This will update only the ' . count($multiParentProducts) . ' products with multiple BOM parents.</small></p>';
    }
    echo '</div>';
} else {
    if ($showAll) {
        echo '<div class="info">No products found with BOMs.</div>';
    } else {
        echo '<div class="info">No products found with multiple BOM parents.</div>';
    }
}

echo '</div>';

// Handle actions
if ($action == 'update_single' && $productId > 0) {
    echo '<div class="section">';
    echo '<h2>3. Single Product Update Result</h2>';

    // Get product details first
    $productDetails = $bomUpdater->getProductDetails($productId);

    $result = $bomUpdater->updateProductPriceFromBOM($productId, $user);

    if ($result['success']) {
        echo '<div class="success">✓ Product updated successfully!</div>';
        echo '<h3>Update Details:</h3>';
        echo '<table>';
        echo '<tr><th>Property</th><th>Value</th></tr>';
        echo '<tr><td>Product ID</td><td>' . $result['product_id'] . '</td></tr>';
        echo '<tr><td>Product Reference</td><td>' . htmlspecialchars($productDetails['ref']) . '</td></tr>';
        echo '<tr><td>Product Name</td><td>' . htmlspecialchars($productDetails['label']) . '</td></tr>';
        echo '<tr><td>Selected BOM ID</td><td>' . $result['selected_bom']['bom_id'] . '</td></tr>';
        echo '<tr><td>Selected BOM Ref</td><td>' . htmlspecialchars($result['selected_bom']['bom_ref']) . '</td></tr>';
        echo '<tr><td>Parent Product</td><td>' . htmlspecialchars($result['selected_bom']['parent_product_ref']) . '</td></tr>';
        echo '<tr><td>Old Price</td><td class="number">' . number_format($result['old_price'], 4) . '</td></tr>';
        echo '<tr><td>New Price</td><td class="number">' . number_format($result['new_price'], 4) . '</td></tr>';
        echo '<tr><td>Total BOM Parents</td><td class="number">' . $result['bom_parents_count'] . '</td></tr>';
        echo '</table>';
    } else {
        echo '<div class="error">✗ Update failed: ' . htmlspecialchars($result['error']) . '</div>';
        echo '<p><strong>Product:</strong> ' . htmlspecialchars($productDetails['ref']) . ' - ' . htmlspecialchars($productDetails['label']) . '</p>';
    }

    echo '</div>';
}

if ($action == 'batch_update') {
    echo '<div class="section">';
    echo '<h2>3. Batch Update Results</h2>';

    $batchResults = $bomUpdater->batchUpdateProductsWithMultipleBOMParents($user);

    echo '<table>';
    echo '<tr><th>Product ID</th><th>Product Name</th><th>Status</th><th>Details</th><th>Old Price</th><th>New Price</th></tr>';

    $successCount = 0;
    $failureCount = 0;

    foreach ($batchResults as $productId => $result) {
        // Get product details for display
        $productDetails = $bomUpdater->getProductDetails($productId);

        echo '<tr>';
        echo '<td>' . $productId . '</td>';
        echo '<td>' . htmlspecialchars($productDetails['ref']) . '<br><small>' . htmlspecialchars($productDetails['label']) . '</small></td>';

        if ($result['success']) {
            $successCount++;
            echo '<td><span style="color: green;">✓ Success</span></td>';
            echo '<td>BOM: ' . htmlspecialchars($result['selected_bom']['bom_ref']) . '</td>';
            echo '<td class="number">' . number_format($result['old_price'], 4) . '</td>';
            echo '<td class="number">' . number_format($result['new_price'], 4) . '</td>';
        } else {
            $failureCount++;
            echo '<td><span style="color: red;">✗ Failed</span></td>';
            echo '<td>' . htmlspecialchars($result['error']) . '</td>';
            echo '<td>-</td>';
            echo '<td>-</td>';
        }

        echo '</tr>';
    }

    echo '</table>';

    echo '<div class="info">';
    echo '<strong>Summary:</strong> ' . $successCount . ' products updated successfully, ' . $failureCount . ' failed.';
    echo '</div>';

    echo '</div>';
}

if ($action == 'batch_all') {
    echo '<div class="section">';
    echo '<h2>3. Batch Update All BOM Products Results</h2>';

    $batchResults = $bomUpdater->batchUpdateAllProductsWithBOMs($user, true, true); // Include drafts and all BOM types

    echo '<table>';
    echo '<tr><th>Product ID</th><th>Product Name</th><th>Status</th><th>Details</th><th>Old Price</th><th>New Price</th></tr>';

    $successCount = 0;
    $failureCount = 0;

    foreach ($batchResults as $productId => $result) {
        // Get product details for display
        $productDetails = $bomUpdater->getProductDetails($productId);

        echo '<tr>';
        echo '<td>' . $productId . '</td>';
        echo '<td>' . htmlspecialchars($productDetails['ref']) . '<br><small>' . htmlspecialchars($productDetails['label']) . '</small></td>';

        if ($result['success']) {
            $successCount++;
            echo '<td><span style="color: green;">✓ Success</span></td>';
            echo '<td>BOM: ' . htmlspecialchars($result['selected_bom']['bom_ref']) . '</td>';
            echo '<td class="number">' . number_format($result['old_price'], 4) . '</td>';
            echo '<td class="number">' . number_format($result['new_price'], 4) . '</td>';
        } else {
            $failureCount++;
            echo '<td><span style="color: red;">✗ Failed</span></td>';
            echo '<td>' . htmlspecialchars($result['error']) . '</td>';
            echo '<td>-</td>';
            echo '<td>-</td>';
        }

        echo '</tr>';
    }

    echo '</table>';

    echo '<div class="info">';
    echo '<strong>Summary:</strong> ' . $successCount . ' products updated successfully, ' . $failureCount . ' failed.';
    echo '</div>';

    echo '</div>';
}

if ($action == 'test' && $productId > 0) {
    echo '<div class="section">';
    echo '<h2>3. Price Calculation Test</h2>';

    // Get product details first
    $productDetails = $bomUpdater->getProductDetails($productId);

    echo '<div class="info">';
    echo '<strong>Testing Product:</strong> ' . htmlspecialchars($productDetails['ref']) . ' - ' . htmlspecialchars($productDetails['label']);
    echo '<br><strong>Current Cost Price:</strong> ' . number_format($productDetails['cost_price'], 4);
    echo '</div>';

    $testResult = $bomUpdater->testCalculationForProduct($productId);

    if ($testResult['success']) {
        echo '<div class="success">✓ Test completed for product ' . $testResult['product_id'] . '</div>';
        echo '<p><strong>BOM Parents found:</strong> ' . $testResult['bom_parents_count'] . '</p>';

        foreach ($testResult['calculations'] as $calc) {
            echo '<div style="border: 1px solid #ddd; margin: 10px 0; padding: 15px;">';
            echo '<h4>BOM ' . $calc['bom_id'] . ': ' . htmlspecialchars($calc['bom_ref']) . '</h4>';
            echo '<table>';
            echo '<tr><th>Property</th><th>Value</th></tr>';
            echo '<tr><td>BOM Type</td><td>' . ($calc['bom_type'] == 0 ? 'Manufacturing' : 'Dismantle') . ' (bomtype=' . $calc['bom_type'] . ')</td></tr>';
            echo '<tr><td>BOM Status</td><td>' . ($calc['bom_status'] == 1 ? 'Validated' : 'Draft') . '</td></tr>';
            echo '<tr><td>Parent Product</td><td>' . htmlspecialchars($calc['parent_product_ref']) . '</td></tr>';
            echo '<tr><td>Current Price</td><td class="number">' . number_format($calc['current_price'], 4) . '</td></tr>';

            if ($calc['calculation_success']) {
                echo '<tr><td>Calculated Price</td><td class="number"><strong>' . number_format($calc['calculated_price'], 4) . '</strong></td></tr>';
                $change = $calc['calculated_price'] - $calc['current_price'];
                $changePercent = $calc['current_price'] > 0 ? ($change / $calc['current_price']) * 100 : 0;
                echo '<tr><td>Price Change</td><td class="number">';
                echo ($change >= 0 ? "+" : "") . number_format($change, 4) . ' (' . number_format($changePercent, 1) . '%)';
                echo '</td></tr>';

                if ($calc['bom_type'] == 1) {
                    echo '<tr><td>Calculation Type</td><td>💡 Dismantle BOM: Origin cost divided among components</td></tr>';
                } else {
                    echo '<tr><td>Calculation Type</td><td>💡 Manufacturing BOM: Sum of component costs</td></tr>';
                }
            } else {
                echo '<tr><td>Calculation Result</td><td style="color: red;">✗ Calculation failed</td></tr>';
            }
            echo '</table>';
            echo '</div>';
        }

        echo '<p><strong>Actions:</strong></p>';
        echo '<p>';
        echo '<a href="?action=update_single&product_id=' . $productId . '" class="button" style="background-color: #007cba; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;">Apply Update</a> ';
        echo '<a href="?" style="margin-left: 10px;">← Back to Main</a>';
        echo '</p>';

    } else {
        echo '<div class="error">✗ Test failed: ' . htmlspecialchars($testResult['error']) . '</div>';
        echo '<p><strong>Product:</strong> ' . htmlspecialchars($productDetails['ref']) . ' - ' . htmlspecialchars($productDetails['label']) . '</p>';
    }

    echo '</div>';
}

?>

</body>
</html>