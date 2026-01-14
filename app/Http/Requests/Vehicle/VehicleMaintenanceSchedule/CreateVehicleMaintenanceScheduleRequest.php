<?php
// app/Http/Requests/Vehicle/VehicleMaintenanceSchedule/CreateVehicleMaintenanceScheduleRequest.php

namespace App\Http\Requests\Vehicle\VehicleMaintenanceSchedule;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleMaintenanceScheduleRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'type' => ['required', 'string'],
            'interval_km' => ['nullable', 'integer', 'min:0'],
            'interval_days' => ['nullable', 'integer', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
        ];
    }
}
