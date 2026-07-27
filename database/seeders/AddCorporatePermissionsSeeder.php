<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated Use AllPermissionsSeeder. Kept for deployment compatibility.
 */
class AddCorporatePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AllPermissionsSeeder::class);
    }
}
