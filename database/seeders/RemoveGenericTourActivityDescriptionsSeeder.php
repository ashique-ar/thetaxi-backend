<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RemoveGenericTourActivityDescriptionsSeeder extends Seeder
{
    private const GENERIC_DESCRIPTION = 'Optional visit stop. Tickets, fees and reservations are not included.';

    public function run(): void
    {
        $updated = DB::table('tour_activities')
            ->where('description', self::GENERIC_DESCRIPTION)
            ->update(['description' => null, 'updated_at' => now()]);

        $this->command?->info("Removed the repeated default description from {$updated} Tour activities.");
    }
}
