<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:Report Hierarchy', ['only' => ['employeeHierarchy']]),
        ];
    }

    /**
     * Display a report of employee hierarchy with total and stage-wise lead counts.
     */
    public function employeeHierarchy(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access'
                ], 401);
            }

            // Fetch active lead stages and their active statuses
            $stages = LeadStage::active()
                ->ordered()
                ->with(['statuses' => function ($q) {
                    $q->active()->ordered();
                }])
                ->get();

            // Determine which employees to include in the report
            if ($user->user_type === 'admin') {
                // Admins see all active/inactive staff users
                $employeesToReport = Employee::whereHas('user', function ($q) {
                    $q->where('user_type', 'staff');
                })->with(['designation', 'user'])->get();
            } else {
                // Staff see their subordinates or themselves if they have none
                $employee = $user->employee;

                if (!$employee) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'The logged-in user does not have an employee profile associated with their account.'
                    ], 404);
                }

                $employeesToReport = Employee::with(['designation', 'user'])
                    ->where('reporting_manager_id', $employee->id)
                    ->get();

                if ($employeesToReport->isEmpty()) {
                    $employeesToReport = collect([$employee]);
                }
            }

            $data = [];

            // Collect user IDs to query lead counts in batch
            $reportUserIds = $employeesToReport->map(function ($emp) {
                return $emp->user?->id;
            })->filter()->toArray();

            $leadsGrouped = collect();
            if (!empty($reportUserIds)) {
                $leadsGrouped = Lead::whereIn('created_by', $reportUserIds)
                    ->select('created_by', 'status_id', DB::raw('count(*) as count'))
                    ->groupBy('created_by', 'status_id')
                    ->get()
                    ->groupBy('created_by');
            }

            foreach ($employeesToReport as $emp) {
                $empUser = $emp->user;
                $userLeads = collect();
                $leadsCount = 0;

                if ($empUser && isset($leadsGrouped[$empUser->id])) {
                    $userLeadsGrouped = $leadsGrouped[$empUser->id];
                    $userLeads = $userLeadsGrouped->pluck('count', 'status_id');
                    $leadsCount = $userLeadsGrouped->sum('count');
                }

                // Format stage-wise details for the employee
                $stageDetails = $stages->map(function ($stage) use ($userLeads) {
                    $stageCount = 0;
                    foreach ($stage->statuses as $status) {
                        $stageCount += $userLeads->get($status->id, 0);
                    }
                    return [
                        'stage_id' => $stage->id,
                        'stage_name' => $stage->name,
                        'leads_count' => (int) $stageCount,
                    ];
                });

                $data[] = [
                    'employee_name' => $emp->full_name,
                    'designation' => $emp->designation?->name,
                    'id_type' => $emp->id_type,
                    'id_number' => $emp->id_number,
                    'employee_code' => $emp->employee_code,
                    'leads_count' => (int) $leadsCount,
                    'stage_details' => $stageDetails->values()->toArray(),
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Employee hierarchy report retrieved successfully',
                'data' => $data,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employee hierarchy report',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
