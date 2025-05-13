<?php
/**
 * Refactored ProductHierarchy
 *
 * Core changes compared to the legacy version:
 *   • Stateless in‑memory graph built by GraphBuilder (no more scattered static maps).
 *   • Single depth‑first cost propagation à‑la ProductMixer for perfect children‑before‑parent evaluation.
 *   • Switchable via per‑product extra‑field **kreap_spread_buyprice** *and* global constant **KREAP_SPREAD_BUYPRICE**.
 *   • Threshold‑based persistence (±0.001 €) with a 100ms sleep to minimise write races.
 *   • Clean DTOs (ProductNode/ChildLink) isolate data from behaviour.
 *   • Public API (class + method name) preserved for backwards compatibility.
 */

class ProductHierarchy
{
    /**
     * Extra‑field key enabling cost propagation on a product basis.
     */
    private const SYNC_FIELD = 'kreap_spread_buyprice';

    /**
     * Optional global constant mirroring ProductMixer's behaviour.
     * Set conf->global->KREAP_SPREAD_BUYPRICE = 1 to activate.
     */
    private const GLOBAL_CONST = 'KREAP_SPREAD_BUYPRICE';

    /**
     * Minimum Δ before persisting a new cost_price (in €).
     */
    private const DELTA_THRESHOLD = 0.001;

    /**
     * Crude mutex to avoid recursive trigger loops.
     * External code MAY read it to skip actions, so keep it public.
     * @var bool
     */
    public static $inProgress = false;

    /**
     * @deprecated The internal map is now hidden; kept only for BC.
     * @var array<int, ProductNode>
     */
    public static $productMap = [];

    // ---------------------------------------------------------------------
    // Public API (kept stable)
    // ---------------------------------------------------------------------

    /**
     * Trigger‑friendly entry point that refreshes the cost_price of an entire
     * virtual‑product tree rooted at $productId.
     *
     * @param int   $productId Root product ID.
     * @param mixed $user      Dolibarr user object (forwarded to ->update()).
     * @return int             1 if the process ran, 0 if skipped/disabled.
     */
    public static function updateProductAttributes($productId, $user)
    {
        global $db, $conf;

        if (self::$inProgress) {
            dol_syslog(__METHOD__ . ' skipped – already running', LOG_INFO);
            return 0;
        }
        self::$inProgress = true;

        try {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

            $product = new Product($db);
            if ($product->fetch($productId) <= 0) {
                dol_syslog(__METHOD__ . " invalid product id $productId", LOG_ERR);
                return 0;
            }

            // Guard 1 – global switch (optional, default ON when undefined)
            $globalSync = empty($conf->global->{self::GLOBAL_CONST}) ? true : (bool)$conf->global->{self::GLOBAL_CONST};
            if (!$globalSync) {
                dol_syslog(__METHOD__ . ' global cost sync disabled', LOG_DEBUG);
                return 0;
            }

            // Guard 2 – per‑product extra field
            $extrafields = new ExtraFields($db);
            $product->fetch_optionals($productId, $extrafields);
            if (empty($product->array_options['options_' . self::SYNC_FIELD])) {
                dol_syslog(__METHOD__ . " extra‑field sync disabled for pid=$productId", LOG_DEBUG);
                return 0;
            }

            // -----------------------------------------------------------------
            // Build the graph and propagate costs
            // -----------------------------------------------------------------
            $nodes                         = GraphBuilder::fromRoot($productId);
            self::$productMap              = $nodes; // expose for legacy code
            $root                          = $nodes[$productId] ?? null;
            if (!$root) {
                dol_syslog(__METHOD__ . ' graph failed to build – aborting', LOG_ERR);
                return 0;
            }

            self::propagateCost($root, $nodes, $user, []);
            dol_syslog(__METHOD__ . " finished – tree rooted at $productId processed", LOG_INFO);
            return 1;
        } finally {
            self::$inProgress = false; // always release lock
        }
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Depth‑first cost propagation (children → parent).
     *
     * @param ProductNode             $node     Current node.
     * @param array<int,ProductNode>  $nodes    Full node map for lookups.
     * @param mixed                   $user     Dolibarr user for ->update().
     * @param array<int,bool>         $visiting Recursion guard for cycle detection.
     * @return float                             Computed cost for $node (never persisted for leaves).
     */
    private static function propagateCost(ProductNode $node, array $nodes, $user, array $visiting): float
    {
        global $db;

        if (isset($visiting[$node->id])) {
            dol_syslog(__METHOD__ . " cycle detected at pid={$node->id}", LOG_WARNING);
            return $node->cost; // break recursion gracefully
        }
        $visiting[$node->id] = true;

        // Leaf node: no children → return cached cost.
        if (empty($node->children)) {
            return $node->cost;
        }

        // Aggregate children's cost first.
        $total = 0.0;
        foreach ($node->children as $link) {
            $child      = $nodes[$link->childId] ?? null;
            $childCost  = $child ? self::propagateCost($child, $nodes, $user, $visiting) : 0.0;
            $total     += $link->qty * $childCost;
        }

        // Persist if the delta is meaningful.
        if (abs($total - $node->cost) >= self::DELTA_THRESHOLD) {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new Product($db);
            if ($prod->fetch($node->id) > 0) {
                $prod->cost_price = $total;
                usleep(100000); // (≈0.1 s) race‑condition dampener, mirrors ProductMixer
                $prod->update($node->id, $user);
                dol_syslog("ProductHierarchy cost updated pid={$node->id} old={$node->cost} new={$total}", LOG_DEBUG);
                $node->cost = $total; // in‑memory
            } else {
                dol_syslog(__METHOD__ . " unable to fetch pid={$node->id} for update", LOG_ERR);
            }
        }

        return $total;
    }
}

// =====================================================================
// Plain‑data containers
// =====================================================================

/** Lightweight product vertex. */
class ProductNode
{
    /** @var int */
    public $id;
    /** @var float */
    public $cost;
    /** @var ChildLink[] */
    public $children = [];

    public function __construct(int $id, float $cost)
    {
        $this->id   = $id;
        $this->cost = $cost;
    }
}

/** Child relationship (qty, dest‑id). */
class ChildLink
{
    /** @var int */
    public $childId;
    /** @var float */
    public $qty;

    public function __construct(int $childId, float $qty)
    {
        $this->childId = $childId;
        $this->qty     = $qty;
    }
}

// =====================================================================
// GraphBuilder – DB hydration
// =====================================================================

final class GraphBuilder
{
    /**
     * Builds the product graph starting from a single root product.
     * Only downward relationships (parent → children) are traversed; upward traversal
     * is unnecessary for cost propagation.
     *
     * @param int $rootId
     * @return array<int,ProductNode> All discovered nodes keyed by product id.
     */
    public static function fromRoot(int $rootId): array
    {
        global $db;

        $nodes = [];
        $queue = [$rootId];

        // BFS to collect every descendant (avoids deep recursion in DB).
        while ($queue) {
            $pid = array_pop($queue);
            if (isset($nodes[$pid])) {
                continue; // already visited
            }
            $nodes[$pid] = new ProductNode($pid, 0.0); // cost filled later

            $sql = 'SELECT fk_product_fils AS child, qty
                    FROM ' . MAIN_DB_PREFIX . 'product_association
                    WHERE fk_product_pere = ' . (int)$pid;
            $res = $db->query($sql);
            if ($res) {
                while ($row = $db->fetch_object($res)) {
                    $childId = (int)$row->child;
                    $qty     = (float)$row->qty;
                    $nodes[$pid]->children[] = new ChildLink($childId, $qty);
                    $queue[]                = $childId; // enqueue child for its own children search
                }
            } else {
                dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
            }
        }

        // Bulk‑load current cost_price for all nodes in one query.
        $idList = implode(',', array_keys($nodes));
        if (!empty($idList)) {
            $sql  = 'SELECT rowid, cost_price FROM ' . MAIN_DB_PREFIX . 'product WHERE rowid IN (' . $idList . ')';
            $res2 = $db->query($sql);
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
