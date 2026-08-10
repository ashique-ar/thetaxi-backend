<?php

namespace App\Http\Requests\Customer\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates requests to update rider device information or push token.
 */
class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'device_manufacturer' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'app_build' => ['nullable', 'string', 'max:50'],
            'push_token' => ['nullable', 'string', 'max:500'],
            'push_provider' => ['nullable', 'string', 'in:fcm,apns'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_uuid.required' => 'Device UUID is required',
            'platform.in' => 'Platform must be either ios or android',
            'push_provider.in' => 'Push provider must be either fcm or apns',
        ];
    }
}
