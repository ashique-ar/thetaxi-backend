<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pickup Arrived Request
 *
 * Validates requests for confirming arrival at the pickup location.
 *
 * @see Requirement 6.1 - Confirm pickup arrival with coordinates
 */
class PickupArrivedRequest extends FormRequest
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
            // Nullable during the mobile rollout so older installed builds keep
            // working; current builds always send both fields.
            'idempotency_key' => ['nullable', 'uuid'],
            'client_recorded_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => 'Latitude is required',
            'latitude.between' => 'Latitude must be between -90 and 90',
            'longitude.required' => 'Longitude is required',
            'longitude.between' => 'Longitude must be between -180 and 180',
        ];
    }
}
