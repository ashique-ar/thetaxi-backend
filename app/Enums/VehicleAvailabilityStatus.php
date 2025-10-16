<?php

namespace App\Enums;

/**
 * Vehicle Availability Status Enum
 * 
 * Defines all possible availability states for vehicles
 */
enum VehicleAvailabilityStatus: string
{
    case AVAILABLE = 'available';
    case ON_HIRE = 'on_hire';
    case UNAVAILABLE_QC = 'unavailable_qc';
    case UNAVAILABLE_REPAIR = 'unavailable_repair';
    case UNAVAILABLE_MAINTENANCE = 'unavailable_maintenance';
    case UNAVAILABLE_OFFLINE = 'unavailable_offline';

    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::ON_HIRE => 'On Hire',
            self::UNAVAILABLE_QC => 'Unavailable - QC',
            self::UNAVAILABLE_REPAIR => 'Unavailable - Repair',
            self::UNAVAILABLE_MAINTENANCE => 'Unavailable - Maintenance',
            self::UNAVAILABLE_OFFLINE => 'Unavailable - Offline',
        };
    }

    /**
     * Get status color
     */
    public function getColor(): string
    {
        return match ($this) {
            self::AVAILABLE => 'green',
            self::ON_HIRE => 'blue',
            self::UNAVAILABLE_QC => 'yellow',
            self::UNAVAILABLE_REPAIR => 'red',
            self::UNAVAILABLE_MAINTENANCE => 'orange',
            self::UNAVAILABLE_OFFLINE => 'gray',
        };
    }

    /**
     * Check if vehicle is available for booking
     */
    public function isAvailableForBooking(): bool
    {
        return $this === self::AVAILABLE;
    }

    /**
     * Check if vehicle is unavailable
     */
    public function isUnavailable(): bool
    {
        return !$this->isAvailableForBooking() && $this !== self::ON_HIRE;
    }
}
