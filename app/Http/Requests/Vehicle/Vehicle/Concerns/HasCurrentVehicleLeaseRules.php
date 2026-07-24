<?php

namespace App\Http\Requests\Vehicle\Vehicle\Concerns;

use Illuminate\Validation\Rule;

trait HasCurrentVehicleLeaseRules
{
    protected function currentVehicleLeaseRules(): array
    {
        $ignoreLeaseId = $this->input('current_lease.id');

        return [
            'current_lease' => ['sometimes', 'nullable', 'array'],
            'current_lease.enabled' => ['required_with:current_lease', 'boolean'],
            'current_lease.id' => ['nullable', 'uuid', 'exists:vehicle_leases,id'],
            'current_lease.finance_provider_id' => [
                'exclude_if:current_lease.enabled,false',
                'required',
                'uuid',
                'exists:vehicle_finance_providers,id',
            ],
            'current_lease.agreement_number' => [
                'exclude_if:current_lease.enabled,false',
                'nullable',
                'string',
                'max:120',
                Rule::unique('vehicle_leases', 'agreement_number')->ignore($ignoreLeaseId),
            ],
            'current_lease.contract_type' => [
                'exclude_if:current_lease.enabled,false',
                'required',
                Rule::in(['vehicle_loan', 'hire_purchase', 'finance_lease', 'operating_lease']),
            ],
            'current_lease.title_holder' => ['exclude_if:current_lease.enabled,false', 'nullable', 'string', 'max:255'],
            'current_lease.lien_reference' => ['exclude_if:current_lease.enabled,false', 'nullable', 'string', 'max:255'],
            'current_lease.ownership_transfer_required' => ['exclude_if:current_lease.enabled,false', 'nullable', 'boolean'],
            'current_lease.start_date' => ['exclude_if:current_lease.enabled,false', 'required', 'date'],
            'current_lease.end_date' => [
                'exclude_if:current_lease.enabled,false',
                'required',
                'date',
                'after:current_lease.start_date',
            ],
            'current_lease.first_payment_date' => [
                'exclude_if:current_lease.enabled,false',
                'required',
                'date',
                'after_or_equal:current_lease.start_date',
                'before_or_equal:current_lease.end_date',
            ],
            'current_lease.currency' => ['exclude_if:current_lease.enabled,false', 'required', 'string', 'size:3'],
            'current_lease.financed_amount' => ['exclude_if:current_lease.enabled,false', 'required', 'numeric', 'min:0.01'],
            'current_lease.down_payment' => ['exclude_if:current_lease.enabled,false', 'nullable', 'numeric', 'min:0'],
            'current_lease.refundable_deposit' => ['exclude_if:current_lease.enabled,false', 'nullable', 'numeric', 'min:0'],
            'current_lease.deposit_paid_amount' => ['exclude_if:current_lease.enabled,false', 'nullable', 'numeric', 'min:0'],
            'current_lease.deposit_paid_date' => ['exclude_if:current_lease.enabled,false', 'nullable', 'date', 'before_or_equal:today'],
            'current_lease.deposit_payment_reference' => ['exclude_if:current_lease.enabled,false', 'nullable', 'string', 'max:255'],
            'current_lease.installment_amount' => ['exclude_if:current_lease.enabled,false', 'required', 'numeric', 'min:0.01'],
            'current_lease.payment_frequency' => [
                'exclude_if:current_lease.enabled,false',
                'required',
                Rule::in(['monthly', 'quarterly', 'semiannual', 'annual']),
            ],
            'current_lease.installment_count' => ['exclude_if:current_lease.enabled,false', 'required', 'integer', 'min:1', 'max:600'],
            'current_lease.interest_rate' => ['exclude_if:current_lease.enabled,false', 'nullable', 'numeric', 'min:0', 'max:100'],
            'current_lease.balloon_payment' => ['exclude_if:current_lease.enabled,false', 'nullable', 'numeric', 'min:0'],
            'current_lease.reminder_days' => ['exclude_if:current_lease.enabled,false', 'nullable', 'integer', 'min:0', 'max:90'],
            'current_lease.terms' => ['exclude_if:current_lease.enabled,false', 'nullable', 'string', 'max:10000'],
            'current_lease.notes' => ['exclude_if:current_lease.enabled,false', 'nullable', 'string', 'max:5000'],
        ];
    }
}
