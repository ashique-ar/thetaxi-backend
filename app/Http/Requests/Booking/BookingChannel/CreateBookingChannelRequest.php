<?php
// app/Http/Requests/Booking/CreateBookingChannelRequest.php

namespace App\Http\Requests\Booking;

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
