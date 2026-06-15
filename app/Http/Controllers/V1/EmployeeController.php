<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\Designation;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class EmployeeController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Employee Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Employee Create', ['only' => ['store']]),
            new Middleware('permission:Employee Update', ['only' => ['update']]),
            new Middleware('permission:Employee Delete', ['only' => ['destroy']]),
        ];
    }

    /**
     * Display a listing of employees.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Employee::with([
                'reportingManager:id,full_name,employee_code',
                'province:id,name',
                'region:id,name',
                'zonal:id,name',
                'branch:id,name',
                'department:id,name',
                'designation:id,name,level,order_weight'
            ]);

            // Apply Search Scope if search parameter is present
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->has('designation_id')) {
                $query->where('designation_id', $request->designation_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $employees = $query->orderBy('full_name', 'asc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => $employees,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created employee in storage.
     */
    public function store(CreateEmployeeRequest $request)
    {
        try {
            $data = $request->validated();

            // Validate manager hierarchy order weight
            $hierarchyError = $this->validateManagerHierarchy($data['designation_id'], $data['reporting_manager_id'] ?? null);
            if ($hierarchyError) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy validation failed',
                    'errors' => [
                        [
                            'field' => 'reporting_manager_id',
                            'messages' => [$hierarchyError]
                        ]
                    ]
                ], 422);
            }
            
            // Automatically assign employee code if not provided
            if (empty($data['employee_code'])) {
                $count = Employee::count() + 1;
                $data['employee_code'] = 'EMP-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            }

            $employee = Employee::create($data);

            $this->logActivity('CREATE', 'Employee', "Created employee: {$employee->full_name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee created successfully',
                'data' => $employee->load([
                    'reportingManager:id,full_name,employee_code',
                    'province:id,name',
                    'region:id,name',
                    'zonal:id,name',
                    'branch:id,name',
                    'department:id,name',
                    'designation:id,name,level,order_weight'
                ]),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create employee',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified employee.
     */
    public function show(string $id)
    {
        try {
            $employee = Employee::with([
                'reportingManager:id,full_name,employee_code',
                'province:id,name',
                'region:id,name',
                'zonal:id,name',
                'branch:id,name',
                'department:id,name',
                'designation:id,name,level,order_weight',
                'subordinates:id,full_name,employee_code,reporting_manager_id',
                'user:id,name,username,email'
            ])->find($id);

            if (! $employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Employee retrieved successfully',
                'data' => $employee,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employee',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified employee in storage.
     */
    public function update(UpdateEmployeeRequest $request, string $id)
    {
        try {
            $employee = Employee::find($id);

            if (! $employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found',
                ], 404);
            }

            $data = $request->validated();

            // Resolve final values for validation (fallback to existing if not updated)
            $designationId = $data['designation_id'] ?? $employee->designation_id;
            $reportingManagerId = array_key_exists('reporting_manager_id', $data) ? $data['reporting_manager_id'] : $employee->reporting_manager_id;
            
            if ($reportingManagerId == $employee->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy validation failed',
                    'errors' => [
                        [
                            'field' => 'reporting_manager_id',
                            'messages' => ['An employee cannot report to themselves.']
                        ]
                    ]
                ], 422);
            }

            $hierarchyError = $this->validateManagerHierarchy($designationId, $reportingManagerId);
            if ($hierarchyError) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hierarchy validation failed',
                    'errors' => [
                        [
                            'field' => 'reporting_manager_id',
                            'messages' => [$hierarchyError]
                        ]
                    ]
                ], 422);
            }

            $employee->update($data);



            $this->logActivity('UPDATE', 'Employee', "Updated employee: {$employee->full_name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Employee updated successfully',
                'data' => $employee->load([
                    'reportingManager:id,full_name,employee_code',
                    'province:id,name',
                    'region:id,name',
                    'zonal:id,name',
                    'branch:id,name',
                    'department:id,name',
                    'designation:id,name,level,order_weight'
                ]),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update employee',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified employee from storage.
     */
    public function destroy(string $id)
    {
        try {
            $employee = Employee::find($id);

            if (! $employee) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Employee not found',
                ], 404);
            }

            $employeeName = $employee->full_name;
            $employee->delete();

            $this->logActivity('DELETE', 'Employee', "Deleted employee: {$employeeName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Employee deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete employee',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a list of employees (lightweight list).
     */
    public function getEmployeeList(Request $request)
    {
        try {
            $query = Employee::active();

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            $employees = $query->orderBy('full_name', 'asc')->get(['id', 'full_name', 'employee_code', 'email']);

            return response()->json([
                'status' => 'success',
                'message' => 'Employees retrieved successfully',
                'data' => $employees,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve employees',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Validate that the reporting manager's designation has a higher order weight.
     *
     * @param int $designationId
     * @param int|null $reportingManagerId
     * @return string|null Error message or null if valid
     */
    protected function validateManagerHierarchy(int $designationId, ?int $reportingManagerId): ?string
    {
        if (empty($reportingManagerId)) {
            return null;
        }

        $manager = Employee::with('designation')->find($reportingManagerId);
        if (!$manager) {
            return 'Reporting manager not found.';
        }

        $designation = Designation::find($designationId);
        if (!$designation) {
            return 'Designation not found.';
        }

        $managerWeight = $manager->designation ? $manager->designation->order_weight : 0;
        $employeeWeight = $designation->order_weight;

        if ($managerWeight <= $employeeWeight) {
            return "The reporting manager's designation weight ({$managerWeight}) must be higher than the employee's designation weight ({$employeeWeight}).";
        }

        return null;
    }
}
