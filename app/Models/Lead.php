<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'birthday',
        'id_type',
        'id_number',
        'preferred_language',
        'company',
        'value',
        'source',
        'notes',
        'status_id',
        'group_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'have_whatsapp' => 'boolean',
        'birthday' => 'date',
    ];

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to search leads by contact details.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone_primary', 'like', "%{$search}%")
              ->orWhere('phone_secondary', 'like', "%{$search}%")
              ->orWhere('whatsapp_number', 'like', "%{$search}%")
              ->orWhere('id_number', 'like', "%{$search}%")
              ->orWhere('company', 'like', "%{$search}%");
        });
    }
}
