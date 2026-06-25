<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\SmsService;
use App\Traits\ActivityLogTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBirthdayWishes extends Command
{
    use ActivityLogTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-birthday-wishes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send birthday wish SMS to leads celebrating their birthday today';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning for leads celebrating their birthday today...');

        $today = Carbon::today();

        $leads = Lead::whereNotNull('birthday')
            ->whereMonth('birthday', $today->month)
            ->whereDay('birthday', $today->day)
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No leads found with a birthday today.');

            return Command::SUCCESS;
        }

        $this->info("Found {$leads->count()} lead(s) celebrating today.");

        $smsService = app(SmsService::class);
        $sentCount = 0;

        // Custom template defined in code
        $template = "Dear {name},\n\nWishing you a very Happy Birthday! May this year bring you joy, happiness, good health, and success throughout the year.";

        // Company signature and contact details
        $footer = "\n\nBest Wishes,\n".
                  "Ceylon Development Plantation (Pvt) Ltd.\n\n".
                  "For any inquiries:\n".
                  "Hotline: +94 114 007 007\n".
                  'Website: https://cdp.lk/';

        foreach ($leads as $lead) {
            if (empty($lead->phone_primary)) {
                $this->warn("Skipping lead ID {$lead->id} ({$lead->name}) - No primary phone number.");

                continue;
            }

            try {
                $resolvedMessage = $smsService->resolveTemplateContent($template, $lead);
                $fullMessage = $resolvedMessage.$footer;

                $success = $smsService->sendSms($lead->phone_primary, $fullMessage);

                if ($success) {
                    $sentCount++;
                    $this->info("Birthday wish successfully sent to lead ID {$lead->id} ({$lead->name}).");
                    $this->logActivity(
                        'SEND_BIRTHDAY_WISH',
                        'Lead',
                        "Birthday wish SMS sent successfully to lead ID {$lead->id} ({$lead->name})",
                        ['lead_id' => $lead->id, 'phone' => $lead->phone_primary]
                    );
                } else {
                    $this->error("Failed to send birthday wish SMS to lead ID {$lead->id} ({$lead->name}).");
                    $this->logActivity(
                        'SEND_BIRTHDAY_WISH_FAILED',
                        'Lead',
                        "Failed to send birthday wish SMS to lead ID {$lead->id} ({$lead->name})",
                        ['lead_id' => $lead->id, 'phone' => $lead->phone_primary],
                        'error'
                    );
                }
            } catch (\Throwable $th) {
                Log::error("Failed to process birthday wish for lead ID {$lead->id}: ".$th->getMessage());
                $this->error("Error sending birthday wish for lead ID {$lead->id}: ".$th->getMessage());
                $this->logActivity(
                    'SEND_BIRTHDAY_WISH_ERROR',
                    'Lead',
                    "Error processing birthday wish for lead ID {$lead->id}: ".$th->getMessage(),
                    ['lead_id' => $lead->id, 'exception' => $th->getMessage()],
                    'error'
                );
            }
        }

        $this->info("Birthday wishes scan finished. Sent {$sentCount} SMS greeting(s).");
        $this->logActivity(
            'SEND_BIRTHDAY_WISHES_SCAN',
            'Lead',
            "Birthday wishes scan finished. Sent {$sentCount} SMS greeting(s).",
            ['total_leads' => $leads->count(), 'sent_count' => $sentCount]
        );

        return Command::SUCCESS;
    }
}
