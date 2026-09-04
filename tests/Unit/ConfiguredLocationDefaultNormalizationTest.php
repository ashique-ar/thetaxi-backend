<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Tests\TestCase;

class ConfiguredLocationDefaultNormalizationTest extends TestCase
{
    public function test_untouched_homepage_location_defaults_include_their_configured_coordinates(): void
    {
        $request = $this->configuredRequest([
            'service_type' => 'configured-service',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('Configured pickup', $request->input('pickup'));
        $this->assertSame('6.927079', $request->input('pickup_lat'));
        $this->assertSame('79.861244', $request->input('pickup_lng'));
    }

    public function test_submitted_location_and_coordinates_are_not_replaced_by_defaults(): void
    {
        $request = $this->configuredRequest([
            'service_type' => 'configured-service',
            'pickup' => 'Selected destination',
            'pickup_lat' => '7.290572',
            'pickup_lng' => '80.633728',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('Selected destination', $request->input('pickup'));
        $this->assertSame('7.290572', $request->input('pickup_lat'));
        $this->assertSame('80.633728', $request->input('pickup_lng'));
    }

    public function test_configured_default_label_replaces_stale_browser_coordinates(): void
    {
        $request = $this->configuredRequest([
            'service_type' => 'configured-service',
            'pickup' => 'Configured pickup',
            'pickup_lat' => '7.180756',
            'pickup_lng' => '79.884117',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('Configured pickup', $request->input('pickup'));
        $this->assertSame('6.927079', $request->input('pickup_lat'));
        $this->assertSame('79.861244', $request->input('pickup_lng'));
    }

    public function test_active_conditional_default_is_canonicalized_without_javascript_aliases(): void
    {
        $request = new class extends BookingSearchRequest {
            public function normalizeForValidation(): void
            {
                $this->prepareForValidation();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'dropoff_location' => [
                        'type' => 'location',
                        'location_mode' => 'conditional',
                        'condition_field' => 'transfer_type',
                        'submit_as' => 'dropoff_location',
                        'conditions' => [
                            'from_airport' => [
                                'type' => 'location',
                                'default' => 'Galle, Sri Lanka',
                                'default_lat' => '6.0328948',
                                'default_lng' => '80.2167912',
                            ],
                        ],
                    ],
                ], true, false];
            }
        };
        $request->initialize([
            'service_type' => 'airport_transfers',
            'transfer_type' => 'from-airport',
            'dropoff_location' => 'Galle, Sri Lanka',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('Galle, Sri Lanka', $request->input('dropoff'));
        $this->assertSame('6.0328948', $request->input('dropoff_lat'));
        $this->assertSame('80.2167912', $request->input('dropoff_lng'));
    }

    public function test_old_custom_address_is_never_paired_with_configured_default_coordinates(): void
    {
        $request = $this->configuredRequest([
            'service_type' => 'configured-service',
            'pickup' => 'Customer entered location',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('Customer entered location', $request->input('pickup'));
        $this->assertNull($request->input('pickup_lat'));
        $this->assertNull($request->input('pickup_lng'));
    }

    public function test_browser_defaults_use_coordinates_from_the_same_dynamic_field(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString('const { latInput, lngInput } = getCoordInputs(control);', $script);
        $this->assertStringContainsString("const defaultLat = control.dataset.defaultLat", $script);
        $this->assertStringNotContainsString('pickupInput.value = "Colombo, Sri Lanka"', $script);
        $this->assertStringNotContainsString('dropoffInput.value = "Galle, Sri Lanka"', $script);
        $this->assertStringNotContainsString('function ensureCoordinateValues()', $script);

        $field = file_get_contents(resource_path('views/components/dynamic-form-field.blade.php'));
        $this->assertStringContainsString("\$currentLat !== ''", $field);
        $this->assertStringContainsString('value="{{ $effectiveLat }}"', $field);
        $this->assertStringContainsString('value="{{ $effectiveLng }}"', $field);
    }

    private function configuredRequest(array $input): BookingSearchRequest
    {
        $request = new class extends BookingSearchRequest {
            public function normalizeForValidation(): void
            {
                $this->prepareForValidation();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'pickup_location' => [
                        'type' => 'location',
                        'required' => true,
                        'submit_as' => 'pickup',
                        'default' => 'Configured pickup',
                        'default_lat' => '6.927079',
                        'default_lng' => '79.861244',
                    ],
                ], true, false];
            }
        };
        $request->initialize($input);

        return $request;
    }
}
