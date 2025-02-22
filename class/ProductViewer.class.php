<?php

/**
 * Class ProductHierarchy
 *
 * Provides methods to build a non‐recursive map of product associations, display
 * a fancy ASCII tree of children/parents, and recalculate cost prices.
 */
class ProductHierarchy
{

    /** @var LocalProduct[]  productMap[productId] */
    private static $productMap = array();

    /**
     * Generate the complete HTML page with:
     *   1) Header (Ref, # subproducts, # parents)
     *   2) Children table
     *   3) Parents table
     *   4) Estratégia de Sincronização table
     *
     * @param int $productId ID of the product to display
     * @return string        HTML output
     */
    public static function getCompletePage($productId)
    {
        global $db, $langs, $conf;

        // 1) Clear map & build BFS tree
        self::$productMap = array();
        self::buildMapBFS($productId);

        // 2) Load top-level product from Dolibarr
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new Product($db);
        if ($prod->fetch($productId) <= 0) {
            return '<p style="color:red;">Erro: produto #' . $productId . ' não encontrado.</p>';
        }

        // 3) Start capturing output
        ob_start();

        // -- SECTION 1: HEADER
        print '<div class="fichecenter">';
        print '<div class="underbanner clearboth"></div>';
        print '<table class="border tableforfield centpercent">';
        // Use getNomUrl(1) to generate a clickable reference link
        $linkRef = $prod->getNomUrl(1);
        print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td><td colspan="3">' . $linkRef . '</td></tr>';

        $lp = self::getLocalProduct($productId);
        $nbChildren = $lp ? count($lp->children) : 0;
        $nbParents  = $lp ? count($lp->parents)  : 0;
        print '<tr><td class="titlefield">Número de produtos que compõem este kit</td><td colspan="3">' . $nbChildren . '</td></tr>';
        print '<tr><td class="titlefield">Número de produtos de embalagem fonte</td><td colspan="3">' . $nbParents . '</td></tr>';
        print '</table>';
        print '</div>';
        print '<div class="clearboth"></div>';
        print dol_get_fiche_end();

        // -- SECTION 2: CHILDREN
        print '<p><strong>Lista de produtos/serviços que são componentes deste kit</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);

        // Print top-level row, then recursively display children
        self::printLine($productId, 0, 0, array(), true, 0, 1, 'child');
        $visitedChildren = array();
        self::fancyChildRecursive($productId, 1, 5, $visitedChildren, array());

        print '</table><br>';

        // -- SECTION 3: PARENTS
        print '<p><strong>Lista de kits com este produto como componente</strong></p>';
        print '<table class="noborder" width="100%">';
        self::printChildParentTableHead($langs);

        self::printLine($productId, 0, 0, array(), true, 0, 1, 'parent');
        $visitedParents = array();
        self::fancyParentRecursive($productId, 1, 5, $visitedParents, array());

        print '</table><br>';

        // -- SECTION 4: Ficha Tecnica
        print '<p><strong>' . $langs->trans("FichaTecnica") . '</strong></p>';
        print '<table class="noborder" width="100%">';
        print '<tr class="liste_titre">';
        print '<td width="10%">' . $langs->trans("Reference") . '</td>';
        print '<td width="50%">' . $langs->trans("Label") . '</td>';
        print '<td width="20%">Qty</td>';
        print '<td width="20%">Tipo</td>';
        print '<td width="10%">CostPrice</td>';
        print '<td width="10%">Subtotal</td>';
        print '</tr>';

        if ($lp) {
            foreach ($lp->children as $childId => $qty) {
                $childLP = self::getLocalProduct($childId);
                if (!$childLP) continue;
                $ref = new Product($db);
                $ref->fetch($childId);
                $linkRef = $ref->getNomUrl(1);

                print '<tr style="font-style: italic;">';
                print '<td>' . $linkRef . '</td>';
                print '<td>' . htmlspecialchars($childLP->label, ENT_QUOTES) . '</td>';
                print '<td>x ' . number_format($qty, 3, '.', '') . '</td>';
                print '<td>' . $langs->trans('Subprodutos') . '</td>';
                $buyVal = price($childLP->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
                print '<td>' . $buyVal . '</td>';
                $subTotal = price($qty * $childLP->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
                print '<td>' . $subTotal . '</td>';
                print '</tr>';
            }

            $sumBuy = self::computeRecursivePrice($lp, 'buyprice');
            print '<tr style="font-style: italic;">';
            print '<td colspan=5>' . $langs->trans('TotaisEstimadosDoProduto') . '</td>';
            print '<td>' . price($sumBuy, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE . '</td>';
            print '</tr>';

            print '<tr style="font-weight: bold;font-size:1.1em;">';
            print '<td colspan=5>' . $langs->trans('PrecoCusto') . '</td>';
            print '<td>' . price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;
            print self::compareIcon($sumBuy, $lp->buyprice);
            print '</td>';
            print '</tr>';
        }

        print '</table>';

        return ob_get_clean();
    }


    /**
     * Build a product map (father/child) using BFS to avoid infinite recursion.
     *
     * @param int $startId  The product ID from which to start BFS
     * @return void
     */
    private static function buildMapBFS($startId)
    {
        global $db;

        // We'll keep a queue of product IDs to process
        $queue = array($startId);
        // We'll keep track of visited product IDs so we don't re‐process them
        $seen  = array($startId => true);

        while (!empty($queue)) {
            $current = array_shift($queue);

            // Query for all associations where $current is father or child
            $sql  = "SELECT pa.fk_product_pere as father, pa.fk_product_fils as child, pa.qty as qty,";
            $sql .= " p.label as fatherLabel, p.ref as fatherRef, p.price as fatherPrice, p.cost_price as fatherBuy,";
            $sql .= " f.label as childLabel, f.ref as childRef, f.price as childPrice, f.cost_price as childBuy";
            $sql .= " FROM " . MAIN_DB_PREFIX . "product_association pa";
            $sql .= " JOIN " . MAIN_DB_PREFIX . "product p ON (p.rowid = pa.fk_product_pere)";
            $sql .= " JOIN " . MAIN_DB_PREFIX . "product f ON (f.rowid = pa.fk_product_fils)";
            $sql .= " WHERE pa.fk_product_pere = " . (int)$current . " OR pa.fk_product_fils = " . (int)$current;

            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    // Ensure father object
                    if (!isset(self::$productMap[$obj->father])) {
                        self::$productMap[$obj->father] = new LocalProduct(
                            $obj->father,
                            $obj->fatherLabel,
                            $obj->fatherRef,
                            (float)$obj->fatherPrice,
                            (float)$obj->fatherBuy
                        );
                    }
                    // Ensure child object
                    if (!isset(self::$productMap[$obj->child])) {
                        self::$productMap[$obj->child] = new LocalProduct(
                            $obj->child,
                            $obj->childLabel,
                            $obj->childRef,
                            (float)$obj->childPrice,
                            (float)$obj->childBuy
                        );
                    }
                    // Deduplicate father->child
                    if (!array_key_exists($obj->child, self::$productMap[$obj->father]->children)) {
                        self::$productMap[$obj->father]->children[$obj->child] = (float)$obj->qty;
                    }
                    // Deduplicate child->father
                    if (!in_array($obj->father, self::$productMap[$obj->child]->parents, true)) {
                        self::$productMap[$obj->child]->parents[] = $obj->father;
                    }

                    // If father not in $seen, add to queue
                    if (empty($seen[$obj->father])) {
                        $queue[] = $obj->father;
                        $seen[$obj->father] = true;
                    }
                    // If child not in $seen, add to queue
                    if (empty($seen[$obj->child])) {
                        $queue[] = $obj->child;
                        $seen[$obj->child] = true;
                    }
                }
                $db->free($resql);
            }
        }
    }

    /**
     * Retrieve a LocalProduct from the internal map by ID.
     *
     * @param int $id Product ID
     * @return LocalProduct|null
     */
    private static function getLocalProduct($id)
    {
        return isset(self::$productMap[$id]) ? self::$productMap[$id] : null;
    }

    /**
     * Print table headers for the children/parents listing:
     * columns: Reference, Designation, Qty, Type, CostPrice.
     *
     * @param \Translate $langs Dolibarr translation object
     * @return void
     */
    private static function printChildParentTableHead($langs)
    {
        print '<tr class="liste_titre">';
        print '<td width="20%">' . $langs->trans("Reference") . '</td>';
        print '<td width="35%">' . $langs->trans("Designation") . '</td>';
        print '<td width="10%">' . $langs->trans("Subproducts") . '</td>';
        print '<td width="10%">' . $langs->trans("Type") . '</td>';
        print '<td width="5%">' . $langs->trans("CostPrice") . '</td>';
        print '</tr>';
    }

    /**
     * Print a single row (line) in the tree, with fancy ASCII indentation
     * based on level, prefix array, and whether this is the last sibling.
     *
     * @param int    $productId ID of the product to print
     * @param float  $qty       Quantity used by parent
     * @param int    $level     Current depth level
     * @param array  $prefix    Boolean array telling which levels have a vertical line
     * @param bool   $isLast    Whether this is the last sibling
     * @param int    $index     Index of this sibling
     * @param int    $count     Total number of siblings at this level
     * @param string $mode      Either 'child' or 'parent'
     * @return void
     */
    private static function printLine($productId, $qty, $level, array $prefix, bool $isLast, int $index, int $count, string $mode)
    {
        global $db, $langs, $conf;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $pr = new Product($db);
        if ($pr->fetch($productId) < 0) return;

        // Build indentation
        $indent = '';
        for ($i = 0; $i < $level; $i++) {
            if (!empty($prefix[$i])) {
                $indent .= '┃&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
            } else {
                $indent .= '';
            }
        }
        if ($level > 0) {
            $indent .= $isLast ? '┗━━ ' : '┣━━ ';
        }

        // Build assoc
        $assoc = '';
        if ($qty > 0) {
            $assoc = number_format($qty, 3, '.', '');
        } else if (!empty($lp->children)) {
            $assoc = count($lp->children);
        } else if (!empty($lp->parents)) {
            $assoc = count($lp->parents) . ' Pais';
        }

        $type = (!empty($lp->children)) ? 'Ficha Técnica' : '';
        $priceStr = price($lp->buyprice, '', '', 0, 3, 3, '') . ' ' . $conf->global->MAIN_MONNAIE;

        print '<tr>';
        print '<td>' . $indent . $pr->getNomUrl(1) . '</td>';
        print '<td>' . htmlspecialchars($lp->label, ENT_QUOTES) . '</td>';
        print '<td>' . $assoc . '</td>';
        print '<td>' . $type . '</td>';
        print '<td>' . $priceStr . '</td>';
        print '</tr>';
    }

    /**
     * Recursively display children using ASCII lines, controlling
     * vertical bars via a prefix array of booleans.
     *
     * @param int   $productId ID of the current product
     * @param int   $level     Current depth
     * @param int   $maxLevel  Maximum depth allowed
     * @param array $visited   Products already displayed
     * @param array $prefix    Boolean array for line drawing
     * @return void
     */
    private static function fancyChildRecursive($productId, int $level, int $maxLevel, array &$visited, array $prefix)
    {
        if (in_array($productId, $visited, true)) return;
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        $childIds = array_keys($lp->children);
        $numKids  = count($childIds);

        for ($i = 0; $i < $numKids; $i++) {
            $childId = $childIds[$i];
            $qty     = $lp->children[$childId];
            $isLast  = ($i == $numKids - 1);

            $childPrefix = $prefix;
            $childPrefix[$level] = !$isLast;

            self::printLine($childId, $qty, $level, $childPrefix, $isLast, $i, $numKids, 'child');

            if ($level < $maxLevel) {
                self::fancyChildRecursive($childId, $level + 1, $maxLevel, $visited, $childPrefix);
            }
        }
    }

    /**
     * Recursively display parents (kits) using ASCII lines,
     * controlling vertical bars via a prefix array of booleans.
     *
     * @param int   $productId ID of the current product
     * @param int   $level     Current depth
     * @param int   $maxLevel  Maximum depth allowed
     * @param array $visited   Products already displayed
     * @param array $prefix    Boolean array for line drawing
     * @return void
     */
    private static function fancyParentRecursive($productId, int $level, int $maxLevel, array &$visited, array $prefix)
    {
        if (in_array($productId, $visited, true)) return;
        $visited[] = $productId;

        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        $pars = $lp->parents;
        $n    = count($pars);

        for ($i = 0; $i < $n; $i++) {
            $parId  = $pars[$i];
            if (in_array($parId, $visited, true)) continue;
            $isLast = ($i == $n - 1);

            $parPrefix = $prefix;
            $parPrefix[$level] = !$isLast;

            self::printLine($parId, 0, $level, $parPrefix, $isLast, $i, $n, 'parent');

            if ($level < $maxLevel) {
                self::fancyParentRecursive($parId, $level + 1, $maxLevel, $visited, $parPrefix);
            }
        }
    }

    /**
     * Recursively compute either the "price" or "buyprice" for a product,
     * summing subproducts if there are children.
     *
     * @param LocalProduct $lp  The product node
     * @param string       $key 'price' or 'buyprice'
     * @return float
     */
    private static function computeRecursivePrice(LocalProduct $lp, string $key)
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

    /**
     * Compare two floating‐point values and return an HTML icon
     * ('tick.png' or 'error.png') to indicate if they are close.
     *
     * @param float $val1
     * @param float $val2
     * @return string HTML <img> tag
     */
    private static function compareIcon($val1, $val2)
    {
        if (abs($val1 - $val2) < 1E-2) {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/tick.png" alt="tick">';
        } else {
            return ' <img src="' . DOL_URL_ROOT . '/theme/eldy/img/error.png" alt="error">';
        }
    }

    /**
     * Update the cost_price (buyprice) of a product by:
     *  - Summing the cost of its children, if any
     *  - Otherwise, updating each parent if this product has no children
     *
     * @param int  $productId ID of the product to update
     * @param User $user      Dolibarr user performing the action
     * @return void
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db;
        dol_syslog("updateProductAttributes: Start for productId=" . $productId);

        // Build BFS map
        self::$productMap = array();
        self::buildMapBFS($productId);

        // Attempt recalc
        $lp = self::getLocalProduct($productId);
        if (!$lp) return;

        if (!empty($lp->children)) {
            $newCost = self::computeRecursivePrice($lp, 'buyprice');
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new Product($db);
            if ($prod->fetch($productId) > 0) {
                $prod->cost_price = $newCost;
                $prod->buyprice = $newCost;
                $res = $prod->update($productId, $user);
                if ($res > 0) {
                    dol_syslog("updateProductAttributes: updated #$productId cost=$newCost");
                }
            }
        } else if (!empty($lp->parents)) {
            // Recalc each parent
            foreach ($lp->parents as $p) {
                self::updateProductAttributes($p, $user);
            }
        }
    }
}

/**
 * Simple container class for a product's essential data.
 */
class LocalProduct
{
    public $id;
    public $label;
    public $ref;
    public $price    = 0.0;
    public $buyprice = 0.0;

    /** @var float[] childId => quantity */
    public $children = array();
    /** @var int[]   parentId[] */
    public $parents  = array();

    /**
     * Constructor
     *
     * @param int    $id       Product ID
     * @param string $label    Product label/name
     * @param string $ref      Product reference
     * @param float  $price    Product sale price
     * @param float  $buyprice Product cost price
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
