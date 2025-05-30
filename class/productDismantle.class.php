<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/lib/mrp_mo.lib.php';

/**
 * Class ProductDismantleController
 * 
 * Handles product dismantling operations including BOM management,
 * stock movements, and cost price calculations.
 */
class ProductDismantleController extends CommonObject
{
    // Constants for better maintainability
    private const DISMANTLE_BOM_TYPE = 1;
    private const MOVEMENT_TYPE_CONSUME = 'consume';
    private const MOVEMENT_TYPE_PRODUCE = 'produce';
    
    public $db;
    public $mo;
    
    private array $errors = [];
    private ?int $defaultWarehouseId = null;

    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->mo = new Mo($this->db);
        $this->defaultWarehouseId = (int)($conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
    }

    /**
     * Find BOM ID associated with a product ID.
     *
     * @param int $productId The ID of the product to find the BOM for.
     * @return int|null The BOM ID if found, null otherwise.
     * @throws InvalidArgumentException If productId is invalid
     */
    public function findBom(int $productId): ?int
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Product ID must be a positive integer');
        }

        dol_syslog(__METHOD__ . " - Product ID: $productId", LOG_DEBUG);
        
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bom_bom
                WHERE fk_product = %d
                AND bomtype = %d";
        
        $sql = sprintf($sql, $productId, self::DISMANTLE_BOM_TYPE);
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->addError("Database query failed for product ID $productId: " . $this->db->lasterror());
            dol_syslog($this->getLastError(), LOG_ERR);
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        
        return $obj ? (int)$obj->rowid : null;
    }

    /**
     * Check if a product belongs to the dismantle category.
     *
     * @param int $productId The product ID to check
     * @return bool True if product is in dismantle category, false otherwise
     * @throws InvalidArgumentException If productId is invalid
     */
    public function productInDismantleCategory(int $productId): bool
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Product ID must be a positive integer');
        }

        dol_syslog(__METHOD__ . " - Product ID: $productId", LOG_DEBUG);
        
        global $conf;
        
        $dismantleCategoryId = (int)($conf->global->KREAGENPRODUCT_PRODUCT_DISMANTLE_CATEGORY ?? 0);
        
        if ($dismantleCategoryId === 0) {
            dol_syslog("No dismantle category configured", LOG_WARNING);
            return false;
        }

        $sql = "SELECT fk_categorie FROM " . MAIN_DB_PREFIX . "categorie_product 
                WHERE fk_product = %d";
        
        $sql = sprintf($sql, $productId);
        $resql = $this->db->query($sql);
        
        if (!$resql) {
            $this->addError("Database query failed for product categories: " . $this->db->lasterror());
            return false;
        }

        $isInCategory = false;
        while ($obj = $this->db->fetch_object($resql)) {
            if ((int)$obj->fk_categorie === $dismantleCategoryId) {
                $isInCategory = true;
                break;
            }
        }
        
        $this->db->free($resql);
        return $isInCategory;
    }

    /**
     * Process dismantling operation: consume main product and produce components.
     *
     * @param int $bomId BOM identifier
     * @param float $qtyMovement Quantity for the movement (positive = normal, negative = reverse)
     * @param float $priceMovement Price for the movement
     * @param string $originRef Reference of the origin document
     * @param int $originId ID of the origin document
     * @param string $originType Type of the origin document
     * @param int|null $movementDate Movement date (timestamp)
     * @return int 0 on success, negative on error
     */
    public function produceAndConsume(
        int $bomId,
        float $qtyMovement,
        float $priceMovement,
        string $originRef,
        int $originId,
        string $originType,
        ?int $movementDate = null
    ): int {
        dol_syslog(__METHOD__ . " - BOM ID: $bomId, Qty: $qtyMovement", LOG_DEBUG);
        
        // Validate inputs
        if (!$this->validateProduceConsumeInputs($bomId, $qtyMovement, $priceMovement, $originRef, $originId, $originType)) {
            return -1;
        }

        $movementDate = $movementDate ?: dol_now();
        
        // Start transaction
        $this->db->begin();
        
        try {
            // Load and validate BOM
            $bom = $this->loadBom($bomId);
            if (!$bom) {
                $this->db->rollback();
                return -1;
            }

            // Get current cost price of the main product
            $currentCostPrice = $this->getProductCostPrice($bom->fk_product);
            if ($currentCostPrice === null) {
                $this->db->rollback();
                return -1;
            }

            // Prepare movement arrays
            $movements = $this->prepareMovements($bom, $currentCostPrice);
            
            // Execute stock movements
            if (!$this->executeStockMovements($movements, $qtyMovement, $priceMovement, $originRef, $originId, $originType, $movementDate)) {
                $this->db->rollback();
                return -1;
            }

            $this->db->commit();
            dol_syslog("Dismantle operation completed successfully", LOG_DEBUG);
            return 0;
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->addError("Exception during dismantle operation: " . $e->getMessage());
            dol_syslog($this->getLastError(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Validate inputs for produceAndConsume method.
     */
    private function validateProduceConsumeInputs(
        int $bomId,
        float $qtyMovement,
        float $priceMovement,
        string $originRef,
        int $originId,
        string $originType
    ): bool {
        if ($bomId <= 0) {
            $this->addError("Invalid BOM ID");
            return false;
        }
        
        if ($qtyMovement == 0) {
            $this->addError("Quantity movement cannot be zero");
            return false;
        }
        
        if ($priceMovement < 0) {
            $this->addError("Price movement cannot be negative");
            return false;
        }
        
        if (empty($originRef)) {
            $this->addError("Origin reference is required");
            return false;
        }
        
        if ($originId <= 0) {
            $this->addError("Invalid origin ID");
            return false;
        }
        
        if (empty($originType)) {
            $this->addError("Origin type is required");
            return false;
        }
        
        return true;
    }

    /**
     * Load and validate BOM.
     */
    private function loadBom(int $bomId): ?BOM
    {
        $bom = new BOM($this->db);
        if ($bom->fetch($bomId) <= 0) {
            $this->addError("Failed to fetch BOM with ID $bomId");
            return null;
        }

        if (!is_array($bom->lines) || empty($bom->lines)) {
            $this->addError("BOM has no component lines");
            return null;
        }

        return $bom;
    }

    /**
     * Get product cost price.
     */
    private function getProductCostPrice(int $productId): ?float
    {
        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            $this->addError("Failed to fetch product #$productId for cost price");
            return null;
        }

        return (float)$product->cost_price;
    }

    /**
     * Prepare movement arrays for consumption and production.
     */
    private function prepareMovements(BOM $bom, float $currentCostPrice): array
    {
        $movements = [
            self::MOVEMENT_TYPE_CONSUME => [],
            self::MOVEMENT_TYPE_PRODUCE => []
        ];

        // Add main product to consume
        $movements[self::MOVEMENT_TYPE_CONSUME][] = [
            'objectid' => $bom->fk_product,
            'qty' => $bom->qty,
            'fk_warehouse' => $this->defaultWarehouseId,
            'cost_price' => null // Will be set during execution
        ];

        // Add BOM components to produce
        foreach ($bom->lines as $line) {
            $movements[self::MOVEMENT_TYPE_PRODUCE][] = [
                'objectid' => $line->fk_product,
                'qty' => $line->qty,
                'fk_warehouse' => $this->defaultWarehouseId,
                'cost_price' => $currentCostPrice / $line->qty // Distribute cost among components
            ];
        }

        return $movements;
    }

    /**
     * Execute all stock movements.
     */
    private function executeStockMovements(
        array $movements,
        float $qtyMovement,
        float $priceMovement,
        string $originRef,
        int $originId,
        string $originType,
        int $movementDate
    ): bool {
        global $user;
        
        foreach ($movements as $movementType => $items) {
            foreach ($items as $item) {
                // Update cost price
                $costPrice = ($movementType === self::MOVEMENT_TYPE_CONSUME) 
                    ? $priceMovement 
                    : $item['cost_price'];
                
                if (!$this->updateProductCostPrice($item['objectid'], $costPrice)) {
                    return false;
                }

                // Execute stock movement
                if (!$this->executeStockMovement(
                    $movementType,
                    $item,
                    $qtyMovement,
                    $costPrice,
                    $originRef,
                    $originId,
                    $originType,
                    $movementDate,
                    $user
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Update product cost price.
     */
    private function updateProductCostPrice(int $productId, float $costPrice): bool
    {
        global $user;
        
        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            $this->addError("Failed to fetch product #$productId for cost update");
            return false;
        }

        $product->cost_price = $costPrice;
        if ($product->update($product->id, $user) <= 0) {
            $this->addError("Failed to update cost price for product #$productId");
            return false;
        }

        return true;
    }

    /**
     * Execute a single stock movement.
     */
    private function executeStockMovement(
        string $movementType,
        array $item,
        float $qtyMovement,
        float $costPrice,
        string $originRef,
        int $originId,
        string $originType,
        int $movementDate,
        $user
    ): bool {
        $stockmove = new MouvementStock($this->db);
        
        $rawQty = $item['qty'] * $qtyMovement;
        $qty = abs($rawQty);
        $isReverse = $rawQty < 0;

        // Determine movement direction
        $shouldReceive = ($movementType === self::MOVEMENT_TYPE_CONSUME && $isReverse) ||
                        ($movementType === self::MOVEMENT_TYPE_PRODUCE && !$isReverse);

        $label = $this->buildMovementLabel($movementType, $isReverse, $originRef);

        if ($shouldReceive) {
            $result = $stockmove->reception(
                $user,
                $item['objectid'],
                $item['fk_warehouse'],
                $qty,
                $costPrice,
                $label,
                '',
                '',
                '',
                $movementDate,
                $originId,
                $originType
            );
        } else {
            $result = $stockmove->livraison(
                $user,
                $item['objectid'],
                $item['fk_warehouse'],
                $qty,
                $costPrice,
                $label,
                $movementDate,
                '',
                '',
                '',
                $originId,
                $originType
            );
        }

        $stockmove->setOrigin($originType, $originId);
        
        if ($result <= 0) {
            $this->addError("Stock movement failed for product #{$item['objectid']}: " . $stockmove->error);
            return false;
        }

        return true;
    }

    /**
     * Build appropriate label for stock movement.
     */
    private function buildMovementLabel(string $movementType, bool $isReverse, string $originRef): string
    {
        $action = match($movementType) {
            self::MOVEMENT_TYPE_CONSUME => $isReverse ? 'Reverse consume' : 'Consume',
            self::MOVEMENT_TYPE_PRODUCE => $isReverse ? 'Reverse produce' : 'Produce',
            default => 'Movement'
        };

        return "$action for MO ($originRef)";
    }

    /**
     * Add error to internal error array.
     */
    private function addError(string $error): void
    {
        $this->errors[] = $error;
        dol_syslog($error, LOG_ERR);
    }

    /**
     * Get the last error message.
     */
    public function getLastError(): string
    {
        return end($this->errors) ?: '';
    }

    /**
     * Get all error messages.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Clear all errors.
     */
    public function clearErrors(): void
    {
        $this->errors = [];
    }
}
