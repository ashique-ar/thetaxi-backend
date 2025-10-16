<?php
// app/Http/Requests/Booking/CreateBookingStatusRequest.php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingStatusRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'booking_id' => ['nullable','exists:bookings,id'],
            'old_status' => ['nullable','string','max:50'],
            'new_status' => ['nullable','string','max:50'],
        ];
    }
}
