<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSmsTemplateRequest;
use App\Http\Requests\UpdateSmsTemplateRequest;
use App\Models\SmsTemplate;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SmsTemplateController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    /**
     * Define the middleware for this controller.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:SmsTemplate Index', only: ['index', 'show']),
            new Middleware('permission:SmsTemplate Create', only: ['store']),
            new Middleware('permission:SmsTemplate Update', only: ['update']),
            new Middleware('permission:SmsTemplate Delete', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of SMS templates.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = SmsTemplate::with(['status', 'creator', 'updater']);

            if ($request->has('search') && $request->search != '') {
                $query->where(function ($q) use ($request) {
                    $q->where('title', 'like', "%{$request->search}%")
                      ->orWhere('content', 'like', "%{$request->search}%");
                });
            }

            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }

            $templates = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'SMS templates retrieved successfully',
                'data' => $templates,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SMS templates',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created SMS template in storage.
     */
    public function store(CreateSmsTemplateRequest $request)
    {
        try {
            $data = $request->validated();
            $data['created_by'] = auth('api')->id();

            $template = SmsTemplate::create($data);

            $this->logActivity('CREATE', 'SmsTemplate', "Created SMS template: {$template->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'SMS template created successfully',
                'data' => $template->load(['status', 'creator']),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create SMS template',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified SMS template.
     */
    public function show(string $id)
    {
        try {
            $template = SmsTemplate::with(['status', 'creator', 'updater'])->find($id);

            if (!$template) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'SMS template not found',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'SMS template retrieved successfully',
                'data' => $template,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SMS template',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified SMS template in storage.
     */
    public function update(UpdateSmsTemplateRequest $request, string $id)
    {
        try {
            $template = SmsTemplate::find($id);

            if (!$template) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'SMS template not found',
                ], 404);
            }

            $data = $request->validated();
            $data['updated_by'] = auth('api')->id();

            $template->update($data);

            $this->logActivity('UPDATE', 'SmsTemplate', "Updated SMS template: {$template->title}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'SMS template updated successfully',
                'data' => $template->load(['status', 'creator', 'updater']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update SMS template',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified SMS template from storage.
     */
    public function destroy(string $id)
    {
        try {
            $template = SmsTemplate::find($id);

            if (!$template) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'SMS template not found',
                ], 404);
            }

            $title = $template->title;
            $template->delete();

            $this->logActivity('DELETE', 'SmsTemplate', "Deleted SMS template: {$title}");

            return response()->json([
                'status' => 'success',
                'message' => 'SMS template deleted successfully',
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete SMS template',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
