<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Company;
use Illuminate\Support\Str;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            [
                'id' => Str::uuid(),
                'name' => 'Casons Rent A Car - Head Office',
                'address' => '181, Gothami Gardens, Gothami Road, Rajagiriya, Sri Lanka.',
                'latitude' => 6.9186278,
                'longitude' => 79.8854714,
                'is_default' => true,
                'city' => 'Colombo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'Casons Rent A Car - Mattale Airport Branch',
                'address' => 'Mattala Rajapaksa International Airport',
                'latitude' => 6.2913906,
                'longitude' => 81.1213571,
                'is_default' => false,
                'city' => 'Kandy',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'Casons Rent A Car - BIA Branch',
                'address' => 'Colombo Bandaranaike International Airport',
                'latitude' => 7.1801596,
                'longitude' => 79.8816746,
                'is_default' => false,
                'city' => 'Galle',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
        Company::truncate();
        foreach ($companies as $company) {
            Company::updateOrCreate(
                ['name' => $company['name']],
                $company
            );
        }

        $this->command->info('Companies seeded successfully!');
    }
}
