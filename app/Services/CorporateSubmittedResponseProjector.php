<?php

namespace App\Services;

use App\Models\Booking\BookingItem;

class CorporateSubmittedResponseProjector
{
    private const ALLOWED_METADATA_KEYS = [
        'passenger_count',
        'luggage_count',
        'flight_number',
        'special_instructions',
        'accessibility_requirements',
        'cost_center',
        'project_code',
        'purpose',
    ];

    public function project(BookingItem $item): array
    {
        $responses = [];
        $metadata = is_array($item->metadata) ? $item->metadata : [];

        foreach (self::ALLOWED_METADATA_KEYS as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '' || !is_scalar($value)) {
                continue;
            }

            $responses[$key] = $value;
        }

        if (is_string($item->notes) && trim($item->notes) !== '') {
            $responses['notes'] = trim($item->notes);
        }

        return $responses;
    }
}
