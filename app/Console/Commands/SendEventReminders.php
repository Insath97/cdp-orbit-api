<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendEventReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-event-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send system notification alerts for upcoming events and appointments based on reminder offsets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting event reminder scan...');

        // Query events that haven't had their reminder sent, and start time is in the future or very recent
        $events = Event::where('reminder_sent', false)
            ->where('start_time', '>=', Carbon::now()->subHours(12))
            ->with(['user', 'lead'])
            ->get();

        $sentCount = 0;

        foreach ($events as $event) {
            $startTime = $event->start_time;
            $reminderValue = $event->reminder_value;
            $reminderUnit = $event->reminder_unit;

            // Calculate trigger time
            $triggerTime = match ($reminderUnit) {
                'minutes' => $startTime->copy()->subMinutes($reminderValue),
                'hours' => $startTime->copy()->subHours($reminderValue),
                'days' => $startTime->copy()->subDays($reminderValue),
                default => $startTime,
            };

            // If the current time is greater than or equal to the trigger time, send the reminder
            if (Carbon::now()->greaterThanOrEqualTo($triggerTime)) {
                try {
                    $assignedUser = $event->user;

                    if ($assignedUser) {
                        $assignedUser->notify(new EventReminderNotification($event));
                        
                        $event->update([
                            'reminder_sent' => true
                        ]);

                        $sentCount++;
                        $this->info("Reminder sent for event ID {$event->id}: '{$event->title}' to User ID {$assignedUser->id}");
                    }
                } catch (\Throwable $th) {
                    Log::error("Failed to send reminder for event ID {$event->id}: " . $th->getMessage());
                }
            }
        }

        $this->info("Event reminder scan finished. Sent {$sentCount} reminder(s).");
        return Command::SUCCESS;
    }
}
