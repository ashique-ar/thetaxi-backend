<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SERVICE_NAMES = [
        'corp_on_meter' => ['code' => 'on_meter', 'name' => 'On Meter'],
        'corp_ride_now' => ['code' => 'ride_now', 'name' => 'Ride Now'],
        'corp_manual_dispatch' => ['code' => 'ride_now', 'name' => 'Ride Now'],
        'corp_point_to_point' => ['code' => 'point_to_point', 'name' => 'Point to Point'],
        'corp_hourly_package' => ['code' => 'hourly_package', 'name' => 'Hourly Package'],
        'corp_tour' => ['code' => 'tour', 'name' => 'Tour'],
        'corp_special_night' => ['code' => 'special_night', 'name' => 'Special Night Transport'],
        'corp_airport_transfer' => ['code' => 'airport_transfer', 'name' => 'Airport Transfer'],
        'corp_ctc' => ['code' => 'transport_contract', 'name' => 'Transport Contract'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('service_types')) {
            return;
        }

        foreach (self::SERVICE_NAMES as $legacyCode => $replacement) {
            DB::table('service_types')
                ->where('context', 'corporate')
                ->where('code', $legacyCode)
                ->update([
                    'code' => $replacement['code'],
                    'name' => $replacement['name'],
                    'slug' => Str::slug($replacement['code']),
                    'description' => DB::raw(
                        "replace(replace(description, 'Corporate pricing', 'Pricing'), 'corporate pricing', 'pricing')"
                    ),
                    'updated_at' => now(),
                ]);
        }

        foreach (self::SERVICE_NAMES as $replacement) {
            DB::table('service_types')
                ->where('context', 'corporate')
                ->where('code', $replacement['code'])
                ->update([
                    'name' => $replacement['name'],
                    'description' => DB::raw(
                        "replace(replace(description, 'Corporate pricing', 'Pricing'), 'corporate pricing', 'pricing')"
                    ),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Names are intentionally not restored because the old terminology is deprecated.
    }
};
