<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Backward-compatible entry point for existing deployment scripts.
     */
    public function run(): void
    {
        $this->call(AllPermissionsSeeder::class);
    }
}
