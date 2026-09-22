<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropAllTables();
    Schema::create('service_types', function (Blueprint $table): void {
        $table->uuid('id')->primary(); $table->string('code'); $table->boolean('uses_dropoff_time')->default(true);
        $table->json('form_config')->nullable(); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('service_packages', function (Blueprint $table): void {
        $table->uuid('id')->primary(); $table->uuid('service_type_id'); $table->string('name'); $table->string('code')->unique();
        $table->text('description')->nullable(); $table->decimal('max_km_per_day')->nullable(); $table->decimal('max_km_per_package')->nullable();
        $table->decimal('price_multiplier')->default(1); $table->string('rate_type'); $table->integer('default_duration_hours')->default(0);
        $table->integer('default_duration_minutes')->default(0); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0);
        $table->timestamps(); $table->softDeletes();
    });
    Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
        $table->uuid('id')->primary(); $table->uuid('service_type_id'); $table->string('name'); $table->string('type')->nullable();
        $table->integer('min_minutes')->nullable(); $table->integer('max_minutes')->nullable(); $table->integer('min_hours')->nullable();
        $table->integer('max_hours')->nullable(); $table->integer('min_days')->nullable(); $table->integer('max_days')->nullable();
        $table->decimal('max_km_per_day')->nullable(); $table->decimal('max_km_per_package')->nullable(); $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true); $table->string('owner_type')->nullable(); $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0); $table->uuid('created_user_id')->nullable(); $table->uuid('updated_user_id')->nullable();
        $table->timestamps(); $table->softDeletes();
    });
    Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
        $table->uuid('id')->primary(); $table->uuid('service_type_id')->nullable(); $table->string('code'); $table->string('name');
        $table->text('description')->nullable(); $table->string('common_rate_type'); $table->string('display_unit')->nullable();
        $table->boolean('is_mandatory')->default(false); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0);
        $table->string('owner_type')->nullable(); $table->uuid('owner_id')->nullable(); $table->integer('priority')->default(0);
        $table->timestamps(); $table->softDeletes();
    });
    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
        $table->uuid('id')->primary(); $table->uuid('service_type_id'); $table->string('name'); $table->text('description')->nullable();
        $table->text('formula'); $table->json('variables')->nullable(); $table->json('conditions')->nullable(); $table->string('status');
        $table->string('owner_type')->nullable(); $table->uuid('owner_id')->nullable(); $table->integer('priority')->default(0);
        $table->uuid('created_by'); $table->uuid('updated_by')->nullable(); $table->timestamps(); $table->softDeletes();
    });

    DB::table('service_types')->insert([
        'id' => '10000000-0000-4000-8000-000000000001', 'code' => 'hourly_package',
        'uses_dropoff_time' => true, 'form_config' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '20000000-0000-4000-8000-000000000001', 'service_type_id' => '10000000-0000-4000-8000-000000000001',
        'name' => 'Hourly Package', 'formula' => '1', 'status' => 'inactive',
        'created_by' => '30000000-0000-4000-8000-000000000001', 'priority' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('keeps hourly package money in four slabs and two shared common rates', function (): void {
    $migration = require base_path('database/migrations/2026_09_09_000003_configure_corporate_hourly_package_pricing.php');
    $migration->up();

    expect(DB::table('service_packages')->count())->toBe(4)
        ->and(DB::table('vehicle_pricing_slab_definitions')->whereNotNull('service_package_id')->where('is_active', true)->count())->toBe(4)
        ->and(DB::table('vehicle_pricing_common_rate_definitions')->where('is_active', true)->pluck('code')->sort()->values()->all())
        ->toBe(['extra_hour_rate', 'extra_km_rate'])
        ->and(DB::table('vehicle_pricing_calculation_definitions')->whereNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('vehicle_pricing_calculation_definitions')->where('name', 'Hourly Package')->value('status'))->toBe('active');

    $formula = DB::table('vehicle_pricing_calculation_definitions')->where('name', 'Hourly Package')->value('formula');
    expect($formula)->toContain('slab_rate')->toContain('extra_km_rate')->toContain('extra_hour_rate');
});

it('adds the nine hour package and linked slab without changing existing pricing', function (): void {
    (require base_path('database/migrations/2026_09_09_000003_configure_corporate_hourly_package_pricing.php'))->up();
    $existingPackages = DB::table('service_packages')->orderBy('code')->get()->toJson();
    $existingSlabs = DB::table('vehicle_pricing_slab_definitions')->orderBy('id')->get()->toJson();
    $migration = require base_path('database/migrations/2026_09_18_000001_add_nine_hour_corporate_hourly_package.php');
    $migration->up();
    $migration->up();

    $package = DB::table('service_packages')->where('code', 'hourly_9h_100km')->first();
    $slab = DB::table('vehicle_pricing_slab_definitions')->where('service_package_id', $package->id)->first();
    expect(DB::table('service_packages')->count())->toBe(5)
        ->and(DB::table('vehicle_pricing_slab_definitions')->count())->toBe(5)
        ->and((int) $package->default_duration_hours)->toBe(9)
        ->and((float) $package->max_km_per_package)->toBe(100.0)
        ->and($slab->type)->toBe('flat_rate')
        ->and((float) $slab->max_km_per_package)->toBe(100.0)
        ->and(DB::table('service_packages')->where('id', '!=', $package->id)->orderBy('code')->get()->toJson())->toBe($existingPackages)
        ->and(DB::table('vehicle_pricing_slab_definitions')->where('id', '!=', $slab->id)->orderBy('id')->get()->toJson())->toBe($existingSlabs);
});
