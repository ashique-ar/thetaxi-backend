<?php
// app/Http/Requests/Inquiry/UpdateInquiryRequest.php

namespace App\Http\Requests\Inquiry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInquiryRequest extends FormRequest
{


    public function rules()
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'exists:customers,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'inquiry_type' => ['sometimes', 'string', 'max:50'],
            'source' => ['sometimes', 'string', 'max:50'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'in:open,in_progress,closed,archived'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:50'],
            'response' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'responded_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
