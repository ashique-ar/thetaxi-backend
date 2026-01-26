<?php
// app/Http/Requests/Driver/DriverLog/CreateDriverLogRequest.php
namespace App\Http\Requests\Driver\DriverLog;

use Illuminate\Foundation\Http\FormRequest;

class CreateDriverLogRequest extends FormRequest
{

    public function rules()
    {
        return [
            'driver_id' => ['required', 'exists:drivers,id'],
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'log_code' => ['nullable', 'date'],
            'log_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:start_time'],
            'start_km' => ['nullable', 'integer'],
            'end_km' => ['nullable', 'integer', 'gte:start_km'],
            'start_image' => ['nullable', 'string'],
            'end_image' => ['nullable', 'string'],
            'status' => ['required', 'in:pending,approved,rejected'],
        ];
    }
}
