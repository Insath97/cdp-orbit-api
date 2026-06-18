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
            new Middleware('permission:Status Index', ['only' => ['index', 'show', 'getActiveList']]),
            new Middleware('permission:Status Create', ['only' => ['store']]),
            new Middleware('permission:Status Update', ['only' => ['update', 'reorder']]),
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
            $query = Status::with(['leadStage', 'smsTemplate']);

            // Apply Search Scope if search parameter is present
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('lead_stage_id')) {
                $query->where('lead_stage_id', $request->lead_stage_id);
            }

            $statuses = $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc')->paginate($perPage);

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
            
            // Assign next sort_order if not provided
            if (!isset($data['sort_order'])) {
                $leadStageId = $data['lead_stage_id'] ?? null;
                $data['sort_order'] = Status::where('lead_stage_id', $leadStageId)->max('sort_order') + 1;
            }

            $status = Status::create($data);

            $this->logActivity('CREATE', 'Status', "Created status: {$status->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Status created successfully',
                'data' => $status->load(['leadStage', 'smsTemplate']),
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
            $status = Status::with(['leadStage', 'smsTemplate'])->find($id);

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
                'data' => $status->load(['leadStage', 'smsTemplate']),
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
            $statuses = Status::active()
                ->ordered()
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'lead_stage_id', 'color_code', 'sort_order']);

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

    /**
     * Reorder statuses in bulk (usually under a specific stage).
     */
    public function reorder(\App\Http\Requests\ReorderStatusesRequest $request)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $data = $request->validated();
            
            foreach ($data['statuses'] as $statusItem) {
                Status::where('id', $statusItem['id'])->update([
                    'sort_order' => $statusItem['sort_order']
                ]);
            }

            \Illuminate\Support\Facades\DB::commit();

            $this->logActivity('REORDER', 'Status', "Bulk reordered statuses", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Statuses reordered successfully'
            ], 200);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reorder statuses',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
