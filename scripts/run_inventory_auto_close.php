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
 * \file       scripts/run_inventory_auto_close.php
 * \ingroup    kreaproducts
 * \brief      Run only KreaProducts inventory-closure scheduled jobs.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("Execução disponível apenas por linha de comandos.\n");
}

define('NOLOGIN', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');
define('NOTOKENRENEWAL', '1');
define('USESUFFIXINLOG', '_kreaproducts_inventory_cron');

$res = @include __DIR__.'/../../../main.inc.php';
if (!$res) {
	fwrite(STDERR, "Não foi possível carregar o Dolibarr.\n");
	exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';

global $conf, $db;

if (empty($conf->cron->enabled)) {
	fwrite(STDERR, "O módulo Trabalhos agendados do Dolibarr não está ativo.\n");
	exit(1);
}

$lockDirectory = DOL_DATA_ROOT.'/temp';
if (!is_dir($lockDirectory) && dol_mkdir($lockDirectory) < 0) {
	fwrite(STDERR, "Não foi possível criar a pasta de bloqueio do executor.\n");
	exit(1);
}
$lockHandle = @fopen($lockDirectory.'/kreaproducts-inventory-auto-close.lock', 'c');
if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
	exit(0);
}

$sql = 'SELECT u.login FROM '.MAIN_DB_PREFIX.'user as u';
$sql .= ' WHERE u.entity = 0 AND u.admin = 1 AND u.statut = 1';
$sql .= ' AND (u.fk_soc IS NULL OR u.fk_soc = 0)';
$sql .= ' ORDER BY u.rowid ASC'.$db->plimit(1, 0);
$resql = $db->query($sql);
$serviceUser = $resql ? $db->fetch_object($resql) : false;
if ($resql) {
	$db->free($resql);
}
if (!$serviceUser || empty($serviceUser->login)) {
	fwrite(STDERR, "Não existe um administrador Dolibarr partilhado e ativo para o executor.\n");
	exit(1);
}

$sql = 'SELECT c.rowid FROM '.MAIN_DB_PREFIX.'cronjob as c';
$sql .= " WHERE c.objectname = 'KreaProductsInventoryCron'";
$sql .= " AND c.methodename = 'closeDueInventories'";
$sql .= ' AND c.status = 1';
$sql .= ' ORDER BY c.entity ASC, c.rowid ASC';
$resql = $db->query($sql);
if (!$resql) {
	fwrite(STDERR, $db->lasterror()."\n");
	exit(1);
}
$jobIds = array();
while ($obj = $db->fetch_object($resql)) {
	$jobIds[] = (int) $obj->rowid;
}
$db->free($resql);

$now = dol_now();
$executed = 0;
$failed = 0;
$initialEntity = (int) $conf->entity;
foreach ($jobIds as $jobId) {
	$cronJob = new Cronjob($db);
	if ($cronJob->fetch($jobId) <= 0) {
		$failed++;
		continue;
	}
	$conf->setEntityValues($db, !empty($cronJob->entity) ? (int) $cronJob->entity : 1);
	if (!verifCond((string) $cronJob->test)) {
		continue;
	}
	if ((!empty($cronJob->datenextrun) && (int) $cronJob->datenextrun > $now)
		|| (!empty($cronJob->datestart) && (int) $cronJob->datestart > $now)
		|| (!empty($cronJob->dateend) && (int) $cronJob->dateend < $now)
	) {
		continue;
	}

	$result = $cronJob->run_jobs((string) $serviceUser->login);
	$executed++;
	if ($result < 0) {
		$failed++;
	}
	if ($cronJob->reprogram_jobs((string) $serviceUser->login, $now) < 0) {
		$failed++;
	}
}
$conf->setEntityValues($db, $initialEntity);

print 'Tarefas de inventário KreaProducts: '.count($jobIds).'; executadas: '.$executed.'; falhas: '.$failed.".\n";
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
$db->close();
exit($failed > 0 ? 1 : 0);
