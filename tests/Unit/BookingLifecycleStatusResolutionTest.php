<?php

namespace Tests\Unit;

use App\Enums\BookingLifecycleStatus;
use App\Enums\DispatchStatus;
use App\Enums\QCStatus;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingQC;
use App\Services\BookingLifecycleService;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class BookingLifecycleStatusResolutionTest extends TestCase
{
    public function test_completed_qc_remains_ready_for_explicit_booking_completion(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, ['status' => 'confirmed']);
        $dispatch = $this->modelWithoutConstructor(BookingDispatch::class, [
            'dispatch_status' => DispatchStatus::RETURNED->value,
        ]);
        $qc = $this->modelWithoutConstructor(BookingQC::class, [
            'qc_status' => QCStatus::COMPLETED->value,
        ]);

        $booking->setRelation('dispatch', $dispatch);
        $booking->setRelation('qc', $qc);

        $this->assertSame(BookingLifecycleStatus::QC_COMPLETED, $booking->getLifecycleStatus());
    }

    public function test_multi_item_completion_is_blocked_until_item_level_dispatch_is_available(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, []);
        $booking->setRelation('bookingItems', collect([
            $this->modelWithoutConstructor(BookingItem::class, []),
            $this->modelWithoutConstructor(BookingItem::class, []),
        ]));
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'assertItemSafeLifecycle');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Multi-item completion requires item-level dispatch');

        $method->invoke($service, $booking, null);
    }

    private function modelWithoutConstructor(string $class, array $attributes): object
    {
        $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $model->setRawAttributes($attributes);

        return $model;
    }
}
