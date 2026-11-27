<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TourHighlightCategorySeeder extends Seeder
{
    private const CATEGORIES = [
        'colombo' => 'City & Airport',
        'negombo' => 'City & Airport',
        'katunayake' => 'City & Airport',
        'kandy' => 'Hill Country & Tea',
        'dambulla' => 'Cultural Triangle',
        'sigiriya' => 'Cultural Triangle',
        'anuradhapura' => 'Cultural Triangle',
        'polonnaruwa' => 'Cultural Triangle',
        'minneriya' => 'Wildlife & Safari',
        'trincomalee' => 'East Coast & Beaches',
        'nuwara_eliya' => 'Hill Country & Tea',
        'ella' => 'Hill Country & Tea',
        'galle' => 'Southern Coast & Beaches',
        'bentota' => 'Southern Coast & Beaches',
        'mirissa' => 'Southern Coast & Beaches',
        'yala' => 'Wildlife & Safari',
        'arugam_bay' => 'East Coast & Beaches',
        'jaffna' => 'Northern Heritage & Islands',
        'kalpitiya' => 'Nature & Adventure',
        'hikkaduwa' => 'Southern Coast & Beaches',
        'adams_peak' => 'Hill Country & Tea',
        'bundala' => 'Wildlife & Safari',
        'batticaloa' => 'East Coast & Beaches',
        'beruwala' => 'Southern Coast & Beaches',
        'hambantota' => 'Southern Coast & Beaches',
        'horton_plains' => 'Hill Country & Tea',
        'kalkudah' => 'East Coast & Beaches',
        'kitulgala' => 'Nature & Adventure',
        'knuckles' => 'Hill Country & Tea',
        'mannar' => 'Northern Heritage & Islands',
        'matara' => 'Southern Coast & Beaches',
        'nilaveli' => 'East Coast & Beaches',
        'pasikudah' => 'East Coast & Beaches',
        'pinnawela' => 'Wildlife & Safari',
        'puttalam' => 'Nature & Adventure',
        'ratnapura' => 'Hill Country & Tea',
        'sinharaja' => 'Nature & Adventure',
        'unawatuna' => 'Southern Coast & Beaches',
        'weligama' => 'Southern Coast & Beaches',
        'wilpattu' => 'Wildlife & Safari',
    ];

    public function run(): void
    {
        $serviceId = DB::table('service_types')->where('code', 'tours')->value('id');
        if (!$serviceId) {
            throw new RuntimeException('Seed the Tours service before assigning highlight categories.');
        }

        $matched = 0;
        foreach (self::CATEGORIES as $code => $category) {
            $destination = DB::table('tour_destinations')
                ->where('service_type_id', $serviceId)
                ->where('code', $code)
                ->whereNull('deleted_at');
            if (!$destination->exists()) {
                continue;
            }
            $destination->update(['category' => $category, 'updated_at' => now()]);
            $matched++;
        }

        if ($matched === 0) {
            throw new RuntimeException('No existing Tour destinations matched the category list. Existing seeded rows were left unchanged.');
        }
    }
}
