<?php
// app/Http/Requests/Vehicle/VehicleGrade/UpdateVehicleGradeRequest.php

namespace App\Http\Requests\Vehicle\VehicleGrade;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleGradeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
