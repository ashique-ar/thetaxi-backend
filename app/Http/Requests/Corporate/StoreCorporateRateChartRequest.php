<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorporateRateChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'vehicle_group_id' => ['nullable', 'uuid', 'exists:vehicle_groups,id'],
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
            'per_km_rate' => ['nullable', 'numeric', 'min:0'],
            'per_hour_rate' => ['nullable', 'numeric', 'min:0'],
            'fixed_route_pricing' => ['nullable', 'array'],
        ];
    }
}
