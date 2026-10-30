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
            'booking_id' => ['sometimes', 'nullable', 'exists:bookings,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $inquiry = $this->route('inquiry');
            $current = $inquiry instanceof \App\Models\Inquiry ? $inquiry : null;
            $email = $this->input('email', $current?->email);
            $phone = $this->input('phone', $current?->phone);
            if (blank($email) && blank($phone)) {
                $validator->errors()->add('phone', 'Keep a phone number or email address so the customer can be contacted.');
            }
        });
    }
}
