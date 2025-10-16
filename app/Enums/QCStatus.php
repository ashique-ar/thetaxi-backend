<?php

namespace App\Enums;

/**
 * QC Status Enum
 * 
 * Defines Quality Control statuses for returned vehicles
 */
enum QCStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case ISSUES_FOUND = 'issues_found';
    case REPAIR_REQUIRED = 'repair_required';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::PENDING => 'QC Pending',
            self::IN_PROGRESS => 'QC In Progress',
            self::COMPLETED => 'QC Completed',
            self::ISSUES_FOUND => 'Issues Found',
            self::REPAIR_REQUIRED => 'Repair Required',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::IN_PROGRESS => 'blue',
            self::COMPLETED => 'green',
            self::ISSUES_FOUND => 'orange',
            self::REPAIR_REQUIRED => 'red',
        };
    }
}
