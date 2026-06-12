<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class DriverBattaRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicle_group_id' => ['required', 'exists:vehicle_groups,id'],
            'batta_category' => ['required', 'string', 'max:100'],
            'base_amount' => ['required', 'numeric', 'min:0'],
            'night_amount' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
