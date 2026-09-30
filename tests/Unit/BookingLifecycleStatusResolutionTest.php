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
use App\Services\Pricing\FinalPricingTelemetryResolver;
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

    public function test_multi_item_lifecycle_requires_an_explicit_item_selection(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, []);
        $booking->setRelation('bookingItems', collect([
            $this->modelWithoutConstructor(BookingItem::class, []),
            $this->modelWithoutConstructor(BookingItem::class, []),
        ]));
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'assertItemSafeLifecycle');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('booking_item_id is required');

        $method->invoke($service, $booking, null);
    }

    public function test_multi_item_lifecycle_accepts_an_item_owned_selection(): void
    {
        $first = $this->modelWithoutConstructor(BookingItem::class, ['id' => 'item-1']);
        $second = $this->modelWithoutConstructor(BookingItem::class, ['id' => 'item-2']);
        $booking = $this->modelWithoutConstructor(Booking::class, []);
        $booking->setRelation('bookingItems', collect([$first, $second]));
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'assertItemSafeLifecycle');

        $method->invoke($service, $booking, 'item-2');

        $this->assertTrue(true);
    }

    public function test_item_dispatch_status_overrides_a_stale_confirmed_parent_for_operations(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, ['status' => 'confirmed']);
        $item = $this->modelWithoutConstructor(BookingItem::class, ['completed_at' => null]);
        $dispatch = $this->modelWithoutConstructor(BookingDispatch::class, [
            'dispatch_status' => DispatchStatus::IN_PROGRESS->value,
        ]);
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'resolveSelectedItemLifecycleStatus');

        $status = $method->invoke($service, $booking, $item, $dispatch, null);

        $this->assertSame(BookingLifecycleStatus::ONGOING_ACTIVE, $status);
    }

    public function test_dispatched_item_is_not_active_hire_until_trip_starts(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, ['status' => 'confirmed']);
        $item = $this->modelWithoutConstructor(BookingItem::class, ['completed_at' => null]);
        $dispatch = $this->modelWithoutConstructor(BookingDispatch::class, [
            'dispatch_status' => DispatchStatus::DISPATCHED->value,
        ]);
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'resolveSelectedItemLifecycleStatus');

        $status = $method->invoke($service, $booking, $item, $dispatch, null);

        $this->assertSame(BookingLifecycleStatus::DISPATCH_OUT, $status);
        $this->assertSame('allocation_dispatch', $status->getStage());
        $this->assertSame('Dispatched', $status->getDisplayName());
    }

    public function test_cancelled_selected_item_wins_over_stale_completion_and_dispatch_records(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, ['status' => 'completed']);
        $item = $this->modelWithoutConstructor(BookingItem::class, [
            'status' => 'cancelled',
            'completed_at' => '2026-09-28 10:00:00',
        ]);
        $dispatch = $this->modelWithoutConstructor(BookingDispatch::class, [
            'dispatch_status' => DispatchStatus::RETURNED->value,
        ]);
        $qc = $this->modelWithoutConstructor(BookingQC::class, [
            'qc_status' => QCStatus::COMPLETED->value,
        ]);
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'resolveSelectedItemLifecycleStatus');

        $status = $method->invoke($service, $booking, $item, $dispatch, $qc);

        $this->assertSame(BookingLifecycleStatus::CANCELLED, $status);
        $this->assertSame('Cancelled', $status->getDisplayName());
        $this->assertSame('final', $status->getStage());
    }

    public function test_booking_cancellation_and_rejection_are_not_overridden_by_item_timestamps(): void
    {
        $item = $this->modelWithoutConstructor(BookingItem::class, [
            'status' => 'confirmed',
            'completed_at' => '2026-09-28 10:00:00',
        ]);
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'resolveSelectedItemLifecycleStatus');

        foreach ([
            ['cancelled', BookingLifecycleStatus::CANCELLED],
            ['canceled', BookingLifecycleStatus::CANCELLED],
            ['rejected', BookingLifecycleStatus::BOOKING_REJECTED],
            ['inquiry_cancelled', BookingLifecycleStatus::INQUIRY_CANCELLED],
        ] as [$bookingStatus, $expected]) {
            $booking = $this->modelWithoutConstructor(Booking::class, ['status' => $bookingStatus]);

            $this->assertSame($expected, $method->invoke($service, $booking, $item, null, null));
        }
    }

    public function test_booking_model_uses_dispatch_out_until_driver_starts_the_trip(): void
    {
        $booking = $this->modelWithoutConstructor(Booking::class, ['status' => 'confirmed']);
        $dispatch = $this->modelWithoutConstructor(BookingDispatch::class, [
            'dispatch_status' => DispatchStatus::DISPATCHED->value,
        ]);
        $booking->setRelation('dispatch', $dispatch);

        $this->assertSame(BookingLifecycleStatus::DISPATCH_OUT, $booking->getLifecycleStatus());

        $dispatch->setAttribute('dispatch_status', DispatchStatus::IN_PROGRESS->value);
        $this->assertSame(BookingLifecycleStatus::ONGOING_ACTIVE, $booking->getLifecycleStatus());
    }

    public function test_booking_model_resolves_every_canonical_lifecycle_value(): void
    {
        foreach (BookingLifecycleStatus::cases() as $expected) {
            $booking = $this->modelWithoutConstructor(Booking::class, ['status' => $expected->value]);

            $this->assertSame($expected, $booking->getLifecycleStatus(), $expected->value);
        }
    }

    public function test_disabled_return_and_qc_stages_map_to_direct_trip_closeout(): void
    {
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingLifecycleService::class, 'normalizeStatusForDisabledOptionalStages');
        $settings = ['enable_return_stage' => false, 'enable_qc_stage' => false];

        $this->assertSame(BookingLifecycleStatus::ONGOING_ACTIVE, $method->invoke($service, BookingLifecycleStatus::RETURN_SCHEDULED, $settings));
        $this->assertSame(BookingLifecycleStatus::ONGOING_ACTIVE, $method->invoke($service, BookingLifecycleStatus::RETURN_OVERDUE, $settings));
        $this->assertSame(BookingLifecycleStatus::COMPLETION_PENDING, $method->invoke($service, BookingLifecycleStatus::RETURN_COMPLETED, $settings));
        $this->assertSame(BookingLifecycleStatus::COMPLETION_PENDING, $method->invoke($service, BookingLifecycleStatus::QC_REPAIR_NEEDED, $settings));
        $this->assertSame(BookingLifecycleStatus::RETURN_SCHEDULED, $method->invoke($service, BookingLifecycleStatus::RETURN_SCHEDULED, ['enable_return_stage' => true, 'enable_qc_stage' => false]));
    }

    public function test_final_telemetry_uses_exact_minutes_and_rejects_reversed_ranges(): void
    {
        $resolver = new FinalPricingTelemetryResolver();

        $this->assertSame(62, $resolver->elapsedMinutes(
            '2026-07-16T09:00:00+00:00',
            '2026-07-16T10:01:01+00:00'
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Actual return time must be on or after actual start time.');

        $resolver->elapsedMinutes(
            '2026-07-16T10:00:00+00:00',
            '2026-07-16T09:59:59+00:00'
        );
    }

    private function modelWithoutConstructor(string $class, array $attributes): object
    {
        $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $model->setRawAttributes($attributes);

        return $model;
    }
}
