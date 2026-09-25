<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCorporateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporates', 'name')->whereNull('deleted_at'),
            ],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'booking_notification_emails' => ['sometimes', 'array', 'max:20'],
            'booking_notification_emails.*' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string'],
            'approval_required' => ['boolean'],
            'exempt_coordinator_from_approval' => ['boolean'],
            'coordinator_can_view_payments' => ['boolean'],
            'default_payment_arrangement' => ['nullable', 'in:monthly_invoice,cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,bank_transfer,card,complimentary'],
        ];
    }
}
