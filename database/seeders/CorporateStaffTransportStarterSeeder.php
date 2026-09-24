<?php

namespace Database\Seeders;

use App\Models\Corporate\Corporate;
use App\Services\CorporateStaffTransportStarterService;
use Illuminate\Database\Seeder;

class CorporateStaffTransportStarterSeeder extends Seeder
{
    public function run(): void
    {
        $starter = app(CorporateStaffTransportStarterService::class);
        Corporate::query()->chunkById(100, fn ($corporates) => $corporates->each(fn ($corporate) => $starter->provision($corporate)));
    }
}
