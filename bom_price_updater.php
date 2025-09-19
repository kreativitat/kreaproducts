<?php
/* Copyright (C) 2024       Kreativitat             <mail@kreativitat.com>
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
 *  \file       htdocs/custom/kreaproducts/bom_price_updater.php
 *  \ingroup    kreaproducts
 *  \brief      BOM Price Updater - Updates product prices based on BOM with most recent purchases
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

// Translations
$langs->loadLangs(array("admin", "kreaproducts@kreaproducts"));

// Parameters
$action = GETPOST('action', 'alpha');
$productId = GETPOST('product_id', 'int');
$show = GETPOST('show', 'alpha');

// Access control
if (!$user->hasRight('produit', 'lire')) {
    accessforbidden();
}

/*
 * Actions
 */

// Initialize the updater
$bomUpdater = new ProductBOMPriceUpdater($db);
$bomUpdater->setDebug(true);

/*
 * View
 */

$title = $langs->trans("BOM Price Updater");
$helpurl = '';

llxHeader('', $title, $helpurl, '', 0, 0, '', '', '', 'mod-kreaproducts page-bom-price-updater');

print '<div class="fiche">';
print '<div class="tabBar tabBarWithBottom">';
print '<table class="border centpercent">';
print '<tr class="liste_titre">';
print '<td class="titlefield">' . $title . '</td>';
print '</tr>';
print '</table>';
print '</div>';
print '</div>';

// Run self-test first
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">' . $langs->trans("System Self-Test") . '</td>';
print '</tr>';
print '<tr>';
print '<td colspan="2">';

$testResults = $bomUpdater->runSelfTest();

if (empty($testResults['errors'])) {
    print '<div class="info">✓ All system tests passed!</div>';
} else {
    print '<div class="error">✗ System test errors:<ul>';
    foreach ($testResults['errors'] as $error) {
        print '<li>' . htmlspecialchars($error) . '</li>';
    }
    print '</ul></div>';
}

print '<h3>Test Results:</h3>';
print '<pre>' . print_r($testResults, true) . '</pre>';
print '</td>';
print '</tr>';
print '</table>';
print '</div>';
print '<br>';

// Get both multiple and all BOM products
$multiParentProducts = $bomUpdater->getProductsWithMultipleBOMParents();
$allBOMProducts = $bomUpdater->getProductsWithBOMParents(true, true, false); // All products with BOMs

// Tab-like interface
$showAll = GETPOST('show', 'alpha') === 'all';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="7">' . $langs->trans("Products with BOM Parents") . '</td>';
print '</tr>';
print '<tr>';
print '<td colspan="7">';

print '<div style="margin-bottom: 15px;">';
print '<strong>Statistics:</strong>';
print '<ul>';
print '<li>Products with multiple BOM parents: ' . count($multiParentProducts) . '</li>';
print '<li>Total products with BOMs: ' . count($allBOMProducts) . '</li>';
print '<li>Products with single BOM: ' . (count($allBOMProducts) - count($multiParentProducts)) . '</li>';
print '</ul>';
print '</div>';

print '<div style="margin-bottom: 15px;">';
print '<a href="?" class="button' . (!$showAll ? ' buttonactive' : '') . '">Multiple BOM Parents</a> ';
print '<a href="?show=all" class="button' . ($showAll ? ' buttonactive' : '') . '">All BOM Products</a>';
print '</div>';

$productsToShow = $showAll ? $allBOMProducts : $multiParentProducts;
$title = $showAll ? 'All Products with BOMs' : 'Products with Multiple BOM Parents';

print '<h3>' . $title . '</h3>';
print '</td>';
print '</tr>';

if (!empty($productsToShow)) {
    foreach ($productsToShow as $product) {
        $bomType = $product['bom_count'] > 1 ? 'Multiple' : 'Single';
        $typeClass = $product['bom_count'] > 1 ? 'oddeven' : 'pair';

        print '<tr class="' . $typeClass . '">';
        print '<td>' . $product['product_id'] . '</td>';
        print '<td>' . htmlspecialchars($product['ref']) . '</td>';
        print '<td>' . htmlspecialchars($product['label']) . '</td>';
        print '<td class="right">' . $product['bom_count'] . '</td>';
        print '<td>' . $bomType . '</td>';
        print '<td><small>' . htmlspecialchars($product['bom_details'] ?? 'N/A') . '</small></td>';
        print '<td>';
        print '<a href="?action=test&product_id=' . $product['product_id'] . ($showAll ? '&show=all' : '') . '" class="button">Test</a> ';
        print '<a href="?action=update_single&product_id=' . $product['product_id'] . ($showAll ? '&show=all' : '') . '" class="button">Update</a>';
        print '</td>';
        print '</tr>';
    }

    // Batch update options
    print '<tr>';
    print '<td colspan="7">';
    print '<div style="margin-top: 15px;">';
    if ($showAll) {
        print '<p><a href="?action=batch_all" class="button">🚀 Batch Update ALL Products with BOMs</a></p>';
        print '<p><small>This will update ALL ' . count($allBOMProducts) . ' products that have BOMs (single or multiple).</small></p>';
    } else {
        print '<p><a href="?action=batch_update" class="button">🚀 Batch Update Multiple BOM Products</a></p>';
        print '<p><small>This will update only the ' . count($multiParentProducts) . ' products with multiple BOM parents.</small></p>';
    }
    print '</div>';
    print '</td>';
    print '</tr>';
} else {
    print '<tr>';
    print '<td colspan="7" class="center">';
    if ($showAll) {
        print '<div class="info">No products found with BOMs.</div>';
    } else {
        print '<div class="info">No products found with multiple BOM parents.</div>';
    }
    print '</td>';
    print '</tr>';
}

print '</table>';
print '</div>';
print '<br>';

// Handle actions
if ($action == 'update_single' && $productId > 0) {
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td colspan="2">' . $langs->trans("Single Product Update Result") . '</td>';
    print '</tr>';

    // Get product details first
    $productDetails = $bomUpdater->getProductDetails($productId);
    $result = $bomUpdater->updateProductPriceFromBOM($productId, $user);

    if ($result['success']) {
        print '<tr>';
        print '<td colspan="2" class="center">';
        print '<div class="info">✓ Product updated successfully!</div>';
        print '</td>';
        print '</tr>';

        print '<tr class="liste_titre">';
        print '<th>Property</th>';
        print '<th>Value</th>';
        print '</tr>';

        print '<tr><td>Product ID</td><td>' . $result['product_id'] . '</td></tr>';
        print '<tr><td>Product Reference</td><td>' . htmlspecialchars($productDetails['ref']) . '</td></tr>';
        print '<tr><td>Product Name</td><td>' . htmlspecialchars($productDetails['label']) . '</td></tr>';
        print '<tr><td>Selected BOM ID</td><td>' . $result['selected_bom']['bom_id'] . '</td></tr>';
        print '<tr><td>Selected BOM Ref</td><td>' . htmlspecialchars($result['selected_bom']['bom_ref']) . '</td></tr>';
        print '<tr><td>Parent Product</td><td>' . htmlspecialchars($result['selected_bom']['parent_product_ref']) . '</td></tr>';
        print '<tr><td>Old Price</td><td class="right">' . number_format($result['old_price'], 4) . '</td></tr>';
        print '<tr><td>New Price</td><td class="right">' . number_format($result['new_price'], 4) . '</td></tr>';
        print '<tr><td>Total BOM Parents</td><td class="right">' . $result['bom_parents_count'] . '</td></tr>';
    } else {
        print '<tr>';
        print '<td colspan="2">';
        print '<div class="error">✗ Update failed: ' . htmlspecialchars($result['error']) . '</div>';
        print '<p><strong>Product:</strong> ' . htmlspecialchars($productDetails['ref']) . ' - ' . htmlspecialchars($productDetails['label']) . '</p>';
        print '</td>';
        print '</tr>';
    }

    print '</table>';
    print '</div>';
    print '<br>';
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

// End of page
llxFooter();