<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ON_METER_SERVICE_ID = '060f1843-ca8c-450d-952f-001512d7266c';
    private const ON_METER_DEFINITION_ID = '16627381-205a-5ac2-8c6c-8d655f695a97';
    private const FREE_WAITING_ID = '01a00433-30a4-7283-9280-ee63656e9f63';
    private const WAITING_CHARGE_ID = 'a2bdc792-f189-470c-bd4e-4982df20689d';
    private const HIRE_WAITING_CHARGE_ID = '7b90c5eb-1508-53cf-b0b9-f92ff8bfa9ba';

    public function up(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('pickup_waiting_time_seconds')->default(0)->after('total_waiting_time_seconds');
            $table->unsignedInteger('hire_waiting_time_seconds')->default(0)->after('pickup_waiting_time_seconds');
        });

        DB::table('vehicle_pricing_common_rate_definitions')->where('id', self::FREE_WAITING_ID)->update([
            'name' => 'Pickup Free Waiting Minutes',
            'code' => 'pickup_free_waiting_minutes',
            'description' => 'Free waiting allowed after pickup arrival and before the passenger boards',
            'display_unit' => 'min',
            'updated_at' => now(),
        ]);
        DB::table('vehicle_pricing_common_rate_definitions')->where('id', self::WAITING_CHARGE_ID)->update([
            'name' => 'Pickup Waiting Charge Per Minute',
            'code' => 'pickup_waiting_charge_per_minute',
            'description' => 'Charge for pickup waiting exceeding the free pickup allowance',
            'display_unit' => 'LKR/min',
            'updated_at' => now(),
        ]);

        DB::table('vehicle_pricing_common_rate_definitions')->updateOrInsert(
            ['id' => self::HIRE_WAITING_CHARGE_ID],
            [
                'service_type_id' => self::ON_METER_SERVICE_ID,
                'vehicle_group_id' => null,
                'name' => 'Hire Waiting Charge Per Minute',
                'code' => 'hire_waiting_charge_per_minute',
                'description' => 'Charge for stationary waiting after the passenger boards',
                'common_rate_type' => 'per_minute',
                'display_unit' => 'LKR/min',
                'owner_type' => null,
                'owner_id' => null,
                'priority' => 200,
                'is_mandatory' => true,
                'is_active' => true,
                'sort_order' => 60,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $existingRates = DB::table('vehicle_group_common_rate_pricing')
            ->where('common_rate_definition_id', self::WAITING_CHARGE_ID)
            ->whereNull('deleted_at')
            ->get();
        foreach ($existingRates as $rate) {
            $attributes = [
                'common_rate_definition_id' => self::HIRE_WAITING_CHARGE_ID,
                'vehicle_group_id' => $rate->vehicle_group_id,
                'owner_type' => $rate->owner_type,
                'owner_id' => $rate->owner_id,
            ];
            if (!DB::table('vehicle_group_common_rate_pricing')->where($attributes)->whereNull('deleted_at')->exists()) {
                DB::table('vehicle_group_common_rate_pricing')->insert(array_merge($attributes, [
                    'id' => (string) Str::uuid(),
                    'value' => $rate->value,
                    'priority' => $rate->priority,
                    'is_active' => $rate->is_active,
                    'created_user_id' => $rate->created_user_id,
                    'updated_user_id' => $rate->updated_user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        DB::table('vehicle_pricing_calculation_definitions')->where('id', self::ON_METER_DEFINITION_ID)->update([
            'formula' => 'base_fare + (max(actual_distance - included_km, 0) * distance_rate_per_km) + (max(pickup_waiting_minutes - pickup_free_waiting_minutes, 0) * pickup_waiting_charge_per_minute) + (hire_waiting_minutes * hire_waiting_charge_per_minute)',
            'variables' => json_encode([
                ['name' => 'actual_distance', 'type' => 'distance', 'default_value' => null, 'is_required' => true, 'description' => 'Actual metered trip distance in kilometres'],
                ['name' => 'pickup_waiting_minutes', 'type' => 'duration', 'default_value' => 0, 'is_required' => false, 'description' => 'Waiting after pickup arrival and before passenger pickup'],
                ['name' => 'hire_waiting_minutes', 'type' => 'duration', 'default_value' => 0, 'is_required' => false, 'description' => 'Stationary waiting after passenger pickup'],
                ['name' => 'total_waiting_minutes', 'type' => 'duration', 'default_value' => 0, 'is_required' => false, 'description' => 'Total pickup and in-hire waiting'],
                ['name' => 'base_fare', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'Base fare including any contracted kilometres'],
                ['name' => 'included_km', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'Kilometres included in the base fare'],
                ['name' => 'distance_rate_per_km', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'Rate for kilometres exceeding the included amount'],
                ['name' => 'pickup_free_waiting_minutes', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'Free pickup waiting before passenger pickup'],
                ['name' => 'pickup_waiting_charge_per_minute', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'Pickup waiting charge after the free allowance'],
                ['name' => 'hire_waiting_charge_per_minute', 'type' => 'common_rate', 'default_value' => null, 'is_required' => true, 'description' => 'In-hire waiting charge from the first minute'],
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('vehicle_group_common_rate_pricing')->where('common_rate_definition_id', self::HIRE_WAITING_CHARGE_ID)->delete();
        DB::table('vehicle_pricing_common_rate_definitions')->where('id', self::HIRE_WAITING_CHARGE_ID)->delete();
        DB::table('vehicle_pricing_common_rate_definitions')->where('id', self::FREE_WAITING_ID)->update(['name' => 'Free Waiting Minutes', 'code' => 'free_waiting_minutes']);
        DB::table('vehicle_pricing_common_rate_definitions')->where('id', self::WAITING_CHARGE_ID)->update(['name' => 'Waiting Charge Per Minute', 'code' => 'waiting_charge_per_minute']);
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropColumn(['pickup_waiting_time_seconds', 'hire_waiting_time_seconds']);
        });
    }
};
