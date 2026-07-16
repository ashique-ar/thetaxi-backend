<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MultiItemPricingFallbackContractTest extends TestCase
{
    public function test_multi_item_fallback_is_item_scoped_and_never_reads_aggregate_booking_metrics(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Services/BookingLifecycleService.php'
        );
        self::assertIsString($source);

        $fallbackStart = strpos($source, '$persistedFallback = $isMultiItem ? [');
        $singleItemBranch = strpos($source, '] : [', $fallbackStart);
        self::assertNotFalse($fallbackStart);
        self::assertNotFalse($singleItemBranch);

        $multiItemBranch = substr($source, $fallbackStart, $singleItemBranch - $fallbackStart);

        self::assertStringContainsString("'items.' . (string) \$bookingItem->id", $source);
        self::assertStringContainsString("'_source' => 'booking_item_persisted_fallback'", $multiItemBranch);
        self::assertStringContainsString("\$itemDistanceMetrics['actual_km']", $multiItemBranch);
        self::assertStringContainsString("\$itemDurationMetrics['waiting_minutes']", $multiItemBranch);
        self::assertStringNotContainsString('$booking->actual_distance', $multiItemBranch);
        self::assertStringNotContainsString('$booking->actual_duration', $multiItemBranch);
        self::assertStringNotContainsString("data_get(\$booking->duration_metrics, 'actual_minutes')", $multiItemBranch);

        self::assertStringContainsString(
            '? ($itemDurationMetrics[\'actual_minutes\'] ?? null)',
            $source
        );
    }
}
