<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SmsController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Sms Send', only: ['send']),
            new Middleware('permission:Sms Send All', only: ['sendToAllLeads']),
            new Middleware('permission:Sms View Logs', only: ['logs']),
        ];
    }

    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send SMS to direct numbers provided in request.
     * Supports num1, num2... or numbers[] array.
     */
    public function send(Request $request): JsonResponse
    {
        $message = $request->input('message', $this->getDefaultMessage());
        $numbers = $this->extractNumbersFromRequest($request);

        if (empty($numbers)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No valid phone numbers provided.'
            ], 422);
        }

        $results = $this->smsService->sendBulkSms($numbers, $message);

        $this->logActivity('SMS_SEND', 'Sms', 'Direct SMS sent to numbers', [
            'total_numbers' => count($numbers)
        ], 'info');

        return response()->json([
            'status' => 'success',
            'message' => 'SMS sending process initiated.',
            'data' => [
                'total_numbers' => count($numbers),
                'results' => $results
            ]
        ], 200);
    } 

    /**
     * Send SMS to all leads.
     */
    public function sendToAllLeads(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Only Super Admin can perform this action.'
                ], 403);
            }

            $message = $request->input('message', $this->getDefaultMessage());

            $leads = Lead::select('phone_primary', 'phone_secondary')->get();

            $numbers = [];
            foreach ($leads as $lead) {
                if (!empty($lead->phone_primary)) {
                    $numbers[] = $lead->phone_primary;
                }
                if (!empty($lead->phone_secondary)) {
                    $numbers[] = $lead->phone_secondary;
                }
            }

            $numbers = array_unique(array_filter($numbers));

            if (empty($numbers)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No leads found with valid phone numbers.'
                ], 422);
            }

            $results = $this->smsService->sendBulkSms($numbers, $message);

            $this->logActivity('SMS_SEND_ALL', 'Sms', 'Bulk SMS sent to all leads', [
                'user_id' => $user->id,
                'total_numbers' => count($numbers)
            ], 'info');

            return response()->json([
                'status' => 'success',
                'message' => 'SMS sending process initiated for all leads.',
                'data' => [
                    'total_numbers' => count($numbers),
                    'results' => $results
                ]
            ], 200);
        } catch (\Throwable $th) {
            $this->logActivity('SMS_SEND_ALL_ERROR', 'Sms', "Bulk SMS to all leads failure: " . $th->getMessage(), null, 'error');
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send SMS to all leads.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve SMS transmission history logs.
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = SmsLog::with(['lead', 'sender']);

            if ($request->has('search') && $request->search != '') {
                $query->search($request->search);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'SMS logs retrieved successfully.',
                'data' => $logs
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SMS logs.',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get the default SMS message template.
     */
    protected function getDefaultMessage(): string
    {
        return "Dear Sir/Madam,\n\nThank you for choosing Ceylon Development Plantation Empire (Pvt) Ltd. We are here to serve you and are committed to providing you with the best Services. Our team will contact you shortly with further details and personalized assistance.\n\nWe look forward to serving you.\n\nThank you.\nCeylon Development Plantation Empire (Pvt) Ltd.\n0114 007 007\nwww.cdp.lk";
    }

    /**
     * Extract numbers from request parameters like num1, num2, etc. or numbers array.
     */
    protected function extractNumbersFromRequest(Request $request): array
    {
        $numbers = [];

        // Support array 'numbers'
        if ($request->has('numbers') && is_array($request->input('numbers'))) {
            $numbers = array_merge($numbers, $request->input('numbers'));
        }

        // Support num1, num2, etc.
        $allParams = $request->all();
        foreach ($allParams as $key => $value) {
            if (preg_match('/^num\d+$/', $key) && !empty($value)) {
                $numbers[] = $value;
            }
        }

        // Remove duplicates and empty values
        return array_unique(array_filter($numbers));
    }
}
