<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Mail\UserCreateMail;
use App\Models\Employee;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UserController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show', 'getStaffList']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function getStaffList(Request $request)
    {
        try {
            $users = User::where('user_type', 'staff')
                ->where('is_active', true)
                ->with([
                    'employee' => function ($query) {
                        $query->select([
                            'id',
                            'full_name',
                            'employee_code',
                            'id_type',
                            'id_number',
                            'reporting_manager_id',
                            'province_id',
                            'region_id',
                            'branch_id',
                            'department_id',
                            'designation_id'
                        ])->with([
                            'reportingManager:id,full_name',
                            'province:id,name',
                            'region:id,name',
                            'branch:id,name',
                            'department:id,name',
                            'designation:id,name'
                        ]);
                    }
                ])
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'employee_id']);

            $formattedUsers = $users->map(function ($user) {
                $emp = $user->employee;
                return [
                    'user_id' => $user->id,
                    'username' => $user->name,
                    'employee_id' => $emp?->id,
                    'full_name' => $emp?->full_name,
                    'employee_code' => $emp?->employee_code,
                    'id_type' => $emp?->id_type,
                    'id_number' => $emp?->id_number,
                    'reporting_manager' => $emp?->reportingManager ? [
                        'id' => $emp->reportingManager->id,
                        'full_name' => $emp->reportingManager->full_name
                    ] : null,
                    'province' => $emp?->province ? [
                        'id' => $emp->province->id,
                        'name' => $emp->province->name
                    ] : null,
                    'region' => $emp?->region ? [
                        'id' => $emp->region->id,
                        'name' => $emp->region->name
                    ] : null,
                    'branch' => $emp?->branch ? [
                        'id' => $emp->branch->id,
                        'name' => $emp->branch->name
                    ] : null,
                    'department' => $emp?->department ? [
                        'id' => $emp->department->id,
                        'name' => $emp->department->name
                    ] : null,
                    'designation' => $emp?->designation ? [
                        'id' => $emp->designation->id,
                        'name' => $emp->designation->name
                    ] : null,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Staff list retrieved successfully',
                'data' => $formattedUsers,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve staff list',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $currentUser = auth('api')->user();
            $query = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.designation', 'roles']);

            if ($currentUser->user_type === 'staff') {
                $descendantIds = $currentUser->getAllDescendantIds();
                $query->whereIn('id', $descendantIds);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            if ($request->has('user_type')) {
                $query->where('user_type', $request->user_type);
            }

            if ($request->has('role')) {
                $roleName = $request->role;
                $query->whereHas('roles', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            }

            if ($request->has('branch_id')) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                });
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->where('is_active', true) : $query->where('is_active', false);
            }

            $users = $query->paginate($perPage);

            // Transform data to hide pivot
            $users->getCollection()->transform(function ($user) {
                $userData = $user->toArray();
                if (isset($userData['roles'])) {
                    foreach ($userData['roles'] as &$role) {
                        unset($role['pivot']);
                    }
                }
                return $userData;
            });
            return response()->json([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve users',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function store(CreateUserRequest $request)
    {
        try {
            $currentUser = auth("api")->user();
            $data = $request->validated();

            // Restrict admin user creation to Super Admins only
            if ($data['user_type'] === 'admin') {
                if (!$currentUser || !$currentUser->hasRole('Super Admin')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only Super Admin can create admin users'
                    ], 403);
                }
            }

            // Create Employee first if user_type is staff
            if ($data['user_type'] === 'staff') {
                $employeeData = [
                    'f_name' => $data['f_name'],
                    'l_name' => $data['l_name'],
                    'full_name' => trim($data['f_name'] . ' ' . $data['l_name']),
                    'employee_code' => $data['employee_code'],
                    'id_number' => $data['id_number'],
                    'phone' => $data['phone'] ?? null,
                    'branch_id' => $data['branch_id'] ?? null,
                    'zonal_id' => $data['zonal_id'] ?? null,
                    'region_id' => $data['region_id'] ?? null,
                    'province_id' => $data['province_id'] ?? null,
                    'designation_id' => $data['designation_id'] ?? null,
                    'reporting_manager_id' => $data['reporting_manager_id'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'employee_type' => $data['employee_type'],
                    'id_type' => $data['id_type'],
                    'date_of_birth' => $data['date_of_birth'],
                    'email' => $data['email'],
                    'address_line_1' => $data['address_line_1'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'country' => $data['country'] ?? 'Sri Lanka',
                    'postal_code' => $data['postal_code'] ?? null,
                    'phone_primary' => $data['phone_primary'],
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'have_whatsapp' => $data['have_whatsapp'] ?? false,
                    'whatsapp_number' => $data['whatsapp_number'] ?? null,
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'name_with_initials' => $data['name_with_initials'] ?? null,
                ];

                $employee = Employee::create($employeeData);

                // Set staff credentials automatically to id_number
                $data['employee_id'] = $employee->id;
                $data['username'] = $data['id_number'];
                $data['password'] = Hash::make($data['id_number']);
                $data['name'] = $data['name'] ?? $employeeData['full_name'];
            } else {
                // For admin users
                $data['employee_id'] = null;
                $data['password'] = Hash::make($data['password']);
            }

            $user = User::create($data);

            // Assign Role
            if (isset($data['role'])) {
                $user->assignRole($data['role']);
            }

            // Send Email
            try {
                // Load relationships for enriched email data
                $user->load(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.designation']);

                // Prepare email data
                $emailData = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'user_type' => $user->user_type,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'password' => ($user->user_type === 'staff') ? $data['id_number'] : $request->password,
                    'role' => $data['role'] ?? null,
                    'created_by' => $currentUser ? $currentUser->name : 'System',
                    'login_url' => trim(config('app.frontend_url') ?? config('app.url')),
                ];

                // Only add relationships if they exist
                if ($user->parent) {
                    $emailData['parent_name'] = $user->parent->name;
                }

                if ($user->employee && $user->employee->designation) {
                    $emailData['designation_name'] = $user->employee->designation->name;
                }

                if ($user->branch) {
                    $emailData['branch_name'] = $user->branch->name;
                }

                if ($user->zone) {
                    $emailData['zone_name'] = $user->zone->name;
                }

                if ($user->region) {
                    $emailData['region_name'] = $user->region->name;
                }

                if ($user->province) {
                    $emailData['province_name'] = $user->province->name;
                }

                Mail::to($user->email)->send(new UserCreateMail($emailData));

                $this->logActivity('EMAIL_SENT', 'User', "User login credentials email sent successfully to: {$user->email}", ['user_id' => $user->id]);
            } catch (\Throwable $th) {
                $this->logActivity('EMAIL_FAILED', 'User', "Failed to send user creation email: {$th->getMessage()}", null, 'error');
            }

            $user->load([
                'roles' => function ($q) {
                    $q->select('id', 'name');
                }
            ]);

            $this->logActivity('CREATE', 'User', "Created user: {$user->username}", $data);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $userData
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $currentUser = auth('api')->user();
            $user = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.subordinates.user', 'employee.designation', 'roles'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // If staff, restrict access to self or descendants
            if ($currentUser->user_type === 'staff') {
                if ($user->id !== $currentUser->id && !in_array($user->id, $currentUser->getAllDescendantIds())) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized access to this user profile'
                    ], 403);
                }
            }

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User retrieved successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        try {
            $currentUser = auth("api")->user();
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $data = $request->validated();

            // Restrict admin user update to Super Admins only (both updating an existing admin or changing user_type to admin)
            $isTargetingAdmin = ($user->user_type === 'admin') || (isset($data['user_type']) && $data['user_type'] === 'admin');
            if ($isTargetingAdmin) {
                if (!$currentUser || !$currentUser->hasRole('Super Admin')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only Super Admin can manage admin users'
                    ], 403);
                }
            }

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Handle user_type logic and employee updates
            $targetUserType = $data['user_type'] ?? $user->user_type;

            if ($targetUserType === 'staff') {
                $employeeData = array_intersect_key($data, array_flip([
                    'f_name',
                    'l_name',
                    'employee_code',
                    'id_number',
                    'phone',
                    'branch_id',
                    'zonal_id',
                    'region_id',
                    'province_id',
                    'designation_id',
                    'reporting_manager_id',
                    'department_id',
                    'employee_type',
                    'id_type',
                    'date_of_birth',
                    'email',
                    'address_line_1',
                    'city',
                    'state',
                    'country',
                    'postal_code',
                    'phone_primary',
                    'phone_secondary',
                    'have_whatsapp',
                    'whatsapp_number',
                    'start_date',
                    'end_date',
                    'name_with_initials'
                ]));

                if ($user->employee) {
                    // Update existing employee
                    if (isset($employeeData['f_name']) || isset($employeeData['l_name'])) {
                        $fName = $employeeData['f_name'] ?? $user->employee->f_name;
                        $lName = $employeeData['l_name'] ?? $user->employee->l_name;
                        $employeeData['full_name'] = trim($fName . ' ' . $lName);
                    }
                    $user->employee->update($employeeData);
                } else {
                    // Create new employee if they were previously an admin
                    $fName = $employeeData['f_name'] ?? '';
                    $lName = $employeeData['l_name'] ?? '';
                    $employeeData['full_name'] = trim($fName . ' ' . $lName);
                    if (!isset($employeeData['country'])) {
                        $employeeData['country'] = 'Sri Lanka';
                    }
                    if (!isset($employeeData['have_whatsapp'])) {
                        $employeeData['have_whatsapp'] = false;
                    }
                    $employee = \App\Models\Employee::create($employeeData);
                    $data['employee_id'] = $employee->id;
                }

                // If id_number is updated, sync username
                if (isset($data['id_number'])) {
                    $data['username'] = $data['id_number'];
                }

                // If f_name/l_name is updated, sync user name
                if (isset($employeeData['f_name']) || isset($employeeData['l_name'])) {
                    $data['name'] = $data['name'] ?? trim(($employeeData['f_name'] ?? $user->employee?->f_name ?? '') . ' ' . ($employeeData['l_name'] ?? $user->employee?->l_name ?? ''));
                }
            } else {
                // If changing from staff to admin, detach and delete the old employee record
                if ($user->employee) {
                    $employee = $user->employee;
                    $user->update(['employee_id' => null]);
                    $employee->delete();
                }
                $data['employee_id'] = null;
            }

            $user->update($data);

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            $user->refresh();
            $user->load([
                'employee.branch',
                'employee.zonal',
                'employee.region',
                'employee.province',
                'employee.reportingManager.user',
                'employee.designation',
                'roles' => function ($q) {
                    $q->select('id', 'name');
                }
            ]);

            $this->logActivity('UPDATE', 'User', "Updated user: {$user->username}", $data);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                $this->logActivity('UNAUTHORIZED_DELETE', 'User', "Unauthorized user deletion attempt on ID: {$id}", ['target_user_id' => $id], 'warning');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete users'
                ], 403);
            }

            // Prevent self-deletion
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot delete your own account'
                ], 422);
            }

            $username = $user->username;
            $employee = $user->employee;

            $user->delete();

            // Cascade delete employee record
            if ($employee) {
                $employee->delete();
            }

            $this->logActivity('DELETE', 'User', "Deleted user: {$username}");

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent self-deactivation
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot deactivate your own account'
                ], 422);
            }

            $user->is_active = !$user->is_active;
            $user->save();

            $this->logActivity('TOGGLE_STATUS', 'User', "Toggled user status: {$user->username} (" . ($user->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'is_active' => $user->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle user status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate user accounts for all employees who do not have one.
     */
    public function generateFromEmployees(Request $request)
    {
        try {
            $currentUser = auth("api")->user();

            // Only allow Super Admins to run this bulk generator
            if (!$currentUser || !$currentUser->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can execute this action.'
                ], 403);
            }

            // Get all employees who do not have an associated user account
            $employees = Employee::whereDoesntHave('user')->get();
            $generatedCount = 0;
            $errors = [];

            foreach ($employees as $employee) {
                if (empty($employee->employee_code)) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'name' => $employee->full_name,
                        'error' => 'Missing employee code.'
                    ];
                    continue;
                }

                if (empty($employee->email)) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'name' => $employee->full_name,
                        'error' => 'Missing email address.'
                    ];
                    continue;
                }

                // Check for duplicate username or email in users table
                if (User::where('username', $employee->employee_code)->exists()) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'name' => $employee->full_name,
                        'error' => "Username '{$employee->employee_code}' already exists."
                    ];
                    continue;
                }

                if (User::where('email', $employee->email)->exists()) {
                    $errors[] = [
                        'employee_id' => $employee->id,
                        'name' => $employee->full_name,
                        'error' => "Email '{$employee->email}' already exists."
                    ];
                    continue;
                }

                // Create User
                $user = User::create([
                    'name' => $employee->full_name,
                    'username' => $employee->employee_code,
                    'email' => $employee->email,
                    'password' => Hash::make('cdp@2026'),
                    'user_type' => 'staff',
                    'employee_id' => $employee->id,
                    'is_active' => true,
                    'can_login' => true,
                ]);

                // Assign CDP Employee Role (ensuring role exists first)
                try {
                    $user->assignRole('CDP Employee');
                } catch (\Throwable $roleEx) {
                    \Spatie\Permission\Models\Role::firstOrCreate(['guard_name' => 'api', 'name' => 'CDP Employee']);
                    $user->assignRole('CDP Employee');
                }

                $generatedCount++;
            }

            $this->logActivity('BULK_GENERATE_USERS', 'User', "Generated {$generatedCount} user accounts from employees.");

            return response()->json([
                'status' => 'success',
                'message' => "Successfully generated {$generatedCount} user accounts for employees.",
                'data' => [
                    'generated_count' => $generatedCount,
                    'skipped' => count($errors),
                    'errors' => $errors
                ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate user accounts',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
