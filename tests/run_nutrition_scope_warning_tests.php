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
 */



// Exercise the production warning formatter with isolated database and translation services.
define('MAIN_DB_PREFIX', 'test_');
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$source = file_get_contents(__DIR__.'/../class/KreaProductsAllergenUpdater.class.php');
$source = str_replace("require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';", '', $source);
eval(substr($source, 5));
class ScopeWarningDb {
    public $queries = 0;
    public $fail = false;
    public function query($sql) {
        $this->queries++;
        if (strpos($sql, 'WHERE p.rowid = 4472') === false) { throw new Exception('Unexpected diagnostic product'); }
        return !$this->fail;
    }
    public function fetch_object($result) { return (object) array('ref' => '3639', 'label' => '<spicy mayonnaise>', 'entity_label' => 'DG99'); }
    public function free($result) {}
}
class ScopeWarningLangs {
    public function trans($key, ...$args) {
        return $key === 'KREAPRODUCTS_COPY_SCOPE_COMPONENT'
            ? vsprintf('The blocked component is %s (%s), owned by %s.', $args)
            : 'Switch company and retry. Nothing was copied.';
    }
}
function checkScopeWarning($ok, $message) {
    if (!$ok) { throw new Exception($message); }
    echo 'PASS '.$message.PHP_EOL;
}
$db = new ScopeWarningDb();
$langs = new ScopeWarningLangs();
$admin = (object) array('admin' => 1);
$member = (object) array('admin' => 0);
$property = new ReflectionProperty('KreaProductsAllergenUpdater', 'blockedProductIds');
$property->setAccessible(true);
checkScopeWarning(KreaProductsAllergenUpdater::getScopeWarning($langs, $admin) === '', 'Other errors retain their normal message');
$property->setValue(null, array(4472, 4480));
$message = KreaProductsAllergenUpdater::getScopeWarning($langs, $admin);
checkScopeWarning(strpos($message, '3639 (&lt;spicy mayonnaise&gt;), owned by DG99') !== false, 'Administrator warning names and escapes blocked component and company');
checkScopeWarning(strpos($message, 'Switch company') !== false, 'Warning explains recovery');
$queries = $db->queries;
$message = KreaProductsAllergenUpdater::getScopeWarning($langs, $member);
checkScopeWarning($db->queries === $queries && strpos($message, '3639') === false, 'Non-administrator receives no inaccessible metadata');
$db->fail = true;
checkScopeWarning(KreaProductsAllergenUpdater::getScopeWarning($langs, $admin) === 'Switch company and retry. Nothing was copied.', 'Failed metadata lookup retains recovery message');
$clear = new ReflectionMethod('KreaProductsAllergenUpdater', 'clearErrors');
$clear->setAccessible(true);
$clear->invoke(null);
checkScopeWarning(KreaProductsAllergenUpdater::getScopeWarning($langs, $admin) === '', 'Processing reset clears stale component warnings');
