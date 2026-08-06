<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Tests\TestCase;

class PublicServiceValidationRulesContractTest extends TestCase
{
    public function test_rules_for_service_uses_the_same_dynamic_owner_as_search_validation(): void
    {
        $request = new class extends BookingSearchRequest {
            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'passengers' => [
                        'type' => 'number',
                        'label' => 'Passengers',
                        'required' => true,
                        'validation' => ['min' => 1, 'max' => 12],
                    ],
                ], true, false];
            }
        };

        $request->initialize(['service_type' => 'configured-service']);

        $this->assertSame(
            $this->serializeRules($request->rulesForService('configured-service')),
            $this->serializeRules($request->rules())
        );
        $this->assertSame(
            'required|numeric|min:1|max:12',
            $request->rulesForService('configured-service')['passengers']
        );
    }

    public function test_public_validation_endpoint_delegates_to_booking_search_request(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/BookingController.php')
        );

        $this->assertStringContainsString(
            '$rules = $request->rulesForService($code);',
            $controller
        );
        $this->assertStringContainsString(
            "return implode('|', array_map(",
            $controller
        );
    }

    private function serializeRules(array $rules): array
    {
        return array_map(function ($fieldRules) {
            if (!is_array($fieldRules)) {
                return $fieldRules;
            }

            return implode('|', array_map(
                fn($rule) => (string) $rule,
                $fieldRules
            ));
        }, $rules);
    }
}
