<?php
// app/Http/Requests/Vehicle/VehicleGroup/CreateVehicleGroupRequest.php

namespace App\Http\Requests\Vehicle\VehicleGroup;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleGroupRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'grade_id'    => ['required','exists:vehicle_grades,id'],
            'make_id'     => ['required','exists:vehicle_makes,id'],
            'model_id'    => ['required','exists:vehicle_models,id'],
            'transmission_id' => ['required','exists:vehicle_transmissions,id'],
            'fuel_type_id'=> ['required','exists:vehicle_fuel_types,id'],
            'category_id' => ['required','exists:vehicle_categories,id'],
            'class_id'    => ['required','exists:vehicle_classes,id'],
            'name'        => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'specs'       => ['nullable','array'],
            'images'      => ['nullable','array'],
        ];
    }
}
