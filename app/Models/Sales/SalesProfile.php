<?php

namespace App\Models\Sales;

use App\Models\BaseModel;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SalesProfile extends BaseModel
{
    protected $fillable = [
        'company_id', 'staff_id', 'staff_category_snapshot', 'sales_code', 'status',
        'acquisition_eligible', 'collection_eligible', 'commission_eligible', 'reporting_currency',
        'version', 'effective_from', 'effective_until', 'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'acquisition_eligible' => 'boolean',
        'collection_eligible' => 'boolean',
        'commission_eligible' => 'boolean',
        'version' => 'integer',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function scopeActiveAt(Builder $query, $at): Builder
    {
        return $query->where('status', 'active')
            ->effectiveAt($at);
    }

    public function scopeEffectiveAt(Builder $query, $at): Builder
    {
        return $query->where('effective_from', '<=', $at)
            ->where(fn ($effective) => $effective->whereNull('effective_until')->orWhere('effective_until', '>', $at));
    }

    public function scopeConfigured(Builder $query): Builder
    {
        return $query->whereNotNull('reporting_currency')
            ->whereNotNull('staff_category_snapshot')
            ->where(fn (Builder $eligibility) => $eligibility
                ->where('acquisition_eligible', true)
                ->orWhere('collection_eligible', true)
                ->orWhere('commission_eligible', true));
    }

    public function scopeEligibleAt(Builder $query, string $eligibility, $at, bool $requireActiveStatus = true): Builder
    {
        $column = match ($eligibility) {
            'acquisition' => 'acquisition_eligible',
            'collection' => 'collection_eligible',
            'commission' => 'commission_eligible',
            default => throw new \InvalidArgumentException('Unsupported Sales Profile eligibility.'),
        };

        $query->effectiveAt($at)->where($column, true)->whereNotNull('reporting_currency')
            ->whereNotNull('staff_category_snapshot');

        return $requireActiveStatus ? $query->where('status', 'active') : $query;
    }
}
