<?php
// app/Http/Resources/StaffResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'staff_type'      => $this->staff_type,
            'code'            => $this->code,
            'dob'             => $this->dob,
            'license_no'      => $this->license_no,
            'license_expiry'  => $this->license_expiry,
            'address'         => $this->address,
            'country_id'      => $this->country_id,
            'state_id'        => $this->state_id,
            'city'         => $this->city,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
