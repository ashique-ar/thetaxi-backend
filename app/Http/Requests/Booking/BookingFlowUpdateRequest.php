<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Booking\Booking;

class BookingFlowUpdateRequest extends FormRequest
{


    public function rules(): array
    {
        return [
            // Everything optional (partial updates), but validated like calculate
            'customer_id' => 'sometimes|string',
            'service_type' => 'sometimes|string',
            'service_type_id' => 'sometimes|string',
            'vehicle_group_id' => 'sometimes|string',
            'vehicle_id' => 'sometimes|string',
            'vehicle_id' => 'sometimes|string',
            'driver_id' => 'sometimes|string',

            'from_date' => 'sometimes|nullable|date',
            'to_date' => 'sometimes|nullable|date|after_or_equal:from_date',
            'from_time' => 'sometimes|nullable|string',
            'to_time' => 'sometimes|nullable|string',

            'pickup_location' => 'sometimes|array',
            'pickup_location.latitude' => 'required_with:pickup_location|numeric',
            'pickup_location.longitude' => 'required_with:pickup_location|numeric',
            'dropoff_location' => 'sometimes|array',
            'dropoff_location.latitude' => 'required_with:dropoff_location|numeric',
            'dropoff_location.longitude' => 'required_with:dropoff_location|numeric',

            'selected_addons' => 'sometimes|array',
            'selected_addons.*.id' => 'required_with:selected_addons|string',
            'selected_addons.*.quantity' => 'sometimes|integer|min:1',
            'selected_addons.*.custom_price' => 'sometimes|numeric',

            'variable_customizations' => 'sometimes|array',
            'variable_customizations.*.id' => 'sometimes|string',
            'variable_customizations.*.variable_name' => 'required_with:variable_customizations|string',
            'variable_customizations.*.variable_type' => 'sometimes|string|in:slab_rate,common_rate,addon_rate,fixed_value',
            'variable_customizations.*.original_value' => 'sometimes|numeric',
            'variable_customizations.*.custom_value' => 'required_with:variable_customizations|numeric',
            'variable_customizations.*.rate_type' => 'sometimes|string|in:per_day,per_hour,flat_rate,per_package',
            'variable_customizations.*.billing_type' => 'sometimes|string|in:per_day,per_hour,per_package',
            'variable_customizations.*.model_type' => 'sometimes|string',
            'variable_customizations.*.model_id' => 'sometimes|string',
            'variable_customizations.*.context' => 'sometimes|string|in:base_pricing,addon_pricing',
            'variable_customizations.*.reason' => 'sometimes|string',

            'session_id' => 'sometimes|string',
            'booking_id' => 'sometimes|string',
            'has_variable_customizations' => 'sometimes|boolean',
            'is_preview_calculation' => 'sometimes|boolean',
            'preserve_custom_pricing' => 'sometimes|boolean',
            'preserve_custom_addon_prices' => 'sometimes|boolean',
            'force_recalculation' => 'sometimes|boolean',
            'currency' => 'sometimes|string|size:3',
            'base_currency' => 'sometimes|string|size:3',

            'applied_discounts' => 'sometimes|array',
            'applied_discounts.*.method' => 'required_with:applied_discounts|string|in:percentage,fixed_amount',
            'applied_discounts.*.type' => 'sometimes|string',
            'applied_discounts.*.value' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.reason' => 'sometimes|string',

            'review_notes' => 'sometimes|array',
            'review_notes.booking_notes' => 'sometimes|string',
            // 'review_notes.terms_accepted' => 'sometimes|boolean',
            'override_reasons' => 'sometimes|array',
            'override_reasons.*' => 'string',
            'status' => 'sometimes|string|in:pending,pending_approval,approved,confirmed,cancelled,completed',
            'approval_reason' => 'sometimes|string',

            'is_self_driven' => 'sometimes|boolean',
            'passenger_count' => 'sometimes|integer|min:1',
            'luggage_count' => 'sometimes|integer|min:0',
        ];
    }
}
