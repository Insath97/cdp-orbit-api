<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification
{
    use Queueable;

    protected $event;

    /**
     * Create a new notification instance.
     */
    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $leadName = $this->event->lead?->name;
        $typeName = ucfirst($this->event->type);
        $startTime = $this->event->start_time->format('Y-m-d H:i:s');

        $message = $leadName 
            ? "Reminder: {$typeName} '{$this->event->title}' with Lead '{$leadName}' starts at {$startTime}."
            : "Reminder: {$typeName} '{$this->event->title}' starts at {$startTime}.";

        return [
            'event_id' => $this->event->id,
            'title' => $this->event->title,
            'type' => $this->event->type,
            'lead_id' => $this->event->lead_id,
            'lead_name' => $leadName,
            'start_time' => $startTime,
            'message' => $message,
        ];
    }
}
