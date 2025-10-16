<?php

namespace App\Models\Vehicle;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleAssignment extends BaseModel
{

    protected $fillable = [
        'vehicle_id',
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
        'maintenance_window',
        'fuel_level',
        'mileage_start',
        'mileage_end',
        'special_requirements',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
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
        'fuel_level' => 'decimal:2',
        'mileage_start' => 'integer',
        'mileage_end' => 'integer',
    ];

    /**
     * Vehicle this assignment belongs to
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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
        return $this->belongsTo(VehicleAssignment::class, 'parent_assignment_id');
    }

    /**
     * Child assignments (concurrent assignments)
     */
    public function childAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class, 'parent_assignment_id');
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
     * User who assigned the vehicle
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
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
}
