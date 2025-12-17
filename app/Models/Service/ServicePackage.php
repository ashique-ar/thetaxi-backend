<?php

namespace App\Models\Service;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class ServicePackage extends BaseModel
{
    protected $table = 'service_packages';

    protected $fillable = [
        'service_type_id',
        'name',
        'code',
        'description',
        'max_km_per_day',
        'max_km_per_package',
        'price_multiplier',
        'rate_type',
        'default_duration_hours',
        'is_active',
        'sort_order',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'included_km' => 'decimal:2',
        'price_multiplier' => 'decimal:4',
        'default_duration_hours' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function districtAdjustments(): HasMany
    {
        return $this->hasMany(DistrictPricingAdjustment::class, 'service_package_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
