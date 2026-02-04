<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Driver Login Request
 * 
 * Validates login requests for the driver mobile application.
 * Includes rate limiting to prevent brute force attacks.
 * 
 * @see Requirement 2.1 - Driver authentication
 */
class DriverLoginRequest extends FormRequest
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
            'device_uuid' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'password.required' => 'Password is required',
            'device_uuid.required' => 'Device UUID is required',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->checkRateLimit();
        });
    }

    /**
     * Check rate limiting for login attempts.
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
        return 'driver-login:' . Str::transliterate(Str::lower($this->input('email')) . '|' . $this->ip());
    }

    /**
     * Clear the rate limit for this request.
     *
     * @return void
     */
    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower($this->input('email')),
            ]);
        }
    }
}
