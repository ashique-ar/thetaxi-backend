<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('vehicle_pricing_calculation_definitions');
    Schema::dropIfExists('service_types');

    Schema::create('service_types', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('code');
    });
    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('service_type_id');
        $table->string('status');
        $table->text('formula');
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('vehicle_pricing_calculation_definitions');
    Schema::dropIfExists('service_types');
});

it('floors existing hourly package overage formulas without changing unrelated formulas', function (): void {
    DB::table('service_types')->insert([
        ['id' => 'hourly-service', 'code' => 'hourly_package'],
        ['id' => 'other-service', 'code' => 'point_to_point'],
    ]);
    $unsafeFormula = 'Hourly_Package + ((total_distance - Minimum_KM) * extra_km_rate)'
        . ' + ((duration_hours - Minimum_Hours) * extra_hour_rate)';
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        [
            'id' => 'hourly-definition',
            'service_type_id' => 'hourly-service',
            'status' => 'active',
            'formula' => $unsafeFormula,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 'other-definition',
            'service_type_id' => 'other-service',
            'status' => 'active',
            'formula' => $unsafeFormula,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    hourlyPackageOverageMigration()->up();

    expect(DB::table('vehicle_pricing_calculation_definitions')->where('id', 'hourly-definition')->value('formula'))
        ->toBe('Hourly_Package + (max(0, total_distance - Minimum_KM) * extra_km_rate)'
            . ' + (max(0, duration_hours - Minimum_Hours) * extra_hour_rate)')
        ->and(DB::table('vehicle_pricing_calculation_definitions')->where('id', 'other-definition')->value('formula'))
        ->toBe($unsafeFormula);
});

function hourlyPackageOverageMigration(): Migration
{
    return require base_path('database/migrations/2026_07_17_120000_floor_hourly_package_overages.php');
}
