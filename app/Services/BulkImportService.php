<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\Country;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Province;
use App\Models\Region;
use App\Models\Status;
use App\Models\User;
use App\Models\Zonal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Carbon\Carbon;

class BulkImportService
{
    /**
     * Get configuration for all importable tables in CDP Orbit.
     */
    public function getImportableConfig(): array
    {
        return [
            'countries' => [
                'model' => Country::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'description', 'is_active'],
            ],
            'provinces' => [
                'model' => Province::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'country_code' => ['model' => Country::class, 'field' => 'code', 'foreign_key' => 'country_id']
                ],
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'zonals' => [
                'model' => Zonal::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id']
                ],
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'regions' => [
                'model' => Region::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id']
                ],
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'groups' => [
                'model' => Group::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'description', 'is_active'],
            ],
            'branches' => [
                'model' => Branch::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zone_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                    'group_code' => ['model' => Group::class, 'field' => 'code', 'foreign_key' => 'group_id'],
                ],
                'fillable' => ['name', 'code', 'address_line1', 'address_line2', 'city', 'postal_code', 'phone_primary', 'phone_secondary', 'email', 'fax', 'opening_date', 'branch_type', 'latitude', 'longitude', 'is_active', 'is_head_office'],
            ],
            'departments' => [
                'model' => Department::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'description', 'is_active'],
            ],
            'designations' => [
                'model' => Designation::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'department_code' => ['model' => Department::class, 'field' => 'code', 'foreign_key' => 'department_id']
                ],
                'fillable' => ['name', 'code', 'level', 'order_weight', 'description', 'is_active'],
            ],
            'employees' => [
                'model' => Employee::class,
                'unique_key' => 'id_number',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                    'branch_code' => ['model' => Branch::class, 'field' => 'code', 'foreign_key' => 'branch_id'],
                    'department_code' => ['model' => Department::class, 'field' => 'code', 'foreign_key' => 'department_id'],
                    'designation_code' => ['model' => Designation::class, 'field' => 'code', 'foreign_key' => 'designation_id'],
                    'reporting_manager_code' => ['model' => Employee::class, 'field' => 'employee_code', 'foreign_key' => 'reporting_manager_id'],
                ],
                'fillable' => [
                    'f_name', 'l_name', 'full_name', 'name_with_initials', 'employee_code', 'employee_type',
                    'id_type', 'id_number', 'date_of_birth', 'email', 'phone', 'address_line_1', 'city',
                    'state', 'country', 'postal_code', 'phone_primary', 'phone_secondary', 'have_whatsapp',
                    'whatsapp_number', 'start_date', 'end_date', 'is_active'
                ],
            ],
            'users' => [
                'model' => User::class,
                'unique_key' => 'username',
                'dependencies' => [
                    'employee_code' => ['model' => Employee::class, 'field' => 'employee_code', 'foreign_key' => 'employee_id']
                ],
                'special_fields' => ['password', 'role'],
                'fillable' => ['name', 'username', 'email', 'user_type', 'is_active', 'can_login'],
            ],
            'lead_stages' => [
                'model' => LeadStage::class,
                'unique_key' => 'name',
                'fillable' => ['name', 'sort_order', 'description', 'is_active'],
            ],
            'statuses' => [
                'model' => Status::class,
                'unique_key' => 'name',
                'dependencies' => [
                    'lead_stage_name' => ['model' => LeadStage::class, 'field' => 'name', 'foreign_key' => 'lead_stage_id']
                ],
                'fillable' => ['name', 'color_code', 'description', 'sort_order', 'is_active', 'is_need_sms'],
            ],
            'leads' => [
                'model' => Lead::class,
                'unique_key' => 'phone_primary',
                'dependencies' => [
                    'status_name' => ['model' => Status::class, 'field' => 'name', 'foreign_key' => 'status_id'],
                    'group_code' => ['model' => Group::class, 'field' => 'code', 'foreign_key' => 'group_id'],
                    'creator_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'created_by'],
                    'updater_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'updated_by'],
                ],
                'fillable' => [
                    'name', 'email', 'phone_primary', 'phone_secondary', 'have_whatsapp', 'whatsapp_number',
                    'birthday', 'id_type', 'id_number', 'preferred_language', 'company', 'value', 'source', 'notes'
                ],
            ],
            'announcements' => [
                'model' => Announcement::class,
                'unique_key' => 'title',
                'dependencies' => [
                    'creator_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'created_by'],
                    'updater_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'updated_by'],
                ],
                'fillable' => ['title', 'content', 'target_type', 'target_id', 'is_active', 'sms'],
            ],
            'campaigns' => [
                'model' => Campaign::class,
                'unique_key' => 'title',
                'dependencies' => [
                    'creator_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'created_by'],
                    'updater_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'updated_by'],
                ],
                'fillable' => ['title', 'description', 'start_date', 'end_date', 'is_active', 'target_type', 'target_id', 'sms'],
            ],
        ];
    }

    /**
     * Import data from CSV file for a specific table.
     */
    public function import(UploadedFile $file, string $table): array
    {
        $results = [
            'total' => 0,
            'imported' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $config = $this->getImportableConfig()[$table] ?? null;
        if (!$config) {
            return array_merge($results, ['errors' => ["Unsupported table: $table"]]);
        }

        $handle = fopen($file->getRealPath(), 'r');

        // Read and clean headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return array_merge($results, ['errors' => ['Empty or invalid CSV file.']]);
        }

        // Clean headers (remove BOM, trim whitespace)
        $headers = array_map(function($header) {
            return trim($header, "\xEF\xBB\xBF");
        }, $headers);
        $headers = array_map('trim', $headers);

        $rowNumber = 1;
        while (($rowData = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $results['total']++;

            // Handle mismatched column counts
            if (count($headers) !== count($rowData)) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => "Column count mismatch. Expected " . count($headers) . ", got " . count($rowData)
                ];
                continue;
            }

            $data = array_combine($headers, $rowData);

            // Clean all data (remove empty strings, trim)
            $data = $this->cleanData($data);

            // Preprocessing for specific tables
            if ($table === 'employees') {
                $data = $this->preprocessEmployeeData($data);
            } elseif ($table === 'leads') {
                $data = $this->preprocessLeadData($data);
            } elseif ($table === 'campaigns') {
                $data = $this->preprocessCampaignData($data);
            } elseif ($table === 'branches') {
                $data = $this->preprocessBranchData($data);
            }

            DB::beginTransaction();
            try {
                $this->processGenericRow($config, $data);
                DB::commit();
                $results['imported']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => $e->getMessage()
                ];
                Log::error("Import failed for table $table at row $rowNumber: " . $e->getMessage());
            }
        }

        fclose($handle);
        return $results;
    }

    /**
     * Clean and sanitize data
     */
    protected function cleanData(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                $cleaned[$key] = null;
            } else {
                $cleaned[$key] = trim($value);
            }
        }
        return $cleaned;
    }

    /**
     * Preprocess employee data
     */
    protected function preprocessEmployeeData(array $data): array
    {
        // Dates
        $dateFields = ['date_of_birth', 'start_date', 'end_date'];
        foreach ($dateFields as $field) {
            if (!empty($data[$field])) {
                $data[$field] = $this->parseDate($data[$field]);
            }
        }

        // WhatsApp boolean
        $data['have_whatsapp'] = $this->parseBoolean($data['have_whatsapp'] ?? null);

        // Phones
        if (!empty($data['phone_primary'])) {
            $data['phone_primary'] = $this->cleanPhoneNumber($data['phone_primary']);
        }
        if (!empty($data['phone_secondary'])) {
            $data['phone_secondary'] = $this->cleanPhoneNumber($data['phone_secondary']);
        }
        if (!empty($data['phone'])) {
            $data['phone'] = $this->cleanPhoneNumber($data['phone']);
        }
        if (!empty($data['whatsapp_number'])) {
            $data['whatsapp_number'] = $this->cleanPhoneNumber($data['whatsapp_number']);
        }

        // Full name fallback
        if (empty($data['full_name'])) {
            $data['full_name'] = trim(($data['f_name'] ?? '') . ' ' . ($data['l_name'] ?? ''));
        }

        // Country
        if (empty($data['country'])) {
            $data['country'] = 'Sri Lanka';
        }

        // Is Active
        $data['is_active'] = $this->parseBoolean($data['is_active'] ?? '1');

        return $data;
    }

    /**
     * Preprocess lead data
     */
    protected function preprocessLeadData(array $data): array
    {
        if (!empty($data['birthday'])) {
            $data['birthday'] = $this->parseDate($data['birthday']);
        }

        $data['have_whatsapp'] = $this->parseBoolean($data['have_whatsapp'] ?? null);

        if (!empty($data['phone_primary'])) {
            $data['phone_primary'] = $this->cleanPhoneNumber($data['phone_primary']);
        }
        if (!empty($data['phone_secondary'])) {
            $data['phone_secondary'] = $this->cleanPhoneNumber($data['phone_secondary']);
        }
        if (!empty($data['whatsapp_number'])) {
            $data['whatsapp_number'] = $this->cleanPhoneNumber($data['whatsapp_number']);
        }

        return $data;
    }

    /**
     * Preprocess campaign data
     */
    protected function preprocessCampaignData(array $data): array
    {
        if (!empty($data['start_date'])) {
            $data['start_date'] = $this->parseDate($data['start_date']);
        }
        if (!empty($data['end_date'])) {
            $data['end_date'] = $this->parseDate($data['end_date']);
        }

        $data['sms'] = $this->parseBoolean($data['sms'] ?? '0');
        $data['is_active'] = $this->parseBoolean($data['is_active'] ?? '1');

        return $data;
    }

    /**
     * Preprocess branch data
     */
    protected function preprocessBranchData(array $data): array
    {
        if (!empty($data['opening_date'])) {
            $data['opening_date'] = $this->parseDate($data['opening_date']);
        }

        $data['is_active'] = $this->parseBoolean($data['is_active'] ?? '1');
        $data['is_head_office'] = $this->parseBoolean($data['is_head_office'] ?? '0');

        return $data;
    }

    /**
     * Parse boolean fields
     */
    protected function parseBoolean($value): bool
    {
        if ($value === null) return false;
        $val = strtolower(trim($value));
        return in_array($val, ['1', 'true', 'yes', 'y', 'on']);
    }

    /**
     * Parse date formats to Y-m-d
     */
    protected function parseDate($date): ?string
    {
        if (empty($date)) return null;

        $date = trim($date);

        // dd/mm/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // dd-mm-YYYY
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // YYYY-mm-dd
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean phone number
     */
    protected function cleanPhoneNumber($phone): ?string
    {
        if (empty($phone)) return null;

        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = ltrim($phone, '0');

        if (strlen($phone) === 9 && preg_match('/^7[0-9]{8}$/', $phone)) {
            $phone = '94' . $phone;
        }

        return $phone;
    }

    /**
     * Generic row processing using config.
     */
    protected function processGenericRow(array $config, array $data): void
    {
        $modelClass = $config['model'];
        $uniqueKeyField = $config['unique_key'];

        // Resolve Dependencies
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $csvCol => $dep) {
                if (!empty($data[$csvCol])) {
                    $resolved = $dep['model']::where($dep['field'], $data[$csvCol])->first();
                    if (!$resolved) {
                        throw new \Exception("Could not resolve dependency {$csvCol} with value '{$data[$csvCol]}'");
                    }
                    $data[$dep['foreign_key']] = $resolved->id;
                }
                unset($data[$csvCol]);
            }
        }

        // Special handling for Users
        if ($modelClass === User::class) {
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                $data['password'] = Hash::make($data['username']); // Default fallback
            }
            $roleName = $data['role'] ?? null;
            unset($data['role']);

            $user = User::updateOrCreate([$uniqueKeyField => $data[$uniqueKeyField]], $data);

            if ($roleName) {
                $user->syncRoles([$roleName]);
            }
            return;
        }

        // Prepare allowed fields
        $allowedFields = $config['fillable'];
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $dep) {
                $allowedFields[] = $dep['foreign_key'];
            }
        }
        if (!in_array($uniqueKeyField, $allowedFields)) {
            $allowedFields[] = $uniqueKeyField;
        }

        // Filter data
        $filteredData = array_intersect_key($data, array_flip($allowedFields));

        try {
            $modelClass::updateOrCreate(
                [$uniqueKeyField => $filteredData[$uniqueKeyField]],
                $filteredData
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                throw new \Exception("Relationship mismatch: Foreign ID connection error. Please ensure database parent entities are imported first.");
            }
            throw $e;
        }
    }

    /**
     * Get list of importable tables for dynamic selection.
     */
    public function getImportableTables(): array
    {
        $configs = $this->getImportableConfig();
        $list = [];

        foreach ($configs as $table => $config) {
            $headers = $config['fillable'];
            if (isset($config['dependencies'])) {
                $headers = array_merge($headers, array_keys($config['dependencies']));
            }
            if (isset($config['special_fields'])) {
                $headers = array_merge($headers, $config['special_fields']);
            }

            $list[] = [
                'table' => $table,
                'headers' => array_values(array_unique($headers)),
                'unique_key' => $config['unique_key']
            ];
        }

        return $list;
    }

    /**
     * Get template data (headers and sample row) for a specific table.
     */
    public function getTemplateData(string $table): ?array
    {
        $configs = $this->getImportableConfig();
        if (!isset($configs[$table])) {
            return null;
        }

        $config = $configs[$table];
        $headers = $config['fillable'];
        if (isset($config['dependencies'])) {
            $headers = array_merge($headers, array_keys($config['dependencies']));
        }
        if (isset($config['special_fields'])) {
            $headers = array_merge($headers, $config['special_fields']);
        }
        if (!in_array($config['unique_key'], $headers)) {
            $headers[] = $config['unique_key'];
        }

        $headers = array_values(array_unique($headers));

        // Mock values for sample row
        $sampleData = [
            'name' => 'Sample Name',
            'code' => 'CODE001',
            'description' => 'This is a sample description.',
            'is_active' => '1',
            'country_code' => 'LK',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'group_code' => 'G01',
            'address_line1' => '123 Main St',
            'address_line2' => 'Apt 4B',
            'city' => 'Colombo',
            'postal_code' => '00100',
            'phone_primary' => '+94771234567',
            'phone_secondary' => '+94777654321',
            'phone' => '+94112345678',
            'email' => 'sample@example.com',
            'fax' => '',
            'opening_date' => '01/06/2026',
            'branch_type' => 'main',
            'latitude' => '6.9271',
            'longitude' => '79.8612',
            'is_head_office' => '0',
            'department_code' => 'DEP01',
            'level' => 'mid',
            'order_weight' => '1',
            'f_name' => 'John',
            'l_name' => 'Doe',
            'full_name' => 'John Doe',
            'name_with_initials' => 'J. Doe',
            'employee_code' => 'EMP1001',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951234567V',
            'date_of_birth' => '15/05/1995',
            'address_line_1' => '123 Main St',
            'have_whatsapp' => '1',
            'whatsapp_number' => '+94771234567',
            'start_date' => '01/06/2026',
            'end_date' => '',
            'username' => 'user01',
            'password' => 'password123',
            'user_type' => 'staff',
            'can_login' => '1',
            'role' => 'Super Admin',
            'sort_order' => '1',
            'lead_stage_name' => 'Inquiry / Lead',
            'color_code' => '#3B82F6',
            'is_need_sms' => '0',
            'birthday' => '20/08/1990',
            'preferred_language' => 'english',
            'company' => 'Acme Corp',
            'value' => '5000.00',
            'source' => 'Website',
            'notes' => 'Sample note.',
            'status_name' => 'New Inquiry',
            'creator_username' => 'devadmin',
            'updater_username' => '',
            'title' => 'Sample Title',
            'content' => 'Sample Content text.',
            'target_type' => 'all',
            'target_id' => '',
            'sms' => '0',
            'designation_code' => 'DES01',
            'reporting_manager_code' => '',
        ];

        $sampleRow = [];
        foreach ($headers as $header) {
            $sampleRow[] = $sampleData[$header] ?? '';
        }

        return [
            'filename' => "{$table}_import_template.csv",
            'headers' => $headers,
            'sample' => $sampleRow
        ];
    }
}
