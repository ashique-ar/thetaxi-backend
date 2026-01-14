<?php
// app/Http/Requests/DrivingLicenseType/CreateDrivingLicenseTypeRequest.php
namespace App\Http\Requests\DrivingLicenseType;

use Illuminate\Foundation\Http\FormRequest;

class CreateDrivingLicenseTypeRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
