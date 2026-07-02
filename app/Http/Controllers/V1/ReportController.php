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

            $leadsGrouped = collect();

            if ($user->user_type === 'admin') {
                // Admins see all active/inactive staff users
                $allEmployees = Employee::whereHas('user', function ($q) {
                    $q->where('user_type', 'staff');
                })->with(['designation', 'user'])->get();

                $reportUserIds = $allEmployees->map(fn($emp) => $emp->user?->id)->filter()->toArray();

                if (!empty($reportUserIds)) {
                    $leadsGrouped = Lead::whereIn('created_by', $reportUserIds)
                        ->select('created_by', 'status_id', DB::raw('count(*) as count'))
                        ->groupBy('created_by', 'status_id')
                        ->get()
                        ->groupBy('created_by');
                }

                $employeesGroupedByManager = $allEmployees->groupBy('reporting_manager_id');
                $employeeIds = $allEmployees->pluck('id')->toArray();

                // Roots are those who have no manager, or their manager is not in the staff list
                $roots = $allEmployees->filter(function ($emp) use ($employeeIds) {
                    return is_null($emp->reporting_manager_id) || !in_array($emp->reporting_manager_id, $employeeIds);
                });

                $data = [];
                foreach ($roots as $root) {
                    $data[] = $this->buildHierarchyTreeInMemory($root, $employeesGroupedByManager, $leadsGrouped, $stages);
                }

            } else {
                // Staff see their subordinates tree
                $employee = $user->employee;

                if (!$employee) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'The logged-in user does not have an employee profile associated with their account.'
                    ], 404);
                }

                // Roots are the direct subordinates of the logged-in user
                $roots = Employee::where('reporting_manager_id', $employee->id)->with(['designation', 'user'])->get();

                // If they have no subordinates, fall back to showing themselves as the single root node
                if ($roots->isEmpty()) {
                    $roots = collect([$employee]);
                }

                // Query all descendants (and themselves) to get all user IDs for lead counts
                $descendants = $employee->getAllDescendantEmployees();
                $allEmployees = collect([$employee])->merge($descendants);

                $reportUserIds = $allEmployees->map(fn($emp) => $emp->user?->id)->filter()->toArray();

                if (!empty($reportUserIds)) {
                    $leadsGrouped = Lead::whereIn('created_by', $reportUserIds)
                        ->select('created_by', 'status_id', DB::raw('count(*) as count'))
                        ->groupBy('created_by', 'status_id')
                        ->get()
                        ->groupBy('created_by');
                }

                $employeesGroupedByManager = $allEmployees->groupBy('reporting_manager_id');

                $data = [];
                foreach ($roots as $root) {
                    $data[] = $this->buildHierarchyTreeInMemory($root, $employeesGroupedByManager, $leadsGrouped, $stages);
                }
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

    /**
     * Recursively build the hierarchy tree in-memory to prevent N+1 queries.
     */
    private function buildHierarchyTreeInMemory($employee, $employeesGroupedByManager, $leadsGrouped, $stages): array
    {
        $empUser = $employee->user;
        $userLeads = collect();
        $leadsCount = 0;

        if ($empUser && isset($leadsGrouped[$empUser->id])) {
            $userLeadsGrouped = $leadsGrouped[$empUser->id];
            $userLeads = $userLeadsGrouped->pluck('count', 'status_id');
            $leadsCount = $userLeadsGrouped->sum('count');
        }

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

        // Resolve subordinates from pre-loaded memory collection
        $subordinates = $employeesGroupedByManager->get($employee->id, collect());
        $subordinatesData = [];
        foreach ($subordinates as $subordinate) {
            $subordinatesData[] = $this->buildHierarchyTreeInMemory($subordinate, $employeesGroupedByManager, $leadsGrouped, $stages);
        }

        return [
            'employee_name' => $employee->full_name ?: (trim($employee->f_name . ' ' . $employee->l_name) ?: ($employee->name_with_initials ?: 'Unnamed Employee')),
            'designation' => $employee->designation?->name,
            'id_type' => $employee->id_type,
            'id_number' => $employee->id_number,
            'employee_code' => $employee->employee_code,
            'leads_count' => (int) $leadsCount,
            'stage_details' => $stageDetails->values()->toArray(),
            'subordinates' => $subordinatesData,
        ];
    }
}
