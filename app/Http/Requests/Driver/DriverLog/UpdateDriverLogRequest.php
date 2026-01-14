<?php
// app/Http/Requests/DriverLog/UpdateDriverLogRequest.php
namespace App\Http\Requests\DriverLog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverLogRequest extends FormRequest
{

    public function rules()
    {
        return [
            'driver_id' => ['sometimes', 'required', 'exists:drivers,id'],
            'booking_id' => ['sometimes', 'nullable', 'exists:bookings,id'],
            'log_code' => ['sometimes', 'nullable', 'date'],
            'log_date' => ['sometimes', 'required', 'date'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i', 'after_or_equal:start_time'],
            'start_km' => ['sometimes', 'nullable', 'integer'],
            'end_km' => ['sometimes', 'nullable', 'integer', 'gte:start_km'],
            'start_image' => ['sometimes', 'nullable', 'string'],
            'end_image' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', 'in:pending,approved,rejected'],
        ];
    }
}
