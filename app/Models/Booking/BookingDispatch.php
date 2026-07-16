<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;
use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Enums\DispatchStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Booking Dispatch Model
 * 
 * Tracks dispatch details for bookings including vehicle handover,
 * driver assignment, and return processing
 */
class BookingDispatch extends BaseModel
{
    protected $table = 'booking_dispatches';

    protected $fillable = [
        'booking_id',
        'booking_item_id',
        'vehicle_id',
        'driver_id',
        'dispatch_status',
        'dispatched_at',
        'dispatched_by',
        'expected_return_at',
        'actual_return_at',
        'returned_by',
        'dispatch_notes',
        'return_notes',
        'fuel_level_out',
        'fuel_level_in',
        'mileage_out',
        'mileage_in',
        'vehicle_condition_out',
        'vehicle_condition_in',
        'damages_reported',
        'additional_charges',
        'late_return_fee',
        'documents_generated',
        'agreements_signed',
        'is_self_driven',
        'trigger_delivered_at',
        'trigger_delivery_channel',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'dispatch_status' => DispatchStatus::class,
        'dispatched_at' => 'datetime',
        'expected_return_at' => 'datetime',
        'actual_return_at' => 'datetime',
        'trigger_delivered_at' => 'datetime',
        'fuel_level_out' => 'decimal:1',
        'fuel_level_in' => 'decimal:1',
        'mileage_out' => 'integer',
        'mileage_in' => 'integer',
        'vehicle_condition_out' => 'array',
        'vehicle_condition_in' => 'array',
        'damages_reported' => 'array',
        'additional_charges' => 'array',
        'late_return_fee' => 'decimal:2',
        'documents_generated' => 'array',
        'agreements_signed' => 'boolean',
        'is_self_driven' => 'boolean',
    ];

    /**
     * Get the booking this dispatch belongs to
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the vehicle for this dispatch
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the driver for this dispatch
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Get the user who dispatched the vehicle
     */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * Get the user who processed the return
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Check if this dispatch is active
     */
    public function isActive(): bool
    {
        return in_array($this->dispatch_status, [
            DispatchStatus::DISPATCHED,
            DispatchStatus::IN_PROGRESS
        ]);
    }

    /**
     * Check if vehicle has been returned
     */
    public function isReturned(): bool
    {
        return $this->dispatch_status === DispatchStatus::RETURNED;
    }

    /**
     * Check if return is overdue
     */
    public function isOverdue(): bool
    {
        return $this->expected_return_at && 
               $this->expected_return_at->isPast() && 
               !$this->isReturned();
    }

    /**
     * Mark as dispatched
     */
    public function markDispatched(string $userId, array $dispatchData = []): void
    {
        $this->update([
            'dispatch_status' => DispatchStatus::DISPATCHED,
            'dispatched_at' => Carbon::now('UTC'),
            'dispatched_by' => $userId,
            'actual_return_at' => null,
            'returned_by' => null,
            'dispatch_notes' => $dispatchData['notes'] ?? null,
            'return_notes' => null,
            'fuel_level_out' => $dispatchData['fuel_level'] ?? null,
            'fuel_level_in' => null,
            'mileage_out' => $dispatchData['mileage'] ?? null,
            'mileage_in' => null,
            'vehicle_condition_out' => $dispatchData['condition'] ?? null,
            'vehicle_condition_in' => null,
            'damages_reported' => null,
            'additional_charges' => null,
            'late_return_fee' => 0.0,
            'agreements_signed' => $dispatchData['agreements_signed'] ?? false,
        ]);
    }

    /**
     * Mark as returned
     */
    public function markReturned(string $userId, array $returnData = []): void
    {
        $actualReturnAt = $returnData['actual_return_time'] ?? null;
        if (!empty($actualReturnAt)) {
            try {
                $actualReturnAt = Carbon::parse($actualReturnAt)->utc();
            } catch (\Throwable $exception) {
                $actualReturnAt = Carbon::now('UTC');
            }
        } else {
            $actualReturnAt = Carbon::now('UTC');
        }

        $this->update([
            'dispatch_status' => DispatchStatus::RETURNED,
            'actual_return_at' => $actualReturnAt,
            'returned_by' => $userId,
            'return_notes' => $returnData['notes'] ?? null,
            'fuel_level_in' => $returnData['fuel_level'] ?? null,
            'mileage_in' => $returnData['mileage'] ?? null,
            'vehicle_condition_in' => $returnData['condition'] ?? null,
            'damages_reported' => $returnData['damages'] ?? null,
            'additional_charges' => $returnData['charges'] ?? null,
            'late_return_fee' => isset($returnData['late_fee'])
                ? (float) $returnData['late_fee']
                : 0.0,
        ]);
    }

    /**
     * Calculate late return fee if applicable.
     * Rate and grace period are read from website_settings so they are configurable.
     */
    public function calculateLateReturnFee(): float
    {
        if (!$this->isOverdue() || $this->isReturned()) {
            return 0;
        }

        $hourlyRate   = (float) (\App\Models\Website\WebsiteSetting::getValue('late_return_fee_per_hour', 10) ?? 10);
        $gracePeriod  = (int)   (\App\Models\Website\WebsiteSetting::getValue('late_return_grace_hours', 0) ?? 0);

        $hoursLate = max(0, $this->expected_return_at->diffInHours(now()) - $gracePeriod);
        if ($hoursLate <= 0) {
            return 0;
        }

        return round($hoursLate * $hourlyRate, 2);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class, 'booking_item_id');
    }

    public function calculateLateReturnFeeAt(Carbon|string|null $returnedAt = null): float
    {
        if (!$this->expected_return_at) {
            return 0.0;
        }

        $actualReturnAt = $returnedAt ? Carbon::parse($returnedAt) : Carbon::now('UTC');
        if ($actualReturnAt->lessThanOrEqualTo($this->expected_return_at)) {
            return 0.0;
        }

        $hourlyRate = (float) (\App\Models\Website\WebsiteSetting::getValue('late_return_fee_per_hour', 10) ?? 10);
        $minuteRateSetting = \App\Models\Website\WebsiteSetting::getValue('late_return_fee_per_minute', null);
        $minuteRate = $minuteRateSetting !== null ? (float) $minuteRateSetting : $hourlyRate / 60;
        $graceMinutes = (int) (\App\Models\Website\WebsiteSetting::getValue(
            'late_return_grace_minutes',
            ((int) (\App\Models\Website\WebsiteSetting::getValue('late_return_grace_hours', 0) ?? 0)) * 60
        ) ?? 0);
        $lateMinutes = max(0, (int) ceil($this->expected_return_at->diffInSeconds($actualReturnAt) / 60) - $graceMinutes);

        return round($lateMinutes * $minuteRate, 2);
    }

    /**
     * Get dispatch summary for reports
     */
    public function getDispatchSummary(): array
    {
        return [
            'dispatch_id' => $this->id,
            'booking_id' => $this->booking_id,
            'vehicle' => $this->vehicle->license_plate ?? 'N/A',
            'driver' => $this->driver->name ?? 'Self-Drive',
            'status' => $this->dispatch_status->getDisplayName(),
            'dispatched_at' => $this->dispatched_at?->format('Y-m-d H:i'),
            'expected_return' => $this->expected_return_at?->format('Y-m-d H:i'),
            'actual_return' => $this->actual_return_at?->format('Y-m-d H:i'),
            'is_overdue' => $this->isOverdue(),
            'duration_hours' => $this->dispatched_at && $this->actual_return_at 
                ? $this->dispatched_at->diffInHours($this->actual_return_at) 
                : null,
        ];
    }
}
