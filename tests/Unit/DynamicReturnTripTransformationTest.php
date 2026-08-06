<?php

namespace Tests\Unit;

use App\Http\Controllers\BookingController;
use App\Models\Service\ServiceType;
use Tests\TestCase;

class DynamicReturnTripTransformationTest extends TestCase
{
    public function test_authorized_dynamic_return_leg_reaches_canonical_search_metadata(): void
    {
        $params = $this->controller(true)->transform([
            'pickup_date' => '2026-08-10',
            'pickup_time' => '09:00',
            'return_date' => '2026-08-12',
            'return_time' => '18:30',
            'is_return_trip' => '1',
            'pickup' => 'Colombo',
            'dropoff' => 'Kandy',
        ]);

        $this->assertTrue($params['is_return_trip']);
        $this->assertSame('2026-08-12', $params['return_date']);
        $this->assertSame('18:30', $params['return_time']);
        $this->assertSame($params['return_date'], $params['to_date']);
        $this->assertSame($params['return_time'], $params['to_time']);
    }

    public function test_unsupported_submitted_return_flag_cannot_reach_pricing_metadata(): void
    {
        $params = $this->controller(false)->transform([
            'pickup_date' => '2026-08-10',
            'pickup_time' => '09:00',
            'return_date' => '2026-08-12',
            'return_time' => '18:30',
            'is_return_trip' => '1',
            'pickup' => 'Colombo',
            'dropoff' => 'Kandy',
        ]);

        $this->assertArrayNotHasKey('is_return_trip', $params);
        $this->assertArrayNotHasKey('return_date', $params);
        $this->assertArrayNotHasKey('return_time', $params);
    }

    private function controller(bool $allowReturnTrip): object
    {
        return new class($allowReturnTrip) extends BookingController {
            public function __construct(private readonly bool $returnTripAllowed)
            {
            }

            public function transform(array $requestData): array
            {
                return $this->transformDynamicSearchParams(
                    $requestData,
                    new ServiceType(['code' => 'configured_return']),
                    ['service_type' => 'configured_return'],
                    'configured_return'
                );
            }

            protected function resolveServiceFormConfiguration(ServiceType $serviceType): array
            {
                return [
                    'fields' => [
                        'pickup_date' => [
                            'type' => 'date',
                            'label' => 'Pickup Date',
                            'submit_as' => 'pickup_date',
                        ],
                        'pickup_time' => [
                            'type' => 'time',
                            'label' => 'Pickup Time',
                            'submit_as' => 'pickup_time',
                        ],
                        'return_date' => [
                            'type' => 'date',
                            'label' => 'Return Date',
                            'submit_as' => 'return_date',
                        ],
                        'return_time' => [
                            'type' => 'time',
                            'label' => 'Return Time',
                            'submit_as' => 'return_time',
                        ],
                        'return_toggle' => [
                            'type' => 'checkbox',
                            'label' => 'Return Trip',
                            'submit_as' => 'is_return_trip',
                        ],
                    ],
                    'field_mappings' => [],
                    'uses_dropoff_time' => true,
                    'allow_return_trip' => $this->returnTripAllowed,
                ];
            }
        };
    }
}
