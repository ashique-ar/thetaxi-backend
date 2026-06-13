<?php

namespace App\Http\Requests\Vehicle\VehicleRevenueLicense;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleRevenueLicenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'authority_name' => ['nullable', 'string', 'max:255'],
            'document_files' => ['nullable', 'array'],
            'document_files.*.path' => ['nullable', 'string'],
            'document_files.*.url' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,expired,renewed,cancelled'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
