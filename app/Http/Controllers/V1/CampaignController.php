<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Events\CampaignCreated;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CampaignController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Campaign Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Campaign Create', ['only' => ['store']]),
            new Middleware('permission:Campaign Update', ['only' => ['update']]),
            new Middleware('permission:Campaign Delete', ['only' => ['destroy']]),
            new Middleware('permission:Campaign Toggle Status', ['only' => ['toggleStatus']]),
        ];
    }

    /**
     * Helper to verify if the user has access to a specific campaign.
     */
    private function checkCampaignAccess(Campaign $campaign): bool
    {
        $user = auth('api')->user();

        // Super Admin or Campaign View All has access to everything
        if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Campaign View All')) {
            return true;
        }

        // Normal staff can only view active campaigns
        $today = now()->toDateString();
        return $campaign->is_active &&
            $campaign->start_date?->toDateString() <= $today &&
            $campaign->end_date?->toDateString() >= $today;
    }

    /**
     * Display a listing of campaigns.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = auth('api')->user();

            $query = Campaign::with(['creator', 'updater']);

            // Scoping based on role/permissions
            if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Campaign View All')) {
                // If has administrative access, can filter by activity status
                if ($request->has('is_active')) {
                    $query->where('is_active', $request->boolean('is_active'));
                }
            } else {
                // Otherwise apply target-based scoping for normal staff
                $query->forUser($user);
            }

            // Optional search filter
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            $campaigns = $query->orderBy('start_date', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Campaigns retrieved successfully',
                'data' => $campaigns,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve campaigns',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created campaign in storage.
     */
    public function store(CreateCampaignRequest $request)
    {
        try {
            $data = $request->validated();
            $data['created_by'] = auth('api')->id();

            $campaign = Campaign::create($data);

            // Send SMS if requested
            if ($campaign->sms) {
                try {
                    $smsService = app(\App\Services\SmsService::class);
                    $smsService->sendCampaignSms(
                        $campaign,
                        $request->input('sms_message'),
                        $request->input('sms_template_id')
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to send campaign SMS: " . $e->getMessage());
                }
            }

            // Broadcast the creation event in realtime
            broadcast(new CampaignCreated($campaign))->toOthers();

            $this->logActivity('CREATE', 'Campaign', "Created campaign: {$campaign->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Campaign created successfully',
                'data' => $campaign->load(['creator']),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create campaign',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified campaign.
     */
    public function show(string $id)
    {
        try {
            $campaign = Campaign::with(['creator', 'updater'])->find($id);

            if (!$campaign) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Campaign not found',
                ], 404);
            }

            if (!$this->checkCampaignAccess($campaign)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to this campaign',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Campaign retrieved successfully',
                'data' => $campaign,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve campaign',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified campaign in storage.
     */
    public function update(UpdateCampaignRequest $request, string $id)
    {
        try {
            $campaign = Campaign::find($id);

            if (!$campaign) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Campaign not found',
                ], 404);
            }

            $data = $request->validated();
            $data['updated_by'] = auth('api')->id();

            $campaign->update($data);

            $this->logActivity('UPDATE', 'Campaign', "Updated campaign: {$campaign->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Campaign updated successfully',
                'data' => $campaign->load(['creator', 'updater']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update campaign',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified campaign from storage.
     */
    public function destroy(string $id)
    {
        try {
            $campaign = Campaign::find($id);

            if (!$campaign) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Campaign not found',
                ], 404);
            }

            $title = $campaign->title;
            $campaign->delete();

            $this->logActivity('DELETE', 'Campaign', "Deleted campaign: {$title}");

            return response()->json([
                'status' => 'success',
                'message' => 'Campaign deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete campaign',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Toggle the active status of a campaign.
     */
    public function toggleStatus(string $id)
    {
        try {
            $campaign = Campaign::find($id);

            if (!$campaign) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Campaign not found'
                ], 404);
            }

            $campaign->is_active = !$campaign->is_active;
            $campaign->updated_by = auth('api')->id();
            $campaign->save();

            $this->logActivity('TOGGLE_STATUS', 'Campaign', "Toggled campaign: {$campaign->title} (" . ($campaign->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'Campaign status updated successfully',
                'data' => [
                    'id' => $campaign->id,
                    'is_active' => $campaign->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle campaign status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
