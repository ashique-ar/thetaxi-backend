<?php
// app/Http/Requests/Vehicle/VehicleGrade/CreateVehicleGradeRequest.php

namespace App\Http\Requests\Vehicle\VehicleGrade;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleGradeRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'name'        => ['required','string','max:255'],
            'description' => ['nullable','string'],
        ];
    }
}
