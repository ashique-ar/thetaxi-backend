<?php
namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Vehicle\VehicleOwnerType
 *
 * @property string $id Primary key (UUID)
 * @property string|null $name Owner type name (optional)
 * @property string|null $description Owner type description (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Vehicle\VehicleOwner[] $owners Vehicle owners of this type
 */
class VehicleOwnerType extends BaseModel
{
    
    protected $fillable = ['name'];
}
