<?php

class ProductHierarchy
{
    // ---------------------------------------------------------------
    //  Internal Map Storage
    // ---------------------------------------------------------------
    private static $productMap = array();
    private static $visited    = array();

    // ---------------------------------------------------------------
    //  MAIN RENDERING METHOD
    // ---------------------------------------------------------------
    public static function getCompletePage($productId)
    {
        global $db, $langs, $conf;

        // Reset map
        self::$productMap = array();
        self::$visited    = array();

        // Build
        self::buildMap($productId);

        // Load Dolibarr product
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new \Product($db);
        if ($prod->fetch($productId) <= 0) {
            return '<p style="color:red;">Erro: produto #' . $productId . ' não encontrado.</p>';
        }

        ob_start();

        // -------------------------
        // 1) HEADER
        // -------------------------
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

        // -------------------------
        // 2) Lista de produtos (Children)
        // -------------------------
        print '<p><strong>Lista de produtos/serviços que são componentes deste kit</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);

        // Optionally show the product itself at level=0
        self::printLine($productId, 0, array(), true, 'child', 0, 0, 1);
        // Recurse children
        $visitedChildren = array();
        $kids = $lp ? array_keys($lp->children) : array();
        self::fancyChildRecursive($productId, 1, 3, $visitedChildren, array());

        print '</table><br>';

        // -------------------------
        // 3) Lista de kits (Parents)
        // -------------------------
        print '<p><strong>Lista de kits com este produto como componente</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);

        self::printLine($productId, 0, array(), true, 'parent', 0, 0, 1);
        $visitedParents = array();
        self::fancyParentRecursive($productId, 1, 3, $visitedParents, array());

        print '</table><br>';

        // -------------------------
        // 4) Estratégia de Sincronização
        // -------------------------
        print '<p><strong>' . $langs->trans("FichaTecnica") . '</strong></p>';
        print '<table class="noborder" width="100%">';
        print '<tr class="liste_titre">';
        print '<td width="50%">' . $langs->trans("Reference") . '</td>';
        print '<td width="20%">' . $langs->trans("Qty") . '</td>';
        print '<td width="20%">' . $langs->trans("Type") . '</td>';
        print '<td width="10%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';

        if ($lp) {
            foreach ($lp->children as $childId => $qty) {
                $childLP = self::getLocalProduct($childId);
                if (!$childLP) continue;

                print '<tr style="font-style: italic;">';
                print '<td>' . htmlspecialchars($childLP->label, ENT_QUOTES) . '</td>';
                print '<td>x ' . number_format($qty, 3, '.', '') . '</td>';
                print '<td>Subprodutos</td>';
                $buyVal = price($childLP->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
                print '<td>' . $buyVal . '</td>';
                print '</tr>';
            }

            $sumBuy = self::computeRecursivePrice($lp, "buyprice");
            print '<tr style="font-style: italic;">';
            print '<td>Totais Estimados do Produto</td><td>&nbsp;</td><td>&nbsp;</td>';
            print '<td>' . price($sumBuy, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE . '</td>';
            print '</tr>';

            print '<tr style="font-weight: bold;font-size:1.1em;">';
            print '<td>PrecoCusto</td><td>&nbsp;</td><td>&nbsp;</td>';
            print '<td>' . price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
            print self::compareIcon($sumBuy, $lp->buyprice);
            print '</td>';
            print '</tr>';
        }

        print '</table>';

        return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Build the local product map
    // ---------------------------------------------------------------
    private static function buildMap($productId)
    {
        global $db;
        if (isset(self::$visited[$productId])) {
            return;
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
                if (!isset(self::$productMap[$obj->father])) {
                    self::$productMap[$obj->father] = new LocalProduct(
                        $obj->father,
                        $obj->fatherLabel,
                        $obj->fatherRef,
                        (float)$obj->fatherPrice,
                        (float)$obj->fatherBuy
                    );
                }
                if (!isset(self::$productMap[$obj->child])) {
                    self::$productMap[$obj->child] = new LocalProduct(
                        $obj->child,
                        $obj->childLabel,
                        $obj->childRef,
                        (float)$obj->childPrice,
                        (float)$obj->childBuy
                    );
                }
                // father -> child
                self::$productMap[$obj->father]->children[$obj->child] = (float)$obj->qty;
                // child -> father
                self::$productMap[$obj->child]->parents[] = $obj->father;

                // Recurse
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

    // ---------------------------------------------------------------
    // Print HEAD for child/parent table
    // ---------------------------------------------------------------
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

    // ---------------------------------------------------------------
    // COST/PRICE
    // ---------------------------------------------------------------
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

    // ---------------------------------------------------------------
    // updateProductAttributes
    // ---------------------------------------------------------------
    public static function updateProductAttributes($productId, $user)
    {
        global $db;
        dol_syslog("updateProductAttributes: Start for productId=" . $productId);

        self::$productMap = array();
        self::$visited = array();
        self::buildMap($productId);

        $lp = self::getLocalProduct($productId);
        if (!$lp) {
            dol_syslog("updateProductAttributes: product #$productId not found in map", LOG_WARNING);
            return;
        }
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
        } else if (!empty($lp->parents)) {
            dol_syslog("updateProductAttributes: #$productId has no children => recalc parent's cost");
            foreach ($lp->parents as $parId) {
                self::updateProductAttributes($parId, $user);
            }
        } else {
            dol_syslog("updateProductAttributes: #$productId has neither children nor parents => no cost update");
        }
    }

    public static function getProductTreeHTML($productId, $user)
    {
        return self::getCompletePage($productId);
    }
}

/**
 * Minimal container for product data
 */
class LocalProduct
{
    public $id;
    public $label;
    public $ref;
    public $price    = 0.0;
    public $buyprice = 0.0;
    public $children = array(); // child => qty
    public $parents  = array();

    public function __construct($id, $label, $ref, $price, $buyprice)
    {
        $this->id       = (int)$id;
        $this->label    = $label;
        $this->ref      = $ref;
        $this->price    = (float)$price;
        $this->buyprice = (float)$buyprice;
    }
}
