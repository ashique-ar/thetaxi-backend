<?php
// app/Http/Requests/Inquiry/UpdateInquiryRequest.php

namespace App\Http\Requests\Inquiry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInquiryRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'customer_id' => ['sometimes','nullable','exists:customers,id'],
            'subject'     => ['sometimes','nullable','string','max:255'],
            'message'     => ['sometimes','nullable','string'],
            'status'      => ['sometimes','nullable','string','max:50'],
            'priority'    => ['sometimes','nullable','string','max:50'],
            'response'    => ['sometimes','nullable','string'],
            'responded_at'=> ['sometimes','nullable','date'],
        ];
    }
}
