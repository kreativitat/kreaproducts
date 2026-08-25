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

/**
 * \file       scripts/repair_inventory_start_snapshot.php
 * \ingroup    kreaproducts
 * \brief      Replace one day's inventory generations with start-of-day anchors.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This repair is available only from the command line.\n");
}

define('NOLOGIN', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');
define('NOTOKENRENEWAL', '1');
define('USESUFFIXINLOG', '_kreaproducts_inventory_repair');

$res = @include __DIR__.'/../../main.inc.php';
if (!$res) {
	$res = @include __DIR__.'/../../../main.inc.php';
}
if (!$res) {
	fwrite(STDERR, "Unable to load Dolibarr.\n");
	exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../class/KreaProductsBusinessDayService.class.php';
require_once __DIR__.'/../class/KreaProductsMobileInventoryService.class.php';

global $conf, $db, $langs, $user;

$options = getopt('', array('date:', 'warehouses:', 'apply'));
$calendarDate = trim((string) ($options['date'] ?? ''));
$warehouseRefs = array_values(array_filter(array_map('trim', explode(',', (string) ($options['warehouses'] ?? '')))));
$apply = array_key_exists('apply', $options);
if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $calendarDate) || empty($warehouseRefs)) {
	fwrite(STDERR, "Usage: php repair_inventory_start_snapshot.php --date=YYYY-MM-DD --warehouses=DG01,DG02,DG07 [--apply]\n");
	exit(1);
}
foreach ($warehouseRefs as $warehouseRef) {
	if (!preg_match('/^[A-Z0-9_-]+$/', $warehouseRef)) {
		fwrite(STDERR, "Invalid warehouse reference.\n");
		exit(1);
	}
}

$quotedWarehouseRefs = array();
foreach ($warehouseRefs as $warehouseRef) {
	$quotedWarehouseRefs[] = "'".$db->escape($warehouseRef)."'";
}
$sql = 'SELECT e.rowid, e.ref, e.entity FROM '.MAIN_DB_PREFIX.'entrepot e';
$sql .= ' WHERE e.ref IN ('.implode(', ', $quotedWarehouseRefs).')';
$sql .= ' ORDER BY e.entity ASC, e.rowid ASC';
$resql = $db->query($sql);
if (!$resql) {
	fwrite(STDERR, $db->lasterror()."\n");
	exit(1);
}
$warehouses = array();
while ($obj = $db->fetch_object($resql)) {
	$warehouses[(string) $obj->ref] = $obj;
}
$db->free($resql);
if (count($warehouses) !== count(array_unique($warehouseRefs))) {
	fwrite(STDERR, "The requested warehouse scope is incomplete or ambiguous.\n");
	exit(1);
}

$sql = 'SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user u';
$sql .= ' WHERE u.admin=1 AND u.statut=1 AND (u.fk_soc IS NULL OR u.fk_soc=0)';
$sql .= ' AND u.entity=0 ORDER BY u.rowid ASC'.$db->plimit(1, 0);
$resql = $db->query($sql);
$adminRow = $resql ? $db->fetch_object($resql) : false;
if ($resql) {
	$db->free($resql);
}
if (!$adminRow) {
	fwrite(STDERR, "Unable to resolve an active shared administrator.\n");
	exit(1);
}
$user = new User($db);
if ($user->fetch((int) $adminRow->rowid) <= 0) {
	fwrite(STDERR, "Unable to load the repair administrator.\n");
	exit(1);
}

$lockDirectory = DOL_DATA_ROOT.'/temp';
if (!is_dir($lockDirectory) && dol_mkdir($lockDirectory) < 0) {
	fwrite(STDERR, "Unable to create the repair lock directory.\n");
	exit(1);
}
$lockHandle = @fopen($lockDirectory.'/kreaproducts-inventory-start-snapshot-repair.lock', 'c');
if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another inventory start-snapshot repair is running.\n");
	exit(1);
}

$initialEntity = (int) $conf->entity;
$businessDayService = new KreaProductsBusinessDayService();
$totalInventories = 0;
$totalRecorded = 0;
$totalOpen = 0;
$totalRebasesBefore = 0;
$totalInvoiceMovementsRetimed = 0;
$totalMoMovementsRetimed = 0;

try {
	foreach ($warehouseRefs as $warehouseRef) {
		$warehouse = $warehouses[$warehouseRef];
		$entity = (int) $warehouse->entity;
		$warehouseId = (int) $warehouse->rowid;
		$conf->setEntityValues($db, $entity);
		$user->loadRights();
		$timezoneName = getDolGlobalString('KREAPRODUCTS_BUSINESS_TIMEZONE', 'Europe/Lisbon');
		$timezone = new DateTimeZone($timezoneName);
		$anchorTime = $businessDayService->normalizeConfiguredTime(
			getDolGlobalString('KREAPRODUCTS_BUSINESS_DAY_CLOSE_TIME', '06:00'),
			'inventory start-of-day time'
		);
		$supplierTime = $businessDayService->normalizeConfiguredTime(
			getDolGlobalString('KREAPRODUCTS_SUPPLIER_MOVE_TIME', '10:30'),
			'supplier receipt time'
		);
		$anchorTimestamp = $businessDayService->resolveDateTimestamp($calendarDate, $timezone, $anchorTime);
		$anchorSql = $db->idate($anchorTimestamp);
		$supplierTimestamp = $businessDayService->resolveDateTimestamp($calendarDate, $timezone, $supplierTime);
		$supplierSql = $db->idate($supplierTimestamp);

		$sql = 'SELECT i.rowid, i.ref, i.status, i.date_inventory,';
		$sql .= ' (SELECT COUNT(*) FROM '.MAIN_DB_PREFIX.'stock_mouvement rb';
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement rr ON rr.origintype='kreaproducts_inventory_rebase_reversal'";
		$sql .= " AND rr.fk_origin=rb.fk_origin AND rr.inventorycode=CONCAT('KPS-REBASE-REV-', rb.rowid)";
		$sql .= " WHERE rb.origintype='kreaproducts_inventory_rebase' AND rb.fk_origin=i.rowid AND rr.rowid IS NULL) active_rebases";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'inventory i';
		$sql .= ' WHERE i.entity='.$entity.' AND i.fk_warehouse='.$warehouseId;
		$sql .= " AND DATE(i.date_inventory)='".$db->escape($calendarDate)."'";
		$sql .= " AND (i.import_key='KPS' OR i.ref LIKE 'KPS-%' OR i.ref LIKE 'KS-%')";
		$sql .= ' AND i.status IN (1,2) ORDER BY i.rowid ASC';
		$resql = $db->query($sql);
		if (!$resql) {
			throw new RuntimeException($db->lasterror());
		}
		$inventories = array();
		while ($obj = $db->fetch_object($resql)) {
			$inventories[] = $obj;
			$totalRebasesBefore += (int) $obj->active_rebases;
		}
		$db->free($resql);
		$totalInventories += count($inventories);

		print $warehouseRef.' entity='.$entity.' anchor='.$anchorSql.' supplier='.$supplierSql.' inventories='.count($inventories)."\n";
		foreach ($inventories as $inventory) {
			print '  inventory='.(int) $inventory->rowid.' ref='.(string) $inventory->ref.' status='.(int) $inventory->status;
			print ' current_anchor='.(string) $inventory->date_inventory.' active_rebases='.(int) $inventory->active_rebases."\n";
		}
		if (!$apply) {
			continue;
		}

		if ($db->begin() <= 0) {
			throw new RuntimeException('Unable to start the operational-time repair transaction.');
		}
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'stock_mouvement sm';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture_fourn f ON f.rowid=sm.fk_origin';
		$sql .= " SET sm.datem='".$db->escape($supplierSql)."'";
		$sql .= " WHERE sm.origintype='invoice_supplier' AND sm.fk_entrepot=".$warehouseId;
		$sql .= ' AND f.entity='.$entity." AND f.datef='".$db->escape($calendarDate)."'";
		$resUpdate = $db->query($sql);
		if (!$resUpdate) {
			$db->rollback();
			throw new RuntimeException($db->lasterror());
		}
		$totalInvoiceMovementsRetimed += (int) $db->affected_rows($resUpdate);

		$sql = 'SELECT DISTINCT mo.rowid FROM '.MAIN_DB_PREFIX.'mrp_mo mo';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'stock_mouvement sm ON sm.origintype=\'mo\' AND sm.fk_origin=mo.rowid';
		$sql .= ' WHERE mo.entity='.$entity.' AND sm.fk_entrepot='.$warehouseId;
		$sql .= " AND DATE(mo.date_creation)='".$db->escape($calendarDate)."'";
		$sql .= " AND mo.label LIKE 'Auto dismantle - %' FOR UPDATE";
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			throw new RuntimeException($db->lasterror());
		}
		$moIds = array();
		while ($obj = $db->fetch_object($resql)) {
			$moIds[] = (int) $obj->rowid;
		}
		$db->free($resql);
		if (!empty($moIds)) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX."mrp_mo SET date_start_planned='".$db->escape($supplierSql)."',";
			$sql .= " date_end_planned='".$db->escape($supplierSql)."' WHERE entity=".$entity;
			$sql .= ' AND rowid IN ('.implode(',', $moIds).')';
			$resUpdate = $db->query($sql);
			if (!$resUpdate) {
				$db->rollback();
				throw new RuntimeException($db->lasterror());
			}
			$sql = 'UPDATE '.MAIN_DB_PREFIX."stock_mouvement SET datem='".$db->escape($supplierSql)."'";
			$sql .= " WHERE origintype='mo' AND fk_entrepot=".$warehouseId;
			$sql .= ' AND fk_origin IN ('.implode(',', $moIds).')';
			$resUpdate = $db->query($sql);
			if (!$resUpdate) {
				$db->rollback();
				throw new RuntimeException($db->lasterror());
			}
			$totalMoMovementsRetimed += (int) $db->affected_rows($resUpdate);
		}
		if ($db->commit() <= 0) {
			$db->rollback();
			throw new RuntimeException('Unable to commit the operational-time repair transaction.');
		}

		$service = new KreaProductsMobileInventoryService($db, $user, $langs, $conf);
		foreach ($inventories as $inventory) {
			$inventoryId = (int) $inventory->rowid;
			if ((int) $inventory->status === 2) {
				$service->editInventory($inventoryId);
				$sql = 'UPDATE '.MAIN_DB_PREFIX."inventory SET date_inventory='".$db->escape($anchorSql)."'";
				$sql .= ' WHERE rowid='.$inventoryId.' AND entity='.$entity.' AND status=1';
				$resUpdate = $db->query($sql);
				if (!$resUpdate || (int) $db->affected_rows($resUpdate) !== 1) {
					throw new RuntimeException('Unable to set the replacement anchor for inventory '.$inventoryId.'.');
				}
				$service->closeInventory($inventoryId, true);
				$totalRecorded++;
			} else {
				$sql = 'UPDATE '.MAIN_DB_PREFIX."inventory SET date_inventory='".$db->escape($anchorSql)."'";
				$sql .= ' WHERE rowid='.$inventoryId.' AND entity='.$entity.' AND status=1';
				$resUpdate = $db->query($sql);
				if (!$resUpdate || (int) $db->affected_rows($resUpdate) !== 1) {
					throw new RuntimeException('Unable to set the open inventory anchor for inventory '.$inventoryId.'.');
				}
				$totalOpen++;
			}
		}
	}
} catch (Throwable $exception) {
	$conf->setEntityValues($db, $initialEntity);
	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);
	fwrite(STDERR, 'Repair failed: '.$exception->getMessage()."\n");
	exit(1);
}

$conf->setEntityValues($db, $initialEntity);
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
print 'mode='.($apply ? 'apply' : 'dry-run').' inventories='.$totalInventories.' recorded_replaced='.$totalRecorded;
print ' open_retimed='.$totalOpen.' active_rebases_before='.$totalRebasesBefore;
print ' invoice_movements_retimed='.$totalInvoiceMovementsRetimed.' mo_movements_retimed='.$totalMoMovementsRetimed."\n";
$db->close();
