<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once __DIR__.'/KreaProductsInventoryLedgerCalculator.class.php';

/**
 * Create physical-inventory movements without changing kit children.
 */
class KreaProductsInventoryMovementService
{
	/** @var DoliDB */
	private $db;

	/** @var Conf */
	private $conf;

	/** @var Translate|null */
	private $langs;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB         $db    Database handler
	 * @param Conf           $conf  Dolibarr configuration
	 * @param Translate|null $langs Translation handler
	 */
	public function __construct($db, $conf, $langs = null)
	{
		$this->db = $db;
		$this->conf = $conf;
		$this->langs = $langs;
	}

	/**
	 * Create one correction on the counted product itself.
	 *
	 * @param MouvementStock $movement      Movement object with origin already set
	 * @param User            $user          Author
	 * @param int             $productId     Product ID
	 * @param int             $warehouseId   Warehouse ID
	 * @param float           $quantity      Signed adjustment
	 * @param float           $price         Movement price
	 * @param string          $label         Label
	 * @param string          $inventoryCode Inventory code
	 * @param int|string      $movementDate  Value date
	 * @param string          $batch         Batch
	 * @param bool            $allowLegacyKitParent Allow correction or reversal of an audited historical parent-only movement
	 * @return int
	 */
	public function create($movement, $user, $productId, $warehouseId, $quantity, $price, $label, $inventoryCode, $movementDate, $batch = '', $allowLegacyKitParent = false)
	{
		$this->error = '';
		$product = new Product($this->db);
		$result = $product->fetch((int) $productId);
		if ($result <= 0 || empty($product->id)) {
			$this->error = $this->trans('KREAPRODUCTS_ERROR_LOAD_COUNTED_PRODUCT', 'Unable to load counted product #%s.', (int) $productId);
			return -1;
		}
		if (!$product->isStockManaged()) {
			$this->error = $this->trans('KREAPRODUCTS_ERROR_PRODUCT_NOT_STOCK_MANAGED', 'Product %s is not stock managed.', (string) $product->ref);
			return -1;
		}

		$isIndependentStockItem = KreaProductsInventoryLedgerCalculator::isIndependentStockItem(
			(bool) getDolGlobalInt('PRODUIT_SOUSPRODUITS'),
			(bool) getDolGlobalInt('PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE'),
			(int) $product->hasFatherOrChild(1)
		);
		if (!$isIndependentStockItem && !$allowLegacyKitParent) {
			$this->error = $this->trans('KREAPRODUCTS_ERROR_KIT_PARENT_NOT_MANAGED', 'Product %s is a kit parent whose stock is not maintained by normal Dolibarr movements.', (string) $product->ref);
			return -1;
		}
		$restoreParentSetting = false;
		$parentSettingExisted = isset($this->conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE);
		$parentSettingValue = $parentSettingExisted ? $this->conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE : null;
		if (!$isIndependentStockItem && $allowLegacyKitParent) {
			$this->conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE = 1;
			$restoreParentSetting = true;
		}

		try {
			$result = $movement->_create(
				$user,
				(int) $productId,
				(int) $warehouseId,
				(float) $quantity,
				((float) $quantity < 0 ? 1 : 0),
				(float) $price,
				(string) $label,
				(string) $inventoryCode,
				$movementDate,
				'',
				'',
				(string) $batch,
				false,
				0,
				1
			);
		} finally {
			if ($restoreParentSetting) {
				if ($parentSettingExisted) {
					$this->conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE = $parentSettingValue;
				} else {
					unset($this->conf->global->PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE);
				}
			}
		}

		if ($result <= 0) {
			$details = !empty($movement->error) ? $movement->error : implode(' ', (array) $movement->errors);
			$this->error = $this->trans('KREAPRODUCTS_ERROR_CREATE_PRODUCT_STOCK_MOVEMENT', 'Unable to create stock movement for %s.', (string) $product->ref);
			if ($details !== '') {
				$this->error .= ' '.$details;
			}
		}

		return (int) $result;
	}

	/**
	 * Translate an error when a translation handler is available.
	 *
	 * @param string $key      Translation key
	 * @param string $fallback English fallback format
	 * @param mixed  $value    Placeholder value
	 * @return string
	 */
	private function trans($key, $fallback, $value)
	{
		if (is_object($this->langs)) {
			return $this->langs->trans($key, $value);
		}
		return sprintf($fallback, $value);
	}
}
