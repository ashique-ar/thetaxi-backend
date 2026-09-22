<?php

namespace Tests\Unit;

use App\Services\BookingFlowService;
use PHPUnit\Framework\TestCase;

class PublicCheckoutAvailabilityContractTest extends TestCase
{
    public function test_persisted_cart_items_are_rechecked_with_canonical_identifiers(): void
    {
        $service = $this->service([
            'group-a' => ['id' => 'group-a', 'allow_booking' => true],
            'group-b' => ['id' => 'group-b', 'allow_booking' => true],
        ]);

        $state = $service->validatePublicCartAvailability([
            'cart-a' => [
                'vehicle_group_id' => 'group-a',
                'service_type_data' => ['id' => 'service-a'],
                'pickup_date' => '2026-08-01',
                'pickup_time' => '09:00',
                'pickup_location' => 'Colombo',
                'pickup_lat' => 6.9271,
                'pickup_lng' => 79.8612,
                'dropoff_location' => 'Kandy',
                'dropoff_lat' => 7.2906,
                'dropoff_lng' => 80.6337,
            ],
            'cart-b' => [
                'vehicle_group_id' => 'group-b',
                'service_type_data' => (object) ['id' => 'service-b'],
                'from_date' => '2026-08-02',
                'to_date' => '2026-08-03',
            ],
        ]);

        $this->assertTrue($state['available']);
        $this->assertSame([], $state['failures']);
        $this->assertSame('service-a', $service->lookups[0]['service_type']);
        $this->assertSame('2026-08-01', $service->lookups[0]['to_date']);
        $this->assertSame(['address' => 'Colombo', 'latitude' => 6.9271, 'longitude' => 79.8612], $service->lookups[0]['pickup_location']);
        $this->assertSame(['address' => 'Kandy', 'latitude' => 7.2906, 'longitude' => 80.6337], $service->lookups[0]['dropoff_location']);
        $this->assertSame('service-b', $service->lookups[1]['service_type']);
    }

    public function test_unavailable_and_quotation_only_groups_block_checkout(): void
    {
        $service = $this->service([
            'group-a' => [
                'id' => 'group-a',
                'allow_booking' => false,
                'quotation_only_reasons' => ['no_vehicles_available'],
            ],
        ]);

        $state = $service->validatePublicCartAvailability([
            'cart-a' => [
                'vehicle_group_id' => 'group-a',
                'service_type' => 'airport_transfers',
                'from_date' => '2026-08-01',
            ],
            'cart-b' => [
                'vehicle_group_id' => 'group-missing',
                'service_type' => 'airport_transfers',
                'from_date' => '2026-08-01',
            ],
        ]);

        $this->assertFalse($state['available']);
        $this->assertSame(['no_vehicles_available'], $state['failures'][0]['reasons']);
        $this->assertSame(['vehicle_group_unavailable'], $state['failures'][1]['reasons']);
    }

    public function test_missing_persisted_context_fails_closed_without_lookup(): void
    {
        $service = $this->service([]);

        $state = $service->validatePublicCartAvailability([
            'cart-a' => [
                'vehicle_group_id' => 'group-a',
                'service_type' => 'airport_transfers',
            ],
        ]);

        $this->assertFalse($state['available']);
        $this->assertSame(['missing_availability_context'], $state['failures'][0]['reasons']);
        $this->assertSame([], $service->lookups);
    }

    public function test_portal_search_cart_add_and_checkout_share_the_availability_owner(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $cartController = file_get_contents($projectRoot . '/app/Http/Controllers/CartController.php');
        $checkoutController = file_get_contents($projectRoot . '/app/Http/Controllers/CheckoutController.php');
        $portalBookingFlow = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/booking/components/booking-flow/booking-flow.service.ts'
        );

        $this->assertStringContainsString(
            '->getPublicVehicleGroupAvailability($pricingParams);',
            $cartController
        );
        $this->assertStringContainsString(
            '->validatePublicCartAvailability($cart);',
            $checkoutController
        );
        $this->assertStringContainsString(
            "'/booking-flow/vehicle-groups/availability'",
            $portalBookingFlow
        );
    }

    private function service(array $availabilityByGroup): BookingFlowService
    {
        return new class($availabilityByGroup) extends BookingFlowService {
            public array $lookups = [];

            public function __construct(private readonly array $availabilityByGroup)
            {
            }

            public function getPublicVehicleGroupAvailability(array $params): ?array
            {
                $this->lookups[] = $params;

                return $this->availabilityByGroup[$params['vehicle_group_id']] ?? null;
            }
        };
    }
}
