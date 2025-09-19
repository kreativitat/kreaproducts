# Product BOM Price Updater - Integration Guide

## 📁 Files Created

1. **`/class/ProductBOMPriceUpdater.class.php`** - Main BOM price updater class
2. **`/class/EnhancedProductUpdater.class.php`** - Enhanced version of existing ProductUpdater
3. **`/test_bom_price_updater.php`** - Web interface for testing and demonstration
4. **`/update_product_bom_price.php`** - Command-line script for batch operations

## 🚀 Quick Start

### Command Line Usage

```bash
# List products with multiple BOM parents
php update_product_bom_price.php 0 list

# Update a specific product
php update_product_bom_price.php 123

# Batch update all products with multiple BOM parents
php update_product_bom_price.php 0 batch
```

### Web Interface

Access: `/custom/kreaproducts/test_bom_price_updater.php`

## 🔧 Integration Options

### Option 1: Direct Integration (Recommended)

Add to your existing `purchasePrice.php` file after line 193:

```php
// Add BOM-based price update after cost price update
if ($result > 0) {
    // Existing code...

    // Add BOM price update
    require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

    $bomUpdater = new ProductBOMPriceUpdater($db);
    $bomResult = $bomUpdater->updateProductPriceFromBOM($object->id, $user);

    if ($bomResult['success']) {
        setEventMessages("BOM price updated: " . $bomResult['old_price'] . " → " . $bomResult['new_price'], null, 'mesgs');
    }
}
```

### Option 2: Enhanced ProductUpdater

Replace your existing ProductUpdater calls with the enhanced version:

```php
// Instead of:
// ProductHierarchy::updateProductAttributes($object->id, $user);

// Use:
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/EnhancedProductUpdater.class.php';
EnhancedProductUpdater::setBOMPriorityMode(true);
$results = EnhancedProductUpdater::updateProductCostPriceEnhanced($object->id, true, true);
```

### Option 3: Trigger Integration

Add to your trigger class (`KreaProductsTriggers.class.php`):

```php
public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
{
    if ($action == 'PRODUCT_MODIFY') {
        require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductBOMPriceUpdater.class.php';

        $bomUpdater = new ProductBOMPriceUpdater($this->db);
        $result = $bomUpdater->updateProductPriceFromBOM($object->id, $user);

        if ($result['success']) {
            dol_syslog("BOM price updated for product " . $object->id);
        }
    }

    return 0;
}
```

## 🎯 Key Features Explained

### 1. Multiple BOM Parent Detection
When a product is used in multiple BOMs, the system:
- Finds all BOM parents where this product is a component
- Analyzes purchase history for each parent product
- Selects the parent with most recent purchases

### 2. Smart Price Calculation
- Uses actual component costs from the selected BOM
- Considers BOM quantities and ratios
- Only updates if price change is significant (> 0.001)

### 3. Purchase History Analysis
Queries supplier invoices (`facture_fourn`) to find:
- Most recent purchase dates for each BOM parent
- Only considers validated invoices (`fk_statut = 1`)
- Falls back to first BOM if no purchase history exists

## 📊 Database Queries Used

### Find BOM Parents
```sql
SELECT b.rowid as bom_id, b.ref as bom_ref, b.fk_product as parent_product_id,
       p.ref as parent_product_ref, bl.qty as qty_in_bom
FROM llx_bom_bom b
JOIN llx_bom_bomline bl ON b.rowid = bl.fk_bom
JOIN llx_product p ON p.rowid = b.fk_product
WHERE bl.fk_product = [PRODUCT_ID]
AND b.bomtype = 0 AND b.status = 1
```

### Find Recent Purchases
```sql
SELECT MAX(f.datef) as most_recent_date
FROM llx_facture_fourn f
JOIN llx_facture_fourn_det d ON f.rowid = d.fk_facture_fourn
WHERE d.fk_product = [PARENT_PRODUCT_ID]
AND f.fk_statut = 1
```

## 🔍 Troubleshooting

### Common Issues

1. **"No BOM parents found"**
   - Product is not used as component in any BOM
   - Check that BOMs are validated (`status = 1`)
   - Verify BOM type is manufacturing (`bomtype = 0`)

2. **"Could not select appropriate BOM parent"**
   - No purchase history found for any parent
   - System will fall back to first BOM found

3. **"Failed to calculate price from BOM"**
   - BOM has no components
   - Component products have no cost prices set

### Debug Mode

Enable debug logging:

```php
$bomUpdater = new ProductBOMPriceUpdater($db);
$bomUpdater->setDebug(true);
```

Check Dolibarr logs for detailed execution information.

## 🎛️ Configuration

### Enable Global BOM Priority Mode

```php
// Enable for all ProductUpdater calls
EnhancedProductUpdater::setBOMPriorityMode(true);

// Or set directly in configuration
$conf->global->KREAPRODUCTS_USE_BOM_PRIORITY = 1;
```

### Customize Purchase Query

Modify `getMostRecentPurchaseDate()` method to:
- Include different invoice statuses
- Filter by date ranges
- Consider specific suppliers

## 📈 Performance Considerations

- **Batch Updates**: Use `batchUpdateProductsWithMultipleBOMParents()` for multiple products
- **Caching**: Results are calculated fresh each time (no caching)
- **Database Load**: Queries are optimized with proper JOINs and indexes

## 🔄 Migration from Existing System

1. **Test First**: Use test script to verify functionality
2. **Backup**: Backup product cost prices before batch updates
3. **Gradual Rollout**: Start with single products, then batch
4. **Monitor**: Check logs for any issues or unexpected results

## 💡 Best Practices

1. **Regular Updates**: Run batch updates periodically when purchase prices change
2. **Validation**: Always verify calculated prices make sense
3. **Logging**: Keep debug mode enabled during initial deployment
4. **Fallbacks**: System gracefully handles missing data with sensible defaults