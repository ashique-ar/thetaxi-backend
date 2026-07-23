<?php
// app/Http/Requests/Vehicle/VehicleAddon/CreateVehicleAddonRequest.php

namespace App\Http\Requests\Vehicle\VehicleAddon;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleAddonRequest extends FormRequest
{


    public function rules(): array
    {
        return [
            // Basic info
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'thumbnail' => ['nullable', 'string', 'max:500'],

            // Classification
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'category_id' => ['nullable', 'uuid', 'exists:vehicle_addon_categories,id'],
            'addon_type' => ['required', 'in:service,item,insurance,fee,discount'],

            // Pricing configuration
            'pricing_type' => ['required', 'in:fixed,per_day,per_hour,per_km,percentage,tiered'],
            'amount' => ['required', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'min:0'], // Alias - will be mapped to amount
            'rate_type' => ['nullable', 'in:flat,percentage'],
            'billing_type' => ['nullable', 'in:per_package,per_day,per_hour'],

            // Quantity configuration
            'quantity_unit' => ['nullable', 'in:pieces,km,hours,days,passengers'],
            'min_qty' => ['nullable', 'integer', 'min:0'],
            'max_qty' => ['nullable', 'integer', 'gte:min_qty'],
            'min_quantity' => ['nullable', 'integer', 'min:0'], // Alias
            'max_quantity' => ['nullable', 'integer', 'gte:min_quantity'], // Alias
            'allow_quantity_selection' => ['nullable', 'boolean'],

            // Threshold pricing
            'threshold_quantity' => ['nullable', 'integer', 'min:1'],
            'threshold_price' => ['nullable', 'numeric', 'min:0', 'required_with:threshold_quantity'],

            // Tax configuration
            'is_taxable' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Status and availability
            'is_active' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_optional' => ['nullable', 'boolean'],
            'availability_type' => ['nullable', 'in:always,conditional,seasonal,service_specific'],
            'availability_conditions' => ['nullable', 'array'],

            // Vehicle compatibility
            'compatible_vehicle_types' => ['nullable', 'array'],
            'compatible_vehicle_types.*' => ['string'],

            // Validity period
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'valid_until' => ['nullable', 'date'], // Alias for valid_to

            // Display settings
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'icon' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],

            // Notes and integration
            'internal_notes' => ['nullable', 'string'],
            'integration_settings' => ['nullable', 'array'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Map frontend aliases to backend field names
        $mappings = [
            'base_price' => 'amount',
            'min_quantity' => 'min_qty',
            'max_quantity' => 'max_qty',
            'valid_until' => 'valid_to',
            'tax_percentage' => 'tax_rate',
        ];

        foreach ($mappings as $alias => $actual) {
            if ($this->has($alias) && !$this->has($actual)) {
                $this->merge([$actual => $this->input($alias)]);
            }
        }
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The addon name is required.',
            'addon_type.required' => 'Please select an addon type.',
            'addon_type.in' => 'Invalid addon type selected.',
            'pricing_type.required' => 'Please select a pricing type.',
            'pricing_type.in' => 'Invalid pricing type selected.',
            'amount.required' => 'The price amount is required.',
            'amount.min' => 'The price amount must be at least 0.',
            'threshold_price.required_with' => 'Threshold price is required when threshold quantity is set.',
        ];
    }
}
