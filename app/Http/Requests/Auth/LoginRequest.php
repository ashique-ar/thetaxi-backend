<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'two_factor_code' => ['nullable', 'string', 'size:6'],
            'recovery_code' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            // Client-provided IP and location (optional) - frontend may provide public IP and geolocation
            'client_ip' => ['nullable', 'ip'],
            'client_location' => ['nullable', 'string', 'max:255'],
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
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'password.required' => 'Password is required',
            'two_factor_code.size' => 'Two-factor code must be 6 digits',
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
            $this->checkRateLimit();
        });
    }

    /**
     * Check rate limiting for login attempts
     *
     * @return void
     * @throws ValidationException
     */
    protected function checkRateLimit(): void
    {
        $key = $this->throttleKey();
        $maxAttempts = 5;
        $decayMinutes = 1;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            throw ValidationException::withMessages([
                'email' => [
                    'Too many login attempts. Please try again in ' . 
                    gmdate('i:s', $seconds) . ' minutes.'
                ]
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * @return string
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }

    /**
     * Clear the rate limit for this request
     *
     * @return void
     */
    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Get device name for token
     *
     * @return string
     */
    public function getDeviceName(): string
    {
        return $this->input('device_name', 'API Token');
    }

    /**
     * Check if remember me is enabled
     *
     * @return bool
     */
    public function shouldRemember(): bool
    {
        return $this->boolean('remember');
    }

    /**
     * Get two-factor authentication code
     *
     * @return string|null
     */
    public function getTwoFactorCode(): ?string
    {
        return $this->input('two_factor_code');
    }

    /**
     * Get recovery code
     *
     * @return string|null
     */
    public function getRecoveryCode(): ?string
    {
        return $this->input('recovery_code');
    }

    /**
     * Check if this is a two-factor authentication request
     *
     * @return bool
     */
    public function isTwoFactorRequest(): bool
    {
        return $this->filled('two_factor_code') || $this->filled('recovery_code');
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
        ]);
    }
}
