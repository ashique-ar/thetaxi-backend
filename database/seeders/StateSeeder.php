<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\State;
use App\Models\Country;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Example states for Sri Lanka
        $country = Country::where('code', 'LK')->first();
        if (!$country) {
            return;
        }

        $states = [
            'Western',
            'Central',
            'Southern',
            'Northern',
            'Eastern',
            'North Western',
            'North Central',
            'Uva',
            'Sabaragamuwa'
        ];

        foreach ($states as $name) {
            State::firstOrCreate(
                ['name' => $name, 'country_id' => $country->id]
            );
        }
    }
}
