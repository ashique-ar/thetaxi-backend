<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CorporatePricingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CorporateDynamicPricingSeeder::class);
    }
}
