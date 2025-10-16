<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;

class BookingApproval extends BaseModel
{

    protected $fillable = [
        'booking_id',
        'requested_by',
        'approver_id',
        'manager_id',
        'status',
        'priority',
        'override_reasons',
        'justification',
        'comments',
        'approved_at',
        'rejected_at',
        'auto_approved',
        'approval_level',
    ];

    protected $casts = [
        'override_reasons' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'auto_approved' => 'boolean',
        'approval_level' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ESCALATED = 'escalated';

    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * Get the booking that needs approval.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the user who requested the approval.
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who approved/rejected.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Get the manager assigned for approval.
     */
    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Scope to get pending approvals.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get approved items.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope to get rejected items.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope to get high priority approvals.
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    /**
     * Check if approval is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if approval is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if approval is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Mark as approved.
     */
    public function approve(string $approverId, string $comments = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approver_id' => $approverId,
            'comments' => $comments,
            'approved_at' => now(),
        ]);
    }

    /**
     * Mark as rejected.
     */
    public function reject(string $approverId, string $comments): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approver_id' => $approverId,
            'comments' => $comments,
            'rejected_at' => now(),
        ]);
    }
}
