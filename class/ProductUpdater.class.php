<?php
/**
 * Product cost‑price synchronizer for Dolibarr.
 *
 * Refactored replacement for the old ProductHierarchy class while
 * maintaining the public entry‑point updateProductAttributes() so existing
 * integrations stay intact. The implementation is now service‑oriented and
 * unit‑test‑friendly.
 *
 * Highlights
 * -----------
 * • Explicit dependency injection (DB + user) – no hidden globals in the
 *   core logic.
 * • Lightweight Node value‑objects hold the graph in memory.
 * • Bottom‑up propagation (Kahn’s algorithm) guarantees children are
 *   calculated before their parents.
 * • Feature gating via a *global constant* and *per‑product extra‑field*.
 * • Database write occurs only when Δ ≥ 0.001 €, plus a 100 ms guard pause
 *   to reduce race conditions in high‑concurrency environments.
 *
 * Usage remains unchanged:
 *     ProductCostSynchronizer::updateProductAttributes($productId, $user);
 */
class ProductCostSynchronizer
{
    /** Global re‑entrancy lock to avoid recursive trigger storms. */
    private static bool $lock = false;

    private DoliDB $db;
    private $user;

    /** @var array<int,Node> */
    private array $node = [];

    private const EXTRA_KEY       = 'kreap_spread_buyprice';
    private const GLOBAL_CONST    = 'KREAP_UseCostPriceSync';
    private const DIFF_THRESHOLD  = 0.001;

    /* -------------------------------------------------------------------- */
    /*  Public API                                                          */
    /* -------------------------------------------------------------------- */

    /**
     * Backwards‑compatible façade expected by external modules.
     *
     * @param int        $productId  Root (recently affected) product.
     * @param User|mixed $user       Dolibarr user used for ->update().
     * @param DoliDB|null $db        Provide to bypass global $db (useful in tests).
     *
     * @return int 1 = update executed, 0 = skipped.
     */
    public static function updateProductAttributes(int $productId, $user, $db = null): int
    {
        global $db as $gdb;         // Fallback for production code
        $db = $db ?: $gdb;

        $svc = new self($db, $user);
        return $svc->handle($productId);
    }

    /* -------------------------------------------------------------------- */
    /*  Construction                                                        */
    /* -------------------------------------------------------------------- */

    private function __construct(DoliDB $db, $user)
    {
        $this->db   = $db;
        $this->user = $user;
    }

    /**
     * Main driver: acquires the lock, verifies feature gates, builds the
     * product graph, then propagates costs bottom‑up.
     */
    private function handle(int $rootId): int
    {
        if (self::$lock) {
            dol_syslog(__METHOD__ . ': already running, aborting', LOG_WARNING);
            return 0;
        }
        self::$lock = true;

        try {
            if (!$this->isSyncEnabledGlobally()) {
                dol_syslog(__METHOD__ . ': global constant ' . self::GLOBAL_CONST . ' disabled.', LOG_DEBUG);
                return 0;
            }
            if (!$this->isSyncEnabledForProduct($rootId)) {
                dol_syslog(__METHOD__ . " extra‑field '" . self::EXTRA_KEY . "' disabled for product $rootId", LOG_DEBUG);
                return 0;
            }

            $this->buildGraph($rootId);
            $this->propagateBottomUp();

            return 1;
        } finally {
            self::$lock = false;
        }
    }

    /* -------------------------------------------------------------------- */
    /*  Graph building                                                      */
    /* -------------------------------------------------------------------- */

    private function buildGraph(int $pivotId): void
    {
        $queue   = [$pivotId];
        $visited = [];

        while ($queue) {
            $id = array_pop($queue);
            if (isset($visited[$id])) continue;
            $visited[$id] = true;

            foreach ($this->loadAssociations($id) as [$parent, $child, $qty]) {
                $p = $this->getNode($parent);
                $c = $this->getNode($child);

                $p->children[$child] = $qty;
                $c->parents[]        = $parent;

                if (!isset($visited[$parent])) $queue[] = $parent;
                if (!isset($visited[$child]))  $queue[] = $child;
            }
        }
        $this->hydrateNodes();
    }

    /**
     * @return list<array{int,int,float}> Rows [parentId, childId, qty]
     */
    private function loadAssociations(int $productId): array
    {
        $sql = "SELECT pa.fk_product_pere as parent, pa.fk_product_fils as child, pa.qty as qty
                FROM " . MAIN_DB_PREFIX . "product_association pa
                WHERE pa.fk_product_pere = " . (int)$productId . "
                   OR pa.fk_product_fils = " . (int)$productId;

        $rows = [];
        $res = $this->db->query($sql);
        if (!$res) {
            dol_syslog(__METHOD__ . ': SQL error ' . $this->db->lasterror(), LOG_ERR);
            return $rows;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = [(int)$o->parent, (int)$o->child, (float)$o->qty];
        }
        return $rows;
    }

    private function getNode(int $id): Node
    {
        return $this->node[$id] ??= new Node($id);
    }

    private function hydrateNodes(): void
    {
        if (!$this->node) return;
        $ids = implode(',', array_map('intval', array_keys($this->node)));
        $sql = "SELECT rowid, ref, label, cost_price FROM " . MAIN_DB_PREFIX . "product WHERE rowid IN ($ids)";

        $res = $this->db->query($sql);
        if (!$res) {
            dol_syslog(__METHOD__ . ': SQL error ' . $this->db->lasterror(), LOG_ERR);
            return;
        }
        while ($o = $this->db->fetch_object($res)) {
            $n = $this->node[$o->rowid];
            $n->ref        = $o->ref;
            $n->label      = $o->label;
            $n->cost_price = (float)$o->cost_price;
        }
    }

    /* -------------------------------------------------------------------- */
    /*  Cost propagation                                                    */
    /* -------------------------------------------------------------------- */

    private function propagateBottomUp(): void
    {
        $inDeg = array_map(fn(Node $n) => count($n->children), $this->node);
        $queue = array_keys(array_filter($inDeg, fn($d) => $d === 0));

        $processed = 0;
        while ($queue) {
            $childId = array_shift($queue);
            $processed++;

            $child = $this->node[$childId];
            foreach ($child->parents as $parentId) {
                if (!isset($inDeg[$parentId])) continue;
                if (--$inDeg[$parentId] === 0) {
                    $this->recalcAndPersist($parentId);
                    $queue[] = $parentId;
                }
            }
        }
        if ($processed < count($this->node)) {
            dol_syslog(__METHOD__ . ': graph may contain a cycle (processed ' . $processed . '/' . count($this->node) . ')', LOG_WARNING);
        }
    }

    private function recalcAndPersist(int $parentId): void
    {
        $parent = $this->node[$parentId];

        $newCost = 0.0;
        foreach ($parent->children as $childId => $qty) {
            $childCost = $this->node[$childId]->cost_price ?? 0.0;
            $newCost  += $childCost * $qty;
        }

        if (abs($newCost - $parent->cost_price) < self::DIFF_THRESHOLD) return;

        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        $prod = new Product($this->db);
        if ($prod->fetch($parentId) <= 0) {
            dol_syslog(__METHOD__ . ": cannot fetch product $parentId", LOG_ERR);
            return;
        }

        usleep(100_000); // naive mutex, mirrors behaviour in original module
        $prod->cost_price = $newCost;
        $res = $prod->update($parentId, $this->user);

        if ($res > 0) {
            dol_syslog(__METHOD__ . ": updated cost_price of $parentId from {$parent->cost_price} to $newCost", LOG_DEBUG);
            $parent->cost_price = $newCost;
        } else {
            dol_syslog(__METHOD__ . ": failed to update product $parentId", LOG_ERR);
        }
    }

    /* -------------------------------------------------------------------- */
    /*  Feature‑gating helpers                                              */
    /* -------------------------------------------------------------------- */

    private function isSyncEnabledGlobally(): bool
    {
        global $conf;
        return !empty($conf->global->{self::GLOBAL_CONST});
    }

    private function isSyncEnabledForProduct(int $id): bool
    {
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

        $prod = new Product($this->db);
        if ($prod->fetch($id
