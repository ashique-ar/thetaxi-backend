<?php
// app/Http/Requests/Vehicle/VehicleMaintenanceRecord/CreateVehicleMaintenanceRecordRequest.php

namespace App\Http\Requests\Vehicle\VehicleMaintenanceRecord;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleMaintenanceRecordRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    public function rules()
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'schedule_id' => ['required', 'exists:vehicle_maintenance_schedules,id'],
            'performed_date' => ['required', 'date'],
            'cost' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
