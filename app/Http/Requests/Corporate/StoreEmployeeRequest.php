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
        ];
    }
}
