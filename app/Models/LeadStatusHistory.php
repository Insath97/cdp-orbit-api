<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'old_status_id',
        'new_status_id',
        'changed_by',
        'reason',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function oldStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'old_status_id');
    }

    public function newStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'new_status_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
