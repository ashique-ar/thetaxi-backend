<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DynamicCheckboxFieldValidationTest extends TestCase
{
    public function test_required_configured_checkbox_must_be_accepted(): void
    {
        $rules = $this->requestWithConfiguredCheckbox(true)->rules();

        $this->assertSame('accepted', $rules['terms_accepted']);
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-checkbox',
        ], $rules)->fails());
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-checkbox',
            'terms_accepted' => '0',
        ], $rules)->fails());
        $this->assertFalse(Validator::make([
            'service_type' => 'configured-checkbox',
            'terms_accepted' => '1',
        ], $rules)->fails());
    }

    public function test_optional_configured_checkbox_remains_a_nullable_boolean(): void
    {
        $rules = $this->requestWithConfiguredCheckbox(false)->rules();

        $this->assertSame('nullable|boolean', $rules['terms_accepted']);
        $this->assertFalse(Validator::make([
            'service_type' => 'configured-checkbox',
        ], $rules)->fails());
        $this->assertFalse(Validator::make([
            'service_type' => 'configured-checkbox',
            'terms_accepted' => '0',
        ], $rules)->fails());
    }

    public function test_portal_required_flag_has_matching_public_checkbox_enforcement(): void
    {
        $portalEditor = file_get_contents(
            base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.ts')
        );
        $publicField = file_get_contents(
            resource_path('views/components/dynamic-form-field.blade.php')
        );

        $this->assertStringContainsString('required: field.required', $portalEditor);
        $this->assertStringContainsString("{{ \$required ? 'required' : '' }}", $publicField);
        $this->assertStringContainsString('@error($submitAs)', $publicField);
    }

    private function requestWithConfiguredCheckbox(bool $required): BookingSearchRequest
    {
        $request = new class($required) extends BookingSearchRequest {
            public function __construct(private readonly bool $fieldRequired)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'terms' => [
                        'type' => 'checkbox',
                        'label' => 'Accept Terms',
                        'required' => $this->fieldRequired,
                        'submit_as' => 'terms_accepted',
                    ],
                ], true, false];
            }
        };
        $request->initialize(['service_type' => 'configured-checkbox']);

        return $request;
    }
}
