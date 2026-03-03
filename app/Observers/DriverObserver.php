<?php

namespace App\Observers;

use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;

/**
 * Observer for Driver model.
 *
 * When a Driver is deleted (soft or hard), nullify default_driver_id
 * on all Vehicles that reference this driver.
 *
 * @see Requirements 11.7, 11.8
 */
class DriverObserver
{
    /**
     * Handle the Driver "deleting" event.
     */
    public function deleting(Driver $driver): void
    {
        Vehicle::where('default_driver_id', $driver->id)
            ->update(['default_driver_id' => null]);
    }

    /**
     * Handle the Driver "updated" event.
     *
     * If the driver is deactivated (user.is_active set to false),
     * clear default_driver_id on referencing vehicles.
     */
    public function updated(Driver $driver): void
    {
        // Check if the related user was deactivated
        if ($driver->user && $driver->user->is_active === false) {
            Vehicle::where('default_driver_id', $driver->id)
                ->update(['default_driver_id' => null]);
        }
    }
}
