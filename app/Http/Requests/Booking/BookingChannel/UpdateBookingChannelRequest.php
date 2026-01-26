<?php
// app/Http/Requests/Booking/BookingChannel/UpdateBookingChannelRequest.php

namespace App\Http\Requests\Booking\BookingChannel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingChannelRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
