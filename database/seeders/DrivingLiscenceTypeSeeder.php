<?php

namespace Database\Seeders;

use App\Models\DrivingLicenseType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DrivingLiscenceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Normal',
            'Heavy Vehicle',
            'Other',
        ];

        foreach ($types as $name) {
            DrivingLicenseType::firstOrCreate(['name' => $name]);
        }
    }
}
