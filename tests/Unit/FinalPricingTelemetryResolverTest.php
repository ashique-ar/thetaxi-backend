<?php

namespace Tests\Unit;

use App\Services\Pricing\FinalPricingTelemetryResolver;
use PHPUnit\Framework\TestCase;

class FinalPricingTelemetryResolverTest extends TestCase
{
    public function test_driver_wins_and_customer_does_not_fill_driver_gaps(): void
    {
        $resolver = new FinalPricingTelemetryResolver();

        $resolved = $resolver->resolve([
            'driver_mobile_activity' => [
                '_source' => 'driver_mobile_activity',
                'actual_start_time' => '2026-07-16T08:00:00Z',
            ],
            'system_activity' => [
                '_source' => 'system_completion',
                'distance_km' => 80,
            ],
            'dispatch_return' => [
                '_source' => 'dispatch_return',
                'distance_km' => 70,
            ],
            'customer_mobile_activity' => [
                '_source' => 'customer_mobile_activity',
                'distance_km' => 60,
            ],
        ], [
            '_source' => 'booking_persisted_fallback',
            'distance_km' => 50,
        ]);

        self::assertSame('driver_mobile_activity', $resolved['source']);
        self::assertSame('driver_mobile_activity', $resolved['source_category']);
        self::assertSame(50, $resolved['distance_km']);
        self::assertSame(FinalPricingTelemetryResolver::SOURCE_PRECEDENCE, $resolved['source_selection']['precedence']);
    }

    public function test_persisted_customer_activity_is_used_only_when_higher_sources_are_absent(): void
    {
        $resolver = new FinalPricingTelemetryResolver();

        $resolved = $resolver->resolve([
            'driver_mobile_activity' => null,
            'system_activity' => null,
            'dispatch_return' => null,
            'customer_mobile_activity' => [
                '_source' => 'customer_mobile_activity',
                'actual_start_time' => '2026-07-16T08:00:00Z',
                'actual_return_time' => '2026-07-16T09:30:00Z',
                'distance_km' => 42.75,
                'waiting_minutes' => 8,
            ],
        ]);

        self::assertSame('customer_mobile_activity', $resolved['source']);
        self::assertSame('customer_mobile_activity', $resolved['source_category']);
        self::assertSame(42.75, $resolved['distance_km']);
        self::assertSame(8, $resolved['waiting_minutes']);
    }

    public function test_system_activity_wins_over_return_and_customer_activity(): void
    {
        $resolver = new FinalPricingTelemetryResolver();

        $resolved = $resolver->resolve([
            'system_activity' => [
                '_source' => 'system_return',
                'actual_return_time' => '2026-07-16T10:00:00Z',
                'distance_km' => 91,
            ],
            'dispatch_return' => ['distance_km' => 88],
            'customer_mobile_activity' => ['distance_km' => 77],
        ]);

        self::assertSame('system_return', $resolved['source']);
        self::assertSame('system_activity', $resolved['source_category']);
        self::assertSame(91, $resolved['distance_km']);
    }

    public function test_explicit_zero_waiting_is_authoritative_telemetry(): void
    {
        $resolver = new FinalPricingTelemetryResolver();

        $resolved = $resolver->resolve([
            'system_activity' => [
                '_source' => 'system_completion',
                'waiting_minutes' => 0,
            ],
        ]);

        self::assertSame('system_activity', $resolved['source_category']);
        self::assertSame('system_completion', $resolved['source']);
        self::assertSame(0, $resolved['waiting_minutes']);
    }
}
