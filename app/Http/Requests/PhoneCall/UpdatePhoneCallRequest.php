<?php
// app/Http/Requests/PhoneCall/UpdatePhoneCallRequest.php

namespace App\Http\Requests\PhoneCall;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePhoneCallRequest extends FormRequest
{


    public function rules()
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'booking_id' => ['sometimes', 'nullable', 'uuid', 'exists:bookings,id'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'call_time' => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }
}
