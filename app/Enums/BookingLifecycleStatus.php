<?php

namespace App\Enums;

/**
 * Booking Lifecycle Status Enum
 * 
 * Defines all possible statuses in the booking lifecycle from inquiry to completion
 * Based on the business operation flow chart
 */
enum BookingLifecycleStatus: string
{
    // Inquiry Stage
    case INQUIRY = 'inquiry';
    case INQUIRY_QUALIFIED = 'inquiry_qualified';
    case INQUIRY_CANCELLED = 'inquiry_cancelled';
    
    // Booking Stage
    case BOOKING_PENDING = 'booking_pending';
    case BOOKING_CONFIRMED = 'booking_confirmed';
    case BOOKING_CANCELLED = 'booking_cancelled';
    case BOOKING_REQUIRES_APPROVAL = 'booking_requires_approval';
    case BOOKING_APPROVED = 'booking_approved';
    case BOOKING_REJECTED = 'booking_rejected';
    
    // Allocation & Dispatch Stage
    case ALLOCATION_PENDING = 'allocation_pending';
    case ALLOCATION_ASSIGNED = 'allocation_assigned';
    case ALLOCATION_CONFLICTS = 'allocation_conflicts';
    case ALLOCATION_APPROVED = 'allocation_approved';
    case DISPATCH_READY = 'dispatch_ready';
    case DISPATCH_OUT = 'dispatch_out';
    
    // Ongoing Stage
    case ONGOING_ACTIVE = 'ongoing_active';
    case ONGOING_REPLACEMENT_NEEDED = 'ongoing_replacement_needed';
    case ONGOING_BREAKDOWN = 'ongoing_breakdown';
    
    // Return Stage
    case RETURN_SCHEDULED = 'return_scheduled';
    case RETURN_OVERDUE = 'return_overdue';
    case RETURN_COMPLETED = 'return_completed';
    case RETURN_LATE = 'return_late';
    
    // QC & Repair Stage
    case QC_PENDING = 'qc_pending';
    case QC_IN_PROGRESS = 'qc_in_progress';
    case QC_ISSUES_FOUND = 'qc_issues_found';
    case QC_REPAIR_NEEDED = 'qc_repair_needed';
    case QC_COMPLETED = 'qc_completed';
    
    // Final Stage
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Get the stage group for this status
     */
    public function getStage(): string
    {
        return match ($this) {
            self::INQUIRY, self::INQUIRY_QUALIFIED, self::INQUIRY_CANCELLED => 'inquiry',
            self::BOOKING_PENDING, self::BOOKING_CONFIRMED, self::BOOKING_CANCELLED, 
            self::BOOKING_REQUIRES_APPROVAL, self::BOOKING_APPROVED, self::BOOKING_REJECTED => 'booking',
            self::ALLOCATION_PENDING, self::ALLOCATION_ASSIGNED, self::ALLOCATION_CONFLICTS, 
            self::ALLOCATION_APPROVED, self::DISPATCH_READY, self::DISPATCH_OUT => 'allocation_dispatch',
            self::ONGOING_ACTIVE, self::ONGOING_REPLACEMENT_NEEDED, self::ONGOING_BREAKDOWN => 'ongoing',
            self::RETURN_SCHEDULED, self::RETURN_OVERDUE, self::RETURN_COMPLETED, self::RETURN_LATE => 'return',
            self::QC_PENDING, self::QC_IN_PROGRESS, self::QC_ISSUES_FOUND, 
            self::QC_REPAIR_NEEDED, self::QC_COMPLETED => 'qc_repair',
            self::COMPLETED, self::CANCELLED => 'final',
        };
    }

    /**
     * Get the display name for this status
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::INQUIRY => 'New Inquiry',
            self::INQUIRY_QUALIFIED => 'Qualified Inquiry',
            self::INQUIRY_CANCELLED => 'Cancelled Inquiry',
            self::BOOKING_PENDING => 'Pending Booking',
            self::BOOKING_CONFIRMED => 'Confirmed Booking',
            self::BOOKING_CANCELLED => 'Cancelled Booking',
            self::BOOKING_REQUIRES_APPROVAL => 'Requires Approval',
            self::BOOKING_APPROVED => 'Approved Booking',
            self::BOOKING_REJECTED => 'Rejected Booking',
            self::ALLOCATION_PENDING => 'Allocation Pending',
            self::ALLOCATION_ASSIGNED => 'Vehicle & Driver Assigned',
            self::ALLOCATION_CONFLICTS => 'Allocation Conflicts',
            self::ALLOCATION_APPROVED => 'Allocation Approved',
            self::DISPATCH_READY => 'Ready for Dispatch',
            self::DISPATCH_OUT => 'Dispatched',
            self::ONGOING_ACTIVE => 'Active Hire',
            self::ONGOING_REPLACEMENT_NEEDED => 'Replacement Needed',
            self::ONGOING_BREAKDOWN => 'Breakdown Reported',
            self::RETURN_SCHEDULED => 'Return Scheduled',
            self::RETURN_OVERDUE => 'Return Overdue',
            self::RETURN_COMPLETED => 'Returned',
            self::RETURN_LATE => 'Late Return',
            self::QC_PENDING => 'QC Pending',
            self::QC_IN_PROGRESS => 'QC In Progress',
            self::QC_ISSUES_FOUND => 'QC Issues Found',
            self::QC_REPAIR_NEEDED => 'Repair Needed',
            self::QC_COMPLETED => 'QC Completed',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Get the next possible statuses from current status
     */
    public function getNextStatuses(): array
    {
        return match ($this) {
            self::INQUIRY => [self::INQUIRY_QUALIFIED, self::INQUIRY_CANCELLED],
            self::INQUIRY_QUALIFIED => [self::BOOKING_PENDING, self::BOOKING_CANCELLED],
            self::BOOKING_PENDING => [self::BOOKING_CONFIRMED, self::BOOKING_REQUIRES_APPROVAL, self::BOOKING_CANCELLED],
            self::BOOKING_REQUIRES_APPROVAL => [self::BOOKING_APPROVED, self::BOOKING_REJECTED],
            self::BOOKING_APPROVED => [self::BOOKING_CONFIRMED],
            self::BOOKING_CONFIRMED => [self::ALLOCATION_PENDING, self::BOOKING_CANCELLED],
            self::ALLOCATION_PENDING => [self::ALLOCATION_ASSIGNED, self::ALLOCATION_CONFLICTS],
            self::ALLOCATION_CONFLICTS => [self::ALLOCATION_ASSIGNED, self::ALLOCATION_APPROVED],
            self::ALLOCATION_ASSIGNED => [self::ALLOCATION_APPROVED, self::DISPATCH_READY],
            self::ALLOCATION_APPROVED => [self::DISPATCH_READY],
            self::DISPATCH_READY => [self::DISPATCH_OUT],
            self::DISPATCH_OUT => [self::ONGOING_ACTIVE],
            self::ONGOING_ACTIVE => [self::ONGOING_REPLACEMENT_NEEDED, self::ONGOING_BREAKDOWN, self::RETURN_SCHEDULED],
            self::ONGOING_REPLACEMENT_NEEDED => [self::ONGOING_ACTIVE],
            self::ONGOING_BREAKDOWN => [self::ONGOING_ACTIVE, self::RETURN_SCHEDULED],
            self::RETURN_SCHEDULED => [self::RETURN_COMPLETED, self::RETURN_OVERDUE],
            self::RETURN_OVERDUE => [self::RETURN_LATE],
            self::RETURN_COMPLETED, self::RETURN_LATE => [self::QC_PENDING],
            self::QC_PENDING => [self::QC_IN_PROGRESS],
            self::QC_IN_PROGRESS => [self::QC_COMPLETED, self::QC_ISSUES_FOUND],
            self::QC_ISSUES_FOUND => [self::QC_REPAIR_NEEDED, self::QC_COMPLETED],
            self::QC_REPAIR_NEEDED => [self::QC_COMPLETED],
            self::QC_COMPLETED => [self::COMPLETED],
            default => [],
        };
    }

    /**
     * Check if status can transition to another status
     */
    public function canTransitionTo(BookingLifecycleStatus $status): bool
    {
        return in_array($status, $this->getNextStatuses());
    }

    /**
     * Get the primary action for this status
     */
    public function getPrimaryAction(): ?string
    {
        return match ($this) {
            self::INQUIRY => 'Qualify Inquiry',
            self::INQUIRY_QUALIFIED => 'Convert to Booking',
            self::BOOKING_PENDING => 'Confirm Booking',
            self::BOOKING_REQUIRES_APPROVAL => 'Request Approval',
            self::BOOKING_APPROVED => 'Confirm Booking',
            self::BOOKING_CONFIRMED => 'Allocate & Dispatch',
            self::ALLOCATION_PENDING => 'Assign Vehicle & Driver',
            self::ALLOCATION_CONFLICTS => 'Resolve Conflicts',
            self::ALLOCATION_ASSIGNED => 'Approve Assignment',
            self::ALLOCATION_APPROVED => 'Prepare Dispatch',
            self::DISPATCH_READY => 'Dispatch Vehicle',
            self::DISPATCH_OUT => 'Monitor Hire',
            self::ONGOING_ACTIVE => 'Monitor or Schedule Return',
            self::ONGOING_REPLACEMENT_NEEDED => 'Process Replacement',
            self::ONGOING_BREAKDOWN => 'Resolve Breakdown',
            self::RETURN_SCHEDULED => 'Process Return',
            self::RETURN_OVERDUE => 'Contact Customer',
            self::RETURN_COMPLETED, self::RETURN_LATE => 'Start QC',
            self::QC_PENDING => 'Begin Inspection',
            self::QC_IN_PROGRESS => 'Complete Inspection',
            self::QC_ISSUES_FOUND => 'Schedule Repair',
            self::QC_REPAIR_NEEDED => 'Complete Repair',
            self::QC_COMPLETED => 'Mark Complete',
            default => null,
        };
    }

    /**
     * Get status color for UI
     */
    public function getColor(): string
    {
        return match ($this->getStage()) {
            'inquiry' => 'blue',
            'booking' => 'green',
            'allocation_dispatch' => 'orange',
            'ongoing' => 'purple',
            'return' => 'indigo',
            'qc_repair' => 'red',
            'final' => 'gray',
        };
    }
}
