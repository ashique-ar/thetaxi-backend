<?php
// app/Http/Requests/Vehicle/VehicleMaintenanceRecord/CreateVehicleMaintenanceRecordRequest.php

namespace App\Http\Requests\Vehicle\VehicleMaintenanceRecord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVehicleMaintenanceRecordRequest extends FormRequest
{

    public function rules()
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'schedule_id' => ['required', Rule::exists('vehicle_maintenance_schedules', 'id')->where('vehicle_id', $this->input('vehicle_id'))],
            'performed_date' => ['required', 'date'],
            'cost' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
