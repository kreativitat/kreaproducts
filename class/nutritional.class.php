<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2024  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2025		Kreativitat	<mail@kreativitat.com>
 * Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com>
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
 * \file        class/nutritional.class.php
 * \ingroup     kreaproducts
 * \brief       This file is a CRUD class file for Nutritional (Create/Read/Update/Delete)
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobjectline.class.php';

/**
 * Class for Nutritional information management
 * 
 * Handles nutritional data for products including energy, macronutrients,
 * and dietary information with proper validation and security measures.
 */
class Nutritional extends CommonObject
{
    // Constants for status management
    const STATUS_DRAFT = 0;
    const STATUS_VALIDATED = 1;
    const STATUS_CANCELED = 9;
    
    // Constants for validation
    const MAX_NUTRITIONAL_VALUE = 999999.9999;
    const MIN_NUTRITIONAL_VALUE = 0.0;
    
    // Constants for field lengths
    const MAX_REF_LENGTH = 128;
    
    /**
     * @var string ID of module.
     */
    public $module = 'kreaproducts';

    /**
     * @var string ID to identify managed object.
     */
    public $element = 'nutritional';

    /**
     * @var string Name of table without prefix where object is stored.
     */
    public $table_element = 'kreaproducts_nutritional';

    /**
     * @var string String with name of icon for nutritional.
     */
    public $picto = 'fa-file';

    // Field definitions with enhanced structure
    public $fields = array(
        "rowid" => array(
            "type" => "integer", 
            "label" => "TechnicalID", 
            "enabled" => "1", 
            'position' => 1, 
            'notnull' => 1, 
            "visible" => "0", 
            "noteditable" => "1", 
            "index" => "1", 
            "css" => "left", 
            "comment" => "Id"
        ),
        "date_creation" => array(
            "type" => "datetime", 
            "label" => "DateCreation", 
            "enabled" => "1", 
            'position' => 500, 
            'notnull' => 1, 
            "visible" => "-2"
        ),
        "tms" => array(
            "type" => "timestamp", 
            "label" => "DateModification", 
            "enabled" => "1", 
            'position' => 501, 
            'notnull' => 0, 
            "visible" => "-2"
        ),
        "fk_user_creat" => array(
            "type" => "integer:User:user/class/user.class.php", 
            "label" => "UserAuthor", 
            "picto" => "user", 
            "enabled" => "1", 
            'position' => 510, 
            'notnull' => 1, 
            "visible" => "-2", 
            "csslist" => "tdoverflowmax150"
        ),
        "fk_user_modif" => array(
            "type" => "integer:User:user/class/user.class.php", 
            "label" => "UserModif", 
            "picto" => "user", 
            "enabled" => "1", 
            'position' => 511, 
            'notnull' => -1, 
            "visible" => "-2", 
            "csslist" => "tdoverflowmax150"
        ),
        "fk_product" => array(
            "type" => "integer", 
            "label" => "fk_product", 
            "enabled" => "1", 
            'position' => 60, 
            'notnull' => 1, 
            "visible" => "-2", 
            "index" => "1"
        ),
        "is_food" => array(
            "type" => "integer", 
            "label" => "FoodProduct", 
            "enabled" => "1", 
            'position' => 61, 
            'notnull' => 1, 
            'default' => 1, 
            "visible" => "1"
        ),
        "energy_kcal" => array(
            "type" => "double(28,4)", 
            "label" => "Energy (kcal)", 
            "enabled" => "1", 
            'position' => 62, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "energy_kj" => array(
            "type" => "double(28,4)", 
            "label" => "Energy (kj)", 
            "enabled" => "1", 
            'position' => 63, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "fat" => array(
            "type" => "double(28,4)", 
            "label" => "Fat", 
            "enabled" => "1", 
            'position' => 64, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "saturates" => array(
            "type" => "double(28,4)", 
            "label" => "Saturates", 
            "enabled" => "1", 
            'position' => 65, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "carbohydrates" => array(
            "type" => "double(28,4)", 
            "label" => "Carbohydrates", 
            "enabled" => "1", 
            'position' => 66, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "sugars" => array(
            "type" => "double(28,4)", 
            "label" => "Sugars", 
            "enabled" => "1", 
            'position' => 67, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "protein" => array(
            "type" => "double(28,4)", 
            "label" => "Protein", 
            "enabled" => "1", 
            'position' => 68, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "salt" => array(
            "type" => "double(28,4)", 
            "label" => "Salt", 
            "enabled" => "1", 
            'position' => 69, 
            'notnull' => 0, 
            "visible" => "1"
        ),
        "fiber" => array(
            "type" => "double(28,4)", 
            "label" => "Fiber", 
            "enabled" => "1", 
            'position' => 70, 
            'notnull' => 0, 
            "visible" => "1"
        )
    );

    // Public properties (inherited from CommonObject, no type declarations)
    public $rowid;
    public $date_creation;
    public $tms;
    public $fk_user_creat;
    public $fk_user_modif;
    public $fk_product;
    public $is_food;
    public $energy_kcal;
    public $energy_kj;
    public $fat;
    public $saturates;
    public $carbohydrates;
    public $sugars;
    public $protein;
    public $salt;
    public $fiber;

    // Internal error handling
    private $validationErrors = array();
    private $nutritionalFields = array(
        'energy_kcal', 'energy_kj', 'fat', 'saturates', 
        'carbohydrates', 'sugars', 'protein', 'salt', 'fiber'
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;
        $this->ismultientitymanaged = 0;
        $this->isextrafieldmanaged = 1;

        $this->initializeFields();
        $this->processFieldTranslations($langs);
    }

    /**
     * Initialize field visibility and settings
     */
    private function initializeFields()
    {
        if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && 
            isset($this->fields['rowid']) && 
            !empty($this->fields['ref'])) {
            $this->fields['rowid']['visible'] = 0;
        }
        
        if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
            $this->fields['entity']['enabled'] = 0;
        }

        // Remove disabled fields
        $enabledFields = array();
        foreach ($this->fields as $key => $field) {
            if (empty($field['enabled'])) {
                continue;
            }
            $enabledFields[$key] = $field;
        }
        $this->fields = $enabledFields;
    }

    /**
     * Process field translations
     */
    private function processFieldTranslations($langs)
    {
        if (!is_object($langs)) {
            return;
        }

        foreach ($this->fields as $key => $field) {
            if (!empty($field['arrayofkeyval']) && is_array($field['arrayofkeyval'])) {
                foreach ($field['arrayofkeyval'] as $key2 => $val2) {
                    $this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
                }
            }
        }
    }

    /**
     * Create object into database with enhanced validation
     *
     * @param User $user User that creates
     * @param int $notrigger 0=launch triggers after, 1=disable triggers
     * @return int Return integer <0 if KO, Id of created object if OK
     */
    public function create($user, $notrigger = 0)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        if (!$this->validateBeforeCreate($user)) {
            return -1;
        }

        // Normalize nutritional values
        $this->normalizeNutritionalValues();

        $result = $this->createCommon($user, $notrigger);
        
        if ($result > 0) {
            dol_syslog("Nutritional record created successfully with ID: $result", LOG_DEBUG);
        }

        return $result;
    }

    /**
     * Validate data before creation
     */
    private function validateBeforeCreate($user)
    {
        $this->clearValidationErrors();

        // Validate user permissions
        if (!$this->checkCreatePermissions($user)) {
            $this->addValidationError("Insufficient permissions to create nutritional data");
            return false;
        }

        // Validate required fields
        if (!$this->validateRequiredFields()) {
            return false;
        }

        // Validate nutritional values
        if (!$this->validateNutritionalValues()) {
            return false;
        }

        // Check for existing record
        if ($this->fk_product && $this->nutritionalExistsForProduct($this->fk_product)) {
            $this->addValidationError("Nutritional data already exists for this product");
            return false;
        }

        return true;
    }

    /**
     * Check if user has create permissions
     */
    private function checkCreatePermissions($user)
    {
        // Add your permission checks here
        return $user->hasRight('kreaproducts', 'write');
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields()
    {
        if (empty($this->fk_product) || $this->fk_product <= 0) {
            $this->addValidationError("Product ID is required and must be positive");
            return false;
        }

        return true;
    }

    /**
     * Validate nutritional values
     */
    private function validateNutritionalValues()
    {
        foreach ($this->nutritionalFields as $field) {
            $value = $this->$field;
            
            if ($value !== null) {
                if (!is_numeric($value)) {
                    $this->addValidationError("Field $field must be numeric");
                    return false;
                }
                
                $numValue = (float)$value;
                if ($numValue < self::MIN_NUTRITIONAL_VALUE || $numValue > self::MAX_NUTRITIONAL_VALUE) {
                    $this->addValidationError(
                        "Field $field must be between " . self::MIN_NUTRITIONAL_VALUE . 
                        " and " . self::MAX_NUTRITIONAL_VALUE
                    );
                    return false;
                }
            }
        }

        // Validate energy conversion (1 kcal ≈ 4.184 kJ)
        if ($this->energy_kcal !== null && $this->energy_kj !== null) {
            $expectedKj = $this->energy_kcal * 4.184;
            $tolerance = $expectedKj * 0.1; // 10% tolerance
            
            if (abs($this->energy_kj - $expectedKj) > $tolerance) {
                $this->addValidationError("Energy values (kcal/kJ) appear inconsistent");
            }
        }

        return empty($this->validationErrors);
    }

    /**
     * Check if nutritional data exists for product
     */
    private function nutritionalExistsForProduct($productId)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . 
               " WHERE fk_product = " . (int)$productId;
        
        $resql = $this->db->query($sql);
        
        if (!$resql) {
            dol_syslog("Error checking existing nutritional data: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $exists = $this->db->num_rows($resql) > 0;
        $this->db->free($resql);
        
        return $exists;
    }

    /**
     * Normalize nutritional values
     */
    private function normalizeNutritionalValues()
    {
        foreach ($this->nutritionalFields as $field) {
            if ($this->$field !== null) {
                $this->$field = round((float)$this->$field, 4);
            }
        }
    }

    /**
     * Clone an object into another one with enhanced validation
     *
     * @param User $user User that creates
     * @param int $fromid Id of object to clone
     * @return mixed New object created, <0 if KO
     */
    public function createFromClone($user, $fromid)
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        if ($fromid <= 0) {
            $this->error = "Invalid source ID for cloning";
            return -1;
        }

        $this->db->begin();

        try {
            $sourceObject = new self($this->db);
            $result = $sourceObject->fetch($fromid);
            
            if ($result <= 0) {
                throw new Exception("Failed to fetch source object");
            }

            // Reset identifying fields
            $this->resetForCloning($sourceObject);

            // Create the clone
            $sourceObject->context['createfromclone'] = 'createfromclone';
            $result = $sourceObject->createCommon($user);
            
            if ($result < 0) {
                throw new Exception("Failed to create clone: " . implode(', ', $sourceObject->errors));
            }

            $this->copyAssociatedData($sourceObject);

            $this->db->commit();
            return $sourceObject;
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->error = $e->getMessage();
            dol_syslog(__METHOD__ . " Error: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Reset fields for cloning
     */
    private function resetForCloning($sourceObject)
    {
        global $langs;
        
        unset($sourceObject->id);
        unset($sourceObject->fk_user_creat);
        unset($sourceObject->import_key);

        if (property_exists($sourceObject, 'ref')) {
            $sourceObject->ref = empty($this->fields['ref']['default']) 
                ? "Copy_Of_" . $sourceObject->ref 
                : $this->fields['ref']['default'];
        }
        
        if (property_exists($sourceObject, 'label')) {
            $sourceObject->label = empty($this->fields['label']['default']) 
                ? $langs->trans("CopyOf") . " " . $sourceObject->label 
                : $this->fields['label']['default'];
        }
        
        $sourceObject->status = self::STATUS_DRAFT;
        $sourceObject->date_creation = dol_now();
        $sourceObject->date_modification = null;
    }

    /**
     * Copy associated data after cloning
     */
    private function copyAssociatedData($clonedObject)
    {
        // Copy internal contacts
        if ($this->copy_linked_contact($clonedObject, 'internal') < 0) {
            dol_syslog("Warning: Failed to copy internal contacts", LOG_WARNING);
        }

        // Copy external contacts if same company
        if (!empty($clonedObject->socid) && 
            property_exists($this, 'fk_soc') && 
            $this->fk_soc == $clonedObject->socid) {
            if ($this->copy_linked_contact($clonedObject, 'external') < 0) {
                dol_syslog("Warning: Failed to copy external contacts", LOG_WARNING);
            }
        }
    }

    /**
     * Load object in memory from the database with enhanced error handling
     *
     * @param int $id Id object
     * @param string $ref Ref
     * @param int $noextrafields 0=Default to load extrafields, 1=No extrafields
     * @param int $nolines 0=Default to load lines, 1=No lines
     * @return int Return integer <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
    {
        if ($id <= 0 && empty($ref)) {
            $this->error = "Invalid parameters: ID or ref is required";
            return -1;
        }

        $result = $this->fetchCommon($id, $ref, '', $noextrafields);
        
        if ($result > 0 && !empty($this->table_element_line) && empty($nolines)) {
            $this->fetchLines($noextrafields);
        }
        
        return $result;
    }

    /**
     * Fetch nutritional data by product ID
     */
    public function fetchByProduct($productId, $noextrafields = 0)
    {
        if ($productId <= 0) {
            $this->error = "Invalid product ID";
            return -1;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . 
               " WHERE fk_product = " . (int)$productId;
        
        $resql = $this->db->query($sql);
        
        if (!$resql) {
            $this->error = "Database error: " . $this->db->lasterror();
            return -1;
        }

        if ($this->db->num_rows($resql) == 0) {
            $this->db->free($resql);
            return 0; // Not found
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        
        return $this->fetch((int)$obj->rowid, null, $noextrafields);
    }

    /**
     * Update object into database with validation
     *
     * @param User $user User that modifies
     * @param int $notrigger 0=launch triggers after, 1=disable triggers
     * @return int Return integer <0 if KO, >0 if OK
     */
    public function update($user, $notrigger = 0)
    {
        if (!$this->validateBeforeUpdate($user)) {
            return -1;
        }

        $this->normalizeNutritionalValues();
        
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Validate data before update
     */
    private function validateBeforeUpdate($user)
    {
        $this->clearValidationErrors();

        if (!$user->hasRight('kreaproducts', 'write')) {
            $this->addValidationError("Insufficient permissions to update nutritional data");
            return false;
        }

        if (!$this->validateNutritionalValues()) {
            return false;
        }

        return true;
    }

    /**
     * Delete object in database with cascade handling
     *
     * @param User $user User that deletes
     * @param int $notrigger 0=launch triggers, 1=disable triggers
     * @return int Return integer <0 if KO, >0 if OK
     */
    public function delete($user, $notrigger = 0)
    {
        if (!$user->hasRight('kreaproducts', 'delete')) {
            $this->error = "Insufficient permissions to delete nutritional data";
            return -1;
        }

        return $this->deleteCommon($user, $notrigger);
    }

    /**
     * Validate object with enhanced checks
     *
     * @param User $user User making status change
     * @param int $notrigger 1=Does not execute triggers, 0= execute triggers
     * @return int Return integer <=0 if OK, 0=Nothing done, >0 if KO
     */
    public function validate($user, $notrigger = 0)
    {
        if ($this->status == self::STATUS_VALIDATED) {
            dol_syslog(get_class($this) . "::validate action abandoned: already validated", LOG_WARNING);
            return 0;
        }

        if (!$this->validateNutritionalValues()) {
            return -1;
        }

        $this->db->begin();

        try {
            $this->updateValidationFields($user);
            
            if (!$notrigger) {
                $result = $this->call_trigger('NUTRITIONAL_VALIDATE', $user);
                if ($result < 0) {
                    throw new Exception("Trigger execution failed");
                }
            }

            $this->status = self::STATUS_VALIDATED;
            $this->db->commit();
            return 1;
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->error = $e->getMessage();
            return -1;
        }
    }

    /**
     * Update validation-related fields
     */
    private function updateValidationFields($user)
    {
        $now = dol_now();
        
        // Generate new ref if needed
        if (preg_match('/^[\(]?PROV/i', $this->ref) || empty($this->ref)) {
            $this->newref = $this->getNextNumRef();
        } else {
            $this->newref = $this->ref;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $updates = array();
        
        if (!empty($this->fields['ref'])) {
            $updates[] = "ref = '" . $this->db->escape($this->newref) . "'";
        }
        
        $updates[] = "status = " . self::STATUS_VALIDATED;
        
        if (!empty($this->fields['date_validation'])) {
            $updates[] = "date_validation = '" . $this->db->idate($now) . "'";
        }
        
        if (!empty($this->fields['fk_user_valid'])) {
            $updates[] = "fk_user_valid = " . ((int) $user->id);
        }

        $sql .= implode(', ', $updates);
        $sql .= " WHERE rowid = " . ((int) $this->id);

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception("Database update failed: " . $this->db->lasterror());
        }
    }

    /**
     * Calculate total energy from macronutrients
     * Uses standard conversion: Protein=4kcal/g, Carbs=4kcal/g, Fat=9kcal/g
     */
    public function calculateEnergyFromMacros()
    {
        if ($this->protein === null && $this->carbohydrates === null && $this->fat === null) {
            return null;
        }

        $energy = 0;
        $energy += ($this->protein ? $this->protein : 0) * 4;        // Protein: 4 kcal/g
        $energy += ($this->carbohydrates ? $this->carbohydrates : 0) * 4;  // Carbohydrates: 4 kcal/g  
        $energy += ($this->fat ? $this->fat : 0) * 9;           // Fat: 9 kcal/g

        return round($energy, 2);
    }

    /**
     * Auto-calculate missing energy values
     */
    public function autoCalculateEnergy()
    {
        if ($this->energy_kcal === null) {
            $calculated = $this->calculateEnergyFromMacros();
            if ($calculated !== null) {
                $this->energy_kcal = $calculated;
            }
        }

        if ($this->energy_kj === null && $this->energy_kcal !== null) {
            $this->energy_kj = round($this->energy_kcal * 4.184, 2);
        }

        if ($this->energy_kcal === null && $this->energy_kj !== null) {
            $this->energy_kcal = round($this->energy_kj / 4.184, 2);
        }
    }

    /**
     * Get nutritional completeness percentage
     */
    public function getNutritionalCompleteness()
    {
        $totalFields = count($this->nutritionalFields);
        $filledFields = 0;

        foreach ($this->nutritionalFields as $field) {
            if ($this->$field !== null && $this->$field > 0) {
                $filledFields++;
            }
        }

        return round(($filledFields / $totalFields) * 100, 1);
    }

    /**
     * Add validation error
     */
    private function addValidationError($error)
    {
        $this->validationErrors[] = $error;
        $this->error = $error; // Set last error for compatibility
        dol_syslog(__CLASS__ . ": " . $error, LOG_ERR);
    }

    /**
     * Clear validation errors
     */
    private function clearValidationErrors()
    {
        $this->validationErrors = array();
        $this->error = '';
    }

    /**
     * Get all validation errors
     */
    public function getValidationErrors()
    {
        return $this->validationErrors;
    }

    /**
     * Enhanced field validation with nutritional-specific rules
     */
    public function validateField($fields, $fieldKey, $fieldValue)
    {
        // Nutritional-specific validation
        if (in_array($fieldKey, $this->nutritionalFields)) {
            if ($fieldValue !== null && $fieldValue !== '') {
                if (!is_numeric($fieldValue)) {
                    $this->addValidationError("$fieldKey must be numeric");
                    return false;
                }

                $numValue = (float)$fieldValue;
                if ($numValue < self::MIN_NUTRITIONAL_VALUE || $numValue > self::MAX_NUTRITIONAL_VALUE) {
                    $this->addValidationError(
                        "$fieldKey must be between " . self::MIN_NUTRITIONAL_VALUE . 
                        " and " . self::MAX_NUTRITIONAL_VALUE
                    );
                    return false;
                }
            }
        }

        return parent::validateField($fields, $fieldKey, $fieldValue);
    }

    /**
     * Enhanced status management
     */
    public function setDraft($user, $notrigger = 0)
    {
        if ($this->status <= self::STATUS_DRAFT) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'NUTRITIONAL_UNVALIDATE');
    }

    /**
     * Cancel nutritional record
     */
    public function cancel($user, $notrigger = 0)
    {
        if ($this->status != self::STATUS_VALIDATED) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'NUTRITIONAL_CANCEL');
    }

    /**
     * Reopen nutritional record
     */
    public function reopen($user, $notrigger = 0)
    {
        if ($this->status == self::STATUS_VALIDATED) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'NUTRITIONAL_REOPEN');
    }

    /**
     * Enhanced getTooltipContentArray with nutritional info
     */
    public function getTooltipContentArray($params)
    {
        global $langs;

        $datas = array();

        if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
            return array('optimize' => $langs->trans("ShowNutritional"));
        }

        $datas['picto'] = img_picto('', $this->picto) . ' <u>' . $langs->trans("Nutritional") . '</u>';
        
        if (isset($this->status)) {
            $datas['picto'] .= ' ' . $this->getLibStatut(5);
        }

        if (property_exists($this, 'ref')) {
            $datas['ref'] = '<br><b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;
        }

        // Add nutritional summary
        if ($this->energy_kcal) {
            $datas['energy'] = '<br><b>Energy:</b> ' . $this->energy_kcal . ' kcal';
        }

        $completeness = $this->getNutritionalCompleteness();
        $datas['completeness'] = '<br><b>Completeness:</b> ' . $completeness . '%';

        return $datas;
    }

    /**
     * Get status label with proper typing
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->status ? $this->status : self::STATUS_DRAFT, $mode);
    }

    /**
     * Enhanced LibStatut with better status handling
     */
    public function LibStatut($status, $mode = 0)
    {
        if ($status === null) {
            return '';
        }

        if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
            global $langs;
            $this->labelStatus = array(
                self::STATUS_DRAFT => $langs->transnoentitiesnoconv('Draft'),
                self::STATUS_VALIDATED => $langs->transnoentitiesnoconv('Enabled'),
                self::STATUS_CANCELED => $langs->transnoentitiesnoconv('Disabled')
            );
            $this->labelStatusShort = array(
                self::STATUS_DRAFT => $langs->transnoentitiesnoconv('Draft'),
                self::STATUS_VALIDATED => $langs->transnoentitiesnoconv('Enabled'),
                self::STATUS_CANCELED => $langs->transnoentitiesnoconv('Disabled')
            );
        }

        $statusType = 'status' . $status;
        if ($status == self::STATUS_CANCELED) {
            $statusType = 'status6';
        }

        $statusLabel = isset($this->labelStatus[$status]) ? $this->labelStatus[$status] : 'Unknown';
        $statusLabelShort = isset($this->labelStatusShort[$status]) ? $this->labelStatusShort[$status] : 'Unknown';

        return dolGetStatus($statusLabel, $statusLabelShort, '', $statusType, $mode);
    }

    /**
     * Initialize object with example values for specimen
     */
    public function initAsSpecimen()
    {
        // Set realistic nutritional values for a specimen
        $this->fk_product = 1;
        $this->energy_kcal = 250.0;
        $this->energy_kj = 1046.0;
        $this->fat = 15.0;
        $this->saturates = 5.0;
        $this->carbohydrates = 20.0;
        $this->sugars = 8.0;
        $this->protein = 12.0;
        $this->salt = 1.2;
        $this->fiber = 3.5;

        return $this->initAsSpecimenCommon();
    }

    /**
     * Load object lines in memory from the database
     */
    public function fetchLines($noextrafields = 0)
    {
        $this->lines = array();
        return $this->fetchLinesCommon('', $noextrafields);
    }

    /**
     * Load list of objects in memory from the database
     */
    public function fetchAll($sortorder = '', $sortfield = '', $limit = 1000, $offset = 0, $filter = '', $filtermode = 'AND')
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        $records = array();

        $sql = "SELECT ";
        $sql .= $this->getFieldList('t');
        $sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
        if (isset($this->isextrafieldmanaged) && $this->isextrafieldmanaged == 1) {
            $sql .= " LEFT JOIN " . $this->db->prefix() . $this->table_element . "_extrafields as te ON te.fk_object = t.rowid";
        }
        if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
            $sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";
        } else {
            $sql .= " WHERE 1 = 1";
        }

        // Manage filter
        $errormessage = '';
        $sql .= forgeSQLFromUniversalSearchCriteria($filter, $errormessage);
        if ($errormessage) {
            $this->errors[] = $errormessage;
            dol_syslog(__METHOD__ . ' ' . implode(',', $this->errors), LOG_ERR);
            return -1;
        }

        if (!empty($sortfield)) {
            $sql .= $this->db->order($sortfield, $sortorder);
        }
        if (!empty($limit)) {
            $sql .= $this->db->plimit($limit, $offset);
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < ($limit ? min($limit, $num) : $num)) {
                $obj = $this->db->fetch_object($resql);

                $record = new self($this->db);
                $record->setVarsFromFetchObj($obj);

                if (!empty($record->isextrafieldmanaged)) {
                    $record->fetch_optionals();
                }

                $records[$record->id] = $record;

                $i++;
            }
            $this->db->free($resql);

            return $records;
        } else {
            $this->errors[] = 'Error ' . $this->db->lasterror();
            dol_syslog(__METHOD__ . ' ' . implode(',', $this->errors), LOG_ERR);

            return -1;
        }
    }

    /**
     * Delete a line of object in database
     */
    public function deleteLine($user, $idline, $notrigger = 0)
    {
        if ($this->status < 0) {
            $this->error = 'ErrorDeleteLineNotAllowedByObjectStatus';
            return -2;
        }

        return $this->deleteLineCommon($user, $idline, $notrigger);
    }

    /**
     * Load the info information in the object
     */
    public function info($id)
    {
        $sql = "SELECT rowid,";
        $sql .= " date_creation as datec, tms as datem";
        if (!empty($this->fields['date_validation'])) {
            $sql .= ", date_validation as datev";
        }
        if (!empty($this->fields['fk_user_creat'])) {
            $sql .= ", fk_user_creat";
        }
        if (!empty($this->fields['fk_user_modif'])) {
            $sql .= ", fk_user_modif";
        }
        if (!empty($this->fields['fk_user_valid'])) {
            $sql .= ", fk_user_valid";
        }
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";
        $sql .= " WHERE t.rowid = " . ((int) $id);

        $result = $this->db->query($sql);
        if ($result) {
            if ($this->db->num_rows($result)) {
                $obj = $this->db->fetch_object($result);

                $this->id = $obj->rowid;

                if (!empty($this->fields['fk_user_creat'])) {
                    $this->user_creation_id = $obj->fk_user_creat;
                }
                if (!empty($this->fields['fk_user_modif'])) {
                    $this->user_modification_id = $obj->fk_user_modif;
                }
                if (!empty($this->fields['fk_user_valid'])) {
                    $this->user_validation_id = $obj->fk_user_valid;
                }
                $this->date_creation     = $this->db->jdate($obj->datec);
                $this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
                if (!empty($obj->datev)) {
                    $this->date_validation   = empty($obj->datev) ? '' : $this->db->jdate($obj->datev);
                }
            }

            $this->db->free($result);
        } else {
            dol_print_error($this->db);
        }
    }

    /**
     * Create an array of lines
     */
    public function getLinesArray()
    {
        $this->lines = array();

        $objectline = new NutritionalLine($this->db);
        $result = $objectline->fetchAll('ASC', 'position', 0, 0, '(fk_nutritional:=:' . ((int) $this->id) . ')');

        if (is_numeric($result)) {
            $this->setErrorsFromObject($objectline);
            return $result;
        } else {
            $this->lines = $result;
            return $this->lines;
        }
    }

    /**
     * Get the reference to the following non used object
     */
    public function getNextNumRef()
    {
        global $langs, $conf;
        $langs->load("kreaproducts@kreaproducts");

        if (!getDolGlobalString('KREAPRODUCTS_MYOBJECT_ADDON')) {
            $conf->global->KREAPRODUCTS_MYOBJECT_ADDON = 'mod_nutritional_standard';
        }

        if (getDolGlobalString('KREAPRODUCTS_MYOBJECT_ADDON')) {
            $mybool = false;

            $file = getDolGlobalString('KREAPRODUCTS_MYOBJECT_ADDON') . ".php";
            $classname = getDolGlobalString('KREAPRODUCTS_MYOBJECT_ADDON');

            // Include file with class
            $dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
            foreach ($dirmodels as $reldir) {
                $dir = dol_buildpath($reldir . "core/modules/kreaproducts/");

                // Load file with numbering class (if found)
                $mybool = $mybool || @include_once $dir . $file;
            }

            if (!$mybool) {
                dol_print_error(null, "Failed to include file " . $file);
                return '';
            }

            if (class_exists($classname)) {
                $obj = new $classname();
                $numref = $obj->getNextValue($this);

                if ($numref != '' && $numref != '-1') {
                    return $numref;
                } else {
                    $this->error = $obj->error;
                    return "";
                }
            } else {
                print $langs->trans("Error") . " " . $langs->trans("ClassNotFound") . ' ' . $classname;
                return "";
            }
        } else {
            print $langs->trans("ErrorNumberingModuleNotSetup", $this->element);
            return "";
        }
    }

    /**
     * Create a document onto disk according to template module
     */
    public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
    {
        global $langs;

        $result = 0;
        $includedocgeneration = 0;

        $langs->load("kreaproducts@kreaproducts");

        if (!dol_strlen($modele)) {
            $modele = 'standard_nutritional';

            if (!empty($this->model_pdf)) {
                $modele = $this->model_pdf;
            } elseif (getDolGlobalString('MYOBJECT_ADDON_PDF')) {
                $modele = getDolGlobalString('MYOBJECT_ADDON_PDF');
            }
        }

        $modelpath = "core/modules/kreaproducts/doc/";

        if ($includedocgeneration && !empty($modele)) {
            $result = $this->commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
        }

        return $result;
    }

    /**
     * Action executed by scheduler
     */
    public function doScheduledJob()
    {
        $error = 0;
        $this->output = '';
        $this->error = '';

        dol_syslog(__METHOD__ . " start", LOG_INFO);

        $now = dol_now();

        $this->db->begin();

        // Add your scheduled job logic here

        $this->db->commit();

        dol_syslog(__METHOD__ . " end", LOG_INFO);

        return $error;
    }

    /**
     * Return a link to the object card (with optionally the picto)
     */
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
    {
        global $conf, $langs, $hookmanager;

        if (!empty($conf->dol_no_mouse_hover)) {
            $notooltip = 1;
        }

        $result = '';
        $params = array(
            'id' => $this->id,
            'objecttype' => $this->element . ($this->module ? '@' . $this->module : ''),
            'option' => $option,
        );
        $classfortooltip = 'classfortooltip';
        $dataparams = '';
        if (getDolGlobalInt('MAIN_ENABLE_AJAX_TOOLTIP')) {
            $classfortooltip = 'classforajaxtooltip';
            $dataparams = ' data-params="' . dol_escape_htmltag(json_encode($params)) . '"';
            $label = '';
        } else {
            $label = implode($this->getTooltipContentArray($params));
        }

        $url = dol_buildpath('/kreaproducts/nutritional_card.php', 1) . '?id=' . $this->id;

        if ($option !== 'nolink') {
            $add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
            if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
                $add_save_lastsearch_values = 1;
            }
            if ($url && $add_save_lastsearch_values) {
                $url .= '&save_lastsearch_values=1';
            }
        }

        $linkclose = '';
        if (empty($notooltip)) {
            if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
                $label = $langs->trans("ShowNutritional");
                $linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
            }
            $linkclose .= ($label ? ' title="' . dol_escape_htmltag($label, 1) . '"' : ' title="tocomplete"');
            $linkclose .= $dataparams . ' class="' . $classfortooltip . ($morecss ? ' ' . $morecss : '') . '"';
        } else {
            $linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
        }

        if ($option == 'nolink' || empty($url)) {
            $linkstart = '<span';
        } else {
            $linkstart = '<a href="' . $url . '"';
        }
        $linkstart .= $linkclose . '>';
        if ($option == 'nolink' || empty($url)) {
            $linkend = '</span>';
        } else {
            $linkend = '</a>';
        }

        $result .= $linkstart;

        if (empty($this->showphoto_on_popup)) {
            if ($withpicto) {
                $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), (($withpicto != 2) ? 'class="paddingright"' : ''), 0, 0, $notooltip ? 0 : 1);
            }
        }

        if ($withpicto != 2) {
            $result .= $this->ref;
        }

        $result .= $linkend;

        global $action, $hookmanager;
        $hookmanager->initHooks(array($this->element . 'dao'));
        $parameters = array('id' => $this->id, 'getnomurl' => &$result);
        $reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action);
        if ($reshook > 0) {
            $result = $hookmanager->resPrint;
        } else {
            $result .= $hookmanager->resPrint;
        }

        return $result;
    }

    /**
     * Return a thumb for kanban views
     */
    public function getKanbanView($option = '', $arraydata = null)
    {
        global $conf, $langs;

        $selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);

        $return = '<div class="box-flex-item box-flex-grow-zero">';
        $return .= '<div class="info-box info-box-sm">';
        $return .= '<span class="info-box-icon bg-infobox-action">';
        $return .= img_picto('', $this->picto);
        $return .= '</span>';
        $return .= '<div class="info-box-content">';
        $return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">' . (method_exists($this, 'getNomUrl') ? $this->getNomUrl() : $this->ref) . '</span>';
        if ($selected >= 0) {
            $return .= '<input id="cb' . $this->id . '" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="' . $this->id . '"' . ($selected ? ' checked="checked"' : '') . '>';
        }
        if (property_exists($this, 'label')) {
            $return .= ' <div class="inline-block opacitymedium valignmiddle tdoverflowmax100">' . $this->label . '</div>';
        }
        if (property_exists($this, 'thirdparty') && is_object($this->thirdparty)) {
            $return .= '<br><div class="info-box-ref tdoverflowmax150">' . $this->thirdparty->getNomUrl(1) . '</div>';
        }
        if (property_exists($this, 'amount')) {
            $return .= '<br>';
            $return .= '<span class="info-box-label amount">' . price($this->amount, 0, $langs, 1, -1, -1, $conf->currency) . '</span>';
        }
        if (method_exists($this, 'getLibStatut')) {
            $return .= '<br><div class="info-box-status">' . $this->getLibStatut(3) . '</div>';
        }
        $return .= '</div>';
        $return .= '</div>';
        $return .= '</div>';

        return $return;
    }

    /**
     * Return the label of the status
     */
    public function getLabelStatus($mode = 0)
    {
        return $this->LibStatut($this->status, $mode);
    }
}

/**
 * Class NutritionalLine for line management
 */
class NutritionalLine extends CommonObjectLine
{
    public $parent_element = 'nutritional';
    public $fk_parent_attribute = 'fk_nutritional';

    public function __construct($db)
    {
        $this->db = $db;
        $this->isextrafieldmanaged = 0;
    }
}
