<?php
// app/Http/Requests/Vehicle/VehicleInsurance/UpdateVehicleInsuranceRequest.php

namespace App\Http\Requests\Vehicle\VehicleInsurance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleInsuranceRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'provider_id' => ['sometimes', 'nullable', 'exists:vehicle_insurance_providers,id'],
            'insurance_type_id' => ['sometimes', 'nullable', 'exists:vehicle_insurance_types,id'],
            'policy_number' => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'renewal_reminder_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:end_date'],
            'renewal_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,expired,renewed,cancelled'],
            'renewed_from_id' => ['sometimes', 'nullable', 'exists:vehicle_insurances,id'],
            'document_files' => ['sometimes', 'nullable', 'array'],
            'document_files.*.path' => ['nullable', 'string'],
            'document_files.*.url' => ['nullable', 'string'],
            'remarks' => ['sometimes', 'nullable', 'string'],
            // 'premium_amount' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
