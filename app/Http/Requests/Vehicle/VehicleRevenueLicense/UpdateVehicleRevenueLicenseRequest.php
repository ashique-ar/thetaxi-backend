<?php

namespace App\Http\Requests\Vehicle\VehicleRevenueLicense;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRevenueLicenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_date' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_date'],
            'renewal_reminder_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:expiry_date'],
            'renewal_date' => ['sometimes', 'nullable', 'date'],
            'renewed_from_id' => ['sometimes', 'nullable', 'exists:vehicle_revenue_licenses,id'],
            'authority_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_files' => ['sometimes', 'nullable', 'array'],
            'document_files.*.path' => ['nullable', 'string'],
            'document_files.*.url' => ['nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,expired,renewed,cancelled'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
