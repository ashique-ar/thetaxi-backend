<?php
// app/Http/Requests/DrivingLicense/UpdateDrivingLicenseRequest.php
namespace App\Http\Requests\DrivingLicense;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDrivingLicenseRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('driving_license')->id;

        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'license_number' => ["sometimes", "required", "string", "max:100", "unique:driving_licenses,license_number,{$id}"],
            'license_type' => ['sometimes', 'nullable', 'exists:driving_license_types,id'],
            'issue_date' => ['sometimes', 'required', 'date'],
            'expiry_date' => ['sometimes', 'required', 'date', 'after_or_equal:issue_date'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:255'],
            'license_class' => ['sometimes', 'nullable', 'string', 'max:50'],
            'restrictions' => ['sometimes', 'nullable', 'string'],
            'endorsements' => ['sometimes', 'nullable', 'string'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_path' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', 'in:active,expired,suspended'],
        ];
    }
}
