<?php

namespace App\Http\Requests\Vehicle\Vehicle\Concerns;

use Illuminate\Validation\Rule;

trait HasCurrentVehicleLeaseRules
{
    protected function prepareForValidation(): void
    {
        $lease = $this->input('current_lease');
        if (!is_array($lease) || !array_key_exists('currency', $lease)) {
            return;
        }

        $lease['currency'] = strtoupper(trim((string) $lease['currency']));
        $this->merge(['current_lease' => $lease]);
    }

    protected function currentVehicleLeaseRules(): array
    {
        $ignoreLeaseId = $this->input('current_lease.id');
        $leaseEnabled = (bool) $this->input('current_lease.enabled', false);

        // If lease is not enabled, make all lease fields nullable and not required
        if (!$leaseEnabled) {
            return [
                'current_lease' => ['sometimes', 'nullable', 'array'],
                'current_lease.enabled' => ['sometimes', 'boolean'],
                'current_lease.id' => ['nullable', 'uuid', 'exists:vehicle_leases,id'],
                'current_lease.finance_provider_id' => ['nullable', 'uuid', 'exists:vehicle_finance_providers,id'],
                'current_lease.agreement_number' => [
                    'nullable',
                    'string',
                    'max:120',
                    Rule::unique('vehicle_leases', 'agreement_number')->ignore($ignoreLeaseId),
                ],
                'current_lease.contract_type' => ['nullable', Rule::in(['vehicle_loan', 'hire_purchase', 'finance_lease', 'operating_lease'])],
                'current_lease.title_holder' => ['nullable', 'string', 'max:255'],
                'current_lease.lien_reference' => ['nullable', 'string', 'max:255'],
                'current_lease.ownership_transfer_required' => ['nullable', 'boolean'],
                'current_lease.start_date' => ['nullable', 'date'],
                'current_lease.end_date' => ['nullable', 'date'],
                'current_lease.first_payment_date' => ['nullable', 'date'],
                'current_lease.currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')->whereNull('deleted_at')],
                'current_lease.financed_amount' => ['nullable', 'numeric', 'min:0.01'],
                'current_lease.down_payment' => ['nullable', 'numeric', 'min:0'],
                'current_lease.down_payment_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
                'current_lease.down_payment_method' => ['nullable', 'string', 'max:40'],
                'current_lease.down_payment_reference' => ['nullable', 'string', 'max:255'],
                'current_lease.refundable_deposit' => ['nullable', 'numeric', 'min:0'],
                'current_lease.deposit_paid_amount' => ['nullable', 'numeric', 'min:0'],
                'current_lease.deposit_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
                'current_lease.deposit_payment_method' => ['nullable', 'string', 'max:40'],
                'current_lease.deposit_payment_reference' => ['nullable', 'string', 'max:255'],
                'current_lease.installment_amount' => ['nullable', 'numeric', 'min:0.01'],
                'current_lease.payment_frequency' => ['nullable', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual'])],
                'current_lease.installment_count' => ['nullable', 'integer', 'min:1', 'max:600'],
                'current_lease.interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'current_lease.balloon_payment' => ['nullable', 'numeric', 'min:0'],
                'current_lease.reminder_days' => ['nullable', 'integer', 'min:0', 'max:90'],
                'current_lease.terms' => ['nullable', 'string', 'max:10000'],
                'current_lease.notes' => ['nullable', 'string', 'max:5000'],
            ];
        }

        // If lease is enabled, apply required validations
        return [
            'current_lease' => ['sometimes', 'nullable', 'array'],
            'current_lease.enabled' => ['required_with:current_lease', 'boolean'],
            'current_lease.id' => ['nullable', 'uuid', 'exists:vehicle_leases,id'],
            'current_lease.finance_provider_id' => [
                'required',
                'uuid',
                'exists:vehicle_finance_providers,id',
            ],
            'current_lease.agreement_number' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('vehicle_leases', 'agreement_number')->ignore($ignoreLeaseId),
            ],
            'current_lease.contract_type' => [
                'required',
                Rule::in(['vehicle_loan', 'hire_purchase', 'finance_lease', 'operating_lease']),
            ],
            'current_lease.title_holder' => ['nullable', 'string', 'max:255'],
            'current_lease.lien_reference' => ['nullable', 'string', 'max:255'],
            'current_lease.ownership_transfer_required' => ['nullable', 'boolean'],
            'current_lease.start_date' => ['required', 'date'],
            'current_lease.end_date' => [
                'required',
                'date',
                'after:current_lease.start_date',
            ],
            'current_lease.first_payment_date' => [
                'required',
                'date',
                'after_or_equal:current_lease.start_date',
                'before_or_equal:current_lease.end_date',
            ],
            'current_lease.currency' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->whereNull('deleted_at'),
            ],
            'current_lease.financed_amount' => ['required', 'numeric', 'min:0.01'],
            'current_lease.down_payment' => ['nullable', 'numeric', 'min:0'],
            'current_lease.down_payment_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
            'current_lease.down_payment_method' => ['nullable', 'string', 'max:40'],
            'current_lease.down_payment_reference' => ['nullable', 'string', 'max:255'],
            'current_lease.refundable_deposit' => ['nullable', 'numeric', 'min:0'],
            'current_lease.deposit_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'current_lease.deposit_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
            'current_lease.deposit_payment_method' => ['nullable', 'string', 'max:40'],
            'current_lease.deposit_payment_reference' => ['nullable', 'string', 'max:255'],
            'current_lease.installment_amount' => ['required', 'numeric', 'min:0.01'],
            'current_lease.payment_frequency' => [
                'required',
                Rule::in(['monthly', 'quarterly', 'semiannual', 'annual']),
            ],
            'current_lease.installment_count' => ['required', 'integer', 'min:1', 'max:600'],
            'current_lease.interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'current_lease.balloon_payment' => ['nullable', 'numeric', 'min:0'],
            'current_lease.reminder_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'current_lease.terms' => ['nullable', 'string', 'max:10000'],
            'current_lease.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
