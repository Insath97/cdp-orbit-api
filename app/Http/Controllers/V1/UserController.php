<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Mail\UserCreateMail;
use App\Models\User;
use App\Traits\FileUploadTrait;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Models\Role;

class UserController extends Controller implements HasMiddleware
{
    use FileUploadTrait, ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.designation', 'roles']);

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

            // Hash password
            $rawPassword = $data['password'];
            $data['password'] = Hash::make($rawPassword);

            // For non-staff users, ensure employee_id is null
            if ($data['user_type'] !== 'staff') {
                $data['employee_id'] = null;
            }

            // Handle Profile Image
            $imagePath = $this->handleFileUpload($request, 'profile_image', null, 'users/profile', $data['email']);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
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

                // Prepare email data - ONLY include existing data, no N/A
                $emailData = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'user_type' => $user->user_type,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'password' => $rawPassword,
                    'role' => $data['role'] ?? null,
                    'created_by' => $currentUser ? $currentUser->name : 'System',
                    'login_url' => config('app.frontend_url') ?? config('app.url'),
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
            if (isset($imagePath)) {
                $this->deleteFile($imagePath);
            }

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
            $user = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.subordinates.user', 'employee.designation', 'roles'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
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
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $data = $request->validated();

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // For non-staff users, ensure employee_id is null
            if (isset($data['user_type']) && $data['user_type'] !== 'staff') {
                $data['employee_id'] = null;
            }

            // Handle Image Upload
            $imagePath = $this->handleFileUpload($request, 'profile_image', $user->profile_image, 'users/profile', $user->email);
            if ($imagePath) {
                $data['profile_image'] = $imagePath;
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
            $this->deleteFile($user->profile_image);
            $user->delete();

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
}
