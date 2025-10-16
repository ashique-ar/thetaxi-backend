<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VipType;

class VipTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Regular',
            'Silver',
            'Gold',
            'Platinum',
        ];

        foreach ($types as $name) {
            VipType::firstOrCreate(['name' => $name]);
        }
    }
}
