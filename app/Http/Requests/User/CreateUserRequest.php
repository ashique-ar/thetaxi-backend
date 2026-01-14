<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            ],
            'role_id' => ['nullable', 'exists:roles,id'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'is_active' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
            'profile_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'send_welcome_email' => ['nullable', 'boolean'],
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
            'first_name.required' => 'First name is required',
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email address is already registered',
            'phone.unique' => 'This phone number is already registered',
            'password.required' => 'Password is required',
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
        $this->merge([
            'email' => strtolower($this->input('email')),
            'is_active' => $this->boolean('is_active', true),
            'send_welcome_email' => $this->boolean('send_welcome_email', false),
        ]);
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
