<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class StaffOperationalAccessSeeder extends Seeder
{
    public function run(): void
    {
        // Deliberately do not bulk-grant installation-wide operations access to every staff record.
    }
}
