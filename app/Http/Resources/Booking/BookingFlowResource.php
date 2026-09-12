<?php

namespace App\Http\Resources\Booking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingFlowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'confirmation_number' => $this->confirmation_number,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'requires_approval' => $this->requires_approval,

            // Customer information
            'customer' => $this->whenLoaded('customer', function () {
                return [
                    'id' => $this->customer->id,
                    'name' => $this->customer->user->first_name . ' ' . $this->customer->user->last_name,
                    'email' => $this->customer->user->email,
                    'phone' => $this->customer->phone,
                ];
            }),

            // Service details
            'service_type' => $this->whenLoaded('serviceType', function () {
                return [
                    'id' => $this->serviceType->id,
                    'name' => $this->serviceType->name,
                    'display_name' => $this->serviceType->display_name,
                ];
            }),

            // Vehicle information
            'vehicle_group' => $this->whenLoaded('vehicleGroup', function () {
                return [
                    'id' => $this->vehicleGroup->id,
                    'name' => $this->vehicleGroup->name,
                    'category' => $this->vehicleGroup->category->name ?? null,
                ];
            }),

            'vehicle' => $this->whenLoaded('vehicle', function () {
                return [
                    'id' => $this->vehicle->id,
                    'name' => $this->vehicle->title,
                    'make' => $this->vehicle->make,
                    'model' => $this->vehicle->model,
                    'year' => $this->vehicle->year,
                    'license_plate' => $this->vehicle->license_plate,
                ];
            }),

            'driver' => $this->whenLoaded('driver', function () {
                return [
                    'id' => $this->driver->id,
                    'name' => $this->driver->user->first_name . ' ' . $this->driver->user->last_name,
                    'phone' => $this->driver->phone,
                    'license_number' => $this->driver->license_number,
                ];
            }),

            // Booking dates and times
            'booking_date' => $this->booking_date?->format('Y-m-d H:i:s'),
            'from_date' => $this->from_date?->format('Y-m-d'),
            'to_date' => $this->to_date?->format('Y-m-d'),
            'from_time' => $this->from_time,
            'to_time' => $this->to_time,

            // Location information
            'pickup_location' => $this->pickup_location,
            'dropoff_location' => $this->dropoff_location,
            'pickup_latitude' => $this->pickup_latitude,
            'pickup_longitude' => $this->pickup_longitude,
            'pickup_landmark' => $this->pickup_landmark,
            'dropoff_latitude' => $this->dropoff_latitude,
            'dropoff_longitude' => $this->dropoff_longitude,
            'dropoff_landmark' => $this->dropoff_landmark,

            // Service details
            'is_self_driven' => $this->is_self_driven,
            'passenger_count' => $this->passenger_count,
            'luggage_count' => $this->luggage_count,
            'special_requirements' => $this->special_requirements,

            // Pricing information
            'pricing' => [
                'base_amount' => $this->base_amount,
                'driver_cost' => $this->driver_cost,
                'distance_cost' => $this->distance_cost,
                'addons_cost' => $this->addons_cost,
                'discount_amount' => $this->discount_amount,
                'tax_amount' => $this->tax_amount,
                'total_estimated' => $this->total_estimated,
                'total_actual' => $this->total_actual,
                'currency' => $this->currency,
                'discounts' => $this->discounts,
                'addon_overrides' => $this->addon_overrides,
                'gamify_points_earned' => $this->gamify_points_earned,
                'gamify_discount_applied' => $this->gamify_discount_applied,
                'gamify_details' => $this->gamify_details,
            ],

            // Distance and time tracking
            'estimated_distance' => $this->estimated_distance,
            'estimated_duration' => $this->estimated_duration,
            'actual_distance' => $this->actual_distance,
            'actual_duration' => $this->actual_duration,

            // Charges inclusion flags
            'toll_charges_included' => $this->toll_charges_included,
            'fuel_charges_included' => $this->fuel_charges_included,
            'parking_charges_included' => $this->parking_charges_included,

            // Payment details
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'payment_reference' => $this->payment_reference,
            'payment_responsibility' => $this->payment_responsibility,
            'payment_collection_method' => $this->payment_collection_method,
            'payment_collection_status' => $this->payment_collection_status,
            'payment_collected_amount' => $this->payment_collected_amount !== null ? (float) $this->payment_collected_amount : null,
            'payment_collected_at' => $this->payment_collected_at?->toISOString(),
            'payment_collected_by_driver_id' => $this->payment_collected_by_driver_id,
            'payment_notes' => $this->payment_notes,

            // Corporate booking fields
            'is_corporate_booking' => $this->is_corporate_booking,
            'corporate_account_id' => $this->corporate_account_id,
            'cost_center' => $this->cost_center,
            'project_code' => $this->project_code,
            'employee_id' => $this->employee_id,

            // Recurring booking fields
            'is_recurring' => $this->is_recurring,
            'recurrence_pattern' => $this->recurrence_pattern,
            'recurrence_end_date' => $this->recurrence_end_date?->format('Y-m-d'),
            'recurrence_days' => $this->recurrence_days,
            'recurring_series_id' => $this->recurring_series_id,
            'recurring_sequence' => $this->recurring_sequence,
            'recurring_occurrence_date' => $this->recurring_occurrence_date?->format('Y-m-d'),

            // Approval workflow
            'approval_details' => [
                'approval_status' => $this->approval_status,
                'requires_approval' => $this->requires_approval,
                'approval_requested_by' => $this->whenLoaded('approvalRequestedBy', function () {
                    return $this->approvalRequestedBy->name ?? null;
                }),
                'approval_requested_at' => $this->approval_requested_at?->format('Y-m-d H:i:s'),
                'approval_justification' => $this->approval_justification,
                'approval_priority' => $this->approval_priority,
                'approved_by' => $this->whenLoaded('approvedBy', function () {
                    return $this->approvedBy->name ?? null;
                }),
                'approval_at' => $this->approval_at?->format('Y-m-d H:i:s'),
                'approval_note' => $this->approval_note,
            ],

            // Emergency contact
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
                'relationship' => $this->emergency_contact_relationship,
            ],

            // Insurance and safety
            'insurance_type' => $this->insurance_type,
            'safety_features_required' => $this->safety_features_required,

            // Notification preferences
            'notifications' => [
                'sms' => $this->notification_sms,
                'email' => $this->notification_email,
                'whatsapp' => $this->notification_whatsapp,
                'push' => $this->notification_push,
            ],

            // Trip tracking
            'trip_status' => $this->trip_status,
            'trip_started_at' => $this->trip_started_at?->format('Y-m-d H:i:s'),
            'trip_ended_at' => $this->trip_ended_at?->format('Y-m-d H:i:s'),
            'current_location' => [
                'latitude' => $this->current_latitude,
                'longitude' => $this->current_longitude,
                'updated_at' => $this->location_updated_at?->format('Y-m-d H:i:s'),
            ],

            // Feedback and ratings
            'feedback' => [
                'customer_rating' => $this->customer_rating,
                'customer_feedback' => $this->customer_feedback,
                'driver_rating' => $this->driver_rating,
                'driver_feedback' => $this->driver_feedback,
            ],

            // Override and workflow information
            'concurrent_assignments' => $this->concurrent_assignments,
            'workflow_step' => $this->workflow_step,
            'workflow_data' => $this->withoutOriginalPrices((array) $this->workflow_data),

            // Add-ons
            'addons' => $this->whenLoaded('addons', function () {
                return $this->addons->map(function ($addon) {
                    return [
                        'id' => $addon->id,
                        'addon_id' => $addon->addon_id,
                        'name' => $addon->addon->name ?? null,
                        'quantity' => $addon->qty,
                        'rate' => $addon->rate,
                        'amount' => $addon->amount,
                        'is_insurance' => $addon->is_insurance,
                        'is_milage' => $addon->is_milage,
                        'label' => $addon->label,
                    ];
                });
            }),

            // Approval records
            'approvals' => $this->whenLoaded('approvals', function () {
                return $this->approvals->map(function ($approval) {
                    return [
                        'id' => $approval->id,
                        'status' => $approval->status,
                        'priority' => $approval->priority,
                        'override_reasons' => $approval->override_reasons,
                        'justification' => $approval->justification,
                        'comments' => $approval->comments,
                        'approved_at' => $approval->approved_at?->format('Y-m-d H:i:s'),
                        'rejected_at' => $approval->rejected_at?->format('Y-m-d H:i:s'),
                        'auto_approved' => $approval->auto_approved,
                        'approval_level' => $approval->approval_level,
                        'requester' => $approval->requester->name ?? null,
                        'approver' => $approval->approver->name ?? null,
                        'manager' => $approval->manager->name ?? null,
                    ];
                });
            }),

            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'booked_at' => $this->booked_at?->format('Y-m-d H:i:s'),
            'confirmed_at' => $this->confirmed_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i:s'),

            // Multi-trip details
            'booking_items' => $this->whenLoaded('bookingItems', function () {
                return $this->bookingItems->map(fn ($item) => $this->withoutOriginalPrices($item->toArray()));
            }),
            'variable_customizations' => $this->whenLoaded('variableCustomizations'),
        ];
    }

    private function withoutOriginalPrices(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array((string) $key, [
                'price_override_amount', 'price_override_reason', 'price_overridden_by',
                'price_overridden_at', 'price_adjustment_reason', 'final_price',
            ], true) || preg_match('/(^original_|_original_|price_before_|calculated_(price|base))/', (string) $key)) {
                unset($values[$key]);
                continue;
            }
            if (is_array($value)) {
                $values[$key] = $this->withoutOriginalPrices($value);
            }
        }

        return $values;
    }
}
