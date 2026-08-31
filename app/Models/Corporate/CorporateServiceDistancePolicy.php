<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Service\ServiceType;
use Illuminate\Database\Eloquent\Builder;

class CorporateServiceDistancePolicy extends BaseModel
{
    protected $fillable = [
        'corporate_id', 'service_type_id', 'policy_id', 'application_mode',
        'origin_location_override', 'return_location_override',
        'include_origin_to_pickup', 'include_dropoff_to_return',
        'movement_rate_method', 'outbound_rate', 'return_rate',
        'maximum_outbound_km', 'maximum_return_km', 'effective_from',
        'effective_until', 'is_active', 'created_user_id', 'updated_user_id',
        'route_template_override', 'route_anchor_sequence_override',
    ];

    protected $casts = [
        'origin_location_override' => 'array', 'return_location_override' => 'array',
        'include_origin_to_pickup' => 'boolean', 'include_dropoff_to_return' => 'boolean',
        'outbound_rate' => 'decimal:2', 'return_rate' => 'decimal:2',
        'maximum_outbound_km' => 'decimal:2', 'maximum_return_km' => 'decimal:2',
        'effective_from' => 'datetime', 'effective_until' => 'datetime', 'is_active' => 'boolean',
        'route_anchor_sequence_override' => 'array',
    ];

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function policy()
    {
        return $this->belongsTo(CorporateDistancePricingPolicy::class, 'policy_id');
    }

    public function scopeEffectiveAt(Builder $query, $at): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at));
    }
}
