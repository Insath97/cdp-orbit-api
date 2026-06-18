<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'target_type',
        'target_id',
        'is_active',
        'sms',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sms' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to announcements targeted to a specific user's demographics.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        $employee = $user->employee;
        $departmentId = $employee?->department_id;
        $branchId = $employee?->branch_id;
        $groupId = $employee?->branch?->group_id;
        $provinceId = $employee?->province_id;
        $zonalId = $employee?->zonal_id;
        $regionId = $employee?->region_id;

        $countryId = null;
        if ($employee?->country) {
            $countryId = \App\Models\Country::where('name', $employee->country)->value('id');
        }

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($user, $departmentId, $branchId, $groupId, $provinceId, $zonalId, $regionId, $countryId) {
                $q->where('target_type', 'all')
                  ->orWhere('target_type', 'users')
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('target_type', 'user')->where('target_id', $user->id);
                  });

                if ($departmentId) {
                    $q->orWhere(function ($sub) use ($departmentId) {
                        $sub->where('target_type', 'department')->where('target_id', $departmentId);
                    });
                }

                if ($branchId) {
                    $q->orWhere(function ($sub) use ($branchId) {
                        $sub->where('target_type', 'branch')->where('target_id', $branchId);
                    });
                }

                if ($groupId) {
                    $q->orWhere(function ($sub) use ($groupId) {
                        $sub->where('target_type', 'group')->where('target_id', $groupId);
                    });
                }

                if ($provinceId) {
                    $q->orWhere(function ($sub) use ($provinceId) {
                        $sub->where('target_type', 'province')->where('target_id', $provinceId);
                    });
                }

                if ($zonalId) {
                    $q->orWhere(function ($sub) use ($zonalId) {
                        $sub->where('target_type', 'zonal')->where('target_id', $zonalId);
                    });
                }

                if ($regionId) {
                    $q->orWhere(function ($sub) use ($regionId) {
                        $sub->where('target_type', 'region')->where('target_id', $regionId);
                    });
                }

                if ($countryId) {
                    $q->orWhere(function ($sub) use ($countryId) {
                        $sub->where('target_type', 'country')->where('target_id', $countryId);
                    });
                }
            });
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhere('content', 'like', "%{$search}%");
        });
    }
}
