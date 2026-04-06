<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * End Trip Request
 *
 * Validates requests for ending a trip with final location.
 *
 * @see Requirement 8.1 - End trip with final coordinates
 */
class EndTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'ending_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => 'Final latitude is required',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.required' => 'Final longitude is required',
            'longitude.between' => 'Longitude must be between -180 and 180',
            'ending_mileage.integer' => 'Ending mileage must be a whole number',
            'ending_mileage.min' => 'Ending mileage cannot be negative',
        ];
    }
}
