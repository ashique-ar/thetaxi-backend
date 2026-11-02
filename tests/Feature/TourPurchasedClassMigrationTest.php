<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Service\ServiceVehicleClassController;
use App\Http\Controllers\Api\Service\ServicePackageController;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Vehicle\VehicleClass;
use App\Services\Pricing\PricingContextPolicyService;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TourPurchasedClassMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['service_types', 'vehicle_classes', 'service_packages'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('vehicle_group_id');
            $table->dateTime('from_date')->nullable();
            $table->dateTime('to_date')->nullable();
        });
        Schema::create('vehicle_pricing_history', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('vehicle_group_id');
            $table->uuid('service_type_id')->nullable();
        });
        DB::table('booking_items')->insert(['id' => 'existing', 'vehicle_group_id' => 'real-group']);
        DB::table('vehicle_classes')->insert(['id' => 'standard']);
        DB::table('service_types')->insert(['id' => 'tours']);
    }

    public function test_existing_group_bookings_remain_group_priced_and_a_class_booking_needs_no_fake_group(): void
    {
        $this->migration()->up();
        self::assertSame('vehicle_group', DB::table('booking_items')->where('id', 'existing')->value('pricing_basis'));
        self::assertSame('real-group', DB::table('booking_items')->where('id', 'existing')->value('vehicle_group_id'));
        DB::table('booking_items')->insert(['id' => 'tour', 'vehicle_class_id' => 'standard', 'pricing_basis' => 'vehicle_class']);
        self::assertNull(DB::table('booking_items')->where('id', 'tour')->value('vehicle_group_id'));
        self::assertSame('standard', DB::table('booking_items')->where('id', 'tour')->value('vehicle_class_id'));
    }

    public function test_rollback_cannot_erase_a_purchased_class(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('booking_items')->insert(['id' => 'tour', 'vehicle_class_id' => 'standard', 'pricing_basis' => 'vehicle_class']);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_rollback_keeps_existing_group_bookings_when_new_class_tables_are_unused(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        self::assertSame('real-group', DB::table('booking_items')->where('id', 'existing')->value('vehicle_group_id'));
        self::assertFalse(Schema::hasColumn('booking_items', 'vehicle_class_id'));
    }

    public function test_service_class_settings_reuse_class_identity_and_keep_upgrade_rules_explicit(): void
    {
        $this->prepareClassSettings();
        DB::table('vehicle_classes')->insert(['id' => 'economy']);
        $setting = ServiceVehicleClassSetting::create([
            'service_type_id' => 'tours', 'vehicle_class_id' => 'economy', 'max_passengers' => 3, 'max_luggage' => 2,
            'allowed_upgrade_class_ids' => ['standard'],
        ]);
        self::assertSame('economy', $setting->vehicleClass->id);
        self::assertSame(['standard'], $setting->fresh()->allowed_upgrade_class_ids);
        self::assertSame(3, $setting->max_passengers);
    }

    public function test_fractional_or_zero_passenger_capacity_is_rejected_without_truncation(): void
    {
        $this->prepareClassSettings();
        foreach ([0, 1.5, -1] as $capacity) {
            try {
                ServiceVehicleClassSetting::create([
                    'service_type_id' => 'tours', 'vehicle_class_id' => 'standard', 'max_passengers' => $capacity, 'max_luggage' => 0,
                ]);
                self::fail('An invalid passenger capacity was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('max_passengers', $exception->errors());
            }
        }
    }

    public function test_purchased_class_only_allows_its_explicit_operational_substitutes(): void
    {
        $this->prepareClassSettings();
        DB::table('vehicle_classes')->insert(['id' => 'economy']);
        $setting = ServiceVehicleClassSetting::create([
            'service_type_id' => 'tours', 'vehicle_class_id' => 'standard', 'max_passengers' => 4, 'max_luggage' => 2,
            'allowed_substitute_class_ids' => ['economy'],
        ]);

        self::assertTrue($setting->allowsOperationalClass('standard'));
        self::assertTrue($setting->allowsOperationalClass('economy'));
        self::assertFalse($setting->allowsOperationalClass('unapproved'));
        self::assertFalse($setting->allowsOperationalClass(null));
    }

    public function test_staff_can_save_class_settings_and_read_them_without_relabelling_the_class_catalogue(): void
    {
        $this->prepareClassSettings();
        DB::table('vehicle_classes')->where('id', 'standard')->update(['name' => 'Standard car']);
        $settings = $this->createMock(WebsiteSettingsService::class);
        $settings->method('get')->willReturn('separate');
        $controller = new ServiceVehicleClassController(
            new PricingContextPolicyService($settings)
        );
        $service = ServiceType::findOrFail('tours');
        $class = VehicleClass::findOrFail('standard');
        $response = $controller->update(Request::create('/classes', 'PUT', [
            'max_passengers' => 3, 'max_luggage' => 2, 'is_hidden' => false, 'is_inquiry_only' => true,
        ]), $service, $class);
        self::assertSame(200, $response->getStatusCode());
        $listed = $controller->index($service)->getData(true)['data']['classes'];
        self::assertSame('Standard car', $listed[0]['name']);
        self::assertSame(3, $listed[0]['settings']['max_passengers']);
        self::assertTrue($listed[0]['settings']['is_inquiry_only']);
        self::assertSame('Standard car', $class->fresh()->name);
    }

    public function test_tour_package_class_defaults_and_upgrades_must_match_service_class_settings(): void
    {
        $this->prepareClassSettings();
        DB::table('service_types')->insert(['id' => 'taxi', 'pricing_basis' => 'vehicle_group']);
        DB::table('vehicle_classes')->insert(['id' => 'economy']);
        ServiceVehicleClassSetting::create([
            'service_type_id' => 'tours', 'vehicle_class_id' => 'standard', 'max_passengers' => 4, 'max_luggage' => 2,
            'allowed_upgrade_class_ids' => ['economy'],
        ]);
        ServiceVehicleClassSetting::create([
            'service_type_id' => 'tours', 'vehicle_class_id' => 'economy', 'max_passengers' => 3, 'max_luggage' => 1,
        ]);
        $settings = $this->createMock(WebsiteSettingsService::class);
        $controller = new ServicePackageController(new PricingContextPolicyService($settings));
        $method = new \ReflectionMethod($controller, 'assertPackageVehicleClasses');

        $method->invoke($controller, ServiceType::findOrFail('taxi'), []);
        $method->invoke($controller, ServiceType::findOrFail('tours'), [
            'default_vehicle_class_id' => 'standard', 'allowed_upgrade_class_ids' => ['economy'],
        ]);
        $this->expectException(ValidationException::class);
        $method->invoke($controller, ServiceType::findOrFail('tours'), [
            'default_vehicle_class_id' => 'economy', 'allowed_upgrade_class_ids' => ['standard'],
        ]);
    }

    private function prepareClassSettings(): void
    {
        activity()->disableLogging();
        $this->migration()->up();
        Schema::table('service_types', fn (Blueprint $table) => $table->string('pricing_basis')->default('vehicle_group'));
        DB::table('service_types')->where('id', 'tours')->update(['pricing_basis' => 'vehicle_class']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_000002_add_service_class_settings_and_purchased_booking_class.php');
    }
}
