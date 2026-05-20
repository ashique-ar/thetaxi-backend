<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'department_id' => ['required', 'uuid', 'exists:corporate_departments,id'],
            'division_id' => ['nullable', 'uuid', 'exists:corporate_divisions,id'],
            'role' => ['required', 'string'],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'locations' => ['nullable', 'array'],
            'locations.*.id' => ['nullable', 'uuid', 'exists:corporate_employee_locations,id'],
            'locations.*.label' => ['nullable', 'string', 'max:100'],
            'locations.*.address' => ['nullable', 'string'],
            'locations.*.latitude' => ['nullable', 'numeric'],
            'locations.*.longitude' => ['nullable', 'numeric'],
            'locations.*.city' => ['nullable', 'string', 'max:100'],
            'locations.*.country' => ['nullable', 'string', 'max:100'],
            'locations.*.place_id' => ['nullable', 'string', 'max:255'],
            'locations.*.placeId' => ['nullable', 'string', 'max:255'],
            'locations.*.is_default_pickup' => ['nullable', 'boolean'],
            'locations.*.is_default_dropoff' => ['nullable', 'boolean'],
            'locations.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
