<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        DB::table('vehicle_group_service_pricing_settings')->update([
            'show_in_internal_bookings' => true,
            'updated_at' => $now,
        ]);

        $serviceTypeIds = DB::table('service_types')->pluck('id');
        $rows = [];

        DB::table('vehicle_groups')->whereNull('deleted_at')->select('id')->orderBy('id')->chunk(500, function ($groups) use ($serviceTypeIds, $now, &$rows): void {
            foreach ($groups as $group) {
                foreach ($serviceTypeIds as $serviceTypeId) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'vehicle_group_id' => $group->id,
                        'service_type_id' => $serviceTypeId,
                        'is_inquiry_only' => false,
                        'is_hidden' => false,
                        'show_in_internal_bookings' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($rows) === 500) {
                        DB::table('vehicle_group_service_pricing_settings')->insertOrIgnore($rows);
                        $rows = [];
                    }
                }
            }
        });

        if ($rows !== []) {
            DB::table('vehicle_group_service_pricing_settings')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        DB::table('vehicle_group_service_pricing_settings')->update([
            'show_in_internal_bookings' => false,
            'updated_at' => now(),
        ]);
    }
};
