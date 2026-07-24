<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleFinanceProvider extends BaseModel
{
    protected $fillable = [
        'code', 'name', 'provider_type', 'registration_number',
        'contact_person', 'email', 'phone', 'address', 'is_active',
        'notes', 'created_user_id', 'updated_user_id',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function leases(): HasMany
    {
        return $this->hasMany(VehicleLease::class, 'finance_provider_id');
    }
}
