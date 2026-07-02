<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Http\Requests\UpdateProfileRequest;
use App\Traits\ActivityLogTrait;

class AuthController extends Controller
{
    use ActivityLogTrait;
      /**
     * Admin Login
     * Only users with user_type = 'admin' can login here
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'login' => 'required|string',
                'password' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $loginVal = $request->input('login');
            $passwordVal = $request->input('password');

            // Find user by username or email
            $user = User::where('username', $loginVal)
                ->orWhere('email', $loginVal)
                ->first();

            // Fallback: search employee by id_number
            if (!$user) {
                $employee = \App\Models\Employee::where('id_number', $loginVal)->first();
                if ($employee) {
                    $user = User::where('employee_id', $employee->id)->first();
                }
            }

            if (!$user || !Hash::check($passwordVal, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials'
                ], 401);
            }

            /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
            $guard = Auth::guard('api');
            $token = $guard->login($user);
            $user = auth('api')->user();

            if (!$user->canLogin()) {
                Auth::guard('api')->logout();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Account is deactivated'
                ], 401);
            }

            if (!$user->roles()->exists()) {
                Auth::guard('api')->logout();
                return response()->json([
                    'success' => false,
                    'message' => 'No admin role assigned. Please contact Super Admin.'
                ], 403);
            }

            $user->updateLastLogin($request->ip());

            $cookie = cookie(
                'auth_token',
                $token,
                60 * 24 * 7,
                '/',
                null,
                true,  // Secure
                true,  // HttpOnly
                false,
                'lax'
            );

            $user->load(['roles' => function ($query) {
                $query->select('id', 'name')
                    ->with(['permissions' => function ($query) {
                        $query->select('id', 'name');
                    }]);
            }]);

            if ($user->relationLoaded('roles')) {
                $user->roles->each->makeHidden(['pivot']);
                $user->roles->each(function ($role) {
                    if ($role->relationLoaded('permissions')) {
                        $role->permissions->each->makeHidden(['pivot']);
                    }
                });
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => $user,
                    'auth_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60
                ]
            ], 200)->cookie($cookie);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to login',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Admin Logout
     */
    public function logout(Request $request)
    {
        try {
            // Logout the user (invalidates the token)
            Auth::guard('api')->logout();

            // Create an expired cookie to remove it from browser
            $cookie = Cookie::forget('auth_token');

            return response()->json([
                'status' => 'success',
                'message' => 'Logout successful'
            ], 200)->withCookie($cookie);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to logout',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated admin user
     */
    public function me()
    {
        try {
            $user = auth('api')->user();

            $user->load(['roles' => function ($query) {
                $query->select('id', 'name')
                    ->with(['permissions' => function ($query) {
                        $query->select('id', 'name');
                    }]);
            }]);

            if ($user->relationLoaded('roles')) {
                $user->roles->each->makeHidden(['pivot']);
                $user->roles->each(function ($role) {
                    if ($role->relationLoaded('permissions')) {
                        $role->permissions->each->makeHidden(['pivot']);
                    }
                });
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User details fetched successfully',
                'data' => [
                    'user' => $user
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user details',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update authenticated user's profile details and/or password.
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        try {
            /** @var \App\Models\User $user */
            $user = auth('api')->user();

            $validatedData = $request->validated();
            $userData = [];

            // 1. Password Verification and Change
            if ($request->has('password')) {
                if (!Hash::check($request->input('current_password'), $user->password)) {
                    return response()->json([
                        'message' => 'The provided current password does not match our records.',
                        'errors' => [
                            [
                                'field' => 'current_password',
                                'messages' => ['The provided current password does not match our records.']
                            ]
                        ]
                    ], 422);
                }
                $userData['password'] = Hash::make($request->input('password'));
            }

            // 2. Email Verification and Change
            if ($request->has('email')) {
                $userData['email'] = $request->input('email');
            }

            // 3. Name Change
            if ($request->has('name')) {
                $userData['name'] = $request->input('name');
            }

            // 4. Handle Staff-Specific (Employee) Profile Fields
            if ($user->user_type === 'staff') {
                $employeeFields = [
                    'f_name',
                    'l_name',
                    'phone',
                    'phone_primary',
                    'phone_secondary',
                    'have_whatsapp',
                    'whatsapp_number',
                    'date_of_birth',
                    'address_line_1',
                    'city',
                    'state',
                    'country',
                    'postal_code',
                    'name_with_initials'
                ];

                $employeeData = array_intersect_key($validatedData, array_flip($employeeFields));

                if ($user->employee) {
                    // Update full_name if first or last name changes
                    if (isset($employeeData['f_name']) || isset($employeeData['l_name'])) {
                        $fName = $employeeData['f_name'] ?? $user->employee->f_name;
                        $lName = $employeeData['l_name'] ?? $user->employee->l_name;
                        $employeeData['full_name'] = trim($fName . ' ' . $lName);
                        
                        // Sync User model's name to the employee full name, unless user explicitly provided name
                        if (!isset($userData['name'])) {
                            $userData['name'] = $employeeData['full_name'];
                        }
                    }

                    // Sync email if updated
                    if (isset($userData['email'])) {
                        $employeeData['email'] = $userData['email'];
                    }

                    $user->employee->update($employeeData);
                }
            }

            // 5. Update User model
            if (!empty($userData)) {
                $user->update($userData);
            }

            $user->refresh();

            // Load same relationships as in the me() method
            $user->load(['roles' => function ($query) {
                $query->select('id', 'name')
                    ->with(['permissions' => function ($query) {
                        $query->select('id', 'name');
                    }]);
            }]);

            if ($user->relationLoaded('roles')) {
                $user->roles->each->makeHidden(['pivot']);
                $user->roles->each(function ($role) {
                    if ($role->relationLoaded('permissions')) {
                        $role->permissions->each->makeHidden(['pivot']);
                    }
                });
            }

            // Log activity
            $this->logActivity('UPDATE_PROFILE', 'User', "User {$user->username} updated their own profile details.");

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user
                ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update profile',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
