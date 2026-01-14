<?php
// app/Http/Requests/PhoneCall/CreatePhoneCallRequest.php

namespace App\Http\Requests\PhoneCall;

use Illuminate\Foundation\Http\FormRequest;

class CreatePhoneCallRequest extends FormRequest
{


    public function rules()
    {
        return [
            'phone' => ['nullable', 'string', 'max:20'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'call_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }
}
