<?php
// app/Http/Requests/DrivingLicenseType/UpdateDrivingLicenseTypeRequest.php
namespace App\Http\Requests\DrivingLicenseType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDrivingLicenseTypeRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
