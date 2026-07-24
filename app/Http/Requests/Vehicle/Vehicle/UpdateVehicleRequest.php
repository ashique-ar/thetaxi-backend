<?php
// app/Http/Requests/Vehicle/Vehicle/UpdateVehicleRequest.php

namespace App\Http\Requests\Vehicle\Vehicle;

use App\Rules\UniqueVehiclePlate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{


    public function rules()
    {
        $vehicle = $this->route('vehicle');
        $id = $vehicle->id;
        $externalOwnershipTypes = ['package_fleet', 'outside_call_taxi', 'rented_asset', 'leased_asset'];
        $effectiveOwnershipType = $this->input('ownership_type', $vehicle->ownership_type ?? 'company_owned');
        $effectiveOwnerId = $this->exists('owner_id') ? $this->input('owner_id') : $vehicle->owner_id;

        return [
            'class_id' => ['sometimes', 'nullable', 'exists:vehicle_classes,id'],
            'fuel_type_id' => ['sometimes', 'nullable', 'exists:vehicle_fuel_types,id'],
            'transmission_id' => ['sometimes', 'nullable', 'exists:vehicle_transmissions,id'],
            'contract_type_id' => ['sometimes', 'nullable', 'exists:vehicle_contract_types,id'],
            'category_id' => ['sometimes', 'nullable', 'exists:vehicle_categories,id'],
            'model_id' => ['sometimes', 'nullable', 'exists:vehicle_models,id'],
            'make_id' => ['sometimes', 'nullable', 'exists:vehicle_makes,id'],
            'owner_id' => [
                'nullable',
                Rule::prohibitedIf(fn () => $effectiveOwnershipType === 'company_owned'),
                Rule::requiredIf(fn () => in_array($effectiveOwnershipType, $externalOwnershipTypes, true)
                    && blank($effectiveOwnerId)),
                'exists:vehicle_owners,id',
            ],
            'grade_id' => ['sometimes', 'nullable', 'exists:vehicle_grades,id'],
            'vehicle_group_id' => ['sometimes', 'nullable', 'exists:vehicle_groups,id'],
            'default_driver_id' => ['sometimes', 'nullable', 'exists:drivers,id'],
            'ownership_type' => ['sometimes', 'required', 'string', 'in:company_owned,package_fleet,outside_call_taxi,rented_asset,leased_asset'],
            'usage_type' => ['sometimes', 'nullable', 'string', 'in:standard_fleet,package_fleet,outside_call_taxi,rental,lease,shared_pool'],
            'payment_model' => ['sometimes', 'nullable', 'string', 'in:none,commission,fixed_monthly,commission_plus_fixed'],
            'owner_payment_method_id' => ['sometimes', 'nullable', 'exists:payment_methods,id'],
            'assignment_policy' => ['sometimes', 'nullable', 'string', 'in:any_driver,specific_driver,owner_driver,unassigned'],
            'agreement_start_date' => ['sometimes', 'nullable', 'date'],
            'agreement_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:agreement_start_date'],
            'agreement_status' => ['sometimes', 'nullable', 'string', 'in:pending,active,ended,cancelled'],
            'initial_mileage' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'current_mileage' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'handover_mileage' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'handover_at' => ['sometimes', 'nullable', 'date'],
            'handover_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'handover_notes' => ['sometimes', 'nullable', 'string'],
            'monthly_payment_commitment' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'monthly_mileage_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'excess_mileage_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'registration_no' => ["sometimes", "nullable", "string", "max:255", "unique:vehicles,registration_no,{$id}"],
            'chasis_no' => ['sometimes', 'nullable', 'string', 'max:255', 'different:engine_no'],
            'engine_no' => ['sometimes', 'nullable', 'string', 'max:255', 'different:chasis_no'],
            'license_plate' => ['sometimes', 'nullable', 'string', 'max:255', new UniqueVehiclePlate($id)],
            'model_year' => ['sometimes', 'nullable', 'integer'],
            'color' => ['sometimes', 'nullable', 'string', 'max:100'],
            'no_od_doors' => ['sometimes', 'nullable', 'integer'],
            'ac' => ['sometimes', 'nullable', 'boolean'],
            'thumbnail' => ['sometimes', 'nullable', 'array'],
            'actual_vehicle_images' => ['sometimes', 'nullable', 'array'],
            'actual_vehicle_images.*.path' => ['nullable', 'string'],
            'actual_vehicle_images.*.url' => ['nullable', 'string'],
            'actual_vehicle_images.*.name' => ['nullable', 'string'],
            'actual_vehicle_images.*.size' => ['nullable', 'numeric'],
            'actual_vehicle_images.*.type' => ['nullable', 'string'],
            'actual_vehicle_images.*.isImage' => ['nullable', 'boolean'],
            'actual_vehicle_images.*.is_primary' => ['nullable', 'boolean'],
            'actual_vehicle_images.*.uploaded_at' => ['nullable', 'date'],
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
