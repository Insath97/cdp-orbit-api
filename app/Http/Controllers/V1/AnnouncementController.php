<?php

namespace App\Http\Controllers\V1;

use App\Events\AnnouncementCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AnnouncementController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Announcement Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Announcement Create', ['only' => ['store']]),
            new Middleware('permission:Announcement Update', ['only' => ['update']]),
            new Middleware('permission:Announcement Delete', ['only' => ['destroy']]),
            new Middleware('permission:Announcement Toggle Status', ['only' => ['toggleStatus']]),
        ];
    }

    /**
     * Helper to verify if the user has access to a specific announcement.
     */
    private function checkAnnouncementAccess(Announcement $announcement): bool
    {
        $user = auth('api')->user();

        // Super Admin or Announcement View All has access to everything
        if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Announcement View All')) {
            return true;
        }

        // Normal staff can only view active announcements targeted to them
        return Announcement::forUser($user)->where('id', $announcement->id)->exists();
    }

    /**
     * Display a listing of the announcements.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = auth('api')->user();

            $query = Announcement::with(['creator', 'updater']);

            // Scoping based on role/permissions
            if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Announcement View All')) {
                // If has administrative access, can filter by activity status
                if ($request->has('is_active')) {
                    $query->where('is_active', $request->boolean('is_active'));
                }
            } else {
                // Otherwise only show active, targeted announcements
                $query->forUser($user);
            }

            // Optional search filter
            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            // Optional targets filter (admin only)
            if ($user->hasRole('Super Admin') || $user->hasPermissionTo('Announcement View All')) {
                if ($request->has('target_type')) {
                    $query->where('target_type', $request->target_type);
                }
                if ($request->has('target_id')) {
                    $query->where('target_id', $request->target_id);
                }
            }

            $announcements = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Announcements retrieved successfully',
                'data' => $announcements,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve announcements',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created announcement in storage.
     */
    public function store(CreateAnnouncementRequest $request)
    {
        try {
            $data = $request->validated();
            $data['created_by'] = auth('api')->id();

            $announcement = Announcement::create($data);

            // Send SMS if requested
            if ($announcement->sms) {
                try {
                    $smsService = app(\App\Services\SmsService::class);
                    $smsService->sendAnnouncementSms(
                        $announcement,
                        $request->input('sms_message'),
                        $request->input('sms_template_id')
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to send announcement SMS: " . $e->getMessage());
                }
            }

            // Broadcast the creation event in realtime
            broadcast(new AnnouncementCreated($announcement))->toOthers();

            $this->logActivity('CREATE', 'Announcement', "Created announcement: {$announcement->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Announcement created successfully',
                'data' => $announcement->load(['creator']),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create announcement',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified announcement.
     */
    public function show(string $id)
    {
        try {
            $announcement = Announcement::with(['creator', 'updater'])->find($id);

            if (! $announcement) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Announcement not found',
                ], 404);
            }

            if (! $this->checkAnnouncementAccess($announcement)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to this announcement',
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Announcement retrieved successfully',
                'data' => $announcement,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve announcement',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified announcement in storage.
     */
    public function update(UpdateAnnouncementRequest $request, string $id)
    {
        try {
            $announcement = Announcement::find($id);

            if (! $announcement) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Announcement not found',
                ], 404);
            }

            $data = $request->validated();
            $data['updated_by'] = auth('api')->id();

            $announcement->update($data);

            $this->logActivity('UPDATE', 'Announcement', "Updated announcement: {$announcement->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Announcement updated successfully',
                'data' => $announcement->load(['creator', 'updater']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update announcement',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified announcement from storage.
     */
    public function destroy(string $id)
    {
        try {
            $announcement = Announcement::find($id);

            if (! $announcement) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Announcement not found',
                ], 404);
            }

            $title = $announcement->title;
            $announcement->delete();

            $this->logActivity('DELETE', 'Announcement', "Deleted announcement: {$title}");

            return response()->json([
                'status' => 'success',
                'message' => 'Announcement deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete announcement',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Toggle the active status of an announcement.
     */
    public function toggleStatus(string $id)
    {
        try {
            $announcement = Announcement::find($id);

            if (! $announcement) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Announcement not found',
                ], 404);
            }

            $announcement->is_active = ! $announcement->is_active;
            $announcement->updated_by = auth('api')->id();
            $announcement->save();

            $this->logActivity('TOGGLE_STATUS', 'Announcement', "Toggled announcement: {$announcement->title} (".($announcement->is_active ? 'Active' : 'Inactive').')');

            return response()->json([
                'status' => 'success',
                'message' => 'Announcement status updated successfully',
                'data' => [
                    'id' => $announcement->id,
                    'is_active' => $announcement->is_active,
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle announcement status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
