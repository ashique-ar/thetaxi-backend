<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Currency;
use App\Models\Country;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'country_code' => 'LK'],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'country_code' => 'US'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'country_code' => 'GB'],
        ];

        foreach ($currencies as $data) {
            $country = Country::where('code', $data['country_code'])->first();
            Currency::firstOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'symbol' => $data['symbol'],
                    'country_id' => $country ? $country->id : null,
                ]
            );
        }
    }
}
