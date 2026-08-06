<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ConfiguredRadioFieldValidationTest extends TestCase
{
    public function test_configured_radio_treats_each_portal_option_as_an_exact_value(): void
    {
        $rules = $this->requestWithConfiguredRadio('service_level')->rules();

        $this->assertFalse($this->validatorFor($rules, [
            'service_level' => 'airport,express',
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'service_level' => 'airport',
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'service_level' => 'express',
        ])->fails());
    }

    public function test_configured_trip_mode_rule_is_not_overwritten_by_auxiliary_defaults(): void
    {
        $rules = $this->requestWithConfiguredRadio('trip_mode')->rules();

        $this->assertIsArray($rules['trip_mode']);
        $this->assertCount(3, $rules['trip_mode']);
        $this->assertFalse($this->validatorFor($rules, [
            'trip_mode' => 'bespoke',
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'trip_mode' => 'open_package',
        ])->fails());
        $this->assertTrue($this->validatorFor($rules)->fails());
    }

    public function test_portal_and_public_radio_render_the_same_option_values(): void
    {
        $portalEditor = file_get_contents(
            base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.ts')
        );
        $publicField = file_get_contents(
            resource_path('views/components/dynamic-form-field.blade.php')
        );

        $this->assertStringContainsString(
            'options: filteredOptions.length > 0 ? filteredOptions : undefined',
            $portalEditor
        );
        $this->assertStringContainsString(
            'value="{{ $option[\'value\'] }}"',
            $publicField
        );
    }

    private function validatorFor(array $rules, array $input = [])
    {
        return Validator::make([
            'service_type' => 'configured-radio',
            ...$input,
        ], $rules);
    }

    private function requestWithConfiguredRadio(string $submitAs): BookingSearchRequest
    {
        $request = new class($submitAs) extends BookingSearchRequest {
            public function __construct(private readonly string $radioSubmitKey)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'service_level' => [
                        'type' => 'radio',
                        'label' => 'Service Level',
                        'required' => true,
                        'submit_as' => $this->radioSubmitKey,
                        'options' => [
                            ['value' => 'airport,express', 'label' => 'Airport Express'],
                            ['value' => 'bespoke', 'label' => 'Bespoke'],
                        ],
                    ],
                ], true, false];
            }
        };
        $request->initialize(['service_type' => 'configured-radio']);

        return $request;
    }
}
