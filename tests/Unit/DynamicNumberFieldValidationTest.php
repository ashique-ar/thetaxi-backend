<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Tests\TestCase;

class DynamicNumberFieldValidationTest extends TestCase
{
    public function test_dynamic_number_constraints_are_enforced_by_the_search_request(): void
    {
        $request = new class extends BookingSearchRequest {
            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'passengers' => [
                        'type' => 'number',
                        'label' => 'Passengers',
                        'required' => true,
                        'validation' => ['min' => 2, 'max' => 8],
                    ],
                ], true, false];
            }
        };
        $request->initialize(['service_type' => 'configured-service']);

        $this->assertSame(
            'required|numeric|min:2|max:8',
            $request->rules()['passengers']
        );
    }

    public function test_public_number_field_uses_the_same_constraints(): void
    {
        $component = file_get_contents(
            resource_path('views/components/dynamic-form-field.blade.php')
        );

        $this->assertStringContainsString(
            "\$minimum = data_get(\$field, 'validation.min')",
            $component
        );
        $this->assertStringContainsString(
            'min="{{ $minimum }}"',
            $component
        );
        $this->assertStringContainsString(
            'max="{{ $maximum }}"',
            $component
        );
    }

    public function test_update_endpoint_preserves_numeric_validation_metadata(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/Api/Service/ServiceFormConfigController.php')
        );

        $this->assertStringContainsString(
            "'form_config.*.validation.min' => 'nullable|numeric'",
            $controller
        );
        $this->assertStringContainsString(
            "'form_config.*.validation.max' => 'nullable|numeric'",
            $controller
        );
    }
}
