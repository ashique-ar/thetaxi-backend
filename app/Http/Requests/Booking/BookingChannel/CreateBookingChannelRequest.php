<?php
// app/Http/Requests/Booking/BookingChannel/CreateBookingChannelRequest.php

namespace App\Http\Requests\Booking\BookingChannel;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingChannelRequest extends FormRequest
{


    public function rules()
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ];
    }
}
