<?php
/* Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/allergens.class.php
 * \ingroup     kreaproducts
 * \brief       Dictionary class for allergens (llx_c_kreaproducts)
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commondict.class.php';

/**
 * Class Allergens
 */
class Allergens extends CommonDict
{
	/**
	 * @var string Id to identify managed objects
	 */
	public $element = 'allergens';

	/**
	 * @var string Name of table without prefix where object is stored
	 */
	public $table_element = 'c_kreaproducts';

	/**
	 * @var string Icon filename
	 */
	public $icon;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id   Id object
	 * @param string $code Code
	 * @return int         Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $code = '')
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$sql = "SELECT rowid, code, label, icon, active";
		$sql .= " FROM " . $this->db->prefix() . $this->table_element;
		if ($id) {
			$sql .= " WHERE rowid = " . ((int) $id);
		} elseif ($code !== '') {
			$sql .= " WHERE code = '" . $this->db->escape($code) . "'";
		} else {
			return 0;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$numrows = $this->db->num_rows($resql);
			if ($numrows) {
				$obj = $this->db->fetch_object($resql);
				$this->id = (int) $obj->rowid;
				$this->code = $obj->code;
				$this->label = $obj->label;
				$this->icon = $obj->icon;
				$this->active = (int) $obj->active;
			}
			$this->db->free($resql);
			return $numrows ? 1 : 0;
		}

		$this->errors[] = 'Error ' . $this->db->lasterror();
		dol_syslog(__METHOD__ . ' ' . implode(',', $this->errors), LOG_ERR);
		return -1;
	}

	/**
	 * Create object into database
	 *
	 * @param User $user      User that creates
	 * @param int  $notrigger 0=launch triggers after, 1=disable triggers
	 * @return int            Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$error = 0;
		$this->code = isset($this->code) ? trim($this->code) : null;
		$this->label = isset($this->label) ? trim($this->label) : null;
		$this->icon = isset($this->icon) ? trim($this->icon) : null;
		$this->active = isset($this->active) ? (int) $this->active : null;

		$sql = "INSERT INTO " . $this->db->prefix() . $this->table_element . " (code, label, icon, active)";
		$sql .= " VALUES (";
		$sql .= (!isset($this->code) ? "NULL" : "'" . $this->db->escape($this->code) . "'") . ",";
		$sql .= (!isset($this->label) ? "NULL" : "'" . $this->db->escape($this->label) . "'") . ",";
		$sql .= (!isset($this->icon) ? "NULL" : "'" . $this->db->escape($this->icon) . "'") . ",";
		$sql .= (!isset($this->active) ? "NULL" : (int) $this->active);
		$sql .= ")";

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . ' ' . implode(',', $this->errors), LOG_ERR);
		}

		if (!$error) {
			$this->id = $this->db->last_insert_id($this->db->prefix() . $this->table_element);
		}

		if ($error) {
			$this->db->rollback();
			return -1 * $error;
		}
		$this->db->commit();
		return $this->id;
	}

	/**
	 * Update object into database
	 *
	 * @param User $user      User that modifies
	 * @param int  $notrigger 0=launch triggers after, 1=disable triggers
	 * @return int            Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		dol_syslog(__METHOD__, LOG_DEBUG);

		$this->code = isset($this->code) ? trim($this->code) : null;
		$this->label = isset($this->label) ? trim($this->label) : null;
		$this->icon = isset($this->icon) ? trim($this->icon) : null;
		$this->active = isset($this->active) ? (int) $this->active : null;

		$sql = "UPDATE " . $this->db->prefix() . $this->table_element . " SET";
		$sql .= " code = " . (isset($this->code) ? "'" . $this->db->escape($this->code) . "'" : "null") . ",";
		$sql .= " label = " . (isset($this->label) ? "'" . $this->db->escape($this->label) . "'" : "null") . ",";
		$sql .= " icon = " . (isset($this->icon) ? "'" . $this->db->escape($this->icon) . "'" : "null") . ",";
		$sql .= " active = " . (isset($this->active) ? (int) $this->active : "null");
		$sql .= " WHERE rowid = " . ((int) $this->id);

		$this->db->begin();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}
}
