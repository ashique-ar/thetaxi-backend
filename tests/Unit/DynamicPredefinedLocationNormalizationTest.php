<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DynamicPredefinedLocationNormalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('predefined_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        DB::table('predefined_locations')->insert([
            [
                'id' => '00000000-0000-0000-0000-000000000001',
                'code' => 'cmb',
                'name' => 'Colombo Office',
                'address' => '100 Galle Road, Colombo',
                'latitude' => 6.927079,
                'longitude' => 79.861244,
                'is_active' => true,
            ],
            [
                'id' => '00000000-0000-0000-0000-000000000002',
                'code' => 'retired-office',
                'name' => 'Retired Office',
                'address' => 'Old Road',
                'latitude' => 6.900000,
                'longitude' => 79.800000,
                'is_active' => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('predefined_locations');

        parent::tearDown();
    }

    public function test_configured_location_submit_key_resolves_an_active_predefined_location(): void
    {
        $request = $this->configuredLocationRequest([
            'service_type' => 'configured-service',
            'origin_predefined' => 'cmb',
        ]);

        $request->normalizeForValidation();

        $this->assertSame('100 Galle Road, Colombo', $request->input('origin'));
        $this->assertSame('6.927079', $request->input('origin_lat'));
        $this->assertSame('79.861244', $request->input('origin_lng'));
    }

    public function test_configured_location_rule_rejects_unknown_and_inactive_codes(): void
    {
        $request = $this->configuredLocationRequest([
            'service_type' => 'configured-service',
            'origin' => 'A custom address',
        ]);
        $rules = $request->rules();

        $this->assertFalse(Validator::make([
            'service_type' => 'configured-service',
            'origin' => 'A custom address',
            'origin_predefined' => 'cmb',
        ], $rules)->fails());
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-service',
            'origin' => 'A custom address',
            'origin_predefined' => 'retired-office',
        ], $rules)->fails());
        $this->assertTrue(Validator::make([
            'service_type' => 'configured-service',
            'origin' => 'A custom address',
            'origin_predefined' => 'unknown',
        ], $rules)->fails());
    }

    public function test_portal_mode_and_public_selector_share_the_predefined_submit_contract(): void
    {
        $portalEditor = file_get_contents(
            base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form-config/service-type-form-config.component.ts')
        );
        $publicSelector = file_get_contents(
            resource_path('views/components/predefined-location-selector.blade.php')
        );

        $this->assertStringContainsString("value: 'predefined_or_custom'", $portalEditor);
        $this->assertStringContainsString('name="{{ $name }}_predefined"', $publicSelector);
    }

    private function configuredLocationRequest(array $input): BookingSearchRequest
    {
        $request = new class extends BookingSearchRequest {
            public function normalizeForValidation(): void
            {
                $this->prepareForValidation();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'origin_location' => [
                        'type' => 'location',
                        'label' => 'Origin',
                        'required' => true,
                        'submit_as' => 'origin',
                        'location_mode' => 'predefined_or_custom',
                    ],
                ], true, false];
            }
        };
        $request->initialize($input);

        return $request;
    }
}
