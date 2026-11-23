<?php

namespace App\Observers;

use App\Events\BookingApprovalStatusUpdated;
use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BookingApprovalNotificationObserver
{
    public function updated(Booking $booking): void
    {
        if (!$booking->wasChanged(['status', 'requires_approval', 'approval_status', 'corporate_approval_status'])) {
            return;
        }

        $userIds = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('data->type', ['booking_approval_requested', 'corporate_booking_approval_requested'])
            ->where('data->data->booking_id', (string) $booking->id)
            ->pluck('notifiable_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) return;

        DB::afterCommit(fn () => $userIds->each(
            fn ($userId) => event(new BookingApprovalStatusUpdated((string) $userId, (string) $booking->id))
        ));
    }
}
