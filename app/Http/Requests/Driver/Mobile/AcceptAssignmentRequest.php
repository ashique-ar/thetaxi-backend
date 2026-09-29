<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept Assignment Request
 *
 * Validates requests for accepting a driver assignment.
 * No body parameters required — the assignment ID comes from the route.
 *
 * @see Requirement 2.1 - Driver accepts assignment
 */
class AcceptAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nullable during rollout so older installed builds remain compatible.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'client_recorded_at' => ['nullable', 'date'],
            'device_snapshot' => ['nullable', 'array'],
            'device_snapshot.device_uuid' => ['nullable', 'string', 'max:100'],
            'device_snapshot.device_name' => ['nullable', 'string', 'max:150'],
            'device_snapshot.device_model' => ['nullable', 'string', 'max:150'],
            'device_snapshot.device_manufacturer' => ['nullable', 'string', 'max:100'],
            'device_snapshot.platform' => ['nullable', 'string', 'max:40'],
            'device_snapshot.os_version' => ['nullable', 'string', 'max:100'],
            'device_snapshot.app_version' => ['nullable', 'string', 'max:50'],
            'device_snapshot.app_build' => ['nullable', 'string', 'max:50'],
        ];
    }
}
