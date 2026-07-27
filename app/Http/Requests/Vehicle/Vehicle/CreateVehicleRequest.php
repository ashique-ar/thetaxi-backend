<?php
// app/Http/Requests/Vehicle/Vehicle/CreateVehicleRequest.php

namespace App\Http\Requests\Vehicle\Vehicle;

use App\Http\Requests\Vehicle\Vehicle\Concerns\HasCurrentVehicleLeaseRules;
use App\Rules\UniqueVehiclePlate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVehicleRequest extends FormRequest
{
    use HasCurrentVehicleLeaseRules;

    public function rules()
    {
        $externalOwnershipTypes = ['package_fleet', 'outside_call_taxi', 'rented_asset', 'leased_asset'];

        return [
            'class_id' => ['nullable', 'exists:vehicle_classes,id'],
            'fuel_type_id' => ['nullable', 'exists:vehicle_fuel_types,id'],
            'transmission_id' => ['nullable', 'exists:vehicle_transmissions,id'],
            'contract_type_id' => ['nullable', 'exists:vehicle_contract_types,id'],
            'category_id' => ['nullable', 'exists:vehicle_categories,id'],
            'model_id' => ['nullable', 'exists:vehicle_models,id'],
            'make_id' => ['nullable', 'exists:vehicle_makes,id'],
            'owner_id' => [
                'nullable',
                Rule::prohibitedIf(fn () => $this->input('ownership_type', 'company_owned') === 'company_owned'),
                Rule::requiredIf(fn () => in_array(
                    $this->input('ownership_type', 'company_owned'),
                    $externalOwnershipTypes,
                    true
                )),
                'exists:vehicle_owners,id',
            ],
            'grade_id' => ['nullable', 'exists:vehicle_grades,id'],
            'vehicle_group_id' => ['nullable', 'exists:vehicle_groups,id'],
            'default_driver_id' => ['nullable', 'exists:drivers,id'],
            'ownership_type' => ['required', 'string', 'in:company_owned,package_fleet,outside_call_taxi,rented_asset,leased_asset'],
            'usage_type' => ['nullable', 'string', 'in:standard_fleet,package_fleet,outside_call_taxi,rental,lease,shared_pool'],
            'payment_model' => ['nullable', 'string', 'in:none,commission,fixed_monthly,commission_plus_fixed'],
            'owner_payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'assignment_policy' => ['nullable', 'string', 'in:any_driver,specific_driver,owner_driver,unassigned'],
            'agreement_start_date' => ['nullable', 'date'],
            'agreement_end_date' => ['nullable', 'date', 'after_or_equal:agreement_start_date'],
            'agreement_status' => ['nullable', 'string', 'in:pending,active,ended,cancelled'],
            'initial_mileage' => ['nullable', 'integer', 'min:0'],
            'current_mileage' => ['prohibited'],
            'handover_mileage' => ['prohibited'],
            'handover_at' => ['prohibited'],
            'handover_location' => ['prohibited'],
            'handover_notes' => ['prohibited'],
            'monthly_payment_commitment' => ['nullable', 'numeric', 'min:0'],
            'monthly_mileage_limit' => ['nullable', 'numeric', 'min:0'],
            'excess_mileage_rate' => ['nullable', 'numeric', 'min:0'],
            'title' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:255', 'unique:vehicles,registration_no'],
            'chasis_no' => ['nullable', 'string', 'max:255', 'different:engine_no'],
            'engine_no' => ['nullable', 'string', 'max:255', 'different:chasis_no'],
            'license_plate' => ['nullable', 'string', 'max:255', new UniqueVehiclePlate],
            'model_year' => ['nullable', 'integer'],
            'color' => ['nullable', 'string', 'max:100'],
            'no_od_doors' => ['nullable', 'integer'],
            'ac' => ['nullable', 'nullable', 'boolean'],
            'thumbnail' => ['nullable', 'array'],
            'actual_vehicle_images' => ['nullable', 'array'],
            'actual_vehicle_images.*.path' => ['nullable', 'string'],
            'actual_vehicle_images.*.url' => ['nullable', 'string'],
            'actual_vehicle_images.*.name' => ['nullable', 'string'],
            'actual_vehicle_images.*.size' => ['nullable', 'numeric'],
            'actual_vehicle_images.*.type' => ['nullable', 'string'],
            'actual_vehicle_images.*.isImage' => ['nullable', 'boolean'],
            'actual_vehicle_images.*.is_primary' => ['nullable', 'boolean'],
            'actual_vehicle_images.*.uploaded_at' => ['nullable', 'date'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:vehicles,slug'],
            'bags' => ['nullable', 'integer'],
            'seats' => ['nullable', 'integer'],
            'refundable_deposit' => ['nullable', 'numeric'],
            'year' => ['nullable', 'integer'],
            'tagline' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            ...$this->currentVehicleLeaseRules(),
        ];
    }
}
