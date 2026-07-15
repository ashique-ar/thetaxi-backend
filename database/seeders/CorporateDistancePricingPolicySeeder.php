<?php

namespace Database\Seeders;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class CorporateDistancePricingPolicySeeder extends Seeder
{
    public function run(): void
    {
        $corporateId = env('CORPORATE_DISTANCE_POLICY_PILOT_ID');
        if (! $corporateId) {
            $this->command?->warn('Skipped: CORPORATE_DISTANCE_POLICY_PILOT_ID is not configured.');

            return;
        }

        $corporate = Corporate::findOrFail($corporateId);
        $address = env('CORPORATE_DISTANCE_POLICY_ORIGIN_ADDRESS');
        $latitude = env('CORPORATE_DISTANCE_POLICY_ORIGIN_LATITUDE');
        $longitude = env('CORPORATE_DISTANCE_POLICY_ORIGIN_LONGITUDE');

        if (! $address || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new RuntimeException('Pilot origin address, latitude, and longitude are required.');
        }

        $policy = CorporateDistancePricingPolicy::firstOrNew([
            'corporate_id' => $corporate->id,
            'is_default' => true,
        ]);
        $policy->fill([
            'name' => 'Pilot defined-location distance policy',
            'default_service_mode' => 'disabled',
            'origin_address' => $address,
            'origin_latitude' => $latitude,
            'origin_longitude' => $longitude,
            'include_origin_to_pickup' => true,
            'include_dropoff_to_return' => true,
            'movement_rate_method' => 'normal_rate',
            'is_active' => true,
        ]);
        $policy->id ??= (string) Str::uuid();
        $policy->save();

        $serviceCode = env('CORPORATE_DISTANCE_POLICY_PILOT_SERVICE_CODE');
        if ($serviceCode) {
            $service = $corporate->serviceTypes()->where('service_types.code', $serviceCode)->firstOrFail();
            $override = CorporateServiceDistancePolicy::firstOrNew([
                'corporate_id' => $corporate->id,
                'service_type_id' => $service->id,
            ]);
            $override->fill([
                'policy_id' => $policy->id,
                'application_mode' => 'disabled',
                'is_active' => true,
            ]);
            $override->id ??= (string) Str::uuid();
            $override->save();
        }

        $this->command?->info('Created or updated a disabled pilot distance policy. Enable it only after preview verification.');
    }
}
