<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;

class PreviewCorporateDistancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'vehicle_group_id' => ['required', 'uuid', 'exists:vehicle_groups,id'],
            'pickup' => ['required', 'array'],
            'pickup.address' => ['required', 'string', 'max:2000'],
            'pickup.latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup.longitude' => ['required', 'numeric', 'between:-180,180'],
            'dropoff' => ['required', 'array'],
            'dropoff.address' => ['required', 'string', 'max:2000'],
            'dropoff.latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
