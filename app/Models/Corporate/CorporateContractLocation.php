<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CorporateContractLocation extends BaseModel
{
    use HasUuids;

    protected $fillable = ['corporate_id', 'owner_type', 'name', 'address', 'latitude', 'longitude', 'is_active'];
    protected $casts = ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_active' => 'boolean'];

    public function snapshot(): array
    {
        return ['location_id' => $this->id, 'owner_type' => $this->owner_type, 'owner_id' => $this->corporate_id, 'label' => $this->name, 'address' => $this->address, 'latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude];
    }
}
