<?php
/**
 * Standalone Product BOM Price Updater
 *
 * Simple command-line script to update product prices based on BOM with most recent purchases.
 * Usage: php update_product_bom_price.php [product_id]
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

// Get command line arguments
$productId = isset($argv[1]) ? (int)$argv[1] : 0;
$action = isset($argv[2]) ? $argv[2] : 'single';

// Add debug flag support
$debugMode = in_array('--debug', $argv) || in_array('-d', $argv);

echo "=== Product BOM Price Updater ===\n";

// Initialize the updater
$bomUpdater = new ProductBOMPriceUpdater($db);
$bomUpdater->setDebug($debugMode);

// Run self-test
echo "Running system self-test...\n";
$testResults = $bomUpdater->runSelfTest();

if (!empty($testResults['errors'])) {
    echo "ERROR: System test failed:\n";
    foreach ($testResults['errors'] as $error) {
        echo "  - $error\n";
    }
    exit(1);
}

echo "✓ System test passed\n\n";

if ($action === 'list') {
    // List products with multiple BOM parents
    echo "Finding products with multiple BOM parents...\n";
    $multiParentProducts = $bomUpdater->getProductsWithMultipleBOMParents();

    if (empty($multiParentProducts)) {
        echo "No products found with multiple BOM parents.\n";
        echo "\nLet's check what BOMs exist in the system...\n";

        // Debug BOM tables
        echo "=== BOM System Debug ===\n";
        $debugInfo = $bomUpdater->debugBOMTables();

        echo "Tables exist:\n";
        foreach ($debugInfo['tables'] as $table => $exists) {
            echo "  - " . MAIN_DB_PREFIX . "$table: " . ($exists ? "✓" : "✗") . "\n";
        }

        echo "\nBOM Counts:\n";
        echo "  - Total BOMs: " . ($debugInfo['total_boms'] ?? 'N/A') . "\n";
        echo "  - Manufacturing BOMs (bomtype=0): " . ($debugInfo['manufacturing_boms'] ?? 'N/A') . "\n";
        echo "  - Validated Manufacturing BOMs (status=1): " . ($debugInfo['validated_manufacturing_boms'] ?? 'N/A') . "\n";
        echo "  - Draft Manufacturing BOMs (status=0): " . ($debugInfo['draft_manufacturing_boms'] ?? 'N/A') . "\n";
        echo "  - Valid BOM Lines: " . ($debugInfo['valid_bom_lines'] ?? 'N/A') . "\n";

        // Try with draft BOMs included if no validated BOMs found
        if (($debugInfo['validated_manufacturing_boms'] ?? 0) == 0 && ($debugInfo['draft_manufacturing_boms'] ?? 0) > 0) {
            echo "\n⚠️  No validated BOMs found, but there are draft BOMs. Trying with draft BOMs included...\n";
            $multiParentProductsWithDraft = $bomUpdater->getProductsWithMultipleBOMParents(true);
            if (!empty($multiParentProductsWithDraft)) {
                echo "Found " . count($multiParentProductsWithDraft) . " products with multiple BOM parents (including drafts):\n";
                foreach ($multiParentProductsWithDraft as $product) {
                    echo "  - Product {$product['product_id']} ({$product['ref']}): {$product['bom_count']} BOMs\n";
                }
            }
        }

        if (!empty($debugInfo['sample_boms'])) {
            echo "\nSample BOMs:\n";
            printf("%-5s %-20s %-8s %-8s %s\n", "ID", "Reference", "Type", "Status", "Lines");
            printf("%-5s %-20s %-8s %-8s %s\n", str_repeat("-", 5), str_repeat("-", 20), str_repeat("-", 8), str_repeat("-", 8), str_repeat("-", 5));
            foreach ($debugInfo['sample_boms'] as $bom) {
                printf("%-5s %-20s %-8s %-8s %s\n",
                    $bom['id'],
                    substr($bom['ref'], 0, 20),
                    $bom['bomtype'],
                    $bom['status'],
                    $bom['line_count']
                );
            }
        }

        echo "\n=== All Products in BOMs ===\n";
        $allProducts = $bomUpdater->getAllProductsInBOMs();
        if (!empty($allProducts)) {
            echo "Found " . count($allProducts) . " products that are components in BOMs:\n\n";
            printf("%-10s %-20s %-30s %-5s %s\n", "ID", "Reference", "Label", "BOMs", "BOM Details");
            printf("%-10s %-20s %-30s %-5s %s\n", str_repeat("-", 10), str_repeat("-", 20), str_repeat("-", 30), str_repeat("-", 5), str_repeat("-", 60));

            foreach ($allProducts as $product) {
                printf("%-10s %-20s %-30s %-5s %s\n",
                    $product['product_id'],
                    substr($product['ref'], 0, 20),
                    substr($product['label'], 0, 30),
                    $product['bom_count'],
                    substr($product['bom_list'], 0, 80)
                );

                // Show multiple BOM parents prominently
                if ($product['bom_count'] > 1) {
                    echo "    ⭐ MULTIPLE BOM PARENTS: {$product['bom_count']} BOMs\n";
                }
            }
        } else {
            echo "No products found as components in any BOMs.\n";
        }

    } else {
        echo "Found " . count($multiParentProducts) . " products with multiple BOM parents:\n\n";
        printf("%-10s %-20s %-30s %s\n", "ID", "Reference", "Label", "BOM Count");
        printf("%-10s %-20s %-30s %s\n", str_repeat("-", 10), str_repeat("-", 20), str_repeat("-", 30), str_repeat("-", 9));

        foreach ($multiParentProducts as $product) {
            printf("%-10s %-20s %-30s %s\n",
                $product['product_id'],
                substr($product['ref'], 0, 20),
                substr($product['label'], 0, 30),
                $product['bom_count']
            );
        }
    }

} elseif ($action === 'batch') {
    // Batch update all products with multiple BOM parents
    echo "Starting batch update of all products with multiple BOM parents...\n";

    $results = $bomUpdater->batchUpdateProductsWithMultipleBOMParents($user);

    $successCount = 0;
    $failureCount = 0;

    foreach ($results as $productId => $result) {
        if ($result['success']) {
            $successCount++;
            echo "✓ Product $productId: Updated from {$result['old_price']} to {$result['new_price']} (BOM: {$result['selected_bom']['bom_ref']})\n";
        } else {
            $failureCount++;
            echo "✗ Product $productId: {$result['error']}\n";
        }
    }

    echo "\n=== Batch Update Summary ===\n";
    echo "Success: $successCount\n";
    echo "Failures: $failureCount\n";
    echo "Total: " . ($successCount + $failureCount) . "\n";

} elseif ($action === 'all') {
    // Batch update ALL products with BOMs (single or multiple)
    echo "Starting batch update of ALL products with BOMs...\n";

    $results = $bomUpdater->batchUpdateAllProductsWithBOMs($user, true, true); // Include drafts and all BOM types

    $successCount = 0;
    $failureCount = 0;

    foreach ($results as $productId => $result) {
        if ($result['success']) {
            $successCount++;
            echo "✓ Product $productId: Updated from {$result['old_price']} to {$result['new_price']} (BOM: {$result['selected_bom']['bom_ref']})\n";
        } else {
            $failureCount++;
            echo "✗ Product $productId: {$result['error']}\n";
        }
    }

    echo "\n=== All Products Update Summary ===\n";
    echo "Success: $successCount\n";
    echo "Failures: $failureCount\n";
    echo "Total: " . ($successCount + $failureCount) . "\n";

} elseif ($action === 'listall') {
    // List ALL products with BOMs (single or multiple)
    echo "Finding ALL products with BOMs...\n";
    $allBOMProducts = $bomUpdater->getProductsWithBOMParents(true, true, false); // Include drafts, all types, single or multiple

    if (empty($allBOMProducts)) {
        echo "No products found with BOMs.\n";
    } else {
        echo "Found " . count($allBOMProducts) . " products with BOMs:\n\n";
        printf("%-10s %-20s %-30s %-5s %-8s %s\n", "ID", "Reference", "Label", "BOMs", "Type", "BOM Details");
        printf("%-10s %-20s %-30s %-5s %-8s %s\n", str_repeat("-", 10), str_repeat("-", 20), str_repeat("-", 30), str_repeat("-", 5), str_repeat("-", 8), str_repeat("-", 40));

        foreach ($allBOMProducts as $product) {
            $bomType = $product['bom_count'] > 1 ? "Multiple" : "Single";
            printf("%-10s %-20s %-30s %-5s %-8s %s\n",
                $product['product_id'],
                substr($product['ref'], 0, 20),
                substr($product['label'], 0, 30),
                $product['bom_count'],
                $bomType,
                substr($product['bom_details'], 0, 60)
            );

            // Highlight multiple BOM parents
            if ($product['bom_count'] > 1) {
                echo "    ⭐ MULTIPLE BOM PARENTS: {$product['bom_count']} BOMs\n";
            }
        }
    }

} elseif ($action === 'test' && $productId > 0) {
    // Test calculation for a product
    echo "Testing calculation for product ID: $productId\n";

    $testResult = $bomUpdater->testCalculationForProduct($productId);

    if ($testResult['success']) {
        echo "✓ Test completed for product {$testResult['product_id']}\n";
        echo "  BOM Parents found: {$testResult['bom_parents_count']}\n\n";

        foreach ($testResult['calculations'] as $calc) {
            echo "--- BOM {$calc['bom_id']}: {$calc['bom_ref']} ---\n";
            echo "  Type: " . ($calc['bom_type'] == 0 ? 'Manufacturing' : 'Dismantle') . " (bomtype={$calc['bom_type']})\n";
            echo "  Status: " . ($calc['bom_status'] == 1 ? 'Validated' : 'Draft') . "\n";
            echo "  Parent Product: {$calc['parent_product_ref']}\n";
            echo "  Current Price: " . number_format($calc['current_price'], 4) . "\n";

            if ($calc['calculation_success']) {
                echo "  Calculated Price: " . number_format($calc['calculated_price'], 4) . "\n";
                $change = $calc['calculated_price'] - $calc['current_price'];
                $changePercent = $calc['current_price'] > 0 ? ($change / $calc['current_price']) * 100 : 0;
                echo "  Price Change: " . ($change >= 0 ? "+" : "") . number_format($change, 4) . " (" . number_format($changePercent, 1) . "%)\n";

                if ($calc['bom_type'] == 1) {
                    echo "  💡 Dismantle BOM: Cost divided among components\n";
                } else {
                    echo "  💡 Manufacturing BOM: Sum of component costs\n";
                }
            } else {
                echo "  ✗ Calculation failed\n";
            }
            echo "\n";
        }
    } else {
        echo "✗ Test failed: {$testResult['error']}\n";
        exit(1);
    }

} elseif ($productId > 0) {
    // Update single product
    echo "Updating product ID: $productId\n";

    $result = $bomUpdater->updateProductPriceFromBOM($productId, $user);

    if ($result['success']) {
        echo "✓ Update successful!\n";
        echo "  Product ID: {$result['product_id']}\n";
        echo "  Selected BOM: {$result['selected_bom']['bom_ref']} (ID: {$result['selected_bom']['bom_id']})\n";
        echo "  Parent Product: {$result['selected_bom']['parent_product_ref']}\n";
        echo "  Old Price: {$result['old_price']}\n";
        echo "  New Price: {$result['new_price']}\n";
        echo "  Total BOM Parents: {$result['bom_parents_count']}\n";
    } else {
        echo "✗ Update failed: {$result['error']}\n";
        exit(1);
    }

} else {
    // Show usage
    echo "Usage:\n";
    echo "  php update_product_bom_price.php [product_id] [action] [flags]\n\n";
    echo "Actions:\n";
    echo "  [product_id]                      - Update single product\n";
    echo "  [product_id] test                - Test calculation for product (no update)\n";
    echo "  0 list                           - List products with multiple BOM parents\n";
    echo "  0 listall                        - List ALL products with BOMs (single or multiple)\n";
    echo "  0 batch                          - Batch update products with multiple BOM parents\n";
    echo "  0 all                            - Batch update ALL products with BOMs (single or multiple)\n";
    echo "  0 debug                          - Debug BOM system and show all products in BOMs\n\n";
    echo "Flags:\n";
    echo "  --debug, -d                      - Enable debug mode\n\n";
    echo "Examples:\n";
    echo "  php update_product_bom_price.php 123            - Update product ID 123\n";
    echo "  php update_product_bom_price.php 123 test       - Test calculation for product 123 (safe)\n";
    echo "  php update_product_bom_price.php 0 list         - Show products with multiple BOM parents\n";
    echo "  php update_product_bom_price.php 0 listall      - Show ALL products with BOMs\n";
    echo "  php update_product_bom_price.php 0 batch        - Update products with multiple BOM parents\n";
    echo "  php update_product_bom_price.php 0 all          - Update ALL products with BOMs\n";
    echo "  php update_product_bom_price.php 0 all --debug  - Update all with debug output\n";
}

echo "\nDone.\n";
?>