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

require_once __DIR__.'/KreaProductsMobileInventoryService.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * Scheduled managed-inventory closure.
 */
class KreaProductsInventoryCron
{
	/** @var int */
	public $entity = 0;

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string */
	public $output = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Close inventories due at 15 minutes before the configured entry cutoff.
	 *
	 * @return int 0 on success, -1 on failure
	 */
	public function closeDueInventories()
	{
		global $langs, $conf;
		$langs->load('kreaproducts@kreaproducts');

		try {
			$schedulerUser = $this->resolveSchedulerUser();
			$service = new KreaProductsMobileInventoryService($this->db, $schedulerUser, $langs, $conf);
			$result = $service->closeDueInventoriesAsScheduler(dol_now());
			$this->output = $langs->trans('KREAPRODUCTS_CRON_OUTPUT', (int) $result['due'], (int) $result['closed']);
			if (!empty($result['errors'])) {
				$this->errors = array_values($result['errors']);
				$this->error = implode(' ', $this->errors);
				return -1;
			}
			return 0;
		} catch (Throwable $exception) {
			$this->error = $exception->getMessage();
			$this->errors = array($this->error);
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 * Resolve an active internal administrator for audited scheduled writes.
	 *
	 * Dolibarr's method cron runner keeps its execution user in method-local
	 * scope, so it is not available as the global web user inside this class.
	 *
	 * @return User
	 */
	private function resolveSchedulerUser()
	{
		global $conf, $langs;

		$sql = 'SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user as u';
		$sql .= ' WHERE u.admin = 1 AND u.statut = 1';
		$sql .= ' AND (u.fk_soc IS NULL OR u.fk_soc = 0)';
		$sql .= ' AND u.entity IN (0, '.((int) $conf->entity).')';
		$sql .= ' ORDER BY (u.entity = '.((int) $conf->entity).') DESC, (u.entity = 0) DESC, u.rowid ASC';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($langs->trans('KREAPRODUCTS_CRON_ERROR_RESOLVE_ADMIN', $this->db->lasterror()));
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			throw new RuntimeException($langs->trans('KREAPRODUCTS_CRON_ERROR_NO_ADMIN'));
		}

		$schedulerUser = new User($this->db);
		if ($schedulerUser->fetch((int) $obj->rowid) <= 0) {
			throw new RuntimeException($langs->trans('KREAPRODUCTS_CRON_ERROR_LOAD_ADMIN'));
		}
		$schedulerUser->loadRights();
		return $schedulerUser;
	}
}
