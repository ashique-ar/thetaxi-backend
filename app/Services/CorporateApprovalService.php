<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CorporateApprovalService
{
    public function __construct(private readonly CorporateBookingNotificationService $corporateBookingNotifications)
    {
    }

    // ─── Approval Methods ─────────────────────────────────────────────

    /**
     * Approve a corporate booking.
     *
     * Completes the corporate approval gate without consuming staff approval.
     */
    public function approveBooking(string $bookingId, string $approverId, string $corporateId, ?string $comments = null): Booking
    {
        return DB::transaction(function () use ($bookingId, $approverId, $corporateId, $comments) {
            $booking = Booking::where('corporate_account_id', $corporateId)->lockForUpdate()->findOrFail($bookingId);

            if ($booking->corporate_approval_status !== 'pending') {
                abort(422, 'Booking is not in pending approval status.');
            }

            $legacyApproval = BookingApproval::query()->where('booking_id', $bookingId)
                ->where('status', BookingApproval::STATUS_PENDING)->lockForUpdate()->latest()->first();
            $legacyConditions = is_array($legacyApproval?->conditions) ? $legacyApproval->conditions : [];
            $history = is_array($booking->corporate_approval_history) && $booking->corporate_approval_history
                ? $booking->corporate_approval_history
                : (is_array($legacyConditions['step_decisions'] ?? null) ? $legacyConditions['step_decisions'] : []);
            abort_if(collect($history)->contains(fn ($decision) => (string) ($decision['user_id'] ?? '') === $approverId), 409, 'Each approval step requires a different approver.');
            $requiredSteps = max(1, min(3, (int) ($legacyConditions['required_steps'] ?? app(WebsiteSettingsService::class)->get('booking_approval_steps', 1))));
            $history[] = [
                'step' => count($history) + 1,
                'user_id' => $approverId,
                'comments' => $comments,
                'decided_at' => now()->toISOString(),
            ];
            $booking->corporate_approval_history = $history;
            $booking->corporate_approval_step = count($history);

            if (count($history) < $requiredSteps) {
                if ($legacyApproval) {
                    $legacyApproval->conditions = array_merge($legacyConditions, ['required_steps' => $requiredSteps, 'step_decisions' => $history]);
                    $legacyApproval->save();
                }
                $booking->save();
                $this->logAudit('corporate_booking_approval_step_completed', 'Booking', $bookingId, [
                    'corporate_id' => $booking->corporate_account_id,
                    'approver_id' => $approverId,
                    'completed_step' => count($history),
                    'required_steps' => $requiredSteps,
                ]);
                return $booking->fresh();
            }

            $legacyApproval?->approve($approverId, $comments);

            $siblings = $booking->recurring_series_id
                ? Booking::query()->where('recurring_series_id', $booking->recurring_series_id)->where('id', '!=', $booking->id)->get()
                : collect();
            $booking->forceFill([
                'corporate_approval_status' => 'approved',
                'corporate_approval_by' => $approverId,
                'corporate_approval_at' => now(),
                'corporate_approval_note' => $comments,
                'updated_user_id' => $approverId,
            ])->save();
            $siblings->each(fn (Booking $sibling) => $sibling->forceFill([
                'corporate_approval_status' => 'approved',
                'corporate_approval_by' => $approverId,
                'corporate_approval_at' => now(),
                'corporate_approval_note' => $comments,
                'corporate_approval_step' => count($history),
                'corporate_approval_history' => $history,
            ])->save());
            $siblings->each(function (Booking $sibling) use ($approverId, $comments): void {
                BookingApproval::query()->where('booking_id', $sibling->id)
                    ->where('status', BookingApproval::STATUS_PENDING)->latest()->first()
                    ?->approve($approverId, $comments);
            });
            app(CorporateBookingService::class)->startInternalStaffApproval($booking, $approverId, $siblings);

            $this->logAudit('corporate_booking_approved', 'Booking', $bookingId, [
                'corporate_id' => $booking->corporate_account_id,
                'approver_id' => $approverId,
                'comments'    => $comments,
                'approved_at' => now()->toISOString(),
            ]);

            $this->corporateBookingNotifications->notifyPortalParticipants($booking, 'approved');

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
            $booking = Booking::where('corporate_account_id', $corporateId)->lockForUpdate()->findOrFail($bookingId);

            if ($booking->corporate_approval_status !== 'pending') {
                abort(422, 'Booking is not in pending approval status.');
            }

            $rejectionNote = implode(' — ', array_filter([trim($reason), trim((string) $comments)]));
            BookingApproval::query()->where('booking_id', $bookingId)
                ->where('status', BookingApproval::STATUS_PENDING)->latest()->first()
                ?->reject($approverId, $rejectionNote);

            $history = is_array($booking->corporate_approval_history) ? $booking->corporate_approval_history : [];
            $history[] = ['step' => count($history) + 1, 'user_id' => $approverId, 'comments' => $rejectionNote, 'decided_at' => now()->toISOString(), 'decision' => 'rejected'];

            $booking->forceFill([
                'corporate_approval_status' => 'rejected',
                'corporate_approval_by' => $approverId,
                'corporate_approval_at' => now(),
                'corporate_approval_note' => $rejectionNote,
                'corporate_approval_step' => count($history),
                'corporate_approval_history' => $history,
                'status' => 'rejected',
                'confirmed' => false,
                'confirmed_at' => null,
                'requires_approval' => false,
                'approval_status' => 'not_required',
                'workflow_step' => 'rejected',
                'updated_user_id' => $approverId,
            ])->save();
            if ($booking->recurring_series_id) {
                $siblings = Booking::query()->where('recurring_series_id', $booking->recurring_series_id)
                    ->where('id', '!=', $booking->id)->get();
                $siblings->each(fn (Booking $sibling) => $sibling->forceFill([
                        'corporate_approval_status' => 'rejected',
                        'corporate_approval_by' => $approverId,
                        'corporate_approval_at' => now(),
                        'corporate_approval_note' => $rejectionNote,
                        'corporate_approval_step' => count($history),
                        'corporate_approval_history' => $history,
                        'status' => 'rejected',
                        'confirmed' => false,
                        'confirmed_at' => null,
                        'requires_approval' => false,
                        'approval_status' => 'not_required',
                        'workflow_step' => 'rejected',
                        'updated_user_id' => $approverId,
                    ])->save());
                $siblings->each(function (Booking $sibling) use ($approverId, $rejectionNote): void {
                    BookingApproval::query()->where('booking_id', $sibling->id)
                        ->where('status', BookingApproval::STATUS_PENDING)->latest()->first()
                        ?->reject($approverId, $rejectionNote);
                });
            }

            $this->logAudit('corporate_booking_rejected', 'Booking', $bookingId, [
                'corporate_id' => $booking->corporate_account_id,
                'approver_id' => $approverId,
                'reason'      => $reason,
                'comments'    => $comments,
                'rejected_at' => now()->toISOString(),
            ]);

            $this->corporateBookingNotifications->notifyPortalParticipants($booking, 'rejected', $reason);

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
