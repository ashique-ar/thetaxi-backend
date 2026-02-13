<?php

namespace App\Models;

use App\Traits\UUID;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\Airport
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string $city
 * @property string $country
 * @property float $latitude
 * @property float $longitude
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $description
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Airport extends BaseModel
{
    use UUID, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'city',
        'country',
        'latitude',
        'longitude',
        'is_default',
        'is_active',
        'sort_order',
        'description',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to get only active airports
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
}
