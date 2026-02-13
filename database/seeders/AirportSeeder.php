<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AirportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $airports = [
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Bandaranaike International Airport (BIA)',
                'code' => 'CMB',
                'city' => 'Colombo',
                'country' => 'Sri Lanka',
                'latitude' => 7.180756,
                'longitude' => 79.884117,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
                'description' => 'Main international airport serving Colombo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Mattala Rajapaksa International Airport',
                'code' => 'HRI',
                'city' => 'Hambantota',
                'country' => 'Sri Lanka',
                'latitude' => 6.284467,
                'longitude' => 81.124128,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 3,
                'description' => 'International airport in Hambantota',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Jaffna International Airport',
                'code' => 'JAF',
                'city' => 'Jaffna',
                'country' => 'Sri Lanka',
                'latitude' => 9.792333,
                'longitude' => 80.070097,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 4,
                'description' => 'International airport in Jaffna',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('airports')->insert($airports);
    }
}
