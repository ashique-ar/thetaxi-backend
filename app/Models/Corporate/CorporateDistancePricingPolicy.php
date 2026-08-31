<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;

class CorporateDistancePricingPolicy extends BaseModel
{
    protected $fillable = [
        'corporate_id', 'name', 'is_default', 'default_service_mode',
        'origin_address', 'origin_latitude', 'origin_longitude',
        'return_address', 'return_latitude', 'return_longitude',
        'include_origin_to_pickup', 'include_dropoff_to_return',
        'movement_rate_method', 'outbound_rate', 'return_rate',
        'maximum_outbound_km', 'maximum_return_km', 'effective_from',
        'effective_until', 'is_active', 'created_user_id', 'updated_user_id',
        'route_contract_version', 'route_template', 'route_anchor_sequence',
    ];

    protected $casts = [
        'is_default' => 'boolean', 'include_origin_to_pickup' => 'boolean',
        'include_dropoff_to_return' => 'boolean', 'is_active' => 'boolean',
        'origin_latitude' => 'decimal:7', 'origin_longitude' => 'decimal:7',
        'return_latitude' => 'decimal:7', 'return_longitude' => 'decimal:7',
        'outbound_rate' => 'decimal:2', 'return_rate' => 'decimal:2',
        'maximum_outbound_km' => 'decimal:2', 'maximum_return_km' => 'decimal:2',
        'effective_from' => 'datetime', 'effective_until' => 'datetime',
        'route_contract_version' => 'integer', 'route_anchor_sequence' => 'array',
    ];

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function serviceOverrides()
    {
        return $this->hasMany(CorporateServiceDistancePolicy::class, 'policy_id');
    }

    public function scopeEffectiveAt(Builder $query, $at): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at));
    }

    public function resolvedReturnLocation(): array
    {
        return [
            'address' => $this->return_address ?: $this->origin_address,
            'latitude' => $this->return_latitude ?? $this->origin_latitude,
            'longitude' => $this->return_longitude ?? $this->origin_longitude,
        ];
    }
}
