<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStage extends Model
{
    use HasFactory;

    protected $table = 'lead_stages';

    protected $fillable = [
        'name',
        'sort_order',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the statuses associated with this lead stage, ordered by sort_order.
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'lead_stage_id')->orderBy('sort_order', 'asc');
    }

    /**
     * Scope a query to only include active lead stages.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to sort lead stages by their sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc');
    }

    /**
     * Scope a query to search lead stages by name or description.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }
}
