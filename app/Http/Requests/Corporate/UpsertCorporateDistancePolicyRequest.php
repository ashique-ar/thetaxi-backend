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
            'route_contract_version' => ['sometimes', 'integer', 'in:2'],
            'route_template' => ['sometimes', Rule::in(\App\Services\ContractualAnchorSequenceService::TEMPLATES)],
            'route_anchor_sequence' => ['nullable', 'array', 'min:2', 'max:30', 'required_if:route_template,custom_sequence,customer_base_to_customer_base,operator_base_to_operator_base'],
            'route_anchor_sequence.*.type' => ['required_with:route_anchor_sequence', Rule::in(\App\Services\ContractualAnchorSequenceService::TYPES)],
            'route_anchor_sequence.*.location_id' => ['nullable', 'uuid', 'exists:corporate_contract_locations,id'],
            'route_anchor_sequence.*.label' => ['nullable', 'string', 'max:150'],
            'route_anchor_sequence.*.billable_to_next' => ['nullable', 'boolean'],
            'route_anchor_sequence.*.rate_treatment_to_next' => ['nullable', Rule::in(['passenger', 'movement'])],
            'route_anchor_sequence.*.cap_km_to_next' => ['nullable', 'numeric', 'gt:0'],
            'origin_address' => ['nullable', 'string', 'max:2000', 'required_if:route_template,full_movement,operator_base_to_dropoff'],
            'origin_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_address'],
            'origin_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_address'],
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
