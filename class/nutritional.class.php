<?php
/* Copyright (C) 2017       Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2024  Frédéric France          <frederic.france@free.fr>
 * Copyright (C) 2025		Kreativitat	<mail@kreativitat.com>
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
    public const STATUS_DRAFT = 0;
    public const STATUS_VALIDATED = 1;
    public const STATUS_CANCELED = 9;
    
    // Constants for validation
    private const MAX_NUTRITIONAL_VALUE = 999999.9999;
    private const MIN_NUTRITIONAL_VALUE = 0.0;
    
    // Constants for field lengths
    private const MAX_REF_LENGTH = 128;
    
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
    public $fields = [
        "rowid" => [
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
        ],
        "date_creation" => [
            "type" => "datetime", 
            "label" => "DateCreation", 
            "enabled" => "1", 
            'position' => 500, 
            'notnull' => 1, 
            "visible" => "-2"
        ],
        "tms" => [
            "type" => "timestamp", 
            "label" => "DateModification", 
            "enabled" => "1", 
            'position' => 501, 
            'notnull' => 0, 
            "visible" => "-2"
        ],
        "fk_user_creat" => [
            "type" => "integer:User:user/class/user.class.php", 
            "label" => "UserAuthor", 
            "picto" => "user", 
            "enabled" => "1", 
            'position' => 510, 
            'notnull' => 1, 
            "visible" => "-2", 
            "csslist" => "tdoverflowmax150"
        ],
        "fk_user_modif" => [
            "type" => "integer:User:user/class/user.class.php", 
            "label" => "UserModif", 
            "picto" => "user", 
            "enabled" => "1", 
            'position' => 511, 
            'notnull' => -1, 
            "visible" => "-2", 
            "csslist" => "tdoverflowmax150"
        ],
        "fk_product" => [
            "type" => "integer", 
            "label" => "fk_product", 
            "enabled" => "1", 
            'position' => 60, 
            'notnull' => 1, 
            "visible" => "-2", 
            "index" => "1"
        ],
        "energy_kcal" => [
            "type" => "double(28,4)", 
            "label" => "Energy (kcal)", 
            "enabled" => "1", 
            'position' => 61, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "energy_kj" => [
            "type" => "double(28,4)", 
            "label" => "Energy (kj)", 
            "enabled" => "1", 
            'position' => 62, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "fat" => [
            "type" => "double(28,4)", 
            "label" => "Fat", 
            "enabled" => "1", 
            'position' => 63, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "saturates" => [
            "type" => "double(28,4)", 
            "label" => "Saturates", 
            "enabled" => "1", 
            'position' => 64, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "carbohydrates" => [
            "type" => "double(28,4)", 
            "label" => "Carbohydrates", 
            "enabled" => "1", 
            'position' => 65, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "sugars" => [
            "type" => "double(28,4)", 
            "label" => "Sugars", 
            "enabled" => "1", 
            'position' => 66, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "protein" => [
            "type" => "double(28,4)", 
            "label" => "Protein", 
            "enabled" => "1", 
            'position' => 67, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "salt" => [
            "type" => "double(28,4)", 
            "label" => "Salt", 
            "enabled" => "1", 
            'position' => 68, 
            'notnull' => 0, 
            "visible" => "1"
        ],
        "fiber" => [
            "type" => "double(28,4)", 
            "label" => "Fiber", 
            "enabled" => "1", 
            'position' => 69, 
            'notnull' => 0, 
            "visible" => "1"
        ]
    ];

    // Public properties with type declarations
    public ?int $rowid = null;
    public ?string $date_creation = null;
    public ?int $tms = null;
    public ?int $fk_user_creat = null;
    public ?int $fk_user_modif = null;
    public ?int $fk_product = null;
    public ?float $energy_kcal = null;
    public ?float $energy_kj = null;
    public ?float $fat = null;
    public ?float $saturates = null;
    public ?float $carbohydrates = null;
    public ?float $sugars = null;
    public ?float $protein = null;
    public ?float $salt = null;
    public ?float $fiber = null;

    // Internal error handling
    private array $validationErrors = [];
    private array $nutritionalFields = [
        'energy_kcal', 'energy_kj', 'fat', 'saturates', 
        'carbohydrates', 'sugars', 'protein', 'salt', 'fiber'
    ];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
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
    private function initializeFields(): void
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
        $this->fields = array_filter($this->fields, function($field) {
            return empty($field['enabled']) ? false : true;
        });
    }

    /**
     * Process field translations
     */
    private function processFieldTranslations(?object $langs): void
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
    public function create(User $user, int $notrigger = 0): int
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
    private function validateBeforeCreate(User $user): bool
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
    private function checkCreatePermissions(User $user): bool
    {
        // Add your permission checks here
        return $user->hasRight('kreaproducts', 'write');
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields(): bool
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
    private function validateNutritionalValues(): bool
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
    private function nutritionalExistsForProduct(int $productId): bool
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . 
               " WHERE fk_product = %d";
        
        $sql = sprintf($sql, $productId);
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
    private function normalizeNutritionalValues(): void
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
     * @return self|int New object created, <0 if KO
     */
    public function createFromClone(User $user, int $fromid)
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
    private function resetForCloning(self $sourceObject): void
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
    private function copyAssociatedData(self $clonedObject): void
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
     * @param string|null $ref Ref
     * @param int $noextrafields 0=Default to load extrafields, 1=No extrafields
     * @param int $nolines 0=Default to load lines, 1=No lines
     * @return int Return integer <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch(int $id, ?string $ref = null, int $noextrafields = 0, int $nolines = 0): int
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
    public function fetchByProduct(int $productId, int $noextrafields = 0): int
    {
        if ($productId <= 0) {
            $this->error = "Invalid product ID";
            return -1;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element . 
               " WHERE fk_product = %d";
        
        $sql = sprintf($sql, $productId);
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
    public function update(User $user, int $notrigger = 0): int
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
    private function validateBeforeUpdate(User $user): bool
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
    public function delete(User $user, int $notrigger = 0): int
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
    public function validate(User $user, int $notrigger = 0): int
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
    private function updateValidationFields(User $user): void
    {
        $now = dol_now();
        
        // Generate new ref if needed
        if (preg_match('/^[\(]?PROV/i', $this->ref ?? '') || empty($this->ref)) {
            $this->newref = $this->getNextNumRef();
        } else {
            $this->newref = $this->ref;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $updates = [];
        
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
    public function calculateEnergyFromMacros(): ?float
    {
        if ($this->protein === null && $this->carbohydrates === null && $this->fat === null) {
            return null;
        }

        $energy = 0;
        $energy += ($this->protein ?? 0) * 4;        // Protein: 4 kcal/g
        $energy += ($this->carbohydrates ?? 0) * 4;  // Carbohydrates: 4 kcal/g  
        $energy += ($this->fat ?? 0) * 9;           // Fat: 9 kcal/g

        return round($energy, 2);
    }

    /**
     * Auto-calculate missing energy values
     */
    public function autoCalculateEnergy(): void
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
    public function getNutritionalCompleteness(): float
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
    private function addValidationError(string $error): void
    {
        $this->validationErrors[] = $error;
        $this->error = $error; // Set last error for compatibility
        dol_syslog(__CLASS__ . ": " . $error, LOG_ERR);
    }

    /**
     * Clear validation errors
     */
    private function clearValidationErrors(): void
    {
        $this->validationErrors = [];
        $this->error = '';
    }

    /**
     * Get all validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Enhanced field validation with nutritional-specific rules
     */
    public function validateField($fields, $fieldKey, $fieldValue): bool
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
    public function setDraft(User $user, int $notrigger = 0): int
    {
        if ($this->status <= self::STATUS_DRAFT) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'NUTRITIONAL_UNVALIDATE');
    }

    /**
     * Cancel nutritional record
     */
    public function cancel(User $user, int $notrigger = 0): int
    {
        if ($this->status != self::STATUS_VALIDATED) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'NUTRITIONAL_CANCEL');
    }

    /**
     * Reopen nutritional record
     */
    public function reopen(User $user, int $notrigger = 0): int
    {
        if ($this->status == self::STATUS_VALIDATED) {
            return 0;
        }

        return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'NUTRITIONAL_REOPEN');
    }

    /**
     * Enhanced getTooltipContentArray with nutritional info
     */
    public function getTooltipContentArray($params): array
    {
        global $langs;

        $datas = [];

        if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
            return ['optimize' => $langs->trans("ShowNutritional")];
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
    public function getLibStatut(int $mode = 0): string
    {
        return $this->LibStatut($this->status ?? self::STATUS_DRAFT, $mode);
    }

    /**
     * Enhanced LibStatut with better status handling
     */
    public function LibStatut(?int $status, int $mode = 0): string
    {
        if ($status === null) {
            return '';
        }

        if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
            global $langs;
            $this->labelStatus = [
                self::STATUS_DRAFT => $langs->transnoentitiesnoconv('Draft'),
                self::STATUS_VALIDATED => $langs->transnoentitiesnoconv('Enabled'),
                self::STATUS_CANCELED => $langs->transnoentitiesnoconv('Disabled')
            ];
            $this->labelStatusShort = [
                self::STATUS_DRAFT => $langs->transnoentitiesnoconv('Draft'),
                self::STATUS_VALIDATED => $langs->transnoentitiesnoconv('Enabled'),
                self::STATUS_CANCELED => $langs->transnoentitiesnoconv('Disabled')
            ];
        }

        $statusType = 'status' . $status;
        if ($status == self::STATUS_CANCELED) {
            $statusType = 'status6';
        }

        return dolGetStatus(
            $this->labelStatus[$status] ?? 'Unknown', 
            $this->labelStatusShort[$status] ?? 'Unknown', 
            '', 
            $statusType, 
            $mode
        );
    }

    /**
     * Initialize object with example values for specimen
     */
    public function initAsSpecimen(): int
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
}

/**
 * Class NutritionalLine for line management
 */
class NutritionalLine extends CommonObjectLine
{
    public $parent_element = 'nutritional';
    public $fk_parent_attribute = 'fk_nutritional';

    public function __construct(DoliDB $db)
    {
        $this->db = $db;
        $this->isextrafieldmanaged = 0;
    }
}
