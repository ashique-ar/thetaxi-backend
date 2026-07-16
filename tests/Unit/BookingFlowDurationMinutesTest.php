<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use Carbon\Carbon;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class BookingFlowDurationMinutesTest extends TestCase
{
    public function test_partial_hours_preserve_exact_minutes_and_fractional_hours(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $result = $service->calculateDurationInDaysAndHours(
            Carbon::parse('2026-07-16 10:15:00'),
            Carbon::parse('2026-07-16 11:45:00')
        );

        $this->assertSame(90, $result['minutes']);
        $this->assertSame(90, $result['total_minutes']);
        $this->assertSame(1.5, $result['hours']);
        $this->assertSame(1, $result['calendar_days']);
        $this->assertSame('1 hour 30 minutes', $result['formatted']);
    }

    public function test_separate_time_fields_are_included_in_duration(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingFlowService::class, 'bookingDateTime');
        $from = $method->invoke($service, '2026-07-16', '22:30');
        $to = $method->invoke($service, '2026-07-17', '00:00');
        $result = $service->calculateDurationInDaysAndHours($from, $to);

        $this->assertSame(90, $result['minutes']);
        $this->assertSame(1.5, $result['hours']);
        $this->assertSame(2, $result['days']);
    }

    public function test_reversed_date_range_is_rejected(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('after the start');

        $service->calculateDurationInDaysAndHours(
            Carbon::parse('2026-07-16 11:45:00'),
            Carbon::parse('2026-07-16 10:15:00')
        );
    }

    public function test_minute_only_pricing_input_derives_fractional_hours_without_defaulting_to_a_day(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingFlowService::class, 'prepareCalculationInputs');
        $inputs = $method->invoke($service, [
            'vehicle_group_id' => 'group-1',
            'duration_minutes' => 90,
        ]);

        $this->assertSame(90.0, $inputs['duration_minutes']);
        $this->assertSame(1.5, $inputs['duration_hours']);
        $this->assertSame(0.0, $inputs['duration_days']);
    }
}
