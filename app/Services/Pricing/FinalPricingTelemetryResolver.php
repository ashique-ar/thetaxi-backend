<?php

namespace App\Services\Pricing;

/**
 * Selects one authoritative operational source for final pricing.
 *
 * Lower-precedence sources never fill gaps in a selected higher-precedence
 * source. Only the already-persisted booking fallback may fill a missing field;
 * this prevents customer telemetry from overriding driver/system/return data.
 */
class FinalPricingTelemetryResolver
{
    public const SOURCE_PRECEDENCE = [
        'driver_mobile_activity',
        'system_activity',
        'dispatch_return',
        'customer_mobile_activity',
        'booking_persisted_fallback',
    ];

    /**
     * @param array<string, array<string, mixed>|null> $sources
     * @param array<string, mixed> $persistedFallback
     * @return array<string, mixed>
     */
    public function resolve(array $sources, array $persistedFallback = []): array
    {
        $sources['booking_persisted_fallback'] = $persistedFallback;
        $available = [];
        $selectedCategory = 'booking_persisted_fallback';
        $selected = $persistedFallback;

        foreach (self::SOURCE_PRECEDENCE as $category) {
            $candidate = $sources[$category] ?? null;
            if (!$this->hasTelemetry($candidate)) {
                continue;
            }

            $available[] = $category;
            if ($selectedCategory === 'booking_persisted_fallback') {
                $selectedCategory = $category;
                $selected = $candidate;
            }
        }

        $selected = $this->withoutNullTelemetry($selected);
        $fallback = $this->withoutNullTelemetry($persistedFallback);
        $sourceLabel = (string) ($selected['_source'] ?? $selectedCategory);
        unset($selected['_source'], $fallback['_source']);

        return array_merge($fallback, $selected, [
            'source' => $sourceLabel,
            'source_category' => $selectedCategory,
            'source_selection' => [
                'precedence' => self::SOURCE_PRECEDENCE,
                'available' => $available,
                'selected' => $selectedCategory,
                'selected_label' => $sourceLabel,
            ],
        ]);
    }

    /** @param array<string, mixed>|null $telemetry */
    public function hasTelemetry(?array $telemetry): bool
    {
        if (!$telemetry) {
            return false;
        }

        foreach (['actual_start_time', 'actual_return_time', 'distance_km', 'waiting_minutes'] as $field) {
            if (array_key_exists($field, $telemetry) && $telemetry[$field] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $telemetry */
    private function withoutNullTelemetry(array $telemetry): array
    {
        return array_filter(
            $telemetry,
            static fn ($value, $key) => $key === '_source' || $value !== null,
            ARRAY_FILTER_USE_BOTH
        );
    }
}
