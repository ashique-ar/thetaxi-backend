<?php

namespace Tests\Feature;

use Database\Seeders\TourLaunchSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TourLaunchSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        DB::table('users')->insert(['id' => 'active-user', 'is_active' => true]);
        DB::table('service_types')->insert([
            'id' => 'day-rental', 'code' => 'day_rental', 'name' => 'Day Rental', 'context' => 'public',
            'pricing_basis' => 'vehicle_group', 'is_active' => true,
        ]);
        DB::table('vehicle_pricing_slab_definitions')->insert([
            'id' => 'day-slab', 'service_type_id' => 'day-rental', 'name' => 'One day',
            'min_days' => 1, 'max_days' => 2, 'max_km_per_day' => null, 'is_active' => true,
        ]);
        DB::table('service_packages')->insert([
            ['id' => 'day-100-km', 'service_type_id' => 'day-rental', 'name' => '100 km', 'max_km_per_day' => 100, 'is_active' => true],
            ['id' => 'day-200-km', 'service_type_id' => 'day-rental', 'name' => '200 km', 'max_km_per_day' => 200, 'is_active' => true],
        ]);
        DB::table('vehicle_pricing_common_rate_definitions')->insert([
            'id' => 'day-km-definition', 'service_type_id' => 'day-rental', 'vehicle_group_id' => null,
            'name' => 'Extra kilometer rate', 'code' => 'extra_km_rate', 'is_active' => true,
        ]);
        DB::table('vehicle_classes')->insert([
            ['id' => 'economy-class', 'name' => 'Economy'],
            ['id' => 'compact-class', 'name' => 'Compact'],
            ['id' => 'commercial-class', 'name' => 'Commercial'],
            ['id' => 'high-roof-class', 'name' => '14 Seater Van (High Roof Van'],
            ['id' => 'full-size-class', 'name' => 'Full-size'],
        ]);
        DB::table('vehicle_groups')->insert([
            ['id' => 'economy-a', 'class_id' => 'economy-class', 'name' => 'Verified sedan A', 'passengers_count' => 4, 'hand_luggages' => 2, 'is_active' => true],
            ['id' => 'economy-b', 'class_id' => 'economy-class', 'name' => 'Verified sedan B', 'passengers_count' => 4, 'hand_luggages' => 2, 'is_active' => true],
            ['id' => 'economy-too-small', 'class_id' => 'economy-class', 'name' => 'Small car', 'passengers_count' => 2, 'hand_luggages' => 2, 'is_active' => true],
            ['id' => 'high-roof', 'class_id' => 'high-roof-class', 'name' => 'Toyota KDH 14 Seater', 'passengers_count' => 14, 'hand_luggages' => 4, 'is_active' => true],
            ['id' => 'coaster', 'class_id' => 'full-size-class', 'name' => 'Toyota Coaster', 'passengers_count' => 22, 'hand_luggages' => 8, 'is_active' => true],
            ['id' => 'standard-axio', 'class_id' => 'compact-class', 'name' => 'Toyota Axio Economy', 'passengers_count' => 4, 'hand_luggages' => 2, 'is_active' => true],
            ['id' => 'flat-roof-kdh', 'class_id' => 'full-size-class', 'name' => 'Toyota KDH 200 8 Seater', 'passengers_count' => 8, 'hand_luggages' => 3, 'is_active' => true],
            ['id' => 'unknown-capacity', 'class_id' => 'full-size-class', 'name' => 'Truck', 'passengers_count' => null, 'hand_luggages' => null, 'is_active' => true],
        ]);
        DB::table('vehicle_group_pricing')->insert([
            ['id' => 'economy-rate-a', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'economy-a', 'rate' => 8000, 'rate_type' => 'per_day', 'includes_fuel' => true, 'includes_driver' => false, 'is_active' => true],
            ['id' => 'economy-rate-b', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'economy-b', 'rate' => 9000, 'rate_type' => 'per_day', 'includes_fuel' => false, 'includes_driver' => true, 'is_active' => true],
            ['id' => 'too-small-rate', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'economy-too-small', 'rate' => 5000, 'rate_type' => 'per_day', 'includes_fuel' => false, 'includes_driver' => false, 'is_active' => true],
            ['id' => 'high-roof-rate', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'high-roof', 'rate' => 24500, 'rate_type' => 'per_day', 'includes_fuel' => false, 'includes_driver' => false, 'is_active' => true],
            ['id' => 'coaster-rate', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'coaster', 'rate' => 18000, 'rate_type' => 'per_day', 'includes_fuel' => false, 'includes_driver' => false, 'is_active' => true],
            ['id' => 'standard-rate', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'standard-axio', 'rate' => 8500, 'rate_type' => 'per_day', 'includes_fuel' => true, 'includes_driver' => false, 'is_active' => true],
            ['id' => 'flat-roof-rate', 'slab_definition_id' => 'day-slab', 'vehicle_group_id' => 'flat-roof-kdh', 'rate' => 16000, 'rate_type' => 'per_day', 'includes_fuel' => false, 'includes_driver' => true, 'is_active' => true],
        ]);
        DB::table('vehicle_group_common_rate_pricing')->insert([
            ['id' => 'economy-km-a', 'vehicle_group_id' => 'economy-a', 'common_rate_definition_id' => 'day-km-definition', 'value' => 140, 'is_active' => true],
            ['id' => 'economy-km-b', 'vehicle_group_id' => 'economy-b', 'common_rate_definition_id' => 'day-km-definition', 'value' => 150, 'is_active' => true],
            ['id' => 'economy-km-small', 'vehicle_group_id' => 'economy-too-small', 'common_rate_definition_id' => 'day-km-definition', 'value' => 80, 'is_active' => true],
            ['id' => 'high-roof-km', 'vehicle_group_id' => 'high-roof', 'common_rate_definition_id' => 'day-km-definition', 'value' => 240, 'is_active' => true],
            ['id' => 'coaster-km', 'vehicle_group_id' => 'coaster', 'common_rate_definition_id' => 'day-km-definition', 'value' => 290, 'is_active' => true],
            ['id' => 'standard-km', 'vehicle_group_id' => 'standard-axio', 'common_rate_definition_id' => 'day-km-definition', 'value' => 145, 'is_active' => true],
            ['id' => 'flat-roof-km', 'vehicle_group_id' => 'flat-roof-kdh', 'common_rate_definition_id' => 'day-km-definition', 'value' => 260, 'is_active' => true],
        ]);

        (require database_path('migrations/2026_11_27_000002_add_tour_vehicle_group_mapping.php'))->up();
    }

    public function test_launch_seeder_uses_existing_pricing_and_keeps_map_and_terms_drafts_unpublished(): void
    {
        DB::table('business_settings')->insert([
            ['id' => 'tour-service-setting', 'type' => 'tour_service_type_id', 'value' => null],
            ['id' => 'tour-enabled-setting', 'type' => 'tours_enabled', 'value' => 'true'],
        ]);

        app(TourLaunchSeeder::class)->run();

        $tourId = DB::table('service_types')->where('code', 'tours')->value('id');
        self::assertNotEmpty($tourId);
        self::assertSame($tourId, DB::table('business_settings')->where('type', 'tour_service_type_id')->value('value'));
        $classSetting = DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'economy-class')->first();
        self::assertSame(['economy-a', 'economy-b'], json_decode($classSetting->eligible_vehicle_group_ids, true));
        self::assertSame(['standard-axio'], json_decode(DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'compact-class')->value('eligible_vehicle_group_ids'), true));
        self::assertSame(['flat-roof-kdh'], json_decode(DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'commercial-class')->value('eligible_vehicle_group_ids'), true));
        self::assertSame('Economy car', $classSetting->customer_display_name);
        self::assertSame(3, $classSetting->max_passengers);
        self::assertSame(3, DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'compact-class')->value('max_passengers'));
        self::assertSame(6, DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'commercial-class')->value('max_passengers'));
        self::assertSame(9, DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'high-roof-class')->value('max_passengers'));
        self::assertSame(14, DB::table('service_vehicle_class_settings')->where('service_type_id', $tourId)->where('vehicle_class_id', 'full-size-class')->value('max_passengers'));

        $tourSlabId = DB::table('vehicle_pricing_slab_definitions')->where('service_type_id', $tourId)->value('id');
        self::assertEquals(100, DB::table('vehicle_pricing_slab_definitions')->where('id', $tourSlabId)->value('max_km_per_day'));
        $classPrice = DB::table('vehicle_group_pricing')->where('slab_definition_id', $tourSlabId)->where('vehicle_class_id', 'economy-class')->first();
        self::assertEquals(8000, $classPrice->rate);
        self::assertTrue((bool) $classPrice->includes_fuel);
        self::assertFalse((bool) $classPrice->includes_driver);
        $distanceRate = DB::table('vehicle_group_common_rate_pricing')->where('vehicle_class_id', 'economy-class')->first();
        self::assertEquals(140, $distanceRate->value);

        self::assertSame(40, DB::table('tour_destinations')->where('service_type_id', $tourId)->count());
        self::assertSame(53, DB::table('tour_activities')->count());
        self::assertSame(0, DB::table('predefined_locations')->where('type', 'tour')->where('is_active', true)->count());
        self::assertSame(93, DB::table('predefined_locations')->where('type', 'tour')->whereNotNull('google_place_id')->count());
        self::assertSame(0, DB::table('tour_destinations')->where('service_type_id', $tourId)->where('is_published', true)->count());
        self::assertSame(0, DB::table('tour_activities')->where('is_published', true)->count());
        self::assertSame('ChIJA3B6D9FT4joRjYPTMk0uCzI', DB::table('predefined_locations')->where('code', 'tour_colombo')->value('google_place_id'));
        self::assertSame(0, (float) DB::table('predefined_locations')->where('code', 'tour_colombo')->value('latitude'));
        self::assertFalse((bool) DB::table('terms_and_conditions')->where('service_type_id', $tourId)->value('is_active'));
        self::assertSame('false', DB::table('business_settings')->where('type', 'tours_enabled')->value('value'));
        self::assertSame(2, DB::table('vehicle_pricing_calculation_definitions')->where('service_type_id', $tourId)->count());

        DB::table('vehicle_group_pricing')->where('slab_definition_id', $tourSlabId)->where('vehicle_class_id', 'economy-class')->update(['rate' => 9999]);
        app(TourLaunchSeeder::class)->run();
        self::assertEquals(9999, DB::table('vehicle_group_pricing')->where('slab_definition_id', $tourSlabId)->where('vehicle_class_id', 'economy-class')->value('rate'));
        self::assertSame(40, DB::table('tour_destinations')->where('service_type_id', $tourId)->count());
    }

    private function createSchema(): void
    {
        Schema::create('users', fn(Blueprint $table) => $table->string('id')->primary()->nullable(false));
        Schema::table('users', fn(Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::create('service_types', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('code')->unique(); $table->string('name');
            $table->string('context')->nullable(); $table->string('pricing_basis')->nullable(); $table->string('slug')->nullable();
            $table->string('type')->nullable(); $table->string('pricing_mode')->nullable(); $table->text('description')->nullable();
            $table->text('terms')->nullable(); $table->boolean('is_active')->default(true); $table->boolean('is_internal')->nullable();
            $table->boolean('is_inquiry')->nullable(); $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('vehicle_classes', function (Blueprint $table): void { $table->string('id')->primary(); $table->string('name'); });
        Schema::create('vehicle_groups', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('class_id'); $table->string('name');
            $table->unsignedInteger('passengers_count')->nullable(); $table->unsignedInteger('hand_luggages')->nullable();
            $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('service_type_id'); $table->string('vehicle_class_id');
            $table->unsignedSmallInteger('max_passengers'); $table->unsignedSmallInteger('max_luggage');
            $table->boolean('is_active')->default(true); $table->boolean('is_hidden')->default(false); $table->boolean('is_inquiry_only')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0); $table->text('description')->nullable();
            $table->json('allowed_upgrade_class_ids')->nullable(); $table->json('allowed_substitute_class_ids')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->unique(['service_type_id', 'vehicle_class_id']);
        });
        Schema::create('predefined_locations', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('name'); $table->string('code')->unique(); $table->string('type');
            $table->text('address')->nullable(); $table->string('city')->nullable(); $table->string('country');
            $table->decimal('latitude', 10, 6); $table->decimal('longitude', 10, 6); $table->boolean('is_active');
            $table->integer('sort_order'); $table->text('description')->nullable(); $table->json('metadata')->nullable();
            $table->string('google_place_id')->nullable(); $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('tour_destinations', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('service_type_id'); $table->string('predefined_location_id');
            $table->string('code'); $table->string('name'); $table->text('description')->nullable(); $table->string('timezone');
            $table->unsignedInteger('sort_order'); $table->boolean('is_published'); $table->string('created_user_id')->nullable();
            $table->string('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes(); $table->unique(['service_type_id', 'code']);
        });
        Schema::create('tour_activities', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('tour_destination_id'); $table->string('predefined_location_id');
            $table->string('code'); $table->string('name'); $table->text('description')->nullable();
            $table->unsignedSmallInteger('visit_duration_minutes'); $table->unsignedInteger('sort_order'); $table->boolean('is_published');
            $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->unique(['tour_destination_id', 'code']);
        });
        Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('service_type_id'); $table->string('name'); $table->string('type')->nullable();
            $table->unsignedInteger('min_days')->nullable(); $table->unsignedInteger('max_days')->nullable(); $table->unsignedInteger('max_km_per_day')->nullable();
            $table->unsignedInteger('sort_order')->default(1); $table->boolean('is_active'); $table->string('created_user_id')->nullable();
            $table->string('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('service_type_id'); $table->string('name');
            $table->decimal('max_km_per_day', 10, 2)->nullable(); $table->boolean('is_active')->default(true);
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('vehicle_group_pricing', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('slab_definition_id'); $table->string('vehicle_group_id')->nullable();
            $table->string('vehicle_class_id')->nullable(); $table->decimal('rate', 10, 2)->nullable(); $table->string('rate_type');
            $table->decimal('minimum_charge', 10, 2)->nullable(); $table->boolean('includes_fuel')->default(false);
            $table->boolean('includes_driver')->default(false); $table->boolean('is_active')->default(true);
            $table->string('owner_type')->nullable(); $table->string('owner_id')->nullable(); $table->integer('priority')->default(0);
            $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX vehicle_group_pricing_class_once ON vehicle_group_pricing (vehicle_class_id, slab_definition_id) WHERE vehicle_class_id IS NOT NULL AND deleted_at IS NULL');
        Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('name'); $table->string('code')->nullable(); $table->string('service_type_id')->nullable();
            $table->string('vehicle_group_id')->nullable(); $table->string('description')->nullable(); $table->string('common_rate_type')->default('fixed_amount');
            $table->boolean('is_mandatory')->default(false); $table->boolean('is_active')->default(true); $table->unsignedInteger('sort_order')->default(0);
            $table->string('owner_type')->nullable(); $table->string('owner_id')->nullable(); $table->integer('priority')->default(0);
            $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('vehicle_group_common_rate_pricing', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('vehicle_group_id')->nullable(); $table->string('vehicle_class_id')->nullable();
            $table->string('common_rate_definition_id'); $table->decimal('value', 10, 2)->nullable(); $table->boolean('is_active')->default(true);
            $table->string('owner_type')->nullable(); $table->string('owner_id')->nullable(); $table->integer('priority')->default(0);
            $table->string('created_user_id')->nullable(); $table->string('updated_user_id')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX vehicle_group_common_rate_class_once ON vehicle_group_common_rate_pricing (vehicle_class_id, common_rate_definition_id) WHERE vehicle_class_id IS NOT NULL AND deleted_at IS NULL');
        Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('service_type_id'); $table->string('name'); $table->text('description')->nullable();
            $table->text('formula'); $table->json('variables')->nullable(); $table->json('conditions')->nullable(); $table->string('status');
            $table->string('owner_type')->nullable(); $table->string('owner_id')->nullable(); $table->integer('priority')->default(0);
            $table->string('created_by'); $table->string('updated_by')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('terms_and_conditions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('title'); $table->string('slug')->unique(); $table->text('content');
            $table->string('service_type_id')->nullable(); $table->string('service_type')->nullable(); $table->string('payment_type')->nullable();
            $table->integer('version'); $table->boolean('is_active'); $table->timestamp('effective_date')->nullable(); $table->integer('display_order');
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->string('type')->unique(); $table->text('value')->nullable(); $table->timestamps();
        });
    }
}
