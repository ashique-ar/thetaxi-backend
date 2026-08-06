<?php

namespace Tests\Unit;

use App\Services\CartService;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use PHPUnit\Framework\TestCase;

class CheckoutExtraKmEligibilityContractTest extends TestCase
{
    public function test_active_slab_and_vehicle_group_rate_create_an_extra_km_offer(): void
    {
        $service = $this->service(true, ['rate' => 125.0, 'currency' => 'LKR']);

        $offer = $service->getExtraKmOfferForItem([
            'vehicle_group_id' => 'vehicle-group-a',
            'service_type_data' => ['id' => 'service-a'],
        ]);

        $this->assertSame('vehicle-group-a', $offer['vehicle_group_id']);
        $this->assertSame('service-a', $offer['service_type_id']);
        $this->assertTrue($offer['has_slab']);
        $this->assertSame(125.0, $offer['rate']['rate']);
        $this->assertSame([['vehicle-group-a', 'service-a']], $service->rateLookups);
    }

    public function test_inactive_slab_blocks_the_offer_before_rate_lookup(): void
    {
        $service = $this->service(false, ['rate' => 125.0, 'currency' => 'LKR']);

        $offer = $service->getExtraKmOfferForItem([
            'vehicle_group_id' => 'vehicle-group-a',
            'service_type_data' => ['id' => 'service-a'],
        ]);

        $this->assertFalse($offer['has_slab']);
        $this->assertNull($offer['rate']);
        $this->assertSame([], $service->rateLookups);
    }

    public function test_persisted_array_and_object_service_identifiers_are_supported(): void
    {
        $service = $this->service(true, ['rate' => 125.0, 'currency' => 'LKR']);

        $this->assertNotNull($service->getExtraKmOfferForItem([
            'vehicle_group_id' => 'vehicle-group-a',
            'service_type_data' => (object) ['id' => 'service-a'],
        ]));
        $this->assertNull($service->getExtraKmOfferForItem([
            'vehicle_group_id' => 'vehicle-group-a',
            'service_type_data' => [],
        ]));
        $this->assertNull($service->getExtraKmOfferForItem([
            'service_type_data' => ['id' => 'service-a'],
        ]));
    }

    public function test_listing_and_mutation_use_the_same_portal_owned_offer(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $cartService = file_get_contents($projectRoot . '/app/Services/CartService.php');
        $cartController = file_get_contents($projectRoot . '/app/Http/Controllers/CartController.php');
        $portalPricingService = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/services/pricing.service.ts'
        );

        $this->assertStringContainsString(
            '$offer = $this->getExtraKmOfferForItem($items[$cartKey]);',
            $cartService
        );
        $this->assertStringContainsString(
            '$offer = $this->cartService->getExtraKmOfferForItem($items[$cartKey]);',
            $cartController
        );
        $this->assertStringContainsString(
            "'/vehicles/pricing-slab-definitions'",
            $portalPricingService
        );
        $this->assertStringContainsString(
            "'/vehicles/common-rate-definitions'",
            $portalPricingService
        );

        $slabDefinition = (new \ReflectionClass(VehiclePricingSlabDefinition::class))
            ->newInstanceWithoutConstructor();
        $this->assertContains('is_active', $slabDefinition->getFillable());
        $this->assertSame('boolean', $slabDefinition->getCasts()['is_active']);
    }

    private function service(bool $hasSlab, ?array $rate): CartService
    {
        return new class($hasSlab, $rate) extends CartService {
            /** @var array<int, array{0: string, 1: string|null}> */
            public array $rateLookups = [];

            public function __construct(
                private readonly bool $hasSlab,
                private readonly ?array $rate
            ) {
            }

            protected function hasActiveExtraKmSlabForService(string $serviceTypeId): bool
            {
                return $this->hasSlab;
            }

            public function getExtraKmRateForVehicleGroup(
                string $vehicleGroupId,
                ?string $serviceTypeId = null
            ): ?array {
                $this->rateLookups[] = [$vehicleGroupId, $serviceTypeId];

                return $this->rate;
            }
        };
    }
}
