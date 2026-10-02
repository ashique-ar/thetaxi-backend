<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id)
            ],
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id)
            ],
            'password' => ['sometimes', 'string', Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            ],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'agent_id' => ['sometimes', 'exists:agents,id'],
            'is_active' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', 'max:50'],
            'language' => ['sometimes', 'string', 'max:10'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
            'profile_image' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'remove_profile_image' => ['sometimes', 'boolean'],
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
            'first_name.string' => 'First name must be a string',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email address is already registered',
            'phone.unique' => 'This phone number is already registered',
            'role_id.exists' => 'Selected role is invalid',
            'agent_id.exists' => 'Selected agent is invalid',
            'roles.*.exists' => 'One or more selected roles are invalid',
            'permissions.*.exists' => 'One or more selected permissions are invalid',
            'profile_image.image' => 'Profile image must be a valid image file',
            'profile_image.max' => 'Profile image must not exceed 2MB',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge([
                'email' => strtolower($this->input('email')),
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
            // Custom validation logic
            if ($this->filled('phone') && !$this->isValidPhoneNumber($this->phone)) {
                $validator->errors()->add('phone', 'Please enter a valid phone number');
            }
            
            // Prevent user from removing their own admin role
            $user = $this->route('user');
            $currentUser = $this->user();
            
            if ($user->id === $currentUser->id && $this->filled('roles')) {
                $currentRoles = $user->getRolesArray();
                $newRoles = $this->input('roles');
                
                if (in_array('admin', $currentRoles) && !in_array('admin', $newRoles)) {
                    $validator->errors()->add('roles', 'You cannot remove your own admin role');
                }
            }
        });
    }

    /**
     * Validate phone number format
     *
     * @param string $phone
     * @return bool
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }
}
