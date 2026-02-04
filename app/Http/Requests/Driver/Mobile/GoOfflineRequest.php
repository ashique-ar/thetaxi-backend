<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Go Offline Request
 * 
 * Validates requests for a driver to go offline.
 * Accepts optional location data for session end coordinates.
 * 
 * @see Requirement 4.3 - Close active session on go-offline
 * @see Requirement 4.4 - Capture end coordinates
 */
class GoOfflineRequest extends FormRequest
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
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
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
            'latitude.numeric' => 'Latitude must be a valid number',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.numeric' => 'Longitude must be a valid number',
            'longitude.between' => 'Longitude must be between -180 and 180',
        ];
    }
}
