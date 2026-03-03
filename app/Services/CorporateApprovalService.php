<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Notifications\BookingRejectedNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CorporateApprovalService
{
    // ─── Approval Methods ─────────────────────────────────────────────

    /**
     * Approve a corporate booking.
     *
     * Verifies the booking is in pending_approval status, updates the
     * BookingApproval record, transitions the booking to confirmed,
     * and logs an audit entry.
     */
    public function approveBooking(string $bookingId, string $approverId, string $corporateId, ?string $comments = null): Booking
    {
        return DB::transaction(function () use ($bookingId, $approverId, $corporateId, $comments) {
            $booking = Booking::where('corporate_account_id', $corporateId)->findOrFail($bookingId);

            if ($booking->status !== 'pending_approval') {
                abort(422, 'Booking is not in pending approval status.');
            }

            // Update the BookingApproval record
            $approval = BookingApproval::where('booking_id', $bookingId)
                ->where('status', BookingApproval::STATUS_PENDING)
                ->latest()
                ->first();

            if ($approval) {
                $approval->approve($approverId, $comments);
            }

            // Transition booking to confirmed
            $booking->applyApprovalDecision('approved', $approverId, $comments);

            $this->logAudit('corporate_booking_approved', 'Booking', $bookingId, [
                'corporate_id' => $booking->corporate_account_id,
                'approver_id' => $approverId,
                'comments'    => $comments,
                'approved_at' => now()->toISOString(),
            ]);

            return $booking->fresh();
        });
    }

    /**
     * Reject a corporate booking.
     *
     * Verifies the booking is in pending_approval status, updates the
     * BookingApproval record, transitions the booking to rejected,
     * and logs an audit entry.
     */
    public function rejectBooking(
        string $bookingId,
        string $approverId,
        string $corporateId,
        string $reason,
        ?string $comments = null,
    ): Booking {
        return DB::transaction(function () use ($bookingId, $approverId, $corporateId, $reason, $comments) {
            $booking = Booking::where('corporate_account_id', $corporateId)->findOrFail($bookingId);

            if ($booking->status !== 'pending_approval') {
                abort(422, 'Booking is not in pending approval status.');
            }

            // Update the BookingApproval record
            $approval = BookingApproval::where('booking_id', $bookingId)
                ->where('status', BookingApproval::STATUS_PENDING)
                ->latest()
                ->first();

            $rejectionNote = $reason . ($comments ? " — {$comments}" : '');

            if ($approval) {
                $approval->reject($approverId, $rejectionNote);
            }

            // Transition booking to rejected
            $booking->applyApprovalDecision('rejected', $approverId, $rejectionNote);

            $this->logAudit('corporate_booking_rejected', 'Booking', $bookingId, [
                'corporate_id' => $booking->corporate_account_id,
                'approver_id' => $approverId,
                'reason'      => $reason,
                'comments'    => $comments,
                'rejected_at' => now()->toISOString(),
            ]);

            // Send notification to the employee who created the booking
            Notification::send($booking->employee, new BookingRejectedNotification($booking, $reason));

            return $booking->fresh();
        });
    }

    // ─── Audit Logging ────────────────────────────────────────────────

    private function logAudit(string $action, string $entity, ?string $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id'   => Auth::id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'timestamp' => now(),
            'details'   => $details,
        ]);
    }
}
