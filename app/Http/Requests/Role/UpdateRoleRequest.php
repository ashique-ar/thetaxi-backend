<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($role->id)
            ],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'guard_name' => ['sometimes', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Role name must be unique',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'name' => strtolower($this->input('name')),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $role = $this->route('role');
            
            // Prevent renaming system roles
            if ($this->filled('name') && in_array($role->name, ['admin', 'super-admin']) && $this->input('name') !== $role->name) {
                $validator->errors()->add('name', 'System roles cannot be renamed');
            }
        });
    }
}
