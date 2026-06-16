<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Requests\ChangeLeadStatusRequest;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class LeadController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Lead Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Lead Create', ['only' => ['store']]),
            new Middleware('permission:Lead Update', ['only' => ['update']]),
            new Middleware('permission:Lead Delete', ['only' => ['destroy']]),
            new Middleware('permission:Lead Change Status', ['only' => ['changeStatus']]),
        ];
    }

    /**
     * Scope query helper to check if a user is allowed to access/modify a specific lead.
     */
    private function checkLeadAccess(Lead $lead): bool
    {
        $user = auth('api')->user();

        // Super Admin or Lead View All bypasses all scoping
        if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Lead View All')) {
            return true;
        }

        // Otherwise, user must own the lead or the lead creator must be one of their descendants
        if ($lead->created_by === $user->id) {
            return true;
        }

        $descendantIds = $user->getAllDescendantIds();
        return in_array($lead->created_by, $descendantIds);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = auth('api')->user();

            $query = Lead::with(['status', 'group', 'creator', 'updater']);

            // Scoping based on permissions and hierarchy
            if (!$user->hasRole('Super Admin') && !$user->hasPermissionTo('Lead View All')) {
                $descendantIds = $user->getAllDescendantIds();
                $allowedUserIds = array_merge([$user->id], $descendantIds);
                $query->whereIn('created_by', $allowedUserIds);
            }

            // Optional search filter
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            // Optional filters
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }

            if ($request->has('group_id')) {
                $query->where('group_id', $request->group_id);
            }

            $leads = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Leads retrieved successfully',
                'data' => $leads,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve leads',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateLeadRequest $request)
    {
        DB::beginTransaction();
        try {
            $user = auth('api')->user();
            $data = $request->validated();

            // Automatically resolve group_id from user's employee branch
            $groupId = $user->employee?->branch?->group_id;

            // Prepare creation data
            $data['group_id'] = $groupId;
            $data['created_by'] = $user->id;

            // Create lead
            $lead = Lead::create($data);

            // Log initial status history
            LeadStatusHistory::create([
                'lead_id' => $lead->id,
                'old_status_id' => null,
                'new_status_id' => $lead->status_id,
                'changed_by' => $user->id,
                'reason' => $data['reason'] ?? 'Lead created',
            ]);

            DB::commit();

            $this->logActivity('CREATE', 'Lead', "Created lead: {$lead->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead created successfully',
                'data' => $lead->load(['status', 'group', 'creator', 'statusHistories.oldStatus', 'statusHistories.newStatus', 'statusHistories.changer']),
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create lead',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $lead = Lead::with(['status', 'group', 'creator', 'updater', 'statusHistories.oldStatus', 'statusHistories.newStatus', 'statusHistories.changer'])->find($id);

            if (!$lead) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead not found',
                ], 404);
            }

            if (!$this->checkLeadAccess($lead)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to this lead',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Lead retrieved successfully',
                'data' => $lead,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lead',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLeadRequest $request, string $id)
    {
        try {
            $lead = Lead::find($id);

            if (!$lead) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead not found',
                ], 404);
            }

            if (!$this->checkLeadAccess($lead)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized update attempt on this lead',
                ], 403);
            }

            $data = $request->validated();
            $data['updated_by'] = auth('api')->id();

            $lead->update($data);

            $this->logActivity('UPDATE', 'Lead', "Updated lead: {$lead->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead updated successfully',
                'data' => $lead->load(['status', 'group', 'creator', 'updater']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update lead',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $lead = Lead::find($id);

            if (!$lead) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead not found',
                ], 404);
            }

            if (!$this->checkLeadAccess($lead)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized delete attempt on this lead',
                ], 403);
            }

            $leadName = $lead->name;
            $lead->delete();

            $this->logActivity('DELETE', 'Lead', "Deleted lead: {$leadName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Lead deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete lead',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Dedicated method to change a lead's status.
     */
    public function changeStatus(ChangeLeadStatusRequest $request, string $id)
    {
        DB::beginTransaction();
        try {
            $lead = Lead::find($id);

            if (!$lead) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lead not found',
                ], 404);
            }

            if (!$this->checkLeadAccess($lead)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized status update attempt on this lead',
                ], 403);
            }

            $data = $request->validated();
            $newStatusId = $data['status_id'];
            $oldStatusId = $lead->status_id;

            // Optional: check if status actually changed
            if ($newStatusId == $oldStatusId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The lead is already in this status.',
                ], 422);
            }

            // Update lead status
            $lead->update([
                'status_id' => $newStatusId,
                'updated_by' => auth('api')->id(),
            ]);

            // Create status history log
            LeadStatusHistory::create([
                'lead_id' => $lead->id,
                'old_status_id' => $oldStatusId,
                'new_status_id' => $newStatusId,
                'changed_by' => auth('api')->id(),
                'reason' => $data['reason'],
            ]);

            DB::commit();

            $this->logActivity('STATUS_CHANGE', 'Lead', "Changed lead status: {$lead->name} from status ID {$oldStatusId} to {$newStatusId}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Lead status updated successfully',
                'data' => $lead->load(['status', 'group', 'creator', 'updater', 'statusHistories.oldStatus', 'statusHistories.newStatus', 'statusHistories.changer']),
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to change lead status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
