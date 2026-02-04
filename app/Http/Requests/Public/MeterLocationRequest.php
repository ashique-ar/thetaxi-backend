<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Meter Location Request
 * 
 * Validates requests to update location during an active meter session.
 * Requires device_uuid (from middleware), latitude, longitude, and optional session_id.
 * 
 * @see Requirement 3.2 - Track by device_uuid without authentication
 */
class MeterLocationRequest extends FormRequest
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
            'session_id' => ['nullable', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'altitude' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
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
            'session_id.uuid' => 'Session ID must be a valid UUID',
            'latitude.required' => 'Latitude is required',
            'latitude.numeric' => 'Latitude must be a valid number',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.required' => 'Longitude is required',
            'longitude.numeric' => 'Longitude must be a valid number',
            'longitude.between' => 'Longitude must be between -180 and 180',
            'speed.min' => 'Speed cannot be negative',
            'heading.between' => 'Heading must be between 0 and 360 degrees',
            'accuracy.min' => 'Accuracy cannot be negative',
        ];
    }
}
