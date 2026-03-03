<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $departmentId = $this->route('department') ?? $this->input('department_id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporate_divisions', 'name')
                    ->where('department_id', $departmentId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
