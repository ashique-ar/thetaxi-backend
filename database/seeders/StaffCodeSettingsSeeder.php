<?php

namespace Database\Seeders;

use App\Models\BusinessNumberSequence;
use App\Models\BusinessSetting;
use App\Models\Staff;
use Illuminate\Database\Seeder;

class StaffCodeSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $next = Staff::withTrashed()->pluck('code')->reduce(
            fn (int $max, ?string $code) => preg_match('/^STF(\d+)$/', (string) $code, $match) ? max($max, (int) $match[1] + 1) : $max,
            1
        );
        foreach (['prefix' => 'STF', 'suffix' => '', 'digits' => '6', 'start_number' => (string) $next] as $key => $value) {
            BusinessSetting::firstOrCreate(['type' => "staff_code_{$key}"], ['value' => $value]);
        }
        BusinessNumberSequence::firstOrCreate(['entity' => 'staff'], ['next_number' => $next]);
    }
}
