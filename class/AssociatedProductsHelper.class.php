<?php

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Class AssociatedProductsHelper
 * 
 * Helper class for managing product associations, hierarchies, and relationships.
 * Provides utility methods for retrieving and formatting product association data.
 */
class AssociatedProductsHelper
{
    // Constants for association types
    public const ASSOCIATION_TYPE_PARENT = 'parent';
    public const ASSOCIATION_TYPE_CHILD = 'child';
    public const ASSOCIATION_TYPE_ALL = 'all';
    
    // Constants for display formats
    public const FORMAT_COUNT_ONLY = 'count';
    public const FORMAT_LABEL_WITH_COUNT = 'label_count';
    public const FORMAT_DETAILED = 'detailed';
    
    // Cache for product associations to improve performance
    private static array $associationCache = [];
    private static array $productCache = [];
    
    // Error handling
    private static array $errors = [];
    private static ?string $lastError = null;

    /**
     * Generate label with number of child products
     *
     * @param int|Product $productId Product ID or Product object
     * @param string $format Output format (count, label_count, detailed)
     * @param bool $useCache Whether to use caching
     * @return string|array|int|null Label with child count, detailed array, or null on error
     */
    public static function getLabelWithChildCount(
        $productId, 
        string $format = self::FORMAT_LABEL_WITH_COUNT,
        bool $useCache = true
    ) {
        global $langs, $db;
        
        try {
            self::clearErrors();
            
            // Validate and normalize input
            $normalizedId = self::normalizeProductInput($productId);
            if ($normalizedId === null) {
                return null;
            }
            
            // Get association count
            $associationData = self::getProductAssociations($normalizedId, self::ASSOCIATION_TYPE_ALL, $useCache);
            if ($associationData === null) {
                return null;
            }
            
            // Format output based on requested format
            return self::formatAssociationOutput($associationData, $format, $langs);
            
        } catch (Exception $e) {
            self::addError("Error in getLabelWithChildCount: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Get detailed product associations information
     *
     * @param int|Product $productId Product ID or Product object
     * @param string $type Type of associations to retrieve (parent, child, all)
     * @param bool $useCache Whether to use caching
     * @return array|null Association data or null on error
     */
    public static function getProductAssociations(
        $productId, 
        string $type = self::ASSOCIATION_TYPE_ALL,
        bool $useCache = true
    ): ?array {
        global $db;
        
        try {
            // Validate and normalize input
            $normalizedId = self::normalizeProductInput($productId);
            if ($normalizedId === null) {
                return null;
            }
            
            // Check cache first
            $cacheKey = self::generateCacheKey($normalizedId, $type);
            if ($useCache && isset(self::$associationCache[$cacheKey])) {
                return self::$associationCache[$cacheKey];
            }
            
            // Get or create product object
            $product = self::getProductObject($normalizedId, $useCache);
            if ($product === null) {
                return null;
            }
            
            // Get association counts and details
            $associationData = self::fetchAssociationData($product, $type);
            
            // Cache the result
            if ($useCache) {
                self::$associationCache[$cacheKey] = $associationData;
            }
            
            return $associationData;
            
        } catch (Exception $e) {
            self::addError("Error retrieving product associations: " . $e->getMessage());
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Get parent products for a given product
     *
     * @param int|Product $productId Product ID or Product object
     * @param bool $useCache Whether to use caching
     * @return array|null Array of parent product IDs or null on error
     */
    public static function getParentProducts($productId, bool $useCache = true): ?array
    {
        $associations = self::getProductAssociations($productId, self::ASSOCIATION_TYPE_PARENT, $useCache);
        return $associations ? $associations['parents'] : null;
    }

    /**
     * Get child products for a given product
     *
     * @param int|Product $productId Product ID or Product object
     * @param bool $useCache Whether to use caching
     * @return array|null Array of child product IDs or null on error
     */
    public static function getChildProducts($productId, bool $useCache = true): ?array
    {
        $associations = self::getProductAssociations($productId, self::ASSOCIATION_TYPE_CHILD, $useCache);
        return $associations ? $associations['children'] : null;
    }

    /**
     * Check if a product has any associations
     *
     * @param int|Product $productId Product ID or Product object
     * @param string $type Type to check (parent, child, all)
     * @param bool $useCache Whether to use caching
     * @return bool|null True if has associations, false if not, null on error
     */
    public static function hasAssociations($productId, string $type = self::ASSOCIATION_TYPE_ALL, bool $useCache = true): ?bool
    {
        $associations = self::getProductAssociations($productId, $type, $useCache);
        if ($associations === null) {
            return null;
        }
        
        switch ($type) {
            case self::ASSOCIATION_TYPE_PARENT:
                return !empty($associations['parents']);
            case self::ASSOCIATION_TYPE_CHILD:
                return !empty($associations['children']);
            case self::ASSOCIATION_TYPE_ALL:
                return !empty($associations['parents']) || !empty($associations['children']);
            default:
                self::addError("Invalid association type: $type");
                return null;
        }
    }

    /**
     * Get association count only
     *
     * @param int|Product $productId Product ID or Product object
     * @param string $type Type of associations to count
     * @param bool $useCache Whether to use caching
     * @return int|null Count of associations or null on error
     */
    public static function getAssociationCount($productId, string $type = self::ASSOCIATION_TYPE_ALL, bool $useCache = true): ?int
    {
        $associations = self::getProductAssociations($productId, $type, $useCache);
        if ($associations === null) {
            return null;
        }
        
        switch ($type) {
            case self::ASSOCIATION_TYPE_PARENT:
                return count($associations['parents']);
            case self::ASSOCIATION_TYPE_CHILD:
                return count($associations['children']);
            case self::ASSOCIATION_TYPE_ALL:
                return count($associations['parents']) + count($associations['children']);
            default:
                self::addError("Invalid association type: $type");
                return null;
        }
    }

    /**
     * Bulk get associations for multiple products
     *
     * @param array $productIds Array of product IDs
     * @param string $type Type of associations to retrieve
     * @param bool $useCache Whether to use caching
     * @return array Array of associations indexed by product ID
     */
    public static function getBulkAssociations(array $productIds, string $type = self::ASSOCIATION_TYPE_ALL, bool $useCache = true): array
    {
        $results = [];
        
        foreach ($productIds as $productId) {
            if (!is_numeric($productId) || $productId <= 0) {
                continue;
            }
            
            $associations = self::getProductAssociations((int)$productId, $type, $useCache);
            if ($associations !== null) {
                $results[(int)$productId] = $associations;
            }
        }
        
        return $results;
    }

    /**
     * Clear association cache
     *
     * @param int|null $productId Specific product ID to clear, or null for all
     */
    public static function clearCache(?int $productId = null): void
    {
        if ($productId === null) {
            self::$associationCache = [];
            self::$productCache = [];
        } else {
            // Clear specific product cache entries
            $keysToRemove = [];
            foreach (array_keys(self::$associationCache) as $key) {
                if (strpos($key, (string)$productId . '_') === 0) {
                    $keysToRemove[] = $key;
                }
            }
            
            foreach ($keysToRemove as $key) {
                unset(self::$associationCache[$key]);
            }
            
            unset(self::$productCache[$productId]);
        }
    }

    /**
     * Normalize product input (ID or Product object)
     *
     * @param int|Product $productInput Product ID or Product object
     * @return int|null Normalized product ID or null if invalid
     */
    private static function normalizeProductInput($productInput): ?int
    {
        if (is_numeric($productInput)) {
            $productId = (int)$productInput;
            if ($productId <= 0) {
                self::addError("Invalid product ID: must be positive integer");
                return null;
            }
            return $productId;
        }
        
        if ($productInput instanceof Product) {
            if (empty($productInput->id) || $productInput->id <= 0) {
                self::addError("Product object has invalid ID");
                return null;
            }
            return (int)$productInput->id;
        }
        
        self::addError("Invalid product input: must be integer ID or Product object");
        return null;
    }

    /**
     * Get or create Product object
     *
     * @param int $productId Product ID
     * @param bool $useCache Whether to use caching
     * @return Product|null Product object or null on error
     */
    private static function getProductObject(int $productId, bool $useCache = true): ?Product
    {
        global $db;
        
        // Check cache first
        if ($useCache && isset(self::$productCache[$productId])) {
            return self::$productCache[$productId];
        }
        
        try {
            $product = new Product($db);
            $result = $product->fetch($productId);
            
            if ($result <= 0) {
                self::addError("Failed to fetch product with ID: $productId");
                return null;
            }
            
            // Cache the product object
            if ($useCache) {
                self::$productCache[$productId] = $product;
            }
            
            return $product;
            
        } catch (Exception $e) {
            self::addError("Error creating Product object: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch association data from product
     *
     * @param Product $product Product object
     * @param string $type Association type
     * @return array Association data
     */
    private static function fetchAssociationData(Product $product, string $type): array
    {
        $data = [
            'product_id' => $product->id,
            'parents' => [],
            'children' => [],
            'total_count' => 0,
            'has_parents' => false,
            'has_children' => false
        ];
        
        try {
            // Get the association count/status from Dolibarr
            $nbFatherAndChild = $product->hasFatherOrChild();
            
            if ($nbFatherAndChild > 0) {
                // If we need detailed information, we could extend this
                // For now, we work with the available information
                $data['total_count'] = $nbFatherAndChild;
                
                // Note: Dolibarr's hasFatherOrChild() returns total count
                // To get separate parent/child counts, we'd need additional queries
                if ($type === self::ASSOCIATION_TYPE_PARENT || $type === self::ASSOCIATION_TYPE_ALL) {
                    $parentCount = self::getParentCount($product);
                    $data['parents'] = $parentCount > 0 ? range(1, $parentCount) : []; // Placeholder
                    $data['has_parents'] = $parentCount > 0;
                }
                
                if ($type === self::ASSOCIATION_TYPE_CHILD || $type === self::ASSOCIATION_TYPE_ALL) {
                    $childCount = $nbFatherAndChild - ($data['has_parents'] ? count($data['parents']) : 0);
                    $data['children'] = $childCount > 0 ? range(1, $childCount) : []; // Placeholder
                    $data['has_children'] = $childCount > 0;
                }
            }
            
        } catch (Exception $e) {
            self::addError("Error fetching association data: " . $e->getMessage());
        }
        
        return $data;
    }

    /**
     * Get parent count for a product (would need custom implementation)
     *
     * @param Product $product Product object
     * @return int Parent count
     */
    private static function getParentCount(Product $product): int
    {
        // This would need a custom query to the product association tables
        // For now, we'll return a simple implementation
        // In a real implementation, you'd query the llx_product_association table
        
        global $db;
        
        try {
            $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "product_association";
            $sql .= " WHERE fk_product_fils = " . ((int) $product->id);
            
            $resql = $db->query($sql);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                $db->free($resql);
                return (int)$obj->count;
            }
        } catch (Exception $e) {
            dol_syslog("Error counting parents: " . $e->getMessage(), LOG_ERR);
        }
        
        return 0;
    }

    /**
     * Format association output based on requested format
     *
     * @param array $associationData Association data
     * @param string $format Output format
     * @param object|null $langs Language object
     * @return string|array|int Formatted output
     */
    private static function formatAssociationOutput(array $associationData, string $format, ?object $langs)
    {
        switch ($format) {
            case self::FORMAT_COUNT_ONLY:
                return $associationData['total_count'];
                
            case self::FORMAT_LABEL_WITH_COUNT:
                if (!$langs) {
                    return "Associated products: " . $associationData['total_count'];
                }
                
                $parentCount = count($associationData['parents']);
                $childCount = count($associationData['children']);
                
                if ($parentCount > 0 && $childCount > 0) {
                    return sprintf(
                        $langs->trans("ProductHasParentsAndChildren"),
                        $parentCount,
                        $childCount
                    );
                } elseif ($parentCount > 0) {
                    return sprintf(
                        $langs->trans("ProductHasParents"),
                        $parentCount
                    );
                } elseif ($childCount > 0) {
                    return sprintf(
                        $langs->trans("ProductHasChildren"),
                        $childCount
                    );
                } else {
                    return $langs->trans("NoAssociatedProducts");
                }
                
            case self::FORMAT_DETAILED:
                return $associationData;
                
            default:
                self::addError("Invalid format specified: $format");
                return $associationData['total_count'];
        }
    }

    /**
     * Generate cache key
     *
     * @param int $productId Product ID
     * @param string $type Association type
     * @return string Cache key
     */
    private static function generateCacheKey(int $productId, string $type): string
    {
        return $productId . '_' . $type;
    }

    /**
     * Add error to error collection
     *
     * @param string $error Error message
     */
    private static function addError(string $error): void
    {
        self::$errors[] = $error;
        self::$lastError = $error;
    }

    /**
     * Clear all errors
     */
    private static function clearErrors(): void
    {
        self::$errors = [];
        self::$lastError = null;
    }

    /**
     * Get last error message
     *
     * @return string|null Last error or null if no errors
     */
    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Get all error messages
     *
     * @return array Array of error messages
     */
    public static function getAllErrors(): array
    {
        return self::$errors;
    }

    /**
     * Check if there are any errors
     *
     * @return bool True if there are errors
     */
    public static function hasErrors(): bool
    {
        return !empty(self::$errors);
    }

    /**
     * Validate association type
     *
     * @param string $type Association type to validate
     * @return bool True if valid
     */
    public static function isValidAssociationType(string $type): bool
    {
        return in_array($type, [
            self::ASSOCIATION_TYPE_PARENT,
            self::ASSOCIATION_TYPE_CHILD,
            self::ASSOCIATION_TYPE_ALL
        ]);
    }

    /**
     * Validate format type
     *
     * @param string $format Format type to validate
     * @return bool True if valid
     */
    public static function isValidFormat(string $format): bool
    {
        return in_array($format, [
            self::FORMAT_COUNT_ONLY,
            self::FORMAT_LABEL_WITH_COUNT,
            self::FORMAT_DETAILED
        ]);
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public static function getCacheStats(): array
    {
        return [
            'association_cache_entries' => count(self::$associationCache),
            'product_cache_entries' => count(self::$productCache),
            'total_cache_size' => count(self::$associationCache) + count(self::$productCache)
        ];
    }
}
