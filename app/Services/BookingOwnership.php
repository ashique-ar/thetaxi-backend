<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BookingOwnership
{
    public function requiresScope(User $user): bool
    {
        return ! $user->can('bookings.view-all')
            && ($user->can('bookings.view-assigned') || $user->can('bookings.view-rep-team'));
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
        if ($user->can('bookings.view-all')) {
            return true;
        }

        return $this->owns($user, $booking)
            || ($user->can('bookings.view-rep-team')
                && (! $booking->responsible_user_id
                    || User::permission('bookings.view-assigned')->whereKey($booking->responsible_user_id)->exists()));
    }

    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('bookings.view-all')) {
            return $query;
        }

        if ($user->can('bookings.view-rep-team')) {
            $assignedScopeIds = User::permission('bookings.view-assigned')->select('id');

            return $query->where(fn (Builder $team) => $team
                ->where('bookings.responsible_user_id', $user->id)
                ->orWhereNull('bookings.responsible_user_id')
                ->orWhereIn('bookings.responsible_user_id', $assignedScopeIds));
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
