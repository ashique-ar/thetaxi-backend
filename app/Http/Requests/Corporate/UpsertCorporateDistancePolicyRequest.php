<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCorporateDistancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'default_service_mode' => ['required', Rule::in(['enabled', 'disabled'])],
            'origin_address' => ['required', 'string', 'max:2000'],
            'origin_latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin_longitude' => ['required', 'numeric', 'between:-180,180'],
            'return_address' => ['nullable', 'string', 'max:2000', 'required_with:return_latitude,return_longitude'],
            'return_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:return_address,return_longitude'],
            'return_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:return_address,return_latitude'],
            'include_origin_to_pickup' => ['required', 'boolean'],
            'include_dropoff_to_return' => ['required', 'boolean'],
            'movement_rate_method' => ['required', Rule::in(['normal_rate', 'separate_rate', 'included'])],
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
