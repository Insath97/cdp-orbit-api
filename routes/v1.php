<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\EmployeeController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\ZonalController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\DesignationController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\GroupController;
use Illuminate\Support\Facades\Route;

/* public routes */

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('permissions/list', [PermissionController::class, 'getPermissionList']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);

    // Countries
    Route::get('countries/list', [CountryController::class, 'getActiveList']);
    Route::apiResource('countries', CountryController::class);

    // Provinces
    Route::get('provinces/list', [ProvinceController::class, 'getProvinceList']);
    Route::apiResource('provinces', ProvinceController::class);

    // Zonals (Zones)
    Route::get('zonals/list', [ZonalController::class, 'getZonalList']);
    Route::apiResource('zonals', ZonalController::class);

    // Regions
    Route::get('regions/list', [RegionController::class, 'getRegionList']);
    Route::apiResource('regions', RegionController::class);

    // Branches
    Route::patch('branches/{id}/toggle-status', [BranchController::class, 'toggleStatus']);
    Route::get('branches/list', [BranchController::class, 'getBranchList']);
    Route::apiResource('branches', BranchController::class);

    // Departments
    Route::get('departments/list', [DepartmentController::class, 'getDepartmentList']);
    Route::apiResource('departments', DepartmentController::class);

    // Designations
    Route::get('designations/list', [DesignationController::class, 'getDesignationList']);
    Route::apiResource('designations', DesignationController::class);

    // Employees
    Route::get('employees/list', [EmployeeController::class, 'getEmployeeList']);
    Route::apiResource('employees', EmployeeController::class);

    // Groups
    Route::get('groups/list', [GroupController::class, 'getActiveList']);
    Route::apiResource('groups', GroupController::class);
});
