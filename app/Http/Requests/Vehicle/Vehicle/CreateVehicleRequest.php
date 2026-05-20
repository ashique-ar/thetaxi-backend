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
            'ownership_type' => ['nullable', 'string', 'in:company_owned,package_fleet,outside_call_taxi,rented_asset,leased_asset'],
            'usage_type' => ['nullable', 'string', 'in:standard_fleet,package_fleet,outside_call_taxi,rental,lease,shared_pool'],
            'payment_model' => ['nullable', 'string', 'in:none,commission,fixed_monthly,commission_plus_fixed'],
            'assignment_policy' => ['nullable', 'string', 'in:any_driver,specific_driver,owner_driver,unassigned'],
            'monthly_payment_commitment' => ['nullable', 'numeric', 'min:0'],
            'monthly_mileage_limit' => ['nullable', 'numeric', 'min:0'],
            'excess_mileage_rate' => ['nullable', 'numeric', 'min:0'],
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
