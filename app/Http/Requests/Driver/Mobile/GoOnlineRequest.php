<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Go Online Request
 * 
 * Validates requests for a driver to go online.
 * Requires device UUID and optional location data.
 * 
 * @see Requirement 4.1 - Create DriverSession on go-online
 * @see Requirement 4.2 - Capture device_uuid, start coordinates
 */
class GoOnlineRequest extends FormRequest
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
            'device_uuid' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'metadata' => ['nullable', 'array'],
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
            'device_uuid.required' => 'Device UUID is required',
            'latitude.numeric' => 'Latitude must be a valid number',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.numeric' => 'Longitude must be a valid number',
            'longitude.between' => 'Longitude must be between -180 and 180',
        ];
    }
}
