<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BookingOwnership
{
    public function owns(User $user, Booking $booking): bool
    {
        if ((string) $booking->responsible_user_id === (string) $user->id) {
            return true;
        }

        return ! $booking->responsible_user_id
            && ((string) $booking->created_by_user_id === (string) $user->id
                || (string) $booking->created_user_id === (string) $user->id);
    }

    public function scope(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $owner) use ($user) {
            $owner->where('bookings.responsible_user_id', $user->id)
                ->orWhere(function (Builder $unassigned) use ($user) {
                    $unassigned->whereNull('bookings.responsible_user_id')
                        ->where(function (Builder $creator) use ($user) {
                            $creator->where('bookings.created_by_user_id', $user->id)
                                ->orWhere('bookings.created_user_id', $user->id);
                        });
                });
        });
    }
}
