<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class BookingAvailabilityMissingCustomerTest extends TestCase
{
    public function test_missing_customer_does_not_break_conflict_priority_or_override_checks(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $booking = (object) [
            'status' => 'confirmed',
            'customer' => null,
            'created_at' => Carbon::now()->subDays(2),
        ];

        $canOverride = new ReflectionMethod(BookingFlowService::class, 'canOverrideBooking');
        $priority = new ReflectionMethod(BookingFlowService::class, 'getBookingPriority');

        $this->assertFalse($canOverride->invoke($service, $booking));
        $this->assertSame('high', $priority->invoke($service, $booking));
    }
}
