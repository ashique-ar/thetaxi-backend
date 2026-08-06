<?php

namespace Tests\Unit;

use App\Http\Controllers\BookingController;
use App\Http\Requests\BookingSearchRequest;
use App\Models\Service\ServiceType;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DynamicDateTimeFieldContractTest extends TestCase
{
    public function test_configured_datetime_requires_the_browser_datetime_local_format(): void
    {
        $request = $this->requestWithConfiguredDateTime();
        $rules = $request->rules();
        $futureDate = now()->addMonth()->format('Y-m-d');

        $this->assertFalse(Validator::make([
            'service_type' => 'configured-datetime',
            'from_date' => $futureDate . 'T09:30',
        ], $rules)->fails());
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-datetime',
            'from_date' => $futureDate,
        ], $rules)->fails());
    }

    public function test_combined_datetime_values_reach_canonical_dates_and_times(): void
    {
        $params = $this->controller()->transform([
            'from_date' => '2026-08-10T09:30',
            'to_date' => '2026-08-12T18:45',
        ]);

        $this->assertSame('2026-08-10', $params['from_date']);
        $this->assertSame('09:30', $params['from_time']);
        $this->assertSame('2026-08-12', $params['to_date']);
        $this->assertSame('18:45', $params['to_time']);
    }

    public function test_portal_datetime_type_has_a_matching_public_control(): void
    {
        $portalEditor = file_get_contents(
            base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.ts')
        );
        $publicField = file_get_contents(
            resource_path('views/components/dynamic-form-field.blade.php')
        );

        $this->assertStringContainsString("{ value: 'datetime', label: 'Date & Time' }", $portalEditor);
        $this->assertStringContainsString("@case('datetime')", $publicField);
        $this->assertStringContainsString('type="datetime-local"', $publicField);
    }

    private function requestWithConfiguredDateTime(): BookingSearchRequest
    {
        $request = new class extends BookingSearchRequest {
            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'start_at' => [
                        'type' => 'datetime',
                        'label' => 'Start At',
                        'required' => true,
                        'submit_as' => 'from_date',
                    ],
                ], true, false];
            }
        };
        $request->initialize(['service_type' => 'configured-datetime']);

        return $request;
    }

    private function controller(): object
    {
        return new class extends BookingController {
            public function __construct()
            {
            }

            public function transform(array $requestData): array
            {
                return $this->transformDynamicSearchParams(
                    $requestData,
                    new ServiceType(['code' => 'configured-datetime']),
                    ['service_type' => 'configured-datetime'],
                    'configured-datetime'
                );
            }

            protected function resolveServiceFormConfiguration(ServiceType $serviceType): array
            {
                return [
                    'fields' => [
                        'start_at' => [
                            'type' => 'datetime',
                            'label' => 'Start At',
                            'submit_as' => 'from_date',
                        ],
                        'end_at' => [
                            'type' => 'datetime',
                            'label' => 'End At',
                            'submit_as' => 'to_date',
                        ],
                    ],
                    'field_mappings' => [
                        'dates' => [
                            'from_date' => 'start_at',
                            'to_date' => 'end_at',
                        ],
                    ],
                    'uses_dropoff_time' => true,
                    'allow_return_trip' => false,
                ];
            }
        };
    }
}
