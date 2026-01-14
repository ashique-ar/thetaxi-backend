<?php
// app/Http/Requests/Vehicle/VehicleMaintenanceRecord/UpdateVehicleMaintenanceRecordRequest.php

namespace App\Http\Requests\Vehicle\VehicleMaintenanceRecord;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleMaintenanceRecordRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['sometimes', 'required', 'exists:vehicles,id'],
            'schedule_id' => ['sometimes', 'required', 'exists:vehicle_maintenance_schedules,id'],
            'performed_date' => ['sometimes', 'required', 'date'],
            'cost' => ['sometimes', 'required', 'numeric'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
