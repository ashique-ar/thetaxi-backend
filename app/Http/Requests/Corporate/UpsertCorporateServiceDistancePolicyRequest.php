<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCorporateServiceDistancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $corporateId = $this->route('corporate')?->id;

        return [
            'application_mode' => ['required', Rule::in(['inherit', 'enabled', 'disabled'])],
            'policy_id' => [
                'nullable', 'uuid',
                Rule::exists('corporate_distance_pricing_policies', 'id')
                    ->where(fn ($query) => $query->where('corporate_id', $corporateId)->whereNull('deleted_at')),
            ],
            'origin_location_override' => ['nullable', 'array'],
            'origin_location_override.address' => ['required_with:origin_location_override', 'string', 'max:2000'],
            'origin_location_override.latitude' => ['required_with:origin_location_override', 'numeric', 'between:-90,90'],
            'origin_location_override.longitude' => ['required_with:origin_location_override', 'numeric', 'between:-180,180'],
            'return_location_override' => ['nullable', 'array'],
            'return_location_override.address' => ['required_with:return_location_override', 'string', 'max:2000'],
            'return_location_override.latitude' => ['required_with:return_location_override', 'numeric', 'between:-90,90'],
            'return_location_override.longitude' => ['required_with:return_location_override', 'numeric', 'between:-180,180'],
            'include_origin_to_pickup' => ['nullable', 'boolean'],
            'include_dropoff_to_return' => ['nullable', 'boolean'],
            'movement_rate_method' => ['nullable', Rule::in(['normal_rate', 'separate_rate', 'included'])],
            'outbound_rate' => ['nullable', 'numeric', 'min:0', 'required_if:movement_rate_method,separate_rate'],
            'return_rate' => ['nullable', 'numeric', 'min:0', 'required_if:movement_rate_method,separate_rate'],
            'maximum_outbound_km' => ['nullable', 'numeric', 'gt:0'],
            'maximum_return_km' => ['nullable', 'numeric', 'gt:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
