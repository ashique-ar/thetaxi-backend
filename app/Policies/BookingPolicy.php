<?php

namespace App\Policies;

use App\Models\Booking\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * System admins and staff with bookings.view can see any booking.
     * Customers can only see their own bookings.
     * Corporate users can see bookings linked to their corporate account.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('bookings.view');
    }

    public function view(User $user, Booking $booking): bool
    {
        if ($user->can('bookings.view')) {
            return true;
        }

        // Customer: own bookings only
        if ($user->isCustomer()) {
            $customerContext = $user->customerContext();
            return $customerContext && $booking->customer_id === $customerContext->context_id;
        }

        // Corporate user: bookings for their corporate account
        if ($booking->is_corporate_booking && $booking->corporate_account_id) {
            return $user->contexts()
                ->where('context_type', 'corporate')
                ->where('is_active', true)
                ->whereHas('corporateEmployee', function ($q) use ($booking) {
                    $q->where('corporate_id', $booking->corporate_account_id)
                      ->where('is_active', true);
                })
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->can('bookings.create');
    }

    public function update(User $user, Booking $booking): bool
    {
        if ($user->can('bookings.edit')) {
            return true;
        }

        // Corporate admin: can edit their corporate bookings
        if ($booking->is_corporate_booking && $booking->corporate_account_id) {
            return $user->contexts()
                ->where('context_type', 'corporate')
                ->where('is_active', true)
                ->whereHas('corporateEmployee', function ($q) use ($booking) {
                    $q->where('corporate_id', $booking->corporate_account_id)
                      ->where('is_active', true);
                })
                ->whereHas('roles', fn($q) => $q->where('name', 'Corporate_Master_Admin'))
                ->exists();
        }

        return false;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->can('bookings.delete');
    }
}
