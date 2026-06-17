<?php

namespace App\Policies;

use App\Models\Driver\Driver;
use App\Models\User;

class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('drivers.view');
    }

    public function view(User $user, Driver $driver): bool
    {
        if ($user->can('drivers.view')) {
            return true;
        }

        // A driver can view their own profile
        $driverContext = $user->contexts()
            ->where('context_type', 'driver')
            ->where('is_active', true)
            ->first();

        return $driverContext && $driverContext->context_id === $driver->id;
    }

    public function create(User $user): bool
    {
        return $user->can('drivers.create');
    }

    public function update(User $user, Driver $driver): bool
    {
        if ($user->can('drivers.edit')) {
            return true;
        }

        // Driver can update their own profile
        $driverContext = $user->contexts()
            ->where('context_type', 'driver')
            ->where('is_active', true)
            ->first();

        return $driverContext && $driverContext->context_id === $driver->id;
    }

    public function delete(User $user, Driver $driver): bool
    {
        return $user->can('drivers.delete');
    }
}
