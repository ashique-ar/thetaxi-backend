<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
            ],
            'password_confirmation' => ['required', 'same:password'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
            'terms_accepted' => ['required', 'boolean', 'accepted'],
            'marketing_consent' => ['nullable', 'boolean'],
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
            'password_confirmation.required' => 'Password confirmation is required',
            'password_confirmation.same' => 'Password confirmation must match password',
            'terms_accepted.accepted' => 'You must accept the terms and conditions',
            'role_id.exists' => 'Selected role is invalid',
            'agent_id.exists' => 'Selected agent is invalid',
        ];
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
            // Custom validation logic can be added here
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
        // Add phone number validation logic here
        // This is a simple example - use a proper phone validation library
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }

    /**
     * Get the cleaned data for registration
     *
     * @return array
     */
    public function getCleanedData(): array
    {
        $data = $this->validated();

        // Remove confirmation fields
        unset($data['password_confirmation']);
        unset($data['terms_accepted']);

        // Set default role if not provided
        if (!isset($data['role_id'])) {
            $data['role_id'] = $this->getDefaultRoleId();
        }

        // Set default timezone if not provided
        if (!isset($data['timezone'])) {
            $data['timezone'] = config('app.timezone');
        }

        // Set default language if not provided
        if (!isset($data['language'])) {
            $data['language'] = config('app.locale');
        }

        return $data;
    }

    /**
     * Get default role ID for new users
     *
     * @return string|null
     */
    private function getDefaultRoleId(): ?string
    {
        $role = \Spatie\Permission\Models\Role::where('name', 'customer')->first();
        return $role ? $role->id : null;
    }
}
