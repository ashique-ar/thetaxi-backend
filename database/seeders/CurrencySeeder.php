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
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'country_code' => 'LK', 'exrate' => '1.0000'], // Base currency
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'country_code' => 'US', 'exrate' => '0.0030'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'country_code' => 'GB', 'exrate' => '0.0024'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'country_code' => 'EU', 'exrate' => '0.0028'],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'country_code' => 'IN', 'exrate' => '0.25'],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'country_code' => 'AU', 'exrate' => '0.0045'],
        ];

        foreach ($currencies as $data) {
            $country = Country::where('code', $data['country_code'])->first();
            Currency::updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'symbol' => $data['symbol'],
                    'exrate' => $data['exrate'],
                    'country_id' => $country ? $country->id : null,
                ]
            );
        }
    }
}
