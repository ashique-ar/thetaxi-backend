<?php
// app/Http/Resources/DrivingLicenseResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DrivingLicenseResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                 => $this->id,
            'user_id'            => $this->user_id,
            'license_number'     => $this->license_number,
            'license_type'       => $this->license_type,
            'issue_date'         => $this->issue_date,
            'expiry_date'        => $this->expiry_date,
            'issuing_authority'  => $this->issuing_authority,
            'license_class'      => $this->license_class,
            'restrictions'       => $this->restrictions,
            'endorsements'       => $this->endorsements,
            'country'            => $this->country,
            'state'              => $this->state,
            'document_path'      => $this->document_path,
            'status'             => $this->status,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
