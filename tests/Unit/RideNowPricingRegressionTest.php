<?php

namespace Tests\Unit;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Services\BookingLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class RideNowPricingRegressionTest extends TestCase
{
    public function test_final_pricing_selects_completed_driver_telemetry_over_duplicates(): void
    {
        Schema::create('driver_assignments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('booking_id');
            $table->string('booking_item_id');
            $table->timestamp('trip_started_at')->nullable();
            $table->timestamp('trip_completed_at')->nullable();
            $table->decimal('total_distance_km')->nullable();
            $table->integer('total_waiting_time_seconds')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $trip = [
            'id' => 'driver-trip', 'booking_id' => 'booking', 'booking_item_id' => 'item',
            'trip_started_at' => '2026-09-12 06:03:44',
            'trip_completed_at' => '2026-09-12 06:26:41',
            'total_distance_km' => 5.95, 'total_waiting_time_seconds' => 14,
            'updated_at' => '2026-09-12 06:26:41',
        ];
        DB::table('driver_assignments')->insert([
            $trip,
            array_replace($trip, ['id' => 'unfinished', 'trip_started_at' => null,
                'trip_completed_at' => null, 'total_distance_km' => null,
                'total_waiting_time_seconds' => null, 'updated_at' => '2026-09-12 06:28:00']),
            array_replace($trip, ['id' => 'cleanup-duplicate', 'trip_started_at' => null,
                'total_waiting_time_seconds' => null, 'updated_at' => '2026-09-12 06:27:00']),
            array_replace($trip, ['id' => 'sibling', 'booking_item_id' => 'other-item',
                'trip_completed_at' => '2026-09-12 07:00:00']),
        ]);
        $service = (new ReflectionClass(BookingLifecycleService::class))->newInstanceWithoutConstructor();
        $booking = new Booking();
        $booking->setRawAttributes(['id' => 'booking']);
        $item = new BookingItem();
        $item->setRawAttributes(['id' => 'item']);

        $selected = (new ReflectionMethod($service, 'finalPricingDriverAssignment'))
            ->invoke($service, $booking, $item);

        self::assertSame('driver-trip', $selected->id);
        self::assertSame(5.95, (float) $selected->total_distance_km);
        self::assertSame(14, $selected->total_waiting_time_seconds);
    }

    public function test_formula_migration_bills_minimum_fare_excess_distance_and_actual_waiting(): void
    {
        Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->text('formula');
            $table->text('variables');
            $table->text('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $variables = [];
        foreach (['Minimum_Fare', 'Minimum_KM', 'service_rate_per_km', 'Free_Waiting', 'Additonal_Waiting_min', 'base_rate'] as $name) {
            $variables[] = ['name' => $name, 'type' => 'common_rate', 'is_required' => true];
        }
        $variables[] = ['name' => 'total_distance', 'type' => 'distance', 'is_required' => true];
        $variables[] = ['name' => 'waiting_minutes', 'type' => 'duration', 'is_required' => false, 'default_value' => 0];
        $variables[] = ['name' => 'duration_minutes', 'type' => 'duration', 'is_required' => false];
        DB::table('vehicle_pricing_calculation_definitions')->insert([
            ['id' => 'faulty', 'name' => 'Ride Now', 'variables' => json_encode($variables),
                'formula' => '(total_distance - Minimum_KM) * service_rate_per_km + (duration_minutes - Free_Waiting)  + Additonal_Waiting_min + base_rate'],
            ['id' => 'custom', 'name' => 'Ride Now', 'variables' => json_encode($variables), 'formula' => 'Minimum_Fare'],
        ]);
        $migration = require database_path('migrations/2026_09_12_120000_correct_ride_now_waiting_formula.php');
        $migration->up();
        $migration->up();
        $saved = DB::table('vehicle_pricing_calculation_definitions')->where('id', 'faulty')->first();
        $savedVariables = json_decode($saved->variables, true);
        self::assertSame([], VehiclePricingCalculationDefinition::validateFormulaConfiguration($saved->formula, $savedVariables));
        self::assertTrue(collect($savedVariables)->firstWhere('name', 'waiting_minutes')['is_required']);
        self::assertSame('Minimum_Fare', DB::table('vehicle_pricing_calculation_definitions')->where('id', 'custom')->value('formula'));

        $evaluate = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
        // Illustrative configured rates, not BK001667's missing corporate tariff.
        $inputs = ['Minimum_Fare' => 1400, 'Minimum_KM' => 10, 'service_rate_per_km' => 140,
            'Free_Waiting' => 10, 'Additonal_Waiting_min' => 10, 'total_distance' => 5.95, 'waiting_minutes' => 1];
        self::assertSame(1400.0, $evaluate->invoke(new VehiclePricingCalculationDefinition(), $saved->formula, $inputs));
        self::assertSame(1710.0, $evaluate->invoke(new VehiclePricingCalculationDefinition(), $saved->formula,
            array_replace($inputs, ['total_distance' => 12, 'waiting_minutes' => 13])));

        Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_type_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('booking_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('customizations');
            $table->text('pricing_breakdown');
        });
        Schema::create('booking_variable_customizations', function (Blueprint $table) {
            $table->string('variable_name');
        });
        $codes = ['Minimum_Fare' => 'minimum_fare', 'Minimum_KM' => 'minimum_km',
            'Free_Waiting' => 'free_waiting_minutes', 'Additonal_Waiting_min' => 'additional_waiting_rate_per_minute'];
        foreach ($codes as $old => $new) {
            DB::table('vehicle_pricing_common_rate_definitions')->insert(['id' => $old, 'code' => $old, 'name' => $old]);
        }
        DB::table('booking_items')->insert(['id' => 'item',
            'customizations' => '[{"variable_name":"Minimum_Fare","custom_value":1200}]',
            'pricing_breakdown' => '{"Minimum_Fare":1400}']);
        DB::table('booking_variable_customizations')->insert(['variable_name' => 'Minimum_Fare']);
        DB::table('vehicle_pricing_calculation_definitions')->where('id', 'custom')->update([
            'formula' => '{Minimum_Fare} + Minimum_KM_bonus',
            'conditions' => '[{"field":"Minimum_KM","operator":"greater_than","value":0}]',
        ]);
        $rename = require database_path('migrations/2026_09_12_130000_standardize_pricing_variable_codes.php');
        $rename->up();
        $rename->up();
        $renamed = DB::table('vehicle_pricing_calculation_definitions')->where('id', 'faulty')->first();
        $renamedInputs = [];
        foreach ($inputs as $code => $value) {
            $renamedInputs[$codes[$code] ?? $code] = $value;
        }
        self::assertSame(1400.0, $evaluate->invoke(new VehiclePricingCalculationDefinition(), $renamed->formula, $renamedInputs));
        self::assertSame([], VehiclePricingCalculationDefinition::validateFormulaConfiguration($renamed->formula, json_decode($renamed->variables, true)));
        foreach ($codes as $old => $new) {
            self::assertSame($new, DB::table('vehicle_pricing_common_rate_definitions')->where('id', $old)->value('code'));
        }
        self::assertSame('{minimum_fare} + Minimum_KM_bonus', DB::table('vehicle_pricing_calculation_definitions')->where('id', 'custom')->value('formula'));
        self::assertStringContainsString('"field":"minimum_km"', DB::table('vehicle_pricing_calculation_definitions')->where('id', 'custom')->value('conditions'));
        self::assertSame('minimum_fare', DB::table('booking_variable_customizations')->value('variable_name'));
        self::assertStringContainsString('"variable_name":"minimum_fare"', DB::table('booking_items')->value('customizations'));
        self::assertSame('{"Minimum_Fare":1400}', DB::table('booking_items')->value('pricing_breakdown'));
    }
}
