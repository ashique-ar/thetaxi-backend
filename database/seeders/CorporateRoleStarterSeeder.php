<?php

namespace Database\Seeders;

use App\Models\Corporate\Corporate;
use App\Services\CorporateRoleStarterService;
use Illuminate\Database\Seeder;

class CorporateRoleStarterSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(CorporateRoleStarterService::class);
        Corporate::query()->orderBy('id')->chunkById(100, function ($corporates) use ($service) {
            $corporates->each(fn (Corporate $corporate) => $service->provision($corporate));
        });
    }
}
