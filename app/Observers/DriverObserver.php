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
     * Handle the Driver "creating" event.
     * Auto-generate driver code and license number if not provided.
     */
    public function creating(Driver $driver): void
    {
        // Auto-generate driver code if not provided
        if (empty($driver->code)) {
            $driver->code = $this->generateUniqueCode();
        }

        // Auto-generate license number if not provided
        if (empty($driver->license_no)) {
            $driver->license_no = $this->generateUniqueLicenseNo();
        }
    }

    /**
     * Generate a unique driver code.
     */
    private function generateUniqueCode(): string
    {
        $prefix = 'DRV';
        $timestamp = substr(strval(time()), -6);
        $random = strtoupper(substr(uniqid(), -4));
        $code = $prefix . $timestamp . $random;

        // Ensure uniqueness
        $counter = 0;
        $originalCode = $code;
        while (Driver::where('code', $code)->exists()) {
            $counter++;
            $code = $originalCode . $counter;
        }

        return $code;
    }

    /**
     * Generate a unique license number.
     */
    private function generateUniqueLicenseNo(): string
    {
        $prefix = 'LIC';
        $timestamp = substr(strval(time()), -5);
        $random = strtoupper(substr(uniqid(), -5));
        $licenseNo = $prefix . '-' . $timestamp . '-' . $random;

        // Ensure uniqueness
        $counter = 0;
        $originalLicense = $licenseNo;
        while (Driver::where('license_no', $licenseNo)->exists()) {
            $counter++;
            $licenseNo = $originalLicense . $counter;
        }

        return $licenseNo;
    }

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
