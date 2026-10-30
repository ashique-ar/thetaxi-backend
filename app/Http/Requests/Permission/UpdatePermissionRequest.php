<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $permission = $this->route('permission');
        $guardName = $this->input('guard_name', $permission->guard_name);
        $guards = [config('permissions.canonical_guard', 'api')];
        
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('permissions', 'name')
                    ->where(fn ($query) => $query->where('guard_name', $guardName))
                    ->ignore($permission->id),
            ],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'guard_name' => ['sometimes', 'string', Rule::in($guards)],
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
            'name.unique' => 'Permission name must be unique',
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $permission = $this->route('permission');
            $nameChanged = $this->filled('name') && $this->input('name') !== $permission->name;
            $guardChanged = $this->filled('guard_name') && $this->input('guard_name') !== $permission->guard_name;

            if ((! $nameChanged && ! $guardChanged)
                || (! $permission->roles()->exists() && ! $permission->users()->exists())) {
                return;
            }

            if ($nameChanged) {
                $validator->errors()->add('name', 'A permission key cannot be changed while it is assigned to a role or user.');
            }
            if ($guardChanged) {
                $validator->errors()->add('guard_name', 'A permission guard cannot be changed while it is assigned to a role or user.');
            }
        });
    }
}
