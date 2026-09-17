<?php

namespace Database\Seeders;

use App\Models\BusinessNumberSequence;
use App\Models\BusinessSetting;
use App\Models\Driver\Driver;
use Illuminate\Database\Seeder;

class DriverCodeSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $next = Driver::withTrashed()->pluck('code')->reduce(
            fn (int $max, ?string $code) => preg_match('/^DRV(\d+)$/', (string) $code, $match) ? max($max, (int) $match[1] + 1) : $max,
            1
        );
        foreach (['prefix' => 'DRV', 'suffix' => '', 'digits' => '6', 'start_number' => (string) $next] as $key => $value) {
            BusinessSetting::firstOrCreate(['type' => "driver_code_{$key}"], ['value' => $value]);
        }
        BusinessNumberSequence::firstOrCreate(['entity' => 'driver'], ['next_number' => $next]);
    }
}
