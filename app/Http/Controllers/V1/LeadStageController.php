<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeadStageRequest;
use App\Http\Requests\UpdateLeadStageRequest;
use App\Http\Requests\ReorderLeadStagesRequest;
use App\Models\LeadStage;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class LeadStageController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:LeadStage Index', ['only' => ['index', 'show', 'getStageList']]),
            new Middleware('permission:LeadStage Create', ['only' => ['store']]),
            new Middleware('permission:LeadStage Update', ['only' => ['update']]),
            new Middleware('permission:LeadStage Delete', ['only' => ['destroy']]),
            new Middleware('permission:LeadStage Toggle Status', ['only' => ['toggleStatus']]),
            new Middleware('permission:LeadStage Reorder', ['only' => ['reorder']]),
        ];
    }

    /**
     * Display a listing of lead stages.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = LeadStage::with(['statuses']);

            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $stages = $query->orderBy('sort_order', 'asc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stages retrieved successfully',
                'data' => $stages,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lead stages',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created lead stage in storage.
     */
    public function store(CreateLeadStageRequest $request)
    {
        try {
            $data = $request->validated();
            
            // If sort_order not provided, assign to end
            if (!isset($data['sort_order'])) {
                $data['sort_order'] = LeadStage::max('sort_order') + 1;
            }

            $stage = LeadStage::create($data);

            $this->logActivity('CREATE', 'LeadStage', "Created lead stage: {$stage->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stage created successfully',
                'data' => $stage,
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create lead stage',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified lead stage.
     */
    public function show(string $id)
    {
        try {
            $stage = LeadStage::with(['statuses'])->find($id);

            if (!$stage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead stage not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stage retrieved successfully',
                'data' => $stage,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lead stage',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified lead stage in storage.
     */
    public function update(UpdateLeadStageRequest $request, string $id)
    {
        try {
            $stage = LeadStage::find($id);

            if (!$stage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead stage not found',
                ], 404);
            }

            $data = $request->validated();
            $stage->update($data);

            $this->logActivity('UPDATE', 'LeadStage', "Updated lead stage: {$stage->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stage updated successfully',
                'data' => $stage,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update lead stage',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified lead stage from storage.
     */
    public function destroy(string $id)
    {
        try {
            $stage = LeadStage::find($id);

            if (!$stage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead stage not found',
                ], 404);
            }

            $stageName = $stage->name;
            $stage->delete();

            $this->logActivity('DELETE', 'LeadStage', "Deleted lead stage: {$stageName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stage deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete lead stage',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Toggle the active status of a lead stage.
     */
    public function toggleStatus(string $id)
    {
        try {
            $stage = LeadStage::find($id);

            if (!$stage) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead stage not found'
                ], 404);
            }

            $stage->is_active = !$stage->is_active;
            $stage->save();

            $this->logActivity('TOGGLE_STATUS', 'LeadStage', "Toggled lead stage: {$stage->name} (" . ($stage->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stage status updated successfully',
                'data' => [
                    'id' => $stage->id,
                    'is_active' => $stage->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle lead stage status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Reorder lead stages in bulk.
     */
    public function reorder(ReorderLeadStagesRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            
            foreach ($data['stages'] as $stageItem) {
                LeadStage::where('id', $stageItem['id'])->update([
                    'sort_order' => $stageItem['sort_order']
                ]);
            }

            DB::commit();

            $this->logActivity('REORDER', 'LeadStage', "Bulk reordered lead stages", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead stages reordered successfully'
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reorder lead stages',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get a lightweight ordered list of active lead stages with their active statuses.
     */
    public function getStageList()
    {
        try {
            $stages = LeadStage::active()
                ->ordered()
                ->with(['statuses' => function ($query) {
                    $query->active()->ordered();
                }])
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Active lead stages and statuses retrieved successfully',
                'data' => $stages,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lead stage list',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
