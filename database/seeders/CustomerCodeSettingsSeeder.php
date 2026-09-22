<?php

namespace Database\Seeders;

use App\Models\BusinessNumberSequence;
use App\Models\BusinessSetting;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerCodeSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $next = Customer::withTrashed()->pluck('code')->reduce(
            fn (int $max, ?string $code) => preg_match('/^CUST(\d+)$/', (string) $code, $match) ? max($max, (int) $match[1] + 1) : $max,
            1
        );
        foreach (['prefix' => 'CUST', 'suffix' => '', 'digits' => '3', 'start_number' => (string) $next] as $key => $value) {
            BusinessSetting::firstOrCreate(['type' => "customer_code_{$key}"], ['value' => $value]);
        }
        BusinessNumberSequence::firstOrCreate(['entity' => 'customer'], ['next_number' => $next]);
    }
}
