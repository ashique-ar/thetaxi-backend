<?php

namespace App\Observers;

use App\Events\BookingApprovalStatusUpdated;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BookingApprovalNotificationObserver
{
    public function updated(Booking|BookingApproval $model): void
    {
        if ($model instanceof Booking && !$model->wasChanged(['status', 'requires_approval', 'approval_status', 'corporate_approval_status'])) {
            return;
        }
        if ($model instanceof BookingApproval && !$model->wasChanged('status')) return;

        $bookingId = (string) ($model instanceof Booking ? $model->id : $model->booking_id);

        $userIds = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('data->type', ['booking_approval_requested', 'corporate_booking_approval_requested'])
            ->where('data->data->booking_id', $bookingId)
            ->pluck('notifiable_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) return;

        DB::afterCommit(fn () => $userIds->each(
            fn ($userId) => event(new BookingApprovalStatusUpdated((string) $userId, $bookingId))
        ));
    }
}
