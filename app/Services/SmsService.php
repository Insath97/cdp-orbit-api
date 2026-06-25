<?php

namespace App\Services;

use App\Traits\ActivityLogTrait;
use App\Models\Lead;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SmsService
{
    use ActivityLogTrait;
    protected $baseUrl;
    protected $sendSmsUrl;
    protected $username;
    protected $password;
    protected $mask;

    public function __construct()
    {
        $this->baseUrl = env('DIALOG_SMS_URL', 'https://esms.dialog.lk');
        // Note: Send SMS endpoint specifically uses e-sms.dialog.lk as per documentation
        $this->sendSmsUrl = 'https://e-sms.dialog.lk/api/v2/sms';
        $this->username = env('DIALOG_SMS_USERNAME');
        $this->password = env('DIALOG_SMS_PASSWORD');
        $this->mask = env('DIALOG_SMS_MASK', 'CDP EMPIRE');
    }

    /**
     * Get access token from Dialog API
     */
    private function getAccessToken(): ?string
    {
        // Check if token exists in cache
        if (Cache::has('dialog_sms_token')) {
            return Cache::get('dialog_sms_token');
        }

        try {
            $response = Http::post($this->baseUrl . '/api/v2/user/login', [
                'username' => $this->username,
                'password' => $this->password
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['status']) && $data['status'] === 'success') {
                    // Store token in cache for 12 hours (43200 seconds as per API doc)
                    Cache::put('dialog_sms_token', $data['token'], now()->addSeconds($data['expiration']));

                    $this->logActivity('SMS_TOKEN_GENERATE', 'Sms', 'Dialog SMS Token Generated Successfully');
                    
                    return $data['token'];
                }
            }

            $this->logActivity('SMS_TOKEN_FAILED', 'Sms', 'Failed to get Dialog SMS Token', ['response' => $response->body()], 'error');

            return null;

        } catch (\Throwable $th) {
            $this->logActivity('SMS_TOKEN_ERROR', 'Sms', 'Dialog SMS Token Error: ' . $th->getMessage(), null, 'error');
            return null;
        }
    }

    /**
     * Send SMS via Dialog Gateway
     *
     * @param string|array $numbers Single number or array of numbers
     * @param string $message
     * @param int $paymentMethod 0=wallet, 4=package
     * @return bool
     */
    public function sendSms($numbers, string $message, int $paymentMethod = 0): bool
    {
        try {
            // Get access token
            $token = $this->getAccessToken();
            if (!$token) {
                $this->logActivity('SMS_SEND_ERROR', 'Sms', 'Cannot send SMS: No valid access token', null, 'error');
                return false;
            }

            // Format numbers
            $numbers = is_array($numbers) ? $numbers : [$numbers];
            $formattedNumbers = [];

            foreach ($numbers as $number) {
                $formattedNumbers[] = [
                    'mobile' => $this->formatNumber($number)
                ];
            }

            // Extract formatted 9-digit numbers
            $numberList = array_map(function ($item) {
                return $item['mobile'];
            }, $formattedNumbers);

            // Generate variations to match different database formats (e.g. 077..., 9477..., +9477...)
            $queryNumbers = [];
            foreach ($numberList as $num) {
                $queryNumbers[] = $num;
                $queryNumbers[] = '0' . $num;
                $queryNumbers[] = '94' . $num;
                $queryNumbers[] = '+94' . $num;
            }
            $queryNumbers = array_unique($queryNumbers);

            // Lookup Lead IDs for these phone numbers
            $leads = Lead::where(function ($q) use ($queryNumbers) {
                $q->whereIn('phone_primary', $queryNumbers)
                  ->orWhereIn('phone_secondary', $queryNumbers);
            })->get(['id', 'phone_primary', 'phone_secondary']);

            // Map number string directly to lead ID
            $numberToLeadId = [];
            foreach ($leads as $lead) {
                if (!empty($lead->phone_primary)) {
                    $numberToLeadId[$lead->phone_primary] = $lead->id;
                }
                if (!empty($lead->phone_secondary)) {
                    $numberToLeadId[$lead->phone_secondary] = $lead->id;
                }
            }

            // Generate unique transaction ID (1-18 digits as per API spec)
            $transactionId = time() . rand(100, 999);

            // Prepare request according to API documentation (v2)
            $payload = [
                'msisdn' => $formattedNumbers,
                'sourceAddress' => $this->mask,
                'message' => $message,
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
            ];

            $this->logActivity(
                'SENDING_SMS',
                'Sms',
                'Sending SMS via Dialog API',
                [
                    'numbers_count' => count($formattedNumbers),
                    'transaction_id' => $transactionId
                ]
            );

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($this->sendSmsUrl, $payload);

            $success = false;

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['status']) && $data['status'] === 'success') {
                    $this->logActivity('SMS_SEND_SUCCESS', 'Sms', "SMS Sent Successfully to " . count($formattedNumbers) . " recipient(s)", [
                        'campaign_id' => $data['data']['campaignId'] ?? null,
                        'campaign_cost' => $data['data']['campaignCost'] ?? null,
                        'transaction_id' => $transactionId
                    ], 'info');
                    $success = true;
                }
            }

            if (!$success) {
                $this->logActivity('SMS_SEND_FAILED', 'Sms', 'SMS API Error Response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'transaction_id' => $transactionId
                ], 'error');
            }

            // Write individual SmsLog records for each targeted recipient
            $sentById = auth('api')->id();
            foreach ($numberList as $num) {
                $leadId = $numberToLeadId[$num] ?? 
                          $numberToLeadId['0' . $num] ?? 
                          $numberToLeadId['94' . $num] ?? 
                          $numberToLeadId['+94' . $num] ?? 
                          null;

                SmsLog::create([
                    'lead_id' => $leadId,
                    'phone_number' => $num,
                    'message' => $message,
                    'status' => $success ? 'success' : 'failed',
                    'transaction_id' => $transactionId,
                    'sent_by' => $sentById,
                ]);
            }

            return $success;

        } catch (\Throwable $th) {
            $this->logActivity('SMS_SEND_EXCEPTION', 'Sms', 'SMS Service Exception: ' . $th->getMessage(), [
                'transaction_id' => $transactionId ?? null
            ], 'error');
            return false;
        }
    }

    /**
     * Check campaign status by transaction ID
     */
    public function checkCampaignStatus(string $transactionId): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/api/v2/sms/check-transaction', [
                'transaction_id' => $transactionId
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;

        } catch (\Throwable $th) {
            Log::error('Check Campaign Status Error: ' . $th->getMessage());
            return null;
        }
    }

    /**
     * Get account balance (for GET request users)
     */
    public function getBalance(string $esmsqk): ?float
    {
        try {
            $response = Http::get($this->baseUrl . '/api/v1/message-via-url/check/balance', [
                'esmsqk' => $esmsqk
            ]);

            if ($response->successful()) {
                $body = $response->body();
                $parts = explode('|', $body);

                if ($parts[0] == 1) {
                    return floatval($parts[1]);
                }
            }

            return null;

        } catch (\Throwable $th) {
            Log::error('Get Balance Error: ' . $th->getMessage());
            return null;
        }
    }

    /**
     * Format phone number to required format (7XXXXXXXX - 9 digits)
     */
    protected function formatNumber(string $number): string
    {
        // Remove all non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $number);

        // If starts with 94, remove it
        if (str_starts_with($number, '94')) {
            $number = substr($number, 2);
        }

        // If starts with 0, remove it
        if (str_starts_with($number, '0')) {
            $number = substr($number, 1);
        }

        // Ensure it's exactly 9 digits as per API docs
        if (strlen($number) > 9) {
            // Keep last 9 digits if longer (might happen if someone enters 07XXXXXXXX)
            $number = substr($number, -9);
        }

        return $number;
    }

    /**
     * Send SMS to multiple recipients
     */
    public function sendBulkSms(array $numbers, string $message, int $paymentMethod = 0): array
    {
        $results = [];

        // Dialog API supports multiple numbers in one request
        // So we can send all at once
        $success = $this->sendSms($numbers, $message, $paymentMethod);

        foreach ($numbers as $number) {
            $results[$number] = $success;
        }

        return $results;
    }

    /**
     * Resolve target phone numbers based on target type and target ID.
     */
    public function getTargetPhoneNumbers(string $targetType, ?int $targetId = null): array
    {
        $numbers = [];

        // 1. Resolve Leads (Customers) if applicable
        if (in_array($targetType, ['all', 'customers'])) {
            $leads = \App\Models\Lead::select('phone_primary', 'phone_secondary')->get();
            foreach ($leads as $lead) {
                if (!empty($lead->phone_primary)) {
                    $numbers[] = $lead->phone_primary;
                }
                if (!empty($lead->phone_secondary)) {
                    $numbers[] = $lead->phone_secondary;
                }
            }
        }

        // 2. Resolve Employees (Users) if applicable
        $employeeQuery = \App\Models\Employee::active();
        $shouldQueryEmployees = false;

        if (in_array($targetType, ['all', 'users'])) {
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'country' && $targetId) {
            $countryName = \App\Models\Country::where('id', $targetId)->value('name');
            if ($countryName) {
                $employeeQuery->where('country', 'like', $countryName);
                $shouldQueryEmployees = true;
            }
        } elseif ($targetType === 'province' && $targetId) {
            $employeeQuery->where('province_id', $targetId);
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'zonal' && $targetId) {
            $employeeQuery->where('zonal_id', $targetId);
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'region' && $targetId) {
            $employeeQuery->where('region_id', $targetId);
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'branch' && $targetId) {
            $employeeQuery->where('branch_id', $targetId);
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'department' && $targetId) {
            $employeeQuery->where('department_id', $targetId);
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'group' && $targetId) {
            $employeeQuery->whereHas('branch', function ($q) use ($targetId) {
                $q->where('group_id', $targetId);
            });
            $shouldQueryEmployees = true;
        } elseif ($targetType === 'user' && $targetId) {
            $employeeId = \App\Models\User::where('id', $targetId)->value('employee_id');
            if ($employeeId) {
                $employeeQuery->where('id', $employeeId);
                $shouldQueryEmployees = true;
            }
        }

        if ($shouldQueryEmployees) {
            $employees = $employeeQuery->select('phone_primary', 'phone_secondary')->get();
            foreach ($employees as $employee) {
                if (!empty($employee->phone_primary)) {
                    $numbers[] = $employee->phone_primary;
                }
                if (!empty($employee->phone_secondary)) {
                    $numbers[] = $employee->phone_secondary;
                }
            }
        }

        // Clean & unique numbers
        return array_unique(array_filter($numbers));
    }

    /**
     * Parse dynamic placeholders like {name}, {title}, {status_name} from template.
     */
    public function resolveTemplateContent(string $templateText, $model, array $extraData = []): string
    {
        $replacements = [];

        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $templateText, $matches);
        if (!empty($matches[1])) {
            foreach (array_unique($matches[1]) as $placeholder) {
                $value = '';

                // Check extra data first
                if (array_key_exists($placeholder, $extraData)) {
                    $value = $extraData[$placeholder];
                }
                // Check model properties
                elseif (isset($model->{$placeholder})) {
                    $val = $model->{$placeholder};
                    if ($val instanceof \DateTimeInterface) {
                        $value = $val->format('Y-m-d');
                    } else {
                        $value = (string)$val;
                    }
                }
                // Special relationship lookups
                elseif ($placeholder === 'status_name' && method_exists($model, 'status')) {
                    $value = $model->status?->name ?? '';
                }

                $replacements['{' . $placeholder . '}'] = $value;
            }
        }

        return strtr($templateText, $replacements);
    }

    /**
     * Send SMS for an announcement using custom message, template ID, or fallback.
     */
    public function sendAnnouncementSms(\App\Models\Announcement $announcement, ?string $customMessage = null, ?int $templateId = null): bool
    {
        $message = '';

        if (!empty($customMessage)) {
            $message = $customMessage;
        } elseif ($templateId) {
            $template = \App\Models\SmsTemplate::find($templateId);
            if ($template) {
                $message = $this->resolveTemplateContent($template->content, $announcement);
            }
        }

        if (empty($message)) {
            // Try to find a template matching announcement type or fallback search
            $template = \App\Models\SmsTemplate::where('type', 'announcement')
                ->orWhere('type', 'all')
                ->first()
                ?: \App\Models\SmsTemplate::where('title', 'like', '%announcement%')
                ->orWhere('content', 'like', '%announcement%')
                ->first();
            if ($template) {
                $message = $this->resolveTemplateContent($template->content, $announcement);
            } else {
                $message = "CDP Announcement: " . $announcement->title . "\n\n" . $announcement->content;
            }
        }

        $numbers = $this->getTargetPhoneNumbers($announcement->target_type, $announcement->target_id);

        if (empty($numbers)) {
            return false;
        }

        return $this->sendSms($numbers, $message);
    }

    /**
     * Send SMS for a campaign using custom message, template ID, or fallback.
     */
    public function sendCampaignSms(\App\Models\Campaign $campaign, ?string $customMessage = null, ?int $templateId = null): bool
    {
        $message = '';

        if (!empty($customMessage)) {
            $message = $customMessage;
        } elseif ($templateId) {
            $template = \App\Models\SmsTemplate::find($templateId);
            if ($template) {
                $message = $this->resolveTemplateContent($template->content, $campaign);
            }
        }

        if (empty($message)) {
            // Try to find a template matching campaign type or fallback search
            $template = \App\Models\SmsTemplate::where('type', 'campaigns')
                ->orWhere('type', 'all')
                ->first()
                ?: \App\Models\SmsTemplate::where('title', 'like', '%campaign%')
                ->orWhere('content', 'like', '%campaign%')
                ->first();
            if ($template) {
                $message = $this->resolveTemplateContent($template->content, $campaign);
            } else {
                $message = "CDP Campaign: " . $campaign->title . "\n\n" . $campaign->description . "\nValid: " . ($campaign->start_date?->format('Y-m-d') ?? '') . " to " . ($campaign->end_date?->format('Y-m-d') ?? '');
            }
        }

        $numbers = $this->getTargetPhoneNumbers($campaign->target_type, $campaign->target_id);

        if (empty($numbers)) {
            return false;
        }

        return $this->sendSms($numbers, $message);
    }

    /**
     * Send SMS on Lead Status change.
     */
    public function sendStatusChangeSms(\App\Models\Lead $lead, \App\Models\Status $status, string $reason = ''): bool
    {
        // Find template by status ID
        $template = \App\Models\SmsTemplate::where('status_id', $status->id)->first();

        if ($template) {
            $message = $this->resolveTemplateContent($template->content, $lead, [
                'status_name' => $status->name,
                'reason' => $reason
            ]);
        } else {
            // Default status fallback
            $message = "Dear " . $lead->name . ", your status has been updated to: " . $status->name . ". Thank you, Ceylon Development Plantation.";
        }

        if (empty($lead->phone_primary)) {
            return false;
        }

        return $this->sendSms($lead->phone_primary, $message);
    }
}
