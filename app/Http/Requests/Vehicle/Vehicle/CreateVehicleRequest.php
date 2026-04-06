<?php
// app/Http/Requests/Vehicle/Vehicle/CreateVehicleRequest.php

namespace App\Http\Requests\Vehicle\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleRequest extends FormRequest
{


    public function rules()
    {
        return [
            'class_id' => ['nullable', 'exists:vehicle_classes,id'],
            'fuel_type_id' => ['nullable', 'exists:vehicle_fuel_types,id'],
            'transmission_id' => ['nullable', 'exists:vehicle_transmissions,id'],
            'contract_type_id' => ['nullable', 'exists:vehicle_contract_types,id'],
            'category_id' => ['nullable', 'exists:vehicle_categories,id'],
            'model_id' => ['nullable', 'exists:vehicle_models,id'],
            'make_id' => ['nullable', 'exists:vehicle_makes,id'],
            'owner_id' => ['nullable', 'exists:vehicle_owners,id'],
            'grade_id' => ['nullable', 'exists:vehicle_grades,id'],
            'vehicle_group_id' => ['nullable', 'exists:vehicle_groups,id'],
            'default_driver_id' => ['nullable', 'exists:drivers,id'],
            'title' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:255', 'unique:vehicles,registration_no'],
            'chasis_no' => ['nullable', 'string', 'max:255'],
            'engine_no' => ['nullable', 'string', 'max:255'],
            'license_plate' => ['nullable', 'string', 'max:255', 'unique:vehicles,license_plate'],
            'model_year' => ['nullable', 'integer'],
            'color' => ['nullable', 'string', 'max:100'],
            'no_od_doors' => ['nullable', 'integer'],
            'ac' => ['nullable', 'nullable', 'boolean'],
            'thumbnail' => ['nullable', 'array'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:vehicles,slug'],
            'bags' => ['nullable', 'integer'],
            'seats' => ['nullable', 'integer'],
            'refundable_deposit' => ['nullable', 'numeric'],
            'year' => ['nullable', 'integer'],
            'tagline' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ];
    }
}
