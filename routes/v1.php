<?php

use App\Http\Controllers\V1\AnnouncementController;
use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\CampaignController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\DesignationController;
use App\Http\Controllers\V1\GroupController;
use App\Http\Controllers\V1\LeadController;
use App\Http\Controllers\V1\LeadStageController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\StatusController;
use App\Http\Controllers\V1\SmsController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\ZonalController;
use App\Http\Controllers\V1\SmsTemplateController;
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

    Route::get('users/staff-list', [UserController::class, 'getStaffList']);
    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);

    // Countries
    Route::apiResource('countries', CountryController::class);
    Route::prefix('countries')->group(function () {
        Route::patch('{id}/toggle-status', [CountryController::class, 'toggleStatus']);
        Route::get('list', [CountryController::class, 'getActiveList']);
    });

    // Provinces
    Route::prefix('provinces')->group(function () {
        Route::patch('{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);
        Route::get('list', [ProvinceController::class, 'getProvinceList']);
    });
    Route::apiResource('provinces', ProvinceController::class);

    // Zonals (Zones)
    Route::prefix('zonals')->group(function () {
        Route::patch('{id}/toggle-status', [ZonalController::class, 'toggleStatus']);
        Route::get('list', [ZonalController::class, 'getZonalList']);
    });
    Route::apiResource('zonals', ZonalController::class);

    // Regions
    Route::prefix('regions')->group(function () {
        Route::patch('{id}/toggle-status', [RegionController::class, 'toggleStatus']);
        Route::get('list', [RegionController::class, 'getRegionList']);
    });
    Route::apiResource('regions', RegionController::class);

    // Branches
      Route::prefix('branches')->group(function () {
        Route::patch('{id}/toggle-status', [BranchController::class, 'toggleStatus']);
        Route::get('list', [BranchController::class, 'getBranchList']);
    });
    Route::apiResource('branches', BranchController::class);

    // Departments
     Route::prefix('departments')->group(function () {
        Route::get('list', [DepartmentController::class, 'getDepartmentList']);
        Route::patch('{id}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });
    Route::apiResource('departments', DepartmentController::class);


    // Designations
     Route::prefix('designations')->group(function () {
        Route::get('list', [DesignationController::class, 'getDesignationList']);
        Route::patch('{id}/toggle-status', [DesignationController::class, 'toggleStatus']);
    });
    Route::apiResource('designations', DesignationController::class);

    // Groups
    Route::prefix('groups')->group(function () {
        Route::get('list', [GroupController::class, 'getActiveList']);
        Route::patch('{id}/toggle-status', [GroupController::class, 'toggleStatus']);
    });
    Route::apiResource('groups', GroupController::class);

    // Statuses
    Route::prefix('statuses')->group(function () {
        Route::get('list', [StatusController::class, 'getActiveList']);
        Route::patch('{id}/toggle-status', [StatusController::class, 'toggleStatus']);
        Route::post('reorder', [StatusController::class, 'reorder']);
    });
    Route::apiResource('statuses', StatusController::class);

    // Lead Stages
    Route::prefix('lead-stages')->group(function () {
        Route::get('list', [LeadStageController::class, 'getStageList']);
        Route::patch('{id}/toggle-status', [LeadStageController::class, 'toggleStatus']);
        Route::post('reorder', [LeadStageController::class, 'reorder']);
    });
    Route::apiResource('lead-stages', LeadStageController::class);

    // Leads
    Route::get('leads/metrics', [LeadController::class, 'getMetrics']);
    Route::patch('leads/{id}/change-status', [LeadController::class, 'changeStatus']);
    Route::apiResource('leads', LeadController::class);

    // Announcements
    Route::patch('announcements/{id}/toggle-status', [AnnouncementController::class, 'toggleStatus']);
    Route::apiResource('announcements', AnnouncementController::class);

    // Campaigns
    Route::patch('campaigns/{id}/toggle-status', [CampaignController::class, 'toggleStatus']);
    Route::apiResource('campaigns', CampaignController::class);

    // SMS routes
    Route::get('sms/logs', [SmsController::class, 'logs']);
    Route::post('sms/send', [SmsController::class, 'send']);
    Route::post('sms/send-to-all', [SmsController::class, 'sendToAllLeads']);
    Route::apiResource('sms-templates', SmsTemplateController::class);

});
