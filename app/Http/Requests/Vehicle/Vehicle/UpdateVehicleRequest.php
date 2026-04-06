<?php
// app/Http/Requests/Vehicle/Vehicle/UpdateVehicleRequest.php

namespace App\Http\Requests\Vehicle\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('vehicle')->id;

        return [
            'class_id' => ['sometimes', 'nullable', 'exists:vehicle_classes,id'],
            'fuel_type_id' => ['sometimes', 'nullable', 'exists:vehicle_fuel_types,id'],
            'transmission_id' => ['sometimes', 'nullable', 'exists:vehicle_transmissions,id'],
            'contract_type_id' => ['sometimes', 'nullable', 'exists:vehicle_contract_types,id'],
            'category_id' => ['sometimes', 'nullable', 'exists:vehicle_categories,id'],
            'model_id' => ['sometimes', 'nullable', 'exists:vehicle_models,id'],
            'make_id' => ['sometimes', 'nullable', 'exists:vehicle_makes,id'],
            'owner_id' => ['sometimes', 'nullable', 'exists:vehicle_owners,id'],
            'grade_id' => ['sometimes', 'nullable', 'exists:vehicle_grades,id'],
            'vehicle_group_id' => ['sometimes', 'nullable', 'exists:vehicle_groups,id'],
            'default_driver_id' => ['sometimes', 'nullable', 'exists:drivers,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'registration_no' => ["sometimes", "nullable", "string", "max:255", "unique:vehicles,registration_no,{$id}"],
            'chasis_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'engine_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'license_plate' => ['sometimes', 'nullable', 'string', 'max:255', "unique:vehicles,license_plate,{$id}"],
            'model_year' => ['sometimes', 'nullable', 'integer'],
            'color' => ['sometimes', 'nullable', 'string', 'max:100'],
            'no_od_doors' => ['sometimes', 'nullable', 'integer'],
            'ac' => ['sometimes', 'nullable', 'boolean'],
            'thumbnail' => ['sometimes', 'nullable', 'array'],
            'slug' => ["sometimes", "nullable", "string", "max:255", "unique:vehicles,slug,{$id}"],
            'bags' => ['sometimes', 'nullable', 'integer'],
            'seats' => ['sometimes', 'nullable', 'integer'],
            'refundable_deposit' => ['sometimes', 'nullable', 'numeric'],
            'year' => ['sometimes', 'nullable', 'integer'],
            'tagline' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
