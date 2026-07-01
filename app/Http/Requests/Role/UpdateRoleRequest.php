<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->currentRole();
        $guardName = $this->input('guard_name', $role?->guard_name ?? config('auth.defaults.guard', 'web'));
        
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where('guard_name', $guardName))
                    ->ignore($role?->id)
            ],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'guard_name' => ['sometimes', 'string', 'max:255'],
            'context_types' => ['sometimes', 'nullable', 'array'],
            'context_types.*' => ['string', 'in:customer,vehicle_owner,staff,agent,driver,corporate,internal'],
            'auto_assign_contexts' => ['sometimes', 'boolean'],
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
            $role = $this->currentRole();

            if (! $role) {
                return;
            }
            
            // Prevent renaming system roles
            if ($this->filled('name') && in_array($role->name, ['admin', 'super-admin']) && $this->input('name') !== $role->name) {
                $validator->errors()->add('name', 'System roles cannot be renamed');
            }
        });
    }

    private function currentRole(): ?Role
    {
        $role = $this->route('role');

        if ($role instanceof Role) {
            return $role;
        }

        if ($role) {
            return Role::find($role);
        }

        return null;
    }
}
