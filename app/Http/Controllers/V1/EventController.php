<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Status;
use App\Models\LeadStatusHistory;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class EventController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Event Index', ['only' => ['index', 'show']]),
            new Middleware('permission:Event Create', ['only' => ['store']]),
            new Middleware('permission:Event Update', ['only' => ['update']]),
            new Middleware('permission:Event Delete', ['only' => ['destroy']]),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Event::with(['lead', 'user', 'creator', 'updater']);

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->where(function ($q) use ($request) {
                    $q->whereBetween('start_time', [$request->start_date, $request->end_date])
                      ->orWhereBetween('end_time', [$request->start_date, $request->end_date]);
                });
            }

            // Filter by specific lead
            if ($request->has('lead_id')) {
                $query->where('lead_id', $request->lead_id);
            }

            // Filter by assigned user
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by type (event, appointment, callback)
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            $events = $query->orderBy('start_time', 'asc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Events retrieved successfully',
                'data' => $events,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve events',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            $data['created_by'] = auth('api')->id();
            $data['reminder_sent'] = false;

            // Create the event
            $event = Event::create($data);

            // If it's an appointment with a lead, transition lead status
            if ($event->type === 'appointment' && $event->lead_id) {
                $lead = Lead::find($event->lead_id);
                if ($lead) {
                    $scheduledStatus = Status::where('name', 'Meeting Scheduled')->first();
                    if ($scheduledStatus && $lead->status_id != $scheduledStatus->id) {
                        $oldStatusId = $lead->status_id;

                        // Update lead status
                        $lead->update([
                            'status_id' => $scheduledStatus->id,
                            'updated_by' => auth('api')->id(),
                        ]);

                        // Record status history
                        LeadStatusHistory::create([
                            'lead_id' => $lead->id,
                            'old_status_id' => $oldStatusId,
                            'new_status_id' => $scheduledStatus->id,
                            'changed_by' => auth('api')->id(),
                            'reason' => 'Status updated via Appointment Booking: ' . $event->title,
                        ]);

                        // Trigger SMS if required by status configuration
                        if ($scheduledStatus->is_need_sms) {
                            try {
                                $smsService = app(\App\Services\SmsService::class);
                                $smsService->sendStatusChangeSms($lead, $scheduledStatus, 'Meeting scheduled');
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error("Failed to send Lead Status change SMS for Appointment: " . $e->getMessage());
                            }
                        }
                    }
                }
            }

            DB::commit();

            $this->logActivity('CREATE', 'Event', "Created event/appointment: {$event->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Event created successfully',
                'data' => $event->load(['lead', 'user']),
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create event',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $event = Event::with(['lead', 'user', 'creator', 'updater'])->find($id);

            if (!$event) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Event not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Event retrieved successfully',
                'data' => $event,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve event',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventRequest $request, string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $event = Event::find($id);

            if (!$event) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Event not found',
                ], 404);
            }

            $data = $request->validated();
            $data['updated_by'] = auth('api')->id();

            // If time or values change, reset reminder_sent to allow re-triggering
            if (isset($data['start_time']) && $data['start_time'] != $event->start_time->format('Y-m-d H:i:s')) {
                $data['reminder_sent'] = false;
            }

            $event->update($data);

            DB::commit();

            $this->logActivity('UPDATE', 'Event', "Updated event/appointment: {$event->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Event updated successfully',
                'data' => $event->load(['lead', 'user']),
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update event',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $event = Event::find($id);

            if (!$event) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Event not found',
                ], 404);
            }

            $title = $event->title;
            $event->delete();

            $this->logActivity('DELETE', 'Event', "Deleted event: {$title}");

            return response()->json([
                'status' => 'success',
                'message' => 'Event deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete event',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
