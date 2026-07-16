<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\Website\WebsiteSetting;
use App\Models\BusinessSetting;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehicleAssignment;
use App\Enums\BookingLifecycleStatus;
use App\Enums\DispatchStatus;
use App\Enums\QCStatus;
use Carbon\Carbon;

/**
 * App\Models\Booking\Booking
 * 
 * @property int $id
 * @property int $customer_id
 * @property string|null $invoice_number
 * @property string|null $log_code
 * @property int|null $vip_id
 * @property \Illuminate\Support\Carbon|null $booking_date
 * @property float|null $total_estimated
 * @property float|null $total_actual
 * @property string|null $created_from
 * @property bool $confirmed
 * @property string|null $third_party_ref
 * @property string|null $payment_status
 * @property string|null $payment_reference
 * @property int|null $created_user_id
 * @property int|null $updated_user_id
 * @property bool|null $is_self_driven
 * @property int|null $passenger_count
 * @property int|null $luggage_count
 * @property string|null $special_requirements
 * @property float|null $base_amount
 * @property float|null $driver_cost
 * @property float|null $distance_cost
 * @property float|null $addons_cost
 * @property float|null $discount_amount
 * @property float|null $tax_amount
 * @property string|null $currency
 * @property string|null $payment_method
 * @property bool|null $is_corporate_booking
 * @property int|null $corporate_account_id
 * @property string|null $cost_center
 * @property string|null $project_code
 * @property int|null $employee_id
 * @property bool|null $is_recurring
 * @property string|null $recurrence_pattern
 * @property \Illuminate\Support\Carbon|null $recurrence_end_date
 * @property array|null $recurrence_days
 * @property bool $requires_approval
 * @property string|null $approval_status
 * @property int|null $approval_requested_by
 * @property \Illuminate\Support\Carbon|null $approval_requested_at
 * @property string|null $approval_justification
 * @property string|null $approval_priority
 * @property float|null $estimated_distance
 * @property float|null $estimated_duration
 * @property float|null $actual_distance
 * @property float|null $actual_duration
 * @property bool|null $toll_charges_included
 * @property bool|null $fuel_charges_included
 * @property bool|null $parking_charges_included
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $emergency_contact_relationship
 * @property string|null $insurance_type
 * @property string|null $safety_features_required
 * @property bool|null $notification_sms
 * @property bool|null $notification_email
 * @property bool|null $notification_whatsapp
 * @property bool|null $notification_push
 * @property string|null $booking_number
 * @property string|null $confirmation_number
 * @property \Illuminate\Support\Carbon|null $booked_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $trip_status
 * @property \Illuminate\Support\Carbon|null $trip_started_at
 * @property \Illuminate\Support\Carbon|null $trip_ended_at
 * @property float|null $current_latitude
 * @property float|null $current_longitude
 * @property \Illuminate\Support\Carbon|null $location_updated_at
 * @property int|null $customer_rating
 * @property string|null $customer_feedback
 * @property int|null $driver_rating
 * @property string|null $driver_feedback
 * @property float|null $base_price_override
 * @property string|null $base_price_override_reason
 * @property int|null $base_price_edited_by
 * @property \Illuminate\Support\Carbon|null $base_price_edited_at
 * @property array|null $addon_overrides
 * @property array|null $discounts
 * @property int|null $approval_by
 * @property \Illuminate\Support\Carbon|null $approval_at
 * @property string|null $approval_note
 * @property array|null $original_totals
 * @property array|null $edited_totals
 * @property int|null $gamify_points_earned
 * @property float|null $gamify_discount_applied
 * @property array|null $gamify_details
 * @property int|null $created_by_user_id
 * @property string|null $booking_source
 * @property array|null $override_reasons
 * @property bool $has_overrides
 * @property int|null $concurrent_assignments
 * @property string|null $workflow_step
 * @property array|null $workflow_data
 * @property array|null $pricing_snapshot
 * @property array|null $duration_metrics
 * @property array|null $distance_metrics
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * 
 * @property-read \App\Models\Customer|null $customer   
 * @property-read \App\Models\VipType|null $vipType
 * @property-read \App\Models\Vehicle\VehicleGroup|null $vehicleGroup
 * @property-read \App\Models\User|null $createdBy
 *
 *
 */
class Booking extends BaseModel
{
    /**
     * Accepted T&C for this booking (booking_terms)
     */
    public function acceptedTerms()
    {
        return $this->hasMany(\App\Models\Booking\BookingTerm::class, 'booking_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'agent_id',
        'invoice_number',
        'log_code',
        'vip_id',
        'booking_date',
        'total_estimated',
        'total_actual',
        'status',
        'created_from',
        'confirmed',
        'third_party_ref',
        'payment_status',
        'payment_reference',
        'created_user_id',
        'updated_user_id',

        // Service details (booking-level, not item-specific)
        'passenger_count',
        'luggage_count',
        'special_requirements',

        // Enhanced pricing fields
        'base_amount',
        'driver_cost',
        'distance_cost',
        'addons_cost',
        'discount_amount',
        'tax_amount',
        'currency',

        // Payment details
        'payment_method',
        'payment_status',
        'payment_reference',
        'payment_type',
        'amount_to_pay',
        'payment_responsibility',
        'payment_collection_method',
        'payment_collection_status',
        'payment_collected_amount',
        'payment_collected_at',
        'payment_collected_by_driver_id',
        'payment_notes',

        // Corporate booking fields
        'is_corporate_booking',
        'corporate_account_id',
        'cost_center',
        'project_code',
        'employee_id',
        'corporate_department_id',
        'corporate_division_id',

        // Recurring booking fields
        'is_recurring',
        'recurrence_pattern',
        'recurrence_end_date',
        'recurrence_days',
        'recurring_series_id',
        'recurring_sequence',
        'recurring_occurrence_date',

        // Approval workflow
        'requires_approval',
        'approval_status',
        'approval_requested_by',
        'approval_requested_at',
        'approval_justification',
        'approval_priority',

        // Distance and time tracking
        'estimated_distance',
        'estimated_duration',
        'actual_distance',
        'actual_duration',

        // Charges inclusion flags
        'toll_charges_included',
        'fuel_charges_included',
        'parking_charges_included',

        // Emergency contact
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',

        // Insurance and safety
        'insurance_type',
        'safety_features_required',

        // Notification preferences
        'notification_sms',
        'notification_email',
        'notification_whatsapp',
        'notification_push',

        // Booking confirmations and numbers
        'booking_number',
        'confirmation_number',

        // Additional timestamps
        'booked_at',
        'confirmed_at',
        'completed_at',
        'cancelled_at',

        // Trip tracking
        'trip_status',
        'trip_started_at',
        'trip_ended_at',
        'current_latitude',
        'current_longitude',
        'location_updated_at',

        // Feedback and ratings
        'customer_rating',
        'customer_feedback',
        'driver_rating',
        'driver_feedback',

        // Pricing overrides and approval management
        'base_price_override',
        'base_price_override_reason',
        'base_price_edited_by',
        'base_price_edited_at',
        'addon_overrides',
        'discounts',
        'approval_by',
        'approval_at',
        'approval_note',
        'original_totals',
        'edited_totals',

        // Gamify integration
        'gamify_points_earned',
        'gamify_discount_applied',
        'gamify_details',

        // Enhanced booking workflow
        'created_by_user_id',
        'booking_source',
        'override_reasons',
        'has_overrides',
        'concurrent_assignments',
        'workflow_step',
        'workflow_data',

        'pricing_snapshot',
        'duration_metrics',
        'distance_metrics',

        // Base model fields
        'is_active'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'booking_date' => 'datetime',
        'approval_requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'recurrence_end_date' => 'date',
        'recurring_occurrence_date' => 'date',
        'payment_collected_at' => 'datetime',
        'payment_collected_amount' => 'decimal:2',

        // booleans
        'is_recurring' => 'boolean',
        'requires_approval' => 'boolean',
        'has_overrides' => 'boolean',
        'confirmed' => 'boolean',

        // json
        'pricing_snapshot' => 'array',
        'duration_metrics' => 'array',
        'distance_metrics' => 'array',
        'discounts' => 'array',
        'override_reasons' => 'array',
        'review_notes' => 'array',
        'workflow_data' => 'array',
        'recurrence_days' => 'array',
    ];

    // Relations

    /**
     * Get the customer for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    /**
     * Get the service type for this booking.
     * Returns the service type from the first booking item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
     */
    public function serviceType()
    {
        $firstItem = $this->bookingItems()->first();
        return $firstItem ? $firstItem->serviceType() : null;
    }

    /**
     * Get all service types for this booking (from all booking items).
     *
     * @return \Illuminate\Support\Collection
     */
    public function serviceTypes()
    {
        return $this->bookingItems()
            ->with('serviceType')
            ->get()
            ->pluck('serviceType')
            ->unique('id')
            ->filter();
    }

    /**
     * Get the VIP type for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vipType()
    {
        return $this->belongsTo(\App\Models\VipType::class, 'vip_id');
    }

    /**
     * Get the assigned vehicle for this booking.
     * Returns the vehicle from the first booking item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
     */
    public function vehicle()
    {
        $firstItem = $this->bookingItems()->first();
        return $firstItem ? $firstItem->vehicle() : null;
    }

    /**
     * Get all vehicles for this booking (from all booking items).
     *
     * @return \Illuminate\Support\Collection
     */
    public function vehicles()
    {
        return $this->bookingItems()
            ->with('vehicle')
            ->get()
            ->pluck('vehicle')
            ->filter();
    }

    /**
     * Get the vehicle group for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicleGroup()
    {
        return $this->belongsTo(\App\Models\Vehicle\VehicleGroup::class);
    }

    /**
     * Get the assigned driver for this booking.
     * Returns the driver from the first booking item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo|null
     */
    public function driver()
    {
        $firstItem = $this->bookingItems()->first();
        return $firstItem ? $firstItem->driver() : null;
    }

    /**
     * Get all drivers for this booking (from all booking items).
     *
     * @return \Illuminate\Support\Collection
     */
    public function drivers()
    {
        return $this->bookingItems()
            ->with('driver')
            ->get()
            ->pluck('driver')
            ->filter();
    }

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_user_id');
    }

    /**
     * Get all addons for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function addons()
    {
        return $this->hasMany(BookingAddon::class);
    }
    /**
     * Get all addons for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookingAddons()
    {
        return $this->hasMany(BookingAddon::class);
    }

    /**
     * Get all status history for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statuses()
    {
        return $this->hasMany(BookingStatus::class);
    }

    /**
     * Get the base pricing for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function bookingPricing()
    {
        return $this->hasOne(BookingPricing::class);
    }

    /**
     * Get all common rate pricings for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookingCommonRatePricings()
    {
        return $this->hasMany(BookingCommonRatePricing::class);
    }

    /**
     * Get the user who edited the base price.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function basePriceEditedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'base_price_edited_by');
    }

    /**
     * Get the user who approved this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approvedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'approval_by');
    }

    /**
     * Get the user who requested approval for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approvalRequestedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'approval_requested_by');
    }

    /**
     * Get all booking approvals for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function approvals()
    {
        return $this->hasMany(BookingApproval::class);
    }

    /**
     * Get the corporate account for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporateAccount()
    {
        return $this->belongsTo(\App\Models\Corporate\Corporate::class, 'corporate_account_id');
    }

    /**
     * Get the corporate department for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporateDepartment()
    {
        return $this->belongsTo(\App\Models\Corporate\CorporateDepartment::class, 'corporate_department_id');
    }

    /**
     * Get the corporate division for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function corporateDivision()
    {
        return $this->belongsTo(\App\Models\Corporate\CorporateDivision::class, 'corporate_division_id');
    }

    /**
     * Get the corporate employee for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function employee()
    {
        return $this->hasOne(\App\Models\Corporate\CorporateEmployee::class, 'user_id', 'employee_id')
            ->where('corporate_id', $this->corporate_account_id);
    }

    /**
     * Get the latest approval record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function latestApproval()
    {
        return $this->hasOne(BookingApproval::class)->latest();
    }

    /**
     * Get all variable customizations for this booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function variableCustomizations()
    {
        return $this->hasMany(BookingVariableCustomization::class);
    }

    /**
     * Get all booking items for this booking (for multi-group bookings)
     */
    public function bookingItems()
    {
        // Order booking items by pickup/start date to ensure consistent chronological display
        return $this->hasMany(BookingItem::class)->orderBy('from_date', 'asc');
    }

    /**
     * Get vehicle assignment for this booking
     */
    public function vehicleAssignment()
    {
        return $this->hasOne(VehicleAssignment::class);
    }

    /**
     * Get driver assignment for this booking
     */
    public function driverAssignment()
    {
        return $this->hasOne(\App\Models\DriverAssignment::class);
    }

    /**
     * Get dispatch record for this booking
     */
    public function dispatch()
    {
        return $this->hasOne(BookingDispatch::class);
    }

    /**
     * Get all item-owned dispatch records for this booking.
     */
    public function dispatches()
    {
        return $this->hasMany(BookingDispatch::class);
    }

    /**
     * Get QC record for this booking
     */
    public function qc()
    {
        return $this->hasOne(BookingQC::class);
    }

    /**
     * Get all vehicle assignments for this booking
     */
    public function vehicleAssignments()
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    /**
     * Get driver assignment for this booking
     */
    public function driverAssignments()
    {
        return $this->hasMany(\App\Models\DriverAssignment::class);
    }

    /**
     * Get all assignments (both vehicle and driver) for this booking
     */
    public function assignments()
    {
        return [
            'vehicle' => $this->vehicleAssignment,
            'driver' => $this->driverAssignment
        ];
    }

    // Helper methods for pricing overrides

    /**
     * Check if this booking has any pricing overrides
     */
    public function hasPricingOverrides(): bool
    {
        return $this->base_price_override !== null ||
            !empty($this->addon_overrides) ||
            !empty($this->discounts) ||
            $this->has_overrides;
    }

    /**
     * Check if this booking requires approval
     */
    public function requiresApproval(): bool
    {
        return $this->requires_approval && $this->approval_status === 'pending';
    }

    /**
     * Get the effective base price (override or calculated)
     */
    public function getEffectiveBasePrice(): float
    {
        return $this->base_price_override ?? $this->base_amount ?? 0;
    }

    /**
     * Apply gamify discount to the booking
     */
    public function applyGamifyDiscount(float $discountAmount, array $details = []): void
    {
        $this->gamify_discount_applied = $discountAmount;
        $this->gamify_details = $details;
        $this->save();
    }

    /**
     * Calculate total amount with all adjustments
     */
    public function getTotalAmount(): float
    {
        $baseAmount = $this->getEffectiveBasePrice();
        $driverCost = $this->driver_cost ?? 0;
        $distanceCost = $this->distance_cost ?? 0;
        $addonsCost = $this->addons_cost ?? 0;
        $discountAmount = $this->discount_amount ?? 0;
        $taxAmount = $this->tax_amount ?? 0;
        $gamifyDiscount = $this->gamify_discount_applied ?? 0;

        return $baseAmount + $driverCost + $distanceCost + $addonsCost + $taxAmount - $discountAmount - $gamifyDiscount;
    }

    /**
     * Calculate total from all booking items
     * This method sums the total_price of all booking items for multi-trip bookings
     * 
     * @return float
     */
    public function calculateTotal(): float
    {
        // If booking has booking items, sum their totals
        if ($this->bookingItems()->exists()) {
            return $this->bookingItems->sum(function ($item) {
                return $item->calculateTotalValue();
            });
        }

        // Fallback to legacy calculation if no booking items exist
        return $this->getTotalAmount();
    }

    /**
     * Get booking duration in hours
     */
    public function getDurationInHours(): int
    {
        if (!$this->from_date || !$this->to_date) {
            return 0;
        }

        $fromDateTime = $this->from_date->copy();
        if ($this->from_time) {
            $fromDateTime->setTimeFromTimeString($this->from_time);
        }

        $toDateTime = $this->to_date->copy();
        if ($this->to_time) {
            $toDateTime->setTimeFromTimeString($this->to_time);
        }

        return $fromDateTime->diffInHours($toDateTime);
    }

    /**
     * Get booking duration in days
     */
    public function getDurationInDays(): int
    {
        if (!$this->from_date || !$this->to_date) {
            return 0;
        }

        return $this->from_date->diffInDays($this->to_date) + 1; // Include both start and end day
    }

    /**
     * Check if booking is active (not cancelled or completed)
     */
    public function isActive(): bool
    {
        return !in_array($this->status, ['cancelled', 'completed']);
    }

    /**
     * Check if booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        if (!in_array($this->status, ['pending', 'pending_approval', 'confirmed'])) {
            return false;
        }

        $startDateTime = $this->from_date?->copy();
        if (!$startDateTime) {
            return false;
        }

        if ($this->from_time) {
            try {
                $startDateTime->setTimeFromTimeString($this->from_time);
            } catch (\Exception $e) {
                // If time parsing fails, fall back to date-only comparison.
            }
        }

        if (!$startDateTime->isFuture()) {
            return false;
        }

        $cancellationAllowed = $this->normalizeSettingBoolean(
            BusinessSetting::getSetting('cancellation_allowed')
                ?? WebsiteSetting::getValue('cancellation_allowed', null),
            true
        );
        if (!$cancellationAllowed) {
            return false;
        }

        $cancellationHours = (int) (
            BusinessSetting::getSetting('cancellation_hours')
            ?? WebsiteSetting::getValue('cancellation_hours', 0)
        );
        if ($cancellationHours > 0) {
            return now()->addHours($cancellationHours)->lte($startDateTime);
        }

        return true;
    }

    /**
     * Normalize boolean values stored in settings.
     */
    protected function normalizeSettingBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Check if booking can be modified
     */
    public function canBeModified(): bool
    {
        return in_array($this->status, ['pending', 'pending_approval'])
            && $this->from_date->isFuture();
    }

    /**
     * Mark booking as confirmed
     */
    public function markAsConfirmed(string $userId = null): void
    {
        $this->status = 'confirmed';
        $this->confirmed = true;
        $this->confirmed_at = now();

        if ($userId) {
            $this->updated_user_id = $userId;
        }

        $this->save();
    }

    /**
     * Mark booking as cancelled
     */
    public function markAsCancelled(string $reason = null, string $userId = null): void
    {
        $this->status = 'cancelled';
        $this->cancelled_at = now();

        if ($reason) {
            $this->workflow_data = array_merge($this->workflow_data ?? [], [
                'cancellation_reason' => $reason,
                'cancelled_by' => $userId
            ]);
        }

        if ($userId) {
            $this->updated_user_id = $userId;
        }

        $this->save();
    }

    /**
     * Apply approval decision
     */
    public function applyApprovalDecision(string $decision, string $approverId, string $note = null): void
    {
        $this->approval_status = $decision;
        $this->approval_by = $approverId;
        $this->approval_at = now();
        $this->approval_note = $note;

        if ($decision === 'approved') {
            $this->markAsConfirmed($approverId);
        } elseif ($decision === 'rejected') {
            $this->markAsCancelled('approval_rejected', $approverId);
        }

        $this->save();
    }

    // ========================
    // LIFECYCLE STATUS METHODS
    // ========================

    /**
     * Get current lifecycle status
     */
    public function getLifecycleStatus(): BookingLifecycleStatus
    {
        // Map current status to lifecycle status
        return match ($this->status) {
            'inquiry' => BookingLifecycleStatus::INQUIRY,
            'inquiry_qualified' => BookingLifecycleStatus::INQUIRY_QUALIFIED,
            'inquiry_cancelled' => BookingLifecycleStatus::INQUIRY_CANCELLED,
            'pending' => BookingLifecycleStatus::BOOKING_PENDING,
            'confirmed' => $this->getDetailedLifecycleStatus(),
            'cancelled' => BookingLifecycleStatus::CANCELLED,
            'completed' => BookingLifecycleStatus::COMPLETED,
            'pending_approval' => BookingLifecycleStatus::BOOKING_REQUIRES_APPROVAL,
            'approved' => BookingLifecycleStatus::BOOKING_APPROVED,
            'rejected' => BookingLifecycleStatus::BOOKING_REJECTED,
            default => BookingLifecycleStatus::BOOKING_PENDING,
        };
    }

    /**
     * Get detailed lifecycle status for confirmed bookings
     */
    private function getDetailedLifecycleStatus(): BookingLifecycleStatus
    {
        // Check if booking is dispatched
        if ($this->dispatch) {
            switch ($this->dispatch->dispatch_status) {
                case DispatchStatus::DISPATCHED:
                case DispatchStatus::IN_PROGRESS:
                    return BookingLifecycleStatus::ONGOING_ACTIVE;
                case DispatchStatus::RETURNED:
                    // Check QC status
                    if ($this->qc) {
                        return match ($this->qc->qc_status) {
                            QCStatus::PENDING => BookingLifecycleStatus::QC_PENDING,
                            QCStatus::IN_PROGRESS => BookingLifecycleStatus::QC_IN_PROGRESS,
                            QCStatus::ISSUES_FOUND => BookingLifecycleStatus::QC_ISSUES_FOUND,
                            QCStatus::REPAIR_REQUIRED => BookingLifecycleStatus::QC_REPAIR_NEEDED,
                            QCStatus::COMPLETED => BookingLifecycleStatus::QC_COMPLETED,
                        };
                    }
                    return BookingLifecycleStatus::QC_PENDING;
                case DispatchStatus::READY_FOR_DISPATCH:
                    return BookingLifecycleStatus::DISPATCH_READY;
                default:
                    return BookingLifecycleStatus::ALLOCATION_PENDING;
            }
        }

        // Check if vehicle/driver assigned
        if ($this->vehicle_id || $this->driver_id) {
            return BookingLifecycleStatus::ALLOCATION_ASSIGNED;
        }

        return BookingLifecycleStatus::ALLOCATION_PENDING;
    }

    /**
     * Transition to new lifecycle status
     */
    public function transitionToStatus(BookingLifecycleStatus $newStatus, string $userId, array $data = []): bool
    {
        $currentStatus = $this->getLifecycleStatus();

        if (!$currentStatus->canTransitionTo($newStatus)) {
            return false;
        }

        // Update the appropriate status based on the new lifecycle status
        $this->updateStatusBasedOnLifecycle($newStatus, $userId, $data);

        return true;
    }

    /**
     * Update model status based on lifecycle status
     */
    private function updateStatusBasedOnLifecycle(BookingLifecycleStatus $status, string $userId, array $data = []): void
    {
        switch ($status) {
            case BookingLifecycleStatus::BOOKING_CONFIRMED:
                $this->markAsConfirmed($userId);
                break;

            case BookingLifecycleStatus::DISPATCH_OUT:
                if (!$this->dispatch) {
                    $this->dispatch()->create([
                        'vehicle_id' => $this->vehicle_id,
                        'driver_id' => $this->driver_id,
                        'dispatch_status' => DispatchStatus::DISPATCHED,
                        'dispatched_at' => now(),
                        'dispatched_by' => $userId,
                        'expected_return_at' => $this->to_date,
                        'is_self_driven' => $this->is_self_driven,
                    ]);
                } else {
                    $this->dispatch->markDispatched($userId, $data);
                }
                break;

            case BookingLifecycleStatus::RETURN_COMPLETED:
                if ($this->dispatch) {
                    $this->dispatch->markReturned($userId, $data);
                }
                break;

            case BookingLifecycleStatus::QC_PENDING:
                if (!$this->qc) {
                    $this->qc()->create([
                        'vehicle_id' => $this->vehicle_id,
                        'dispatch_id' => $this->dispatch?->id,
                        'qc_status' => QCStatus::PENDING,
                    ]);
                }
                break;

            case BookingLifecycleStatus::QC_IN_PROGRESS:
                if ($this->qc) {
                    $this->qc->startInspection($userId);
                }
                break;

            case BookingLifecycleStatus::QC_COMPLETED:
                if ($this->qc) {
                    $this->qc->markCompleted();
                }
                break;

            case BookingLifecycleStatus::COMPLETED:
                $this->status = 'completed';
                $this->completed_at = Carbon::now('UTC');
                $this->updated_user_id = $userId;
                $this->save();
                break;
        }
    }

    /**
     * Get next possible actions based on current lifecycle status
     */
    public function getNextActions(): array
    {
        $currentStatus = $this->getLifecycleStatus();
        $nextStatuses = $currentStatus->getNextStatuses();

        return array_map(function ($status) {
            return [
                'status' => $status->value,
                'display_name' => $status->getDisplayName(),
                'action' => $status->getPrimaryAction(),
                'color' => $status->getColor(),
            ];
        }, $nextStatuses);
    }

    /**
     * Check if booking is in a specific lifecycle stage
     */
    public function isInStage(string $stage): bool
    {
        return $this->getLifecycleStatus()->value === $stage;
    }

    // ========================
    // BOOKING ITEMS HELPERS
    // ========================

    /**
     * Get the primary booking item (first item or single item)
     */
    public function primaryItem(): ?BookingItem
    {
        return $this->bookingItems()->first();
    }

    /**
     * Check if this is a multi-item booking
     */
    public function isMultiItem(): bool
    {
        return $this->bookingItems()->count() > 1;
    }

    // ========================
    // ACCESSOR METHODS FOR BACKWARD COMPATIBILITY
    // ========================

    /**
     * Get vehicle_group_id from primary booking item
     */
    public function getVehicleGroupIdAttribute(): ?string
    {
        return $this->primaryItem()?->vehicle_group_id;
    }

    /**
     * Get vehicle_id from primary booking item
     */
    public function getVehicleIdAttribute(): ?string
    {
        return $this->primaryItem()?->vehicle_id;
    }

    /**
     * Get driver_id from primary booking item
     */
    public function getDriverIdAttribute(): ?string
    {
        return $this->primaryItem()?->driver_id;
    }

    /**
     * Get service_type_id from primary booking item
     */
    public function getServiceTypeIdAttribute(): ?string
    {
        return $this->primaryItem()?->service_type_id;
    }

    /**
     * Get from_date from primary booking item
     */
    public function getFromDateAttribute()
    {
        return $this->primaryItem()?->from_date;
    }

    /**
     * Get to_date from primary booking item
     */
    public function getToDateAttribute()
    {
        return $this->primaryItem()?->to_date;
    }

    /**
     * Get from_time from primary booking item
     */
    public function getFromTimeAttribute(): ?string
    {
        return $this->primaryItem()?->from_time;
    }

    /**
     * Get to_time from primary booking item
     */
    public function getToTimeAttribute(): ?string
    {
        return $this->primaryItem()?->to_time;
    }

    /**
     * Get pickup_location from primary booking item
     */
    public function getPickupLocationAttribute(): ?array
    {
        $location = $this->primaryItem()?->pickup_location;
        
        // Handle if it's a JSON string
        if (is_string($location)) {
            $decoded = json_decode($location, true);
            return is_array($decoded) ? $decoded : null;
        }
        
        return is_array($location) ? $location : null;
    }

    /**
     * Get dropoff_location from primary booking item
     */
    public function getDropoffLocationAttribute(): ?array
    {
        $location = $this->primaryItem()?->dropoff_location;
        
        // Handle if it's a JSON string
        if (is_string($location)) {
            $decoded = json_decode($location, true);
            return is_array($decoded) ? $decoded : null;
        }
        
        return is_array($location) ? $location : null;
    }

    /**
     * Get pickup_latitude from primary booking item
     */
    public function getPickupLatitudeAttribute(): ?float
    {
        return $this->primaryItem()?->pickup_latitude;
    }

    /**
     * Get pickup_longitude from primary booking item
     */
    public function getPickupLongitudeAttribute(): ?float
    {
        return $this->primaryItem()?->pickup_longitude;
    }

    /**
     * Get pickup_landmark from primary booking item
     */
    public function getPickupLandmarkAttribute(): ?string
    {
        return $this->primaryItem()?->pickup_landmark;
    }

    /**
     * Get dropoff_latitude from primary booking item
     */
    public function getDropoffLatitudeAttribute(): ?float
    {
        return $this->primaryItem()?->dropoff_latitude;
    }

    /**
     * Get dropoff_longitude from primary booking item
     */
    public function getDropoffLongitudeAttribute(): ?float
    {
        return $this->primaryItem()?->dropoff_longitude;
    }

    /**
     * Get dropoff_landmark from primary booking item
     */
    public function getDropoffLandmarkAttribute(): ?string
    {
        return $this->primaryItem()?->dropoff_landmark;
    }

    /**
     * Get is_self_driven from primary booking item
     */
    public function getIsSelfDrivenAttribute(): ?bool
    {
        return $this->primaryItem()?->is_self_driven;
    }

    // ========================
    // COLLECTION METHODS
    // ========================

    /**
     * Get all vehicle IDs from booking items
     */
    public function getVehicleIds(): array
    {
        return $this->bookingItems()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get all driver IDs from booking items
     */
    public function getDriverIds(): array
    {
        return $this->bookingItems()
            ->whereNotNull('driver_id')
            ->pluck('driver_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get all service type IDs from booking items
     */
    public function getServiceTypeIds(): array
    {
        return $this->bookingItems()
            ->whereNotNull('service_type_id')
            ->pluck('service_type_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get all vehicle group IDs from booking items
     */
    public function getVehicleGroupIds(): array
    {
        return $this->bookingItems()
            ->whereNotNull('vehicle_group_id')
            ->pluck('vehicle_group_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get earliest from_date from all booking items
     */
    public function getEarliestFromDate()
    {
        return $this->bookingItems()->min('from_date');
    }

    /**
     * Get latest to_date from all booking items
     */
    public function getLatestToDate()
    {
        return $this->bookingItems()->max('to_date');
    }

     /*
     * Format: {PREFIX}{6-digit-sequence} e.g. BK000001 or QT000123
     * Accepts optional $prefix (default 'BK').
     */
    public static function generateBookingNumber(): string
    {
        // Default booking number generator (BKxxxxxx)
        $prefix = 'BK';
        $attempt = 0;
        $maxAttempts = 100; // Prevent infinite loops

        // Attempt to find the most recent booking with this prefix (including soft deleted)
        $last = static::withTrashed()
            ->where('booking_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        $next = 1;

        if ($last && preg_match('/(\d+)$/', $last->booking_number, $m)) {
            $next = intval($m[1]) + 1;
        } else {
            $count = static::withTrashed()->where('booking_number', 'like', $prefix . '%')->count();
            $next = $count + 1;
        }

        // Add loop to ensure uniqueness
        do {
            $candidate = $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
            
            // Check existence including soft deleted records
            if (!static::withTrashed()->where('booking_number', $candidate)->exists()) {
                return $candidate;
            }
            
            $next++;
            $attempt++;
        } while ($attempt < $maxAttempts);

        // Fallback for extreme cases (should basically never happen)
        return $prefix . now()->format('ymdHis');
    }

    /**
     * Generate quotation number (QTxxxxxx)
     */
    public static function generateQuotationNumber(): string
    {
        $prefix = 'QT';
        $attempt = 0;
        $maxAttempts = 100;

        $last = static::withTrashed()
            ->where('booking_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        $next = 1;

        if ($last && preg_match('/(\d+)$/', $last->booking_number, $m)) {
            $next = intval($m[1]) + 1;
        } else {
            $count = static::withTrashed()->where('booking_number', 'like', $prefix . '%')->count();
            $next = $count + 1;
        }

        do {
            $candidate = $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
            
            if (!static::withTrashed()->where('booking_number', $candidate)->exists()) {
                return $candidate;
            }
            
            $next++;
            $attempt++;
        } while ($attempt < $maxAttempts);

        return $prefix . now()->format('ymdHis');
    }

    /**
     * Generate unique confirmation number
     */
    public static function generateConfirmationNumber(): string
    {
        $prefix = 'CNF';
        $timestamp = now()->timestamp;
        $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        return $prefix . $timestamp . $random;
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = static::generateBookingNumber();
            }
        });

        static::updating(function ($booking) {
            if ($booking->isDirty('status') && $booking->status === 'confirmed' && empty($booking->confirmation_number)) {
                $booking->confirmation_number = static::generateConfirmationNumber();
            }
        });
    }
}
