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
            'latitude' => ['nullable', 'required_unless:tracking_unavailable_acknowledged,true', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_unless:tracking_unavailable_acknowledged,true', 'numeric', 'between:-180,180'],
            'tracking_unavailable_acknowledged' => ['sometimes', 'boolean'],
            'tracking_outage_kind' => ['nullable', 'required_if:tracking_unavailable_acknowledged,true', 'in:gps_only,gps_and_data,permission_denied,foreground_service_unavailable,fresh_fix_unavailable'],
            'tracking_unavailable_reason' => ['nullable', 'required_if:tracking_unavailable_acknowledged,true', 'string', 'min:3', 'max:500'],
            'address' => ['nullable', 'string', 'max:500'],
            'final_address' => ['nullable', 'string', 'max:500'],
            'ending_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
            'client_recorded_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => 'Final latitude is required',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.required' => 'Final longitude is required',
            'longitude.between' => 'Longitude must be between -180 and 180',
            'tracking_outage_kind.required_if' => 'Select why location evidence is unavailable',
            'tracking_unavailable_reason.required_if' => 'Explain why the hire must be completed without a current GPS fix',
            'ending_mileage.integer' => 'Ending mileage must be a whole number',
            'ending_mileage.min' => 'Ending mileage cannot be negative',
            'collected_amount.numeric' => 'Collected amount must be a number',
            'collected_amount.min' => 'Collected amount cannot be negative',
        ];
    }
}
