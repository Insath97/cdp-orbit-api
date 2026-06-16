<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStatusRequest;
use App\Http\Requests\UpdateStatusRequest;
use App\Models\Status;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StatusController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Status Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Status Create', ['only' => ['store']]),
            new Middleware('permission:Status Update', ['only' => ['update']]),
            new Middleware('permission:Status Delete', ['only' => ['destroy']]),
            new Middleware('permission:Status Toggle Status', ['only' => ['toggleStatus']]),
        ];
    }

    /**
     * Display a listing of statuses.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Status::query();

            // Apply Search Scope if search parameter is present
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $statuses = $query->orderBy('name', 'asc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Statuses retrieved successfully',
                'data' => $statuses,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve statuses',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created status in storage.
     */
    public function store(CreateStatusRequest $request)
    {
        try {
            $data = $request->validated();
            $status = Status::create($data);

            $this->logActivity('CREATE', 'Status', "Created status: {$status->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Status created successfully',
                'data' => $status,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified status.
     */
    public function show(string $id)
    {
        try {
            $status = Status::find($id);

            if (! $status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Status retrieved successfully',
                'data' => $status,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified status in storage.
     */
    public function update(UpdateStatusRequest $request, string $id)
    {
        try {
            $status = Status::find($id);

            if (! $status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status not found',
                ], 404);
            }

            $data = $request->validated();
            $status->update($data);

            $this->logActivity('UPDATE', 'Status', "Updated status: {$status->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Status updated successfully',
                'data' => $status,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified status from storage.
     */
    public function destroy(string $id)
    {
        try {
            $status = Status::find($id);

            if (! $status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status not found',
                ], 404);
            }

            $statusName = $status->name;
            $status->delete();

            $this->logActivity('DELETE', 'Status', "Deleted status: {$statusName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Status deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a list of all active statuses (lightweight list).
     */
    public function getActiveList()
    {
        try {
            $statuses = Status::active()->orderBy('name', 'asc')->get(['id', 'name', 'color_code']);

            if ($statuses->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No active statuses found',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Active statuses retrieved successfully',
                'data' => $statuses,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve active statuses',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Toggle the active status of a status.
     */
    public function toggleStatus(string $id)
    {
        try {
            $status = Status::find($id);

            if (!$status) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Status not found'
                ], 404);
            }

            $status->is_active = !$status->is_active;
            $status->save();

            $this->logActivity('TOGGLE_STATUS', 'Status', "Toggled status: {$status->name} (" . ($status->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'Status status updated successfully',
                'data' => [
                    'id' => $status->id,
                    'is_active' => $status->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
