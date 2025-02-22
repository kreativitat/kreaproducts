<?php
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


// Require the ProductUpdater.class.php located in the same folder
//require_once __DIR__ . '/ProductUpdater.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaproducts/class/ProductViewer.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';


//$permissiontoadd = (($object->type == Product::TYPE_PRODUCT && $user->hasRight('produit', 'creer')) || ($object->type == Product::TYPE_SERVICE && $user->hasRight('service', 'creer')));

// Load language translations
$langs->loadLangs(array('other', 'products'));

// Retrieve GET parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action');
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');

// Initialize objects
$object = new Product($db);
if ($id > 0 || !empty($ref)) {
    $result = $object->fetch($id, $ref);

    if (isModEnabled("product")) {
        $upload_dir = $conf->product->multidir_output[$object->entity] . '/' . get_exdir(0, 0, 0, 1, $object, 'product');
    } elseif (isModEnabled("service")) {
        $upload_dir = $conf->service->multidir_output[$object->entity] . '/' . get_exdir(0, 0, 0, 1, $object, 'product');
    }

    if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {    // For backward compatibility, we scan also old dirs
        if (isModEnabled("product")) {
            $upload_dirold = $conf->product->multidir_output[$object->entity] . '/' . substr(substr("000" . $object->id, -2), 1, 1) . '/' . substr(substr("000" . $object->id, -2), 0, 1) . '/' . $object->id . "/photos";
        } else {
            $upload_dirold = $conf->service->multidir_output[$object->entity] . '/' . substr(substr("000" . $object->id, -2), 1, 1) . '/' . substr(substr("000" . $object->id, -2), 0, 1) . '/' . $object->id . "/photos";
        }
    }
}

$title = $langs->trans('ProductTreeCard');
$helpurl = '';
$shortlabel = dol_trunc($object->label, 16);
if (GETPOST("type") == '0' || ($object->type == Product::TYPE_PRODUCT)) {
    $title = $langs->trans('Product') . " " . $shortlabel . " - " . $langs->trans('ProductTree');
    $helpurl = 'EN:Module_Products|FR:Module_Produits|ES:M&oacute;dulo_Productos';
}
if (GETPOST("type") == '1' || ($object->type == Product::TYPE_SERVICE)) {
    $title = $langs->trans('Service') . " " . $shortlabel . " - " . $langs->trans('ProductTree');
    $helpurl = 'EN:Module_Services_En|FR:Module_Services|ES:M&oacute;dulo_Servicios';
}

llxHeader('', $title, $helpurl, '', 0, 0, '', '', '', 'mod-kreaproducts page-card_krea_producttree');


if ($object->id) {
    $head = product_prepare_head($object);
    $titre = $langs->trans("CardProduct" . $object->type);
    $picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');

    print dol_get_fiche_head($head, 'krea_producttree', $titre, -1, $picto);

    $parameters = array();
    $reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
    print $hookmanager->resPrint;
    if ($reshook < 0) {
        setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
    }

    // Build file list
    $filearray = dol_dir_list($upload_dir, "files", 0, '', '(\.meta|_preview.*\.png)$', $sortfield, (strtolower($sortorder) == 'desc' ? SORT_DESC : SORT_ASC), 1);

    if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {    // For backward compatibility, we scan also old dirs
        $filearrayold = dol_dir_list($upload_dirold, "files", 0, '', '(\.meta|_preview.*\.png)$', $sortfield, (strtolower($sortorder) == 'desc' ? SORT_DESC : SORT_ASC), 1);
        $filearray = array_merge($filearray, $filearrayold);
    }

    $totalsize = 0;
    foreach ($filearray as $key => $file) {
        $totalsize += $file['size'];
    }

    $linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1&type=' . $object->type . '">' . $langs->trans("BackToList") . '</a>';
    $object->next_prev_filter = "fk_product_type = " . ((int) $object->type);

    $shownav = 1;
    if ($user->socid && !in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
        $shownav = 0;
    }

    dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref');

    // Generate final HTML (4 sections)
    $html = ProductHierarchy::getCompletePage($id);
    echo $html;
} else {
    print $langs->trans("ErrorUnknown");
}

// End of page
llxFooter();
$db->close();
