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
        if ($level > 0) {
            // For each level EXCEPT the last, add "┃   "
            for ($i = 0; $i < $level - 1; $i++) {
                $indentStr .= '┃&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
            }
            // For the final "branch" at this level, add "┗━━ "
            $indentStr .= '┗━━ ';
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







    public static function getCollapsibleTreeHTML($productId)
    {
        global $db;

        // 1) Build or rebuild the product map
        self::$productMap = array();
        self::$visited    = array();
        self::buildMap($productId);  // your existing buildMap logic

        // 2) Check if product exists in map
        $rootLP = self::getLocalProduct($productId);
        if (!$rootLP) {
            return '<p style="color:red;">Product #' . $productId . ' not found in map.</p>';
        }

        // 3) Start building the HTML output
        ob_start();

?>
        <!-- Minimal styling for tree toggles -->
        <style>
            .tree ul {
                list-style-type: none;
                /* Remove normal bullet points */
                margin: 0;
                padding: 0 20px;
                /* Indent sub-lists */
                display: none;
                /* Hidden by default, toggled by JS */
            }

            .tree li {
                margin: 4px 0;
                cursor: pointer;
                /* So the user sees a pointer on hover */
                position: relative;
            }

            .tree li::before {
                content: "▶";
                /* Triangle icon */
                display: inline-block;
                margin-right: 5px;
                transition: transform 0.2s ease;
            }

            .tree li.expanded::before {
                transform: rotate(90deg);
                /* Rotate the triangle when expanded */
            }

            /* Show the top-level UL by default */
            .tree ul.top-level {
                display: block !important;
            }

            /* Make text unselectable if you prefer */
            .tree li,
            .tree li * {
                user-select: none;
            }
        </style>

        <!-- JavaScript to handle expand/collapse -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Add click listeners to <li> elements that have a nested <ul>
                document.querySelectorAll('.tree li').forEach(function(li) {
                    var childUl = li.querySelector(':scope > ul');
                    if (childUl) {
                        // Show a pointer or do something to indicate it can expand/collapse
                        li.addEventListener('click', function(e) {
                            // Prevent the click from toggling parents
                            e.stopPropagation();

                            // Toggle "expanded" class
                            li.classList.toggle('expanded');
                            if (childUl.style.display === 'block') {
                                childUl.style.display = 'none';
                            } else {
                                childUl.style.display = 'block';
                            }
                        });
                    }
                });
            });
        </script>

        <div class="tree">
            <?php
            // 4) Print a top-level <ul> with a special "top-level" class so it’s initially shown
            echo '<ul class="top-level">';
            // 5) Recursively build the nested <ul> structure (CHILD direction in this example)
            echo self::buildNestedList($productId);
            echo '</ul>';
            ?>
        </div>
<?php

        return ob_get_clean();
    }

    /**
     * Build a nested <ul><li> structure for the children of a given product ID.
     * If you want to do parent→child or child→parent, just adapt the logic.
     */
    private static function buildNestedList($productId, $level = 0, &$visited = array())
    {
        // Avoid cycles
        if (in_array($productId, $visited, true)) {
            return '';
        }
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            return '';
        }

        // Each <li> may have a nested <ul> if it has children
        // For demonstration, let's show: [Ref] - [Label] - [Cost Price]
        $html = '';
        $html .= '<li>';

        // Show node text
        $html .= '<strong>' . htmlspecialchars($lp->ref) . '</strong> &mdash; ';
        $html .= htmlspecialchars($lp->label) . ' &mdash; ';
        $html .= 'Cost: ' . number_format($lp->buyprice, 2) . ' ';

        // If it has children, recursively build the sub-list
        if (!empty($lp->children)) {
            $html .= '<ul>';
            foreach ($lp->children as $childId => $qty) {
                $html .= self::buildNestedList($childId, $level + 1, $visited);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }






    // ---------------------------------------------------------------
    // Single line printing with “tree” indentation
    // ---------------------------------------------------------------
    /**
     * Print a single line for a product, with a "prefix" array that indicates
     * which levels should have a vertical line. If $level=0 => no indentation.
     *
     * We do either '┣━━ ' or '┗━━ ' depending on $isLast, plus for each ancestor level
     * we do either '┃   ' if $prefix[i]=true or '    ' if false.
     *
     * @param int    $productId
     * @param float  $qty
     * @param int    $level
     * @param array  $prefix   Boolean array for each level
     * @param bool   $isLast
     * @param int    $index
     * @param int    $count
     * @param string $mode
     */
    private static function printLine($productId, $qty, $level, array $prefix, $isLast, $index, $count, $mode)
    {
        global $db, $langs, $conf;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new \Product($db);
        if ($prod->fetch($productId) < 0) return;

        // Build indentation
        $indent = '';
        for ($i = 0; $i < $level; $i++) {
            // if prefix[i] => we have siblings => "┃   " else "    "
            if (!empty($prefix[$i])) {
                $indent .= '┃   ';
            } else {
                $indent .= '    ';
            }
        }

        // Then the corner: if level>0
        if ($level > 0) {
            $indent .= ($isLast ? '┗━━ ' : '┣━━ ');
        }

        // Association text
        $assoc = '';
        if ($qty > 0) {
            $assoc = 'quantidade : ' . number_format($qty, 3, '.', '');
        } else if (!empty($lp->children)) {
            $assoc = count($lp->children) . ' Subprodutos';
        } else if (!empty($lp->parents)) {
            $assoc = count($lp->parents) . ' Pais';
        }

        $typeStr = (!empty($lp->children)) ? 'Ficha Técnica' : '';
        $priceStr = price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;

        print '<tr>';
        print '<td>' . $indent . $prod->getNomUrl(1) . '</td>';
        print '<td>' . htmlspecialchars($lp->label, ENT_QUOTES) . '</td>';
        print '<td>' . $assoc . '</td>';
        print '<td>' . $typeStr . '</td>';
        print '<td>' . $priceStr . '</td>';
        print '</tr>';
    }

    // ---------------------------------------------------------------
    // CHILD RECURSION with fancy lines
    // ---------------------------------------------------------------
    private static function fancyChildRecursive($productId, $level, $maxLevel, array &$visited, array $prefix)
    {
        // Avoid duplicates
        if (in_array($productId, $visited, true)) {
            return;
        }
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        $childIds = array_keys($lp->children);
        $numKids = count($childIds);

        for ($i = 0; $i < $numKids; $i++) {
            $childId = $childIds[$i];
            $qty = $lp->children[$childId];
            $isLast = ($i == $numKids - 1);

            // Copy the prefix array
            $childPrefix = $prefix;
            // If not last => we keep vertical line at this level
            $childPrefix[$level] = !$isLast;

            // Print line
            self::printLine($childId, $qty, $level + 1, $childPrefix, $isLast, $i, $numKids, 'child');

            if (($level + 1) < $maxLevel) {
                self::fancyChildRecursive($childId, $level + 1, $maxLevel, $visited, $childPrefix);
            }
        }
    }

    // ---------------------------------------------------------------
    // PARENT RECURSION with fancy lines
    // ---------------------------------------------------------------
    private static function fancyParentRecursive($productId, $level, $maxLevel, array &$visited, array $prefix)
    {
        if (in_array($productId, $visited, true)) {
            return;
        }
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        $parents = $lp->parents;
        $numPar = count($parents);

        for ($i = 0; $i < $numPar; $i++) {
            $parentId = $parents[$i];
            // Avoid cycles
            if (in_array($parentId, $visited, true)) {
                continue;
            }
            $isLast = ($i == $numPar - 1);

            $parentPrefix = $prefix;
            $parentPrefix[$level] = !$isLast;

            // Print line
            self::printLine($parentId, 0, $level + 1, $parentPrefix, $isLast, $i, $numPar, 'parent');

            if (($level + 1) < $maxLevel) {
                self::fancyParentRecursive($parentId, $level + 1, $maxLevel, $visited, $parentPrefix);
            }
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
