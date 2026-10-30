<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRoleRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guardName = $this->input('guard_name', config('permissions.canonical_guard', 'api'));
        $guards = array_values(array_unique(array_merge(
            [config('permissions.canonical_guard', 'api')],
            config('permissions.legacy_guards', ['web'])
        )));

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where(fn ($query) => $query->where('guard_name', $guardName)),
            ],
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'guard_name' => ['required', 'string', Rule::in($guards)],
            'context_types' => ['nullable', 'array'],
            'context_types.*' => ['string', 'in:customer,vehicle_owner,staff,agent,driver,corporate,internal'],
            'auto_assign_contexts' => ['sometimes', 'boolean'],
            'permissions' => ['nullable', 'array'],
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
            'name.required' => 'Role name is required',
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
        $this->merge([
            'name' => strtolower($this->input('name')),
            'guard_name' => $this->input('guard_name', config('permissions.canonical_guard', 'api')),
        ]);
    }
}
