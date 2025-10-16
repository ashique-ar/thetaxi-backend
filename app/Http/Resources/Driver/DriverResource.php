<?php
// app/Http/Resources/DriverResource.php
namespace App\Http\Resources\Driver;

use App\Http\Resources\CountryResource;
use App\Http\Resources\DrivingLicenseTypeResource;
use App\Http\Resources\StateResource;
use App\Http\Resources\UserResource;
use App\Models\State;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'code' => $this->code,
            'nic' => $this->nic,
            'license_no' => $this->license_no,
            'license_expiry' => $this->license_expiry,
            'license_type' => $this->license_type,
            'dob' => $this->dob,
            'address' => $this->address,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city' => $this->city,
            'remarks' => $this->remarks,
            'postal_code' => $this->postal_code,
            'license_type' => new DrivingLicenseTypeResource($this->whenLoaded('licenseType')),
            'state' => new StateResource($this->whenLoaded('state')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'user' => new UserResource($this->whenLoaded('user')),
            'is_active' => $this->is_active,
        ];
    }
}
