<?php

namespace App\Policies;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;

class CorporatePolicy
{
    /**
     * Determine if the user can view any corporates.
     * System admin (has 'corporates.view' permission) or user with corporate context.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('corporates.view') || $this->hasCorporateContext($user);
    }

    /**
     * Determine if the user can view a specific corporate.
     * System admin or user belongs to this corporate.
     */
    public function view(User $user, Corporate $corporate): bool
    {
        return $user->can('corporates.view') || $this->belongsToCorporate($user, $corporate);
    }

    /**
     * Determine if the user can create corporates.
     * System admin only.
     */
    public function create(User $user): bool
    {
        return $user->can('corporates.create');
    }

    /**
     * Determine if the user can update a corporate.
     * System admin only.
     */
    public function update(User $user, Corporate $corporate): bool
    {
        return $user->can('corporates.edit');
    }

    /**
     * Determine if the user can manage departments in this corporate.
     * User has Corporate_Master_Admin role in this corporate.
     */
    public function manageDepartments(User $user, Corporate $corporate): bool
    {
        return $this->hasRoleInCorporate($user, $corporate, 'Corporate_Master_Admin');
    }

    /**
     * Determine if the user can manage employees in this corporate.
     * User has 'manage_employees' permission in this corporate.
     */
    public function manageEmployees(User $user, Corporate $corporate): bool
    {
        return $this->hasPermissionInCorporate($user, $corporate, 'manage_employees');
    }

    /**
     * Determine if the user can approve bookings in this corporate.
     * User has 'approve_bookings' permission in this corporate.
     */
    public function approveBookings(User $user, Corporate $corporate): bool
    {
        return $this->hasPermissionInCorporate($user, $corporate, 'approve_bookings');
    }

    /**
     * Determine if the user can view bookings in this corporate.
     * Any user in this corporate.
     */
    public function viewBookings(User $user, Corporate $corporate): bool
    {
        return $this->belongsToCorporate($user, $corporate);
    }

    /**
     * Determine if the user can view all bookings in this corporate.
     * User has 'view_all_bookings' permission in this corporate.
     */
    public function viewAllBookings(User $user, Corporate $corporate): bool
    {
        return $this->hasPermissionInCorporate($user, $corporate, 'view_all_bookings');
    }

    /**
     * Determine if the user can view payments in this corporate.
     * User has 'view_payments' permission in this corporate.
     */
    public function viewPayments(User $user, Corporate $corporate): bool
    {
        return $this->hasPermissionInCorporate($user, $corporate, 'view_payments');
    }

    /**
     * Check if a user belongs to a specific corporate via UserContext.
     */
    protected function belongsToCorporate(User $user, Corporate $corporate): bool
    {
        return $user->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->whereHas('corporateEmployee', function ($query) use ($corporate) {
                $query->where('corporate_id', $corporate->id);
            })
            ->exists();
    }

    /**
     * Check if the user has any active corporate context.
     */
    protected function hasCorporateContext(User $user): bool
    {
        return $user->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Check if the user has a specific role in a corporate via UserContext roles.
     */
    protected function hasRoleInCorporate(User $user, Corporate $corporate, string $roleName): bool
    {
        return $user->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->whereHas('corporateEmployee', function ($query) use ($corporate) {
                $query->where('corporate_id', $corporate->id)
                    ->where('is_active', true);
            })
            ->whereHas('roles', function ($query) use ($roleName) {
                $query->where('name', $roleName);
            })
            ->exists();
    }

    /**
     * Check if the user has a specific permission in a corporate via UserContext roles.
     */
    protected function hasPermissionInCorporate(User $user, Corporate $corporate, string $permission): bool
    {
        return $user->contexts()
            ->where('context_type', 'corporate')
            ->where('is_active', true)
            ->whereHas('corporateEmployee', function ($query) use ($corporate) {
                $query->where('corporate_id', $corporate->id)
                    ->where('is_active', true);
            })
            ->whereHas('roles.permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }
}
