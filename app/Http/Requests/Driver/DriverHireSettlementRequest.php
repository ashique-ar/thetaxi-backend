<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class DriverHireSettlementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'exists:bookings,id'],
            'booking_item_id' => ['nullable', 'exists:booking_items,id'],
            'driver_id' => ['required', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'vehicle_group_id' => ['required', 'exists:vehicle_groups,id'],
            'driver_log_id' => ['nullable', 'exists:driver_logs,id'],
            'batta_category' => ['nullable', 'string', 'max:100'],
            'base_batta_amount' => ['nullable', 'numeric', 'min:0'],
            'night_count' => ['nullable', 'integer', 'min:0'],
            'night_batta_rate' => ['nullable', 'numeric', 'min:0'],
            'manual_adjustment_amount' => ['nullable', 'numeric'],
            'manual_adjustment_reason' => ['nullable', 'string'],
            'ops_review_notes' => ['nullable', 'string'],
            'accounts_notes' => ['nullable', 'string'],
        ];
    }
}
