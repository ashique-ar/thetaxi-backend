<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use AllPermissionsSeeder. Kept for deployment compatibility.
 */
class AdditionalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AllPermissionsSeeder::class);
    }
}
