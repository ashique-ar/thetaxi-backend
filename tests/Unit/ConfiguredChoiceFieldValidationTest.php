<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ConfiguredChoiceFieldValidationTest extends TestCase
{
    public function test_configured_select_accepts_only_values_owned_by_the_editor(): void
    {
        $request = $this->requestWithConfiguredSelect();
        $rules = $request->rules();

        $this->assertSame(['required', 'string'], array_slice($rules['vehicle_class'], 0, 2));
        $this->assertCount(3, $rules['vehicle_class']);
        $this->assertFalse(Validator::make([
            'service_type' => 'configured-service',
            'vehicle_class' => 'van',
        ], $rules)->fails());
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-service',
            'vehicle_class' => 'bus',
        ], $rules)->fails());
    }

    public function test_optional_configured_select_remains_optional(): void
    {
        $request = $this->requestWithConfiguredSelect(false);
        $rules = $request->rules();

        $this->assertSame(['nullable', 'string'], array_slice($rules['vehicle_class'], 0, 2));
        $this->assertCount(3, $rules['vehicle_class']);
        $this->assertFalse(Validator::make([
            'service_type' => 'configured-service',
        ], $rules)->fails());
    }

    public function test_portal_and_public_form_use_the_same_configured_option_values(): void
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
            '<option value="{{ $option[\'value\'] }}"',
            $publicField
        );
    }

    private function requestWithConfiguredSelect(bool $required = true): BookingSearchRequest
    {
        $request = new class($required) extends BookingSearchRequest {
            public function __construct(private readonly bool $fieldRequired)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'vehicle_class' => [
                        'type' => 'select',
                        'label' => 'Vehicle Class',
                        'required' => $this->fieldRequired,
                        'options' => [
                            ['value' => 'sedan', 'label' => 'Sedan'],
                            ['value' => 'van', 'label' => 'Van'],
                        ],
                    ],
                ], true, false];
            }
        };
        $request->initialize(['service_type' => 'configured-service']);

        return $request;
    }
}
