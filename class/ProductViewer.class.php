<?php

class ProductHierarchy
{
    // ----------------------------------------------------------------------------------
    //  Internal Map Storage
    // ----------------------------------------------------------------------------------

    /**
     * @var array  productMap[productId] = LocalProduct object
     */
    private static $productMap = array();

    /**
     * Track visited IDs to avoid infinite loops
     */
    private static $visited    = array();

    // ----------------------------------------------------------------------------------
    //  MAIN ENTRY POINT for the 4-Section HTML
    // ----------------------------------------------------------------------------------

    /**
     * Returns the entire HTML page with:
     *  1) Header (Ref, # subproducts, # parents)
     *  2) "Lista de produtos..." table (children) NO stock column
     *  3) "Lista de kits..." table (parents) NO stock column
     *  4) "Estratégia de Sincronização" table with only (Reference, Association, Type, CostPrice)
     *
     * @param  int $productId
     * @return string HTML
     */
    public static function getCompletePage($productId)
    {
        global $db, $langs, $conf;

        // Reset internal map
        self::$productMap = array();
        self::$visited    = array();

        // Build local map from product_association
        self::buildMap($productId);

        // Load Dolibarr product for reference
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new \Product($db);
        if ($prod->fetch($productId) <= 0) {
            return '<p style="color:red;">Erro: produto #' . $productId . ' não encontrado.</p>';
        }

        // Capture output
        ob_start();

        // 1) HEADER: "Ref", "# subproducts", "# parents"
        print '<div class="fichecenter">';
        print '<div class="underbanner clearboth"></div>';
        print '<table class="border tableforfield centpercent">';
        print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td><td colspan="3">' . $prod->ref . '</td></tr>';
        $lp = self::getLocalProduct($productId);
        $nbChildren = $lp ? count($lp->children) : 0;
        print '<tr><td class="titlefield">Número de produtos que compõem este kit</td><td colspan="3">' . $nbChildren . '</td></tr>';
        $nbParents = $lp ? count($lp->parents) : 0;
        print '<tr><td class="titlefield">Número de produtos de embalagem fonte</td><td colspan="3">' . $nbParents . '</td></tr>';
        print '</table>';
        print '</div>';
        print '<div class="clearboth"></div>';
        print dol_get_fiche_end();

        // 2) Lista de produtos/serviços que são componentes deste kit (children)
        print '<p><strong>Lista de produtos/serviços que são componentes deste kit</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);  // NO stock column
        // Show top-level row
        self::printRow($productId, 0, 0, 'child');
        // Recursively show children
        self::printChildrenRecursive($productId, 1, 3);
        print '</table><br>';

        // 3) Lista de kits com este produto como componente (parents)
        print '<p><strong>Lista de kits com este produto como componente</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);  // NO stock column
        // Show top-level row
        self::printRow($productId, 0, 0, 'parent');
        // Recursively show parents
        self::printParentsRecursive($productId, 1, 3);
        print '</table><br>';

        // 4) Estratégia de Sincronização
        print '<p><strong>' . $langs->trans("FichaTecnica") . '</strong></p>';
        print '<table class="noborder" width="100%">';
        // HEAD: Reference, Association, Type, CostPrice (No "Price" column)
        print '<tr class="liste_titre">';
        print '<td width="50%">' . $langs->trans("Reference") . '</td>';
        print '<td width="20%">' . $langs->trans("Qty") . '</td>';
        print '<td width="20%">' . $langs->trans("Type") . '</td>';
        print '<td width="10%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';

        if ($lp) {
            // Show each child => "Subprodutos"
            foreach ($lp->children as $childId => $qty) {
                $childLP = self::getLocalProduct($childId);
                if (!$childLP) {
                    continue;
                }
                print '<tr style="font-style: italic;">';
                print '<td>' . htmlspecialchars($childLP->label, ENT_QUOTES) . '</td>';
                print '<td>x ' . number_format($qty, 3, '.', '') . '</td>';
                print '<td>Subprodutos</td>';
                // Only CostPrice
                $buyVal = price($childLP->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
                print '<td>' . $buyVal . '</td>';
                print '</tr>';
            }

            // "Totais Estimados do Produto"
            $sumBuy   = self::computeRecursivePrice($lp, "buyprice");
            print '<tr style="font-style: italic;">';
            print '<td>Totais Estimados do Produto</td><td>&nbsp;</td><td>&nbsp;</td>';
            print '<td>' . price($sumBuy, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE . '</td>';
            print '</tr>';

            // "Preço de Custo"
            print '<tr style="font-weight: bold;font-size:1.1em;">';
            print '<td>' . $langs->trans("PrecoCusto") . '</td><td>&nbsp;</td><td>&nbsp;</td>';

            // Compare cost
            print '<td>' . price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
            print self::compareIcon($sumBuy, $lp->buyprice);
            print '</td>';
            print '</tr>';
        }

        print '</table>';

        return ob_get_clean();
    }

    // ----------------------------------------------------------------------------------
    //  BUILD MAP FROM product_association
    // ----------------------------------------------------------------------------------

    private static function buildMap($productId)
    {
        global $db;
        if (isset(self::$visited[$productId])) {
            return; // already built
        }
        self::$visited[$productId] = true;

        $sql  = "SELECT pa.fk_product_pere as father, pa.fk_product_fils as child, pa.qty as qty,";
        $sql .= " p.label as fatherLabel, p.ref as fatherRef, p.price as fatherPrice, p.cost_price as fatherBuy,";
        $sql .= " f.label as childLabel, f.ref as childRef, f.price as childPrice, f.cost_price as childBuy";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_association pa";
        $sql .= " JOIN " . MAIN_DB_PREFIX . "product p ON (p.rowid = pa.fk_product_pere)";
        $sql .= " JOIN " . MAIN_DB_PREFIX . "product f ON (f.rowid = pa.fk_product_fils)";
        $sql .= " WHERE pa.fk_product_pere = " . (int)$productId . " OR pa.fk_product_fils = " . (int)$productId;

        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                // father local object
                if (!isset(self::$productMap[$obj->father])) {
                    self::$productMap[$obj->father] = new LocalProduct(
                        $obj->father,
                        $obj->fatherLabel,
                        $obj->fatherRef,
                        (float)$obj->fatherPrice,
                        (float)$obj->fatherBuy
                    );
                }
                // child local object
                if (!isset(self::$productMap[$obj->child])) {
                    self::$productMap[$obj->child] = new LocalProduct(
                        $obj->child,
                        $obj->childLabel,
                        $obj->childRef,
                        (float)$obj->childPrice,
                        (float)$obj->childBuy
                    );
                }
                // Link father -> child
                self::$productMap[$obj->father]->children[$obj->child] = (float)$obj->qty;
                // Link child -> father
                self::$productMap[$obj->child]->parents[] = $obj->father;

                // Recurse father & child
                self::buildMap($obj->father);
                self::buildMap($obj->child);
            }
            $db->free($resql);
        }
    }

    private static function getLocalProduct($pid)
    {
        return isset(self::$productMap[$pid]) ? self::$productMap[$pid] : null;
    }

    // ----------------------------------------------------------------------------------
    //  RECURSIVE DISPLAY: CHILD & PARENT
    // ----------------------------------------------------------------------------------

    private static function printChildParentTableHead($langs)
    {
        print '<tr class="liste_titre">';
        print '<td width="20%">' . $langs->trans("Reference") . '</td>';
        print '<td width="35%">' . $langs->trans("Designation") . '</td>';
        print '<td width="10%">' . $langs->trans("Qty") . '</td>';
        print '<td width="10%">' . $langs->trans("Type") . '</td>';
        print '<td width="5%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';
    }

    private static function printRow($productId, $qty, $level, $mode)
    {
        global $db, $langs, $conf;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return;
        }
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new \Product($db);
        if ($prod->fetch($productId) < 0) {
            return;
        }

        $indentStr = '';
        for ($i = 0; $i < $level; $i++) {
            if ($i == $level - 1) {
                $indentStr .= 'O---- ';
            } else {
                $indentStr .= '|&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
            }
        }

        $refHtml = $indentStr . $prod->getNomUrl(1);

        if ($qty > 0) {
            $assoc = 'quantidade : ' . number_format($qty, 3, '.', '');
        } elseif (!empty($lp->children)) {
            $assoc = count($lp->children) . ' Subprodutos';
        } elseif (!empty($lp->parents)) {
            $assoc = count($lp->parents) . ' Pais';
        } else {
            $assoc = '';
        }

        $typeStr = !empty($lp->children) ? 'Ficha Técnica' : '';

        // "Price" => we show buyprice
        $priceStr = price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;

        print '<tr>';
        print '<td>' . $refHtml . '</td>';
        print '<td>' . htmlspecialchars($lp->label, ENT_QUOTES) . '</td>';
        print '<td>' . $assoc . '</td>';
        print '<td>' . $typeStr . '</td>';
        print '<td>' . $priceStr . '</td>';
        print '</tr>';
    }

    private static function printChildrenRecursive($productId, $level, $maxLevel, array &$visited = array())
    {
        if (in_array($productId, $visited, true)) {
            return;
        }
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return;
        }

        foreach ($lp->children as $childId => $qty) {
            self::printRow($childId, $qty, $level, 'child');
            if ($level < $maxLevel) {
                self::printChildrenRecursive($childId, $level + 1, $maxLevel, $visited);
            }
        }
    }

    private static function printParentsRecursive($productId, $level, $maxLevel, array &$visited = array())
    {
        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return;
        }

        foreach ($lp->parents as $parentId) {
            if (in_array($parentId, $visited, true)) {
                continue;
            }
            $visited[] = $parentId;

            self::printRow($parentId, 0, $level, 'parent');
            if ($level < $maxLevel) {
                self::printParentsRecursive($parentId, $level + 1, $maxLevel, $visited);
            }
        }
    }

    // ----------------------------------------------------------------------------------
    //  RECURSIVE COST/PRICE
    // ----------------------------------------------------------------------------------

    private static function computeRecursivePrice(LocalProduct $lp, $key)
    {
        if (empty($lp->children)) {
            return ($key === 'price') ? $lp->price : $lp->buyprice;
        }
        $sum = 0;
        foreach ($lp->children as $childId => $qty) {
            $child = self::getLocalProduct($childId);
            if ($child) {
                $sum += $qty * self::computeRecursivePrice($child, $key);
            }
        }
        return $sum;
    }

    private static function compareIcon($val1, $val2)
    {
        if (abs($val1 - $val2) < 1E-2) {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/tick.png" alt="tick">';
        } else {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/error.png" alt="error">';
        }
    }

    // ----------------------------------------------------------------------------------
    //  FIXED updateProductAttributes
    // ----------------------------------------------------------------------------------

    /**
     * Recalculate cost_price for the given product. If it has children => sum them
     * and update DB. Else if no children but has parents => call update for each parent.
     *
     * @param int  $productId
     * @param User $user
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;
        dol_syslog("updateProductAttributes: Start for productId=" . $productId);

        // Clear map and build fresh
        self::$productMap = array();
        self::$visited    = array();
        self::buildMap($productId);

        // Grab the local product
        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            dol_syslog("updateProductAttributes: product #$productId not found in map", LOG_WARNING);
            return;
        }

        // If product has children => sum them
        if (!empty($lp->children)) {
            $newCost = self::computeRecursivePrice($lp, 'buyprice');
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new \Product($db);
            if ($prod->fetch($productId) > 0) {
                $prod->cost_price = $newCost;
                $prod->buyprice   = $newCost;
                $res = $prod->update($productId, $user);
                if ($res > 0) {
                    dol_syslog("updateProductAttributes: updated #$productId cost=$newCost");
                } else {
                    dol_syslog("updateProductAttributes: failed to update #$productId", LOG_ERR);
                }
            }
            // Else if no children but we have parents => we recalc each parent
        } else if (!empty($lp->parents)) {
            dol_syslog("updateProductAttributes: #$productId has no children => recalc parent's cost");
            // For each parent => call update
            foreach ($lp->parents as $parentId) {
                self::updateProductAttributes($parentId, $user);
            }
        } else {
            dol_syslog("updateProductAttributes: #$productId has neither children nor parents => no cost update");
        }
    }
}

/**
 * LocalProduct container for each product's data
 */
class LocalProduct
{
    /** @var int   ID */
    public $id;
    /** @var string Label */
    public $label;
    /** @var string Ref */
    public $ref;
    /** @var float  Sale price */
    public $price = 0.0;
    /** @var float  Buy price (cost) */
    public $buyprice = 0.0;

    /** @var float[] childId => quantity */
    public $children = array();
    /** @var int[]   parentId[] */
    public $parents  = array();

    /**
     * Constructor
     */
    public function __construct($id, $label, $ref, $price, $buyprice)
    {
        $this->id       = (int)$id;
        $this->label    = $label;
        $this->ref      = $ref;
        $this->price    = (float)$price;
        $this->buyprice = (float)$buyprice;
    }
}
