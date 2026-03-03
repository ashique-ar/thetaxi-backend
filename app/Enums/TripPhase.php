<?php

namespace App\Enums;

/**
 * Trip Phase Enum
 *
 * Defines the lifecycle phases of a driver's trip from assignment creation
 * through completion or decline.
 */
enum TripPhase: string
{
    case ACTIVE = 'active';
    case CONFIRMED = 'confirmed';
    case ACCEPTED = 'accepted';
    case PICKUP_ARRIVED = 'pickup_arrived';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case DECLINED = 'declined';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::CONFIRMED => 'Confirmed',
            self::ACCEPTED => 'Accepted',
            self::PICKUP_ARRIVED => 'Pickup Arrived',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::DECLINED => 'Declined',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE => 'blue',
            self::CONFIRMED => 'cyan',
            self::ACCEPTED => 'orange',
            self::PICKUP_ARRIVED => 'yellow',
            self::IN_PROGRESS => 'green',
            self::COMPLETED => 'gray',
            self::DECLINED => 'red',
        };
    }

    /**
     * Check if this phase represents an active trip tracking session.
     */
    public function isTracking(): bool
    {
        return in_array($this, [
            self::ACCEPTED,
            self::PICKUP_ARRIVED,
            self::IN_PROGRESS,
        ]);
    }
}
