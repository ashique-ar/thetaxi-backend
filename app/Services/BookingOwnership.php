<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BookingOwnership
{
    public function isScopedRole(User $user): bool
    {
        return $user->hasRole('rep-marketing') || $user->hasRole('rep-marketing-admin');
    }

    private function isRepMarketingRep(User $user): bool
    {
        return $user->hasRole('rep-marketing') && ! $user->hasRole('rep-marketing-admin');
    }

    public function owns(User $user, Booking $booking): bool
    {
        if ((string) $booking->responsible_user_id === (string) $user->id) {
            return true;
        }

        return ! $booking->responsible_user_id
            && ((string) $booking->created_by_user_id === (string) $user->id
                || (string) $booking->created_user_id === (string) $user->id);
    }

    public function canAccess(User $user, Booking $booking): bool
    {
        if ($this->isRepMarketingRep($user)) {
            return $this->owns($user, $booking);
        }

        return $this->owns($user, $booking)
            || ($user->can('bookings.view-rep-team')
                && (! $booking->responsible_user_id
                    || User::role('rep-marketing')->whereKey($booking->responsible_user_id)->exists()));
    }

    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('bookings.view-rep-team') && ! $this->isRepMarketingRep($user)) {
            $repIds = User::role('rep-marketing')->select('id');

            return $query->where(fn (Builder $team) => $team
                ->where('bookings.responsible_user_id', $user->id)
                ->orWhereNull('bookings.responsible_user_id')
                ->orWhereIn('bookings.responsible_user_id', $repIds));
        }

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
