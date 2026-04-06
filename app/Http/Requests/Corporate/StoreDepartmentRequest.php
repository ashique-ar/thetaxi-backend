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
        $corporateRoute = $this->route('corporate');
        $departmentRoute = $this->route('department');
        $corporateId = $this->input('corporate_id') ?? (is_object($corporateRoute) ? $corporateRoute->id : $corporateRoute);
        $departmentId = is_object($departmentRoute) ? $departmentRoute->id : $departmentRoute;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporate_departments', 'name')
                    ->where('corporate_id', $corporateId)
                    ->ignore($departmentId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
