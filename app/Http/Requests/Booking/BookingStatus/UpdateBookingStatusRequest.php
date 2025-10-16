<?php
// app/Http/Requests/Booking/UpdateBookingStatusRequest.php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'booking_id' => ['sometimes','nullable','exists:bookings,id'],
            'old_status' => ['sometimes','nullable','string','max:50'],
            'new_status' => ['sometimes','nullable','string','max:50'],
        ];
    }
}
