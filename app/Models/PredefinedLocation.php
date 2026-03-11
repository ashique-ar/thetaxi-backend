<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\PredefinedLocation
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property string|null $address
 * @property string|null $city
 * @property string $country
 * @property float $latitude
 * @property float $longitude
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $description
 * @property array|null $metadata
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class PredefinedLocation extends BaseModel
{
    use UUID, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'type',
        'address',
        'city',
        'country',
        'latitude',
        'longitude',
        'is_active',
        'sort_order',
        'description',
        'metadata',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Scope to get only active locations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope to filter by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get location as array format for booking system
     */
    public function toLocationArray(): array
    {
        return [
            'address' => $this->address ?? $this->name,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
        ];
    }
}
