<?php
// app/Http/Requests/Vehicle/VehicleMaintenanceSchedule/UpdateVehicleMaintenanceScheduleRequest.php

namespace App\Http\Requests\Vehicle\VehicleMaintenanceSchedule;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleMaintenanceScheduleRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'type' => ['sometimes', 'required', 'string'],
            'interval_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'interval_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'next_due_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
