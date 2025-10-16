<?php
// app/Http/Requests/Inquiry/CreateInquiryRequest.php

namespace App\Http\Requests\Inquiry;

use Illuminate\Foundation\Http\FormRequest;

class CreateInquiryRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'customer_id' => ['nullable','exists:customers,id'],
            'subject'     => ['nullable','string','max:255'],
            'message'     => ['nullable','string'],
            'status'      => ['nullable','string','max:50'],
            'priority'    => ['nullable','string','max:50'],
            'response'    => ['nullable','string'],
            'responded_at'=> ['nullable','date'],
        ];
    }
}
