<?php

namespace Tests\Unit;

use App\Services\TaxiMeterPricingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxiMeterPricingServiceTest extends TestCase
{
    public function test_distance_hourly_combined_and_open_meter_fares(): void
    {
        $pricing = new TaxiMeterPricingService();

        $distance = $pricing->normalize([
            'meter_package_kind' => 'distance', 'meter_included_km' => 50,
            'meter_fixed_price' => 5000, 'meter_extra_per_km' => 100,
        ]);
        self::assertSame(5000.0, $pricing->calculate($distance, 50, 10)['total']);
        self::assertSame(5300.0, $pricing->calculate($distance, 53, 10)['total']);

        $hourly = $pricing->normalize([
            'meter_package_kind' => 'hourly', 'meter_included_hours' => 2,
            'meter_fixed_price' => 3000, 'meter_extra_per_hour' => 500,
        ]);
        self::assertSame(3500.0, $pricing->calculate($hourly, 0, 3)['total']);

        $combined = $pricing->normalize([
            'meter_package_kind' => 'combined', 'meter_included_km' => 50,
            'meter_included_hours' => 4, 'meter_fixed_price' => 6000,
            'meter_extra_per_km' => 100, 'meter_extra_per_hour' => 500,
        ]);
        $breakdown = $pricing->calculate($combined, 52, 5);
        self::assertSame(200.0, $breakdown['extra_km_charge']);
        self::assertSame(500.0, $breakdown['extra_hour_charge']);
        self::assertSame(6700.0, $breakdown['total']);

        $open = $pricing->normalize([
            'meter_package_kind' => 'open_meter', 'meter_extra_per_km' => 100,
            'meter_extra_per_hour' => 500,
        ]);
        self::assertSame(750.0, $pricing->calculate($open, 2.5, 1)['total']);
    }

    public function test_invalid_package_limits_and_rates_are_rejected(): void
    {
        $pricing = new TaxiMeterPricingService();

        try {
            $pricing->normalize([
                'meter_package_kind' => 'distance', 'meter_included_km' => 0,
                'meter_fixed_price' => 100, 'meter_extra_per_km' => 10,
            ]);
            self::fail('Expected zero included distance to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('meter_included_km', $exception->errors());
        }

        try {
            $pricing->normalize([
                'meter_package_kind' => 'open_meter', 'meter_extra_per_km' => -1,
                'meter_extra_per_hour' => 10,
            ]);
            self::fail('Expected a negative rate to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('meter_extra_per_km', $exception->errors());
        }
    }
}
