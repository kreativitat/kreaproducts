<?php
/*
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

class AssociatedProductsHelper
{
    /**
     * Generate label with number of child products
     *
     * @param Product $object Product object
     * @return string Label with child count
     */
    public static function getLabelWithChildCount($obj)
    {
        global $langs, $db;

        // Fetch product object
        $product = new Product($db);
        $product->fetch($obj);

        $nbFatherAndChild = $product->hasFatherOrChild();

        return $nbFatherAndChild;
    }
}
