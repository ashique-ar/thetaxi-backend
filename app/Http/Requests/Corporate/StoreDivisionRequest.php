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
        $departmentRoute = $this->route('department');
        $divisionRoute = $this->route('division');
        $departmentId = is_object($departmentRoute)
            ? $departmentRoute->id
            : ($departmentRoute ?? (is_object($divisionRoute) ? $divisionRoute->department_id : $this->input('department_id')));
        $divisionId = is_object($divisionRoute) ? $divisionRoute->id : $divisionRoute;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporate_divisions', 'name')
                    ->where('department_id', $departmentId)
                    ->ignore($divisionId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
