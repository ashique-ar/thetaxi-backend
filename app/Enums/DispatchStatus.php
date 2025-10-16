<?php

namespace App\Enums;

/**
 * Dispatch Status Enum
 * 
 * Defines dispatch-specific statuses for bookings
 */
enum DispatchStatus: string
{
    case NOT_DISPATCHED = 'not_dispatched';
    case READY_FOR_DISPATCH = 'ready_for_dispatch';
    case DISPATCHED = 'dispatched';
    case IN_PROGRESS = 'in_progress';
    case RETURNED = 'returned';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::NOT_DISPATCHED => 'Not Dispatched',
            self::READY_FOR_DISPATCH => 'Ready for Dispatch',
            self::DISPATCHED => 'Dispatched',
            self::IN_PROGRESS => 'In Progress',
            self::RETURNED => 'Returned',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NOT_DISPATCHED => 'gray',
            self::READY_FOR_DISPATCH => 'orange',
            self::DISPATCHED => 'blue',
            self::IN_PROGRESS => 'green',
            self::RETURNED => 'purple',
        };
    }
}
