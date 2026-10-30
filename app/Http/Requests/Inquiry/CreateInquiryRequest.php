<?php
// app/Http/Requests/Inquiry/CreateInquiryRequest.php

namespace App\Http\Requests\Inquiry;

use Illuminate\Foundation\Http\FormRequest;

class CreateInquiryRequest extends FormRequest
{


    public function rules()
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'inquiry_type' => ['required', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:50'],
            'assigned_to' => ['prohibited'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,in_progress,closed,archived'],
            'priority' => ['nullable', 'string', 'max:50'],
            'response' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'responded_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('phone', 'Enter a phone number or email address.');
            }
        });
    }
}
