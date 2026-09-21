<?php

namespace App\Http\Requests\Corporate;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class StoreCorporateBookingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('booking_party_mode')) {
            $this->merge([
                'booking_party_mode' => $this->filled('corporate_contact') && ! $this->filled('employee_id')
                    ? 'general'
                    : 'employee',
            ]);
        }

        if (! $this->filled('payment_collection_method')) {
            $corporateId = $this->attributes->get('corporate_id') ?? $this->input('corporate_id');
            $this->merge([
                'payment_collection_method' => $corporateId
                    && Schema::hasTable('corporates')
                    && Schema::hasColumn('corporates', 'default_payment_arrangement')
                    ? (Corporate::query()->whereKey($corporateId)->value('default_payment_arrangement') ?: 'monthly_invoice')
                    : 'monthly_invoice',
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_party_mode' => ['required', 'string', 'in:employee,general'],
            'payment_collection_method' => ['required', 'string', 'in:monthly_invoice,cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,bank_transfer,card,complimentary'],
            'service_type_id' => ['nullable', 'uuid'],
            'service_type' => ['nullable', 'uuid'],
            'vehicle_group_id' => ['nullable', 'uuid'],
            'pickup_location' => ['nullable'],
            'dropoff_location' => ['nullable'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_pattern' => ['nullable', 'required_if:is_recurring,true', 'in:daily,weekly,monthly'],
            'recurrence_end_date' => ['nullable', 'required_if:is_recurring,true', 'date', 'after:from_date'],
            'recurrence_days' => ['nullable', 'array'],
            'employee_id' => ['nullable', 'uuid'],
            'corporate_department_id' => ['nullable', 'uuid'],
            'corporate_division_id' => ['nullable', 'uuid'],
            'booking_items' => ['nullable', 'array', 'min:1'],
            'booking_items.*.service_type_id' => ['nullable', 'uuid'],
            'booking_items.*.service_type' => ['nullable', 'uuid'],
            'booking_items.*.vehicle_group_id' => ['required_with:booking_items', 'uuid'],
            'booking_items.*.pickup_location' => ['nullable'],
            'booking_items.*.dropoff_location' => ['nullable'],
            'booking_items.*.from_date' => ['nullable', 'date'],
            'booking_items.*.to_date' => ['nullable', 'date'],
            'booking_items.*.from_time' => ['nullable', 'date_format:H:i'],
            'booking_items.*.to_time' => ['nullable', 'date_format:H:i'],
            'booking_items.*.addons' => ['nullable', 'array'],
            'booking_items.*.notes' => ['nullable', 'string'],
            'booking_items.*.metadata' => ['nullable', 'array'],
            'booking_items.*.service_package_id' => ['nullable', 'uuid'],
            'booking_items.*.package_id' => ['nullable', 'uuid'],
            'selected_addons' => ['nullable', 'array'],
            'applied_discounts' => ['nullable', 'array'],
            'variable_customizations' => ['nullable', 'array'],
            'currency' => ['nullable', 'string', 'max:10'],
            'base_currency' => ['nullable', 'string', 'max:10'],
            'review_notes' => ['nullable', 'array'],
            'override_reasons' => ['nullable', 'array'],
            'approval_reason' => ['nullable', 'string'],
            'corporate_contact' => ['nullable', 'array'],
            'corporate_contact.name' => ['required_if:booking_party_mode,general', 'nullable', 'string', 'max:255'],
            'corporate_contact.email' => ['nullable', 'email', 'max:255'],
            'corporate_contact.phone' => ['required_if:booking_party_mode,general', 'nullable', 'string', 'max:50'],
            'service_package_id' => ['nullable', 'uuid'],
            'package_id' => ['nullable', 'uuid'],
            'has_overrides' => ['nullable', 'boolean'],
            'persist_customizations' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $corporateId = $this->attributes->get('corporate_id') ?? $this->input('corporate_id');
            $vehicleGroupId = $this->input('vehicle_group_id');

            if ($corporateId && $vehicleGroupId) {
                $corporate = Corporate::find($corporateId);

                if ($corporate && !$corporate->vehicleGroups()->where('vehicle_groups.id', $vehicleGroupId)->exists()) {
                    $validator->errors()->add(
                        'vehicle_group_id',
                        'The selected vehicle group is not assigned to your corporate.'
                    );
                }
            }

            if ($corporateId && $this->filled('employee_id') && ! CorporateEmployee::query()
                ->where('corporate_id', $corporateId)
                ->whereKey($this->input('employee_id'))
                ->exists()) {
                $validator->errors()->add('employee_id', 'The selected employee does not belong to your corporate.');
            }

            if ($corporateId && $this->filled('corporate_department_id') && ! CorporateDepartment::query()
                    ->where('corporate_id', $corporateId)
                    ->whereKey($this->input('corporate_department_id'))
                    ->exists()) {
                $validator->errors()->add('corporate_department_id', 'The selected department does not belong to your corporate.');
            }

            if ($corporateId && $this->filled('corporate_division_id') && ! CorporateDivision::query()
                ->whereHas('department', fn ($query) => $query->where('corporate_id', $corporateId))
                ->whereKey($this->input('corporate_division_id'))
                ->exists()) {
                $validator->errors()->add('corporate_division_id', 'The selected division does not belong to your corporate.');
            }
        });
    }
}
