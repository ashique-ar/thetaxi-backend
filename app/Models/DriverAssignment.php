<?php

namespace App\Models;

use App\Enums\TripPhase;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Driver\Driver;
use App\Models\Driver\RoutePoint;
use App\Models\DriverAssignmentStop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverAssignment extends BaseModel
{

    protected $fillable = [
        'driver_id',
        'booking_id',
        'parent_assignment_id',
        'customer_name',
        'service_type',
        'assigned_from',
        'assigned_to',
        'assignment_type',
        'status',
        'overlap_type',
        'overlap_details',
        'requires_approval',
        'approved_by',
        'approved_at',
        'approval_notes',
        'override_reasons',
        'manually_confirmed',
        'confirmed_by',
        'confirmed_at',
        'confirmation_method',
        'confirmation_notes',
        'assigned_by',
        'actual_start',
        'actual_end',
        'assignment_notes',
        'hourly_rate',
        'overtime_applicable',
        'special_requirements',
        'booking_item_id',
        'trip_phase',
        'trip_started_at',
        'trip_completed_at',
        'pickup_arrived_at',
        'pickup_arrival_latitude',
        'pickup_arrival_longitude',
        'final_latitude',
        'final_longitude',
        'total_distance_km',
        'total_waiting_time_seconds',
        'decline_reason',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'trip_phase' => TripPhase::class,
        'trip_started_at' => 'datetime',
        'trip_completed_at' => 'datetime',
        'pickup_arrived_at' => 'datetime',
        'pickup_arrival_latitude' => 'decimal:8',
        'pickup_arrival_longitude' => 'decimal:8',
        'final_latitude' => 'decimal:8',
        'final_longitude' => 'decimal:8',
        'total_distance_km' => 'decimal:2',
        'total_waiting_time_seconds' => 'integer',
        'assigned_from' => 'datetime',
        'assigned_to' => 'datetime',
        'approved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'overlap_details' => 'array',
        'override_reasons' => 'array',
        'special_requirements' => 'array',
        'manually_confirmed' => 'boolean',
        'requires_approval' => 'boolean',
        'overtime_applicable' => 'boolean',
        'hourly_rate' => 'decimal:2',
    ];

    /**
     * Driver this assignment belongs to
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Booking this assignment is for
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Parent assignment (for concurrent assignments)
     */
    public function parentAssignment(): BelongsTo
    {
        return $this->belongsTo(DriverAssignment::class, 'parent_assignment_id');
    }

    /**
     * Child assignments (concurrent assignments)
     */
    public function childAssignments(): HasMany
    {
        return $this->hasMany(DriverAssignment::class, 'parent_assignment_id');
    }

    /**
     * User who approved this assignment
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * User who confirmed this assignment
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * User who assigned the driver
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Booking item this assignment is linked to
     */
    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    /**
     * Waiting time records for this assignment
     */
    public function waitingTimeRecords(): HasMany
    {
        return $this->hasMany(WaitingTimeRecord::class, 'assignment_id');
    }

    /**
     * Route points recorded during this assignment's trip
     */
    public function routePoints(): HasMany
    {
        return $this->hasMany(RoutePoint::class, 'assignment_id');
    }

    /**
     * Ordered pickup/dropoff progress stops for this assignment.
     */
    public function stops(): HasMany
    {
        return $this->hasMany(DriverAssignmentStop::class, 'assignment_id')
            ->orderBy('route_order');
    }

    /**
     * User who created this record
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * User who last updated this record
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scope for active assignments
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for pending approval assignments
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', 'pending_approval');
    }

    /**
     * Scope for assignments requiring manual confirmation
     */
    public function scopeRequiringConfirmation($query)
    {
        return $query->where('manually_confirmed', false)
                    ->whereIn('status', ['active', 'pending_approval']);
    }

    /**
     * Scope for concurrent assignments
     */
    public function scopeConcurrent($query)
    {
        return $query->where('assignment_type', 'concurrent');
    }

    /**
     * Scope for assignments within date range
     */
    public function scopeWithinDateRange($query, $from, $to)
    {
        return $query->where(function ($q) use ($from, $to) {
            $q->whereBetween('assigned_from', [$from, $to])
              ->orWhereBetween('assigned_to', [$from, $to])
              ->orWhere(function ($q2) use ($from, $to) {
                  $q2->where('assigned_from', '<=', $from)
                     ->where('assigned_to', '>=', $to);
              });
        });
    }

    /**
     * Check if assignment overlaps with given period
     */
    public function overlapsWithPeriod($from, $to): bool
    {
        return !($this->assigned_to < $from || $this->assigned_from > $to);
    }

    /**
     * Get duration in hours
     */
    public function getDurationInHours(): float
    {
        return $this->assigned_from->diffInHours($this->assigned_to);
    }

    /**
     * Check if assignment is currently active
     */
    public function isCurrentlyActive(): bool
    {
        $now = now();
        return $this->status === 'active' && 
               $this->assigned_from <= $now && 
               $this->assigned_to >= $now;
    }

    /**
     * Check if assignment requires approval
     */
    public function needsApproval(): bool
    {
        return $this->requires_approval && !$this->approved_at;
    }

    /**
     * Check if assignment needs manual confirmation
     */
    public function needsConfirmation(): bool
    {
        return !$this->manually_confirmed && 
               in_array($this->status, ['active', 'pending_approval']);
    }

    /**
     * Calculate total cost for this assignment
     */
    public function calculateCost(): float
    {
        if (!$this->hourly_rate) {
            return 0;
        }

        $hours = $this->getDurationInHours();
        $regularHours = min($hours, 8); // Assume 8 hours is regular
        $overtimeHours = max(0, $hours - 8);

        $cost = $regularHours * $this->hourly_rate;
        
        if ($this->overtime_applicable && $overtimeHours > 0) {
            $cost += $overtimeHours * ($this->hourly_rate * 1.5); // 1.5x for overtime
        }

        return $cost;
    }
}
