<?php
// app/Http/Requests/Vehicle/VehiclePricingSlabDefinition/CreateVehiclePricingSlabDefinitionRequest.php

namespace App\Http\Requests\Vehicle\VehiclePricingSlabDefinition;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehiclePricingSlabDefinitionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge($this->durationFieldsForType((string) $this->input('type')));
    }

    public function rules()
    {
        return [
            'service_type_id' => ['required', 'exists:service_types,id'],
            'service_package_id' => ['nullable', 'uuid', 'exists:service_packages,id'],
            'name' => ['required', 'string', 'max:255'],
            'min_days' => ['nullable', 'required_if:type,days,per_day', 'integer', 'min:0'],
            'max_days' => ['nullable', 'integer', 'gte:min_days'],
            'min_hours' => ['nullable', 'required_if:type,hours', 'integer', 'min:0'],
            'max_hours' => ['nullable', 'integer', 'gte:min_hours'],
            'min_minutes' => ['nullable', 'required_if:type,minutes', 'integer', 'min:0'],
            'max_minutes' => ['nullable', 'integer', 'gte:min_minutes'],
            'type' => ['required', 'string', 'in:minutes,hours,days,per_day,per_km,flat_rate'],
            'max_km_per_day' => ['nullable', 'integer', 'min:0'],
            'max_km_per_package' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'owner_type' => ['nullable', 'string', 'in:corporate'],
            'owner_id' => ['nullable', 'uuid', 'exists:corporates,id', 'required_with:owner_type'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function durationFieldsForType(string $type): array
    {
        return [
            'min_minutes' => $type === 'minutes' ? $this->input('min_minutes') : null,
            'max_minutes' => $type === 'minutes' ? $this->input('max_minutes') : null,
            'min_hours' => in_array($type, ['hours', 'flat_rate', 'per_km'], true) ? $this->input('min_hours') : null,
            'max_hours' => in_array($type, ['hours', 'flat_rate', 'per_km'], true) ? $this->input('max_hours') : null,
            'min_days' => in_array($type, ['days', 'per_day'], true) ? $this->input('min_days') : null,
            'max_days' => in_array($type, ['days', 'per_day'], true) ? $this->input('max_days') : null,
        ];
    }
}
