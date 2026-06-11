<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method_type' => ['sometimes', 'required', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'required_if:method_type,bank_transfer', 'string', 'max:255'],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_number' => ['sometimes', 'nullable', 'required_if:method_type,bank_transfer', 'string', 'max:100'],
            'routing_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'card_brand' => ['sometimes', 'nullable', 'string', 'max:50'],
            'card_last_four' => ['sometimes', 'nullable', 'digits:4'],
            'card_expiry_month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'card_expiry_year' => ['sometimes', 'nullable', 'integer', 'min:' . date('Y')],
            'wallet_provider' => ['sometimes', 'nullable', 'required_if:method_type,wallet', 'string', 'max:120'],
            'wallet_identifier' => ['sometimes', 'nullable', 'required_if:method_type,wallet', 'string', 'max:255'],
            'cheque_payee_name' => ['sometimes', 'nullable', 'required_if:method_type,cheque', 'string', 'max:255'],
            'cheque_bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_default' => ['sometimes', 'nullable', 'boolean'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
