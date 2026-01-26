<?php
// app/Http/Requests/Booking/BookingStatus/CreateBookingStatusRequest.php

namespace App\Http\Requests\Booking\BookingStatus;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingStatusRequest extends FormRequest
{


    public function rules()
    {
        return [
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'old_status' => ['nullable', 'string', 'max:50'],
            'new_status' => ['nullable', 'string', 'max:50'],
        ];
    }
}
