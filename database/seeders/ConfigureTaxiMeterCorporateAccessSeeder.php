<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConfigureTaxiMeterCorporateAccessSeeder extends Seeder
{
    public function run(): void
    {
        $serviceType = DB::table('service_types')
            ->where('code', 'taxi_meter')
            ->where('context', 'corporate')
            ->whereNull('deleted_at')
            ->first();

        if (!$serviceType) {
            $this->command?->warn('Corporate Taxi Meter service type was not found; no changes were made.');
            return;
        }

        DB::table('service_types')->where('id', $serviceType->id)->update([
            'is_internal' => true,
            'updated_at' => now(),
        ]);

        $now = now();
        DB::table('corporates')->where('is_active', true)->whereNull('deleted_at')->get(['id'])->each(function ($corporate) use ($serviceType, $now): void {
            $assignment = DB::table('corporate_service_types')
                ->where('corporate_id', $corporate->id)
                ->where('service_type_id', $serviceType->id)
                ->whereNull('deleted_at')
                ->first();

            if ($assignment) {
                DB::table('corporate_service_types')->where('id', $assignment->id)->update([
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('corporate_service_types')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'corporate_id' => $corporate->id,
                    'service_type_id' => $serviceType->id,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);

        $this->command?->info('Taxi Meter remains available to staff in corporate workflows and is marked internal.');
    }
}
