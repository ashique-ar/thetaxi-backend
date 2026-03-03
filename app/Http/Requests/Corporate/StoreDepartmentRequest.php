<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $corporateId = $this->input('corporate_id') ?? $this->route('corporate');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporate_departments', 'name')
                    ->where('corporate_id', $corporateId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
