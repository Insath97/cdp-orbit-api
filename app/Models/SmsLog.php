<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    use HasFactory;

    protected $table = 'sms_logs';

    protected $fillable = [
        'lead_id',
        'phone_number',
        'message',
        'status',
        'transaction_id',
        'sent_by',
    ];

    /**
     * Get the lead associated with this log.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Get the user who sent the SMS.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * Scope a query to search logs by phone number, message, or lead name.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('phone_number', 'like', "%{$search}%")
              ->orWhere('message', 'like', "%{$search}%")
              ->orWhereHas('lead', function ($leadQuery) use ($search) {
                  $leadQuery->where('name', 'like', "%{$search}%");
              });
        });
    }
}
