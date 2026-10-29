<?php
// app/Http/Requests/PhoneCall/CreatePhoneCallRequest.php

namespace App\Http\Requests\PhoneCall;

use Illuminate\Foundation\Http\FormRequest;

class CreatePhoneCallRequest extends FormRequest
{


    public function rules()
    {
        return [
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'booking_id' => ['nullable', 'uuid', 'exists:bookings,id'],
            'phone' => ['nullable', 'required_without:client_name', 'string', 'max:20'],
            'client_name' => ['nullable', 'required_without:phone', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'call_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'follow_up' => ['nullable', 'array'],
            'follow_up.title' => ['required_with:follow_up', 'string', 'max:255'],
            'follow_up.due_at' => ['required_with:follow_up', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/'],
        ];
    }
}
