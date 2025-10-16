<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleDiscountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $discountId = $isUpdate ? $this->route('vehicleDiscount')->id : null;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('vehicle_discounts', 'code')->ignore($discountId)->whereNull('deleted_at')
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            
            // Scope configuration
            'service_type_id' => 'nullable|string|exists:service_types,id',
            'vehicle_group_id' => 'nullable|string|exists:vehicle_groups,id',
            
            // Discount configuration
            'amount' => 'required|numeric|min:0|max:999999.99',
            'is_percentage' => 'required|boolean',
            'applies_to' => 'required|in:subtotal,total,addons',
            
            // Validity period
            'valid_from' => 'nullable|date|after_or_equal:today',
            'valid_to' => 'nullable|date|after:valid_from',
            'is_active' => 'sometimes|boolean',
            
            // Additional configuration
            'minimum_amount' => 'nullable|numeric|min:0|max:999999.99',
            'maximum_discount' => 'nullable|numeric|min:0|max:999999.99',
            'usage_limit' => 'nullable|integer|min:1|max:999999',
        ];
    }

    /**
     * Get custom validation messages
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Discount code is required',
            'code.unique' => 'This discount code already exists',
            'code.regex' => 'Discount code must contain only uppercase letters, numbers, hyphens, and underscores',
            'name.required' => 'Discount name is required',
            'amount.required' => 'Discount amount is required',
            'amount.min' => 'Discount amount must be at least 0',
            'is_percentage.required' => 'Discount type (percentage/fixed) is required',
            'applies_to.required' => 'Discount application scope is required',
            'applies_to.in' => 'Invalid discount application scope',
            'valid_from.after_or_equal' => 'Valid from date cannot be in the past',
            'valid_to.after' => 'Valid to date must be after valid from date',
            'service_type_id.exists' => 'Selected service type does not exist',
            'vehicle_group_id.exists' => 'Selected vehicle group does not exist',
            'minimum_amount.min' => 'Minimum amount must be at least 0',
            'maximum_discount.min' => 'Maximum discount must be at least 0',
            'usage_limit.min' => 'Usage limit must be at least 1',
        ];
    }

    /**
     * Configure validation for percentage vs fixed amount
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $isPercentage = $this->input('is_percentage');
            $amount = $this->input('amount');

            // Validate percentage range
            if ($isPercentage && $amount && $amount > 100) {
                $validator->errors()->add('amount', 'Percentage discount cannot exceed 100%');
            }

            // Validate maximum discount logic
            $maxDiscount = $this->input('maximum_discount');
            if ($maxDiscount && !$isPercentage && $amount && $maxDiscount > $amount) {
                $validator->errors()->add('maximum_discount', 'Maximum discount cannot exceed the discount amount');
            }

            // Validate minimum amount logic
            $minAmount = $this->input('minimum_amount');
            if ($minAmount && !$isPercentage && $amount && $minAmount < $amount) {
                // This is actually OK - minimum order amount can be less than fixed discount
                // Just ensuring logical validation here
            }
        });
    }

    /**
     * Prepare data for validation
     */
    protected function prepareForValidation()
    {
        // Ensure code is uppercase
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper($this->input('code'))
            ]);
        }

        // Set default values for optional fields
        if (!$this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }

        if (!$this->has('applies_to')) {
            $this->merge(['applies_to' => 'subtotal']);
        }
    }
}
