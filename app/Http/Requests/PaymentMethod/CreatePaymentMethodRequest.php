<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payable_type' => ['required', 'string', 'in:vehicle_owner,driver,customer'],
            'payable_id' => ['required', 'uuid'],
            'method_type' => ['required', 'string', 'in:cash,bank_transfer,cheque,card,wallet,online,other'],
            'label' => ['nullable', 'string', 'max:120'],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'required_if:method_type,bank_transfer', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'required_if:method_type,bank_transfer', 'string', 'max:100'],
            'routing_number' => ['nullable', 'string', 'max:100'],
            'card_brand' => ['nullable', 'string', 'max:50'],
            'card_last_four' => ['nullable', 'digits:4'],
            'card_expiry_month' => ['nullable', 'integer', 'between:1,12'],
            'card_expiry_year' => ['nullable', 'integer', 'min:' . date('Y')],
            'wallet_provider' => ['nullable', 'required_if:method_type,wallet', 'string', 'max:120'],
            'wallet_identifier' => ['nullable', 'required_if:method_type,wallet', 'string', 'max:255'],
            'cheque_payee_name' => ['nullable', 'required_if:method_type,cheque', 'string', 'max:255'],
            'cheque_bank_name' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
