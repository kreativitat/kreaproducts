<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Commercial support and integration services are available from
 * Kreativität Works <mail@kreativitat.com>.
 */
// Load Dolibarr environment (2 tries: module in htdocs/ OR in htdocs/custom/)
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php'))    $res = @include __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = @include __DIR__ . '/../../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../master.inc.php'))  $res = @include __DIR__ . '/../master.inc.php';
if (!$res && file_exists(__DIR__ . '/../../master.inc.php')) $res = @include __DIR__ . '/../../master.inc.php';
if (!$res) die('Failed to include main.inc.php');

require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
dol_include_once('/kreaproducts/class/ProductViewer.class.php');

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
    $html = ProductHierarchyTree::getCompletePage($id);
    echo $html;
} else {
    print $langs->trans("ErrorUnknown");
}

// End of page
llxFooter();
$db->close();
