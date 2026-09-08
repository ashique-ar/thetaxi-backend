<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_types')
            ->where('code', 'day_rental')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'form_config'])
            ->each(function ($service): void {
                $config = json_decode((string) $service->form_config, true) ?: [];
                $config['trip_mode'] = 'open_package';
                $config['disable_route_preview'] = true;
                $config['disable_distance_estimate'] = true;
                if (isset($config['dropoff_location']) && is_array($config['dropoff_location'])) {
                    $config['dropoff_location']['required'] = false;
                }

                DB::table('service_types')->where('id', $service->id)->update([
                    // Day Rental still needs planned return date/time; only the
                    // destination location becomes optional.
                    'uses_dropoff_time' => true,
                    'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('service_types')
            ->where('code', 'day_rental')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'form_config'])
            ->each(function ($service): void {
                $config = json_decode((string) $service->form_config, true) ?: [];
                $config['trip_mode'] = 'fixed_route';
                $config['disable_route_preview'] = false;
                $config['disable_distance_estimate'] = false;

                DB::table('service_types')->where('id', $service->id)->update([
                    'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            });
    }
};
