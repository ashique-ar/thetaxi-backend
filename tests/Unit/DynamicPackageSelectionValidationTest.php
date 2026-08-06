<?php

namespace Tests\Unit;

use App\Http\Requests\BookingSearchRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DynamicPackageSelectionValidationTest extends TestCase
{
    private const SERVICE_TYPE_ID = '00000000-0000-0000-0000-000000000100';
    private const OTHER_SERVICE_TYPE_ID = '00000000-0000-0000-0000-000000000200';
    private const ACTIVE_PACKAGE_ID = '00000000-0000-0000-0000-000000000101';
    private const INACTIVE_PACKAGE_ID = '00000000-0000-0000-0000-000000000102';
    private const OTHER_PACKAGE_ID = '00000000-0000-0000-0000-000000000201';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('service_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->boolean('is_active')->default(true);
        });

        DB::table('service_packages')->insert([
            [
                'id' => self::ACTIVE_PACKAGE_ID,
                'service_type_id' => self::SERVICE_TYPE_ID,
                'is_active' => true,
            ],
            [
                'id' => self::INACTIVE_PACKAGE_ID,
                'service_type_id' => self::SERVICE_TYPE_ID,
                'is_active' => false,
            ],
            [
                'id' => self::OTHER_PACKAGE_ID,
                'service_type_id' => self::OTHER_SERVICE_TYPE_ID,
                'is_active' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('service_packages');

        parent::tearDown();
    }

    public function test_configured_package_selector_accepts_only_active_owned_packages(): void
    {
        $rules = $this->requestWithConfiguredPackage('service_package_id')->rules();

        $this->assertFalse($this->validatorFor($rules, [
            'service_package_id' => self::ACTIVE_PACKAGE_ID,
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'service_package_id' => self::INACTIVE_PACKAGE_ID,
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'service_package_id' => self::OTHER_PACKAGE_ID,
        ])->fails());
    }

    public function test_auxiliary_package_id_cannot_bypass_the_configured_selector(): void
    {
        $rules = $this->requestWithConfiguredPackage('service_package_id')->rules();

        $this->assertFalse($this->validatorFor($rules, [
            'service_package_id' => self::ACTIVE_PACKAGE_ID,
            'package_id' => self::ACTIVE_PACKAGE_ID,
        ])->fails());
        $this->assertTrue($this->validatorFor($rules, [
            'service_package_id' => self::ACTIVE_PACKAGE_ID,
            'package_id' => self::OTHER_PACKAGE_ID,
        ])->fails());
    }

    public function test_package_id_submit_key_keeps_its_scoped_configured_rule(): void
    {
        $rules = $this->requestWithConfiguredPackage('package_id')->rules();

        $this->assertIsArray($rules['package_id']);
        $this->assertCount(3, $rules['package_id']);
        $this->assertTrue($this->validatorFor($rules, [
            'package_id' => self::INACTIVE_PACKAGE_ID,
        ])->fails());
    }

    public function test_service_without_package_selector_does_not_validate_stale_package_id(): void
    {
        $request = new class(self::SERVICE_TYPE_ID) extends BookingSearchRequest {
            public function __construct(private readonly string $serviceTypeId)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'pickup_location' => [
                        'type' => 'location',
                        'required' => true,
                        'submit_as' => 'pickup',
                    ],
                ], true, false, $this->serviceTypeId];
            }

            public function prepareForTest(): void
            {
                $this->prepareForValidation();
            }
        };
        $request->initialize([
            'service_type' => 'point_to_point',
            'pickup' => 'Galle, Sri Lanka',
            'package_id' => self::OTHER_PACKAGE_ID,
        ]);

        $request->prepareForTest();
        $rules = $request->rules();

        $this->assertFalse($request->has('package_id'));
        $this->assertArrayNotHasKey('package_id', $rules);
        $this->assertFalse($this->validatorFor($rules, [
            'pickup' => 'Galle, Sri Lanka',
            'package_id' => self::OTHER_PACKAGE_ID,
        ])->fails());
    }

    public function test_configured_selector_with_no_active_packages_discards_stale_package_id(): void
    {
        $request = new class(self::OTHER_SERVICE_TYPE_ID) extends BookingSearchRequest {
            public function __construct(private readonly string $serviceTypeId)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'pickup_location' => [
                        'type' => 'location',
                        'required' => true,
                        'submit_as' => 'pickup',
                    ],
                    'package_id' => [
                        'type' => 'package_select',
                        'required' => false,
                        'submit_as' => 'package_id',
                    ],
                ], true, false, $this->serviceTypeId];
            }

            public function prepareForTest(): void
            {
                $this->prepareForValidation();
            }
        };
        DB::table('service_packages')
            ->where('service_type_id', self::OTHER_SERVICE_TYPE_ID)
            ->update(['is_active' => false]);
        $request->initialize([
            'service_type' => 'point_to_point',
            'pickup' => 'Colombo, Sri Lanka',
            'package_id' => self::OTHER_PACKAGE_ID,
        ]);

        $request->prepareForTest();
        $rules = $request->rules();

        $this->assertFalse($request->has('package_id'));
        $this->assertArrayNotHasKey('package_id', $rules);
        $this->assertFalse(Validator::make($request->all(), $rules)->fails());
    }

    public function test_single_active_package_is_authoritative_default(): void
    {
        $request = new class(self::SERVICE_TYPE_ID) extends BookingSearchRequest {
            public function __construct(private readonly string $serviceTypeId)
            {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'package_id' => [
                        'type' => 'package_select',
                        'required' => false,
                        'submit_as' => 'package_id',
                    ],
                ], true, false, $this->serviceTypeId];
            }

            public function prepareForTest(): void
            {
                $this->prepareForValidation();
            }
        };
        $request->initialize([
            'service_type' => 'configured-package',
            'package_id' => self::OTHER_PACKAGE_ID,
        ]);

        $request->prepareForTest();

        $this->assertSame(self::ACTIVE_PACKAGE_ID, $request->input('package_id'));
        $this->assertSame(self::ACTIVE_PACKAGE_ID, $request->input('service_package_id'));
        $this->assertFalse(Validator::make($request->all(), $request->rules())->fails());
    }

    private function validatorFor(array $rules, array $input)
    {
        return Validator::make([
            'service_type' => 'configured-package',
            ...$input,
        ], $rules);
    }

    private function requestWithConfiguredPackage(string $submitAs): BookingSearchRequest
    {
        $request = new class($submitAs, self::SERVICE_TYPE_ID) extends BookingSearchRequest {
            public function __construct(
                private readonly string $packageSubmitKey,
                private readonly string $serviceTypeId
            ) {
                parent::__construct();
            }

            protected function resolveServiceFormConfig(string $serviceCode): array
            {
                return [[
                    'package' => [
                        'type' => 'package_select',
                        'label' => 'Package',
                        'required' => true,
                        'submit_as' => $this->packageSubmitKey,
                    ],
                ], true, false, $this->serviceTypeId];
            }
        };
        $request->initialize(['service_type' => 'configured-package']);

        return $request;
    }
}
