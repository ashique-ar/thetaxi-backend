<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleAddonCategory extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'icon',
        'sort_order',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function addons(): HasMany
    {
        return $this->hasMany(VehicleAddon::class, 'category_id');
    }
}
