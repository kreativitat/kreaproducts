<?php

class ProductHierarchy
{
    private const SYNC_FIELD   = 'kreap_spread_buyprice';
    private const GLOBAL_CONST = 'KREAP_SPREAD_BUYPRICE';
    private const DELTA        = 0.001;

    public static $inProgress = false;

    // deprecated exposed for legacy code
    public static $productMap = [];

    public static function updateProductAttributes($productId, $user)
    {
        global $db, $conf;

        if (self::$inProgress) {
            dol_syslog(__METHOD__ . ' skipped – already running', LOG_DEBUG);
            return 0;
        }
        self::$inProgress = true;

        try {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

            // Guards
            $globalOn = empty($conf->global->{self::GLOBAL_CONST}) ? true
                : (bool)$conf->global->{self::GLOBAL_CONST};
            if (!$globalOn) {
                dol_syslog(__METHOD__ . ' global sync disabled', LOG_INFO);
                return 0;
            }

            $prod = new Product($db);
            if ($prod->fetch($productId) <= 0) {
                dol_syslog(__METHOD__ . " invalid product id $productId", LOG_ERR);
                return 0;
            }
            $extrafields = new ExtraFields($db);
            $prod->fetch_optionals($productId, $extrafields);
            if (empty($prod->array_options['options_' . self::SYNC_FIELD])) {
                dol_syslog(__METHOD__ . " sync disabled by extra‑field for pid=$productId", LOG_DEBUG);
                return 0;
            }

            // Build graph
            $nodes            = GraphBuilder::aroundPivot($productId);
            self::$productMap = $nodes; // legacy exposure
            $pivot            = $nodes[$productId] ?? null;
            if (!$pivot) {
                dol_syslog(__METHOD__ . ' graph build failed', LOG_ERR);
                return 0;
            }

            // Propagate
            $visited = [];
            self::propagateUpstream($pivot, $nodes, $user, $visited);
            dol_syslog(__METHOD__ . " cost propagation completed for pid=$productId", LOG_INFO);
            return 1;
        } finally {
            self::$inProgress = false;
        }
    }

    private static function propagateUpstream(ProductNode $node, array &$nodes, $user, array &$visited): void
    {
        global $db;
        if (isset($visited[$node->id])) return;
        $visited[$node->id] = true;

        foreach ($node->parents as $parentId => $qtyWithParentNotUsedHere) {
            if (!isset($nodes[$parentId])) continue;
            $parent = $nodes[$parentId];

            // Compute new cost from unique child list
            $newCost = 0.0;
            foreach ($parent->children as $childId => $qty) {
                $childNode = $nodes[$childId] ?? null;
                $childCost = $childNode ? $childNode->cost : 0.0;
                $newCost  += $qty * $childCost;
            }

            // Persist if Δ meaningful
            if (abs($newCost - $parent->cost) >= self::DELTA) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $p = new Product($db);
                if ($p->fetch($parent->id) > 0) {
                    $p->cost_price = $newCost;
                    usleep(100000); // 0.1 s guard
                    $p->update($parent->id, $user);
                    dol_syslog("ProductHierarchy updated pid={$parent->id} cost {$parent->cost}→{$newCost}", LOG_DEBUG);
                    $parent->cost = $newCost; // keep in‑memory in sync
                } else {
                    dol_syslog(__METHOD__ . " cannot fetch pid={$parent->id}", LOG_ERR);
                }
            }

            // Recurse further up
            self::propagateUpstream($parent, $nodes, $user, $visited);
        }
    }
}

class ProductNode
{
    public $id;

    public $cost = 0.0;

    /** @var array<int,float> childId ⇒ qty */
    public $children = [];

    /** @var array<int,float> parentId ⇒ qty */
    public $parents  = [];

    public function __construct(int $id, float $cost = 0.0)
    {
        $this->id   = $id;
        $this->cost = $cost;
    }
}

final class GraphBuilder
{
    public static function aroundPivot(int $pivotId): array
    {
        global $db;

        $nodes = [];
        $queue = [$pivotId];
        $seen  = [];

        while ($queue) {
            $pid = array_pop($queue);
            if (isset($seen[$pid])) continue;
            $seen[$pid] = true;

            $nodes[$pid] = $nodes[$pid] ?? new ProductNode($pid);

            $sql = 'SELECT fk_product_pere AS parent, fk_product_fils AS child, qty
                    FROM ' . MAIN_DB_PREFIX . 'product_association
                    WHERE fk_product_pere = ' . (int)$pid . '
                       OR fk_product_fils = ' . (int)$pid;
            $res = $db->query($sql);
            if (!$res) {
                dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
                continue;
            }
            while ($row = $db->fetch_object($res)) {
                $parentId = (int)$row->parent;
                $childId  = (int)$row->child;
                $qty      = (float)$row->qty;

                $nodes[$parentId] = $nodes[$parentId] ?? new ProductNode($parentId);
                $nodes[$childId]  = $nodes[$childId]  ?? new ProductNode($childId);

                // Downward (unique)
                $nodes[$parentId]->children[$childId] = $qty; // overwrite duplicates

                // Upward  (unique)
                $nodes[$childId]->parents[$parentId]  = $qty; // overwrite duplicates

                if (!isset($seen[$parentId])) $queue[] = $parentId;
                if (!isset($seen[$childId]))  $queue[] = $childId;
            }
        }

        // Bulk‑load current cost_price
        $idList = implode(',', array_keys($nodes));
        if ($idList) {
            $sql2 = 'SELECT rowid, cost_price FROM ' . MAIN_DB_PREFIX . 'product WHERE rowid IN (' . $idList . ')';
            $res2 = $db->query($sql2);
            if ($res2) {
                while ($row = $db->fetch_object($res2)) {
                    $nodes[$row->rowid]->cost = (float)$row->cost_price;
                }
            } else {
                dol_syslog(__METHOD__ . ' SQL error on cost fetch: ' . $db->lasterror(), LOG_ERR);
            }
        }
        return $nodes;
    }
}
