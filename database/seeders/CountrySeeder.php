<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['name' => 'Sri Lanka', 'code' => 'LK', 'callcode' => '94'],
            ['name' => 'United States', 'code' => 'US', 'callcode' => '1'],
            ['name' => 'United Kingdom', 'code' => 'GB', 'callcode' => '44'],
            // Add more countries as needed
        ];

        foreach ($countries as $data) {
            Country::firstOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
