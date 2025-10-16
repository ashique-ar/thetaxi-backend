<?php
// app/Http/Requests/DrivingLicense/CreateDrivingLicenseRequest.php
namespace App\Http\Requests\DrivingLicense;

use Illuminate\Foundation\Http\FormRequest;

class CreateDrivingLicenseRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'user_id'            => ['required','exists:users,id'],
            'license_number'     => ['required','string','max:100','unique:driving_licenses,license_number'],
            'license_type'       => ['nullable','exists:driving_license_types,id'],
            'issue_date'         => ['required','date'],
            'expiry_date'        => ['required','date','after_or_equal:issue_date'],
            'issuing_authority'  => ['nullable','string','max:255'],
            'license_class'      => ['nullable','string','max:50'],
            'restrictions'       => ['nullable','string'],
            'endorsements'       => ['nullable','string'],
            'country'            => ['nullable','string','max:100'],
            'state'              => ['nullable','string','max:100'],
            'document_path'      => ['nullable','string'],
            'status'             => ['required','in:active,expired,suspended'],
        ];
    }
}
