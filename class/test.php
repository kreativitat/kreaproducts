<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}



// Include the allergens updater class
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/KreaProductsAllergenUpdater.class.php';


// Retrieve product ID from form submission (if any)
$productId = GETPOST('id', 'int');
$message = '';

// Process the form if submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($productId)) {
    dol_syslog("Test Page: Starting allergens update for product ID " . $productId, LOG_DEBUG);
    KreaProductsAllergenUpdater::updateAllergenAttributes($productId, $user);
    $message = $langs->trans("Allergen update executed for product ID: ") . ' ' . $productId;
}

// Display header using Dolibarr's llxHeader function
llxHeader('', $langs->trans("TestAllergenUpdater"), '');

// Display page title and any message
echo '<h1>' . $langs->trans("TestAllergenUpdater") . '</h1>';
if (!empty($message)) {
    echo '<p style="color:green;">' . $message . '</p>';
}

// Display the form to enter a product ID
echo '<form method="post" action="' . $_SERVER["PHP_SELF"] . '">';
echo '<label for="id">' . $langs->trans("EnterProductID") . ':</label> ';
echo '<input type="text" id="id" name="id" value="' . htmlspecialchars($productId, ENT_QUOTES) . '"/>';
echo '<br/><br/>';
echo '<input type="submit" value="' . $langs->trans("Submit") . '"/>';
echo '</form>';

// Display footer using Dolibarr's llxFooter function
llxFooter();
$db->close();