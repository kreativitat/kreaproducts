<?php

// Include necessary Dolibarr environment setup here.
// For example, including the main.inc.php file if you're writing a script.

require '../../main.inc.php';

// Load Dolibarr classes.
dol_include_once('/product/class/product.class.php');
dol_include_once('/user/class/user.class.php');

// Assume you have the product ID and the current user.
$productId = 8455; // The ID of the product to update.
$user = $user; // The current user (instance of User).

// Include the KreaCostUpdater class.
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaCostUpdater.class.php';

// Create an instance of the updater and execute it.
$kreaCostUpdater = new KreaCostUpdater($productId);
$kreaCostUpdater->execute();
