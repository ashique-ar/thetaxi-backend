<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\Country;

class CountriesFromJsonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Path to the countries.json file from project root
        $jsonPath = base_path('./database/seeders/countries.json');

        // Check if file exists
        if (!File::exists($jsonPath)) {
            $this->command->error("Countries JSON file not found at: {$jsonPath}");
            return;
        }

        // Read and decode JSON file
        $jsonContent = File::get($jsonPath);
        $countries = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Invalid JSON file: ' . json_last_error_msg());
            return;
        }

        $this->command->info('Starting to seed countries from JSON...');

        $created = 0;
        $skipped = 0;

        foreach ($countries as $countryData) {
            // Map JSON data to Country model fields
            $countryAttributes = [
                'name' => $countryData['name'] ?? null,
                'code' => $countryData['iso2'] ?? null,
                'code3' => $countryData['iso3'] ?? null,
                'callcode' => $countryData['phonecode'] ?? null,
            ];

            // Build description from additional data
            $descriptionParts = [];
            if (!empty($countryData['capital'])) {
                $descriptionParts[] = "Capital: {$countryData['capital']}";
            }
            if (!empty($countryData['region'])) {
                $descriptionParts[] = "Region: {$countryData['region']}";
            }
            if (!empty($countryData['subregion'])) {
                $descriptionParts[] = "Subregion: {$countryData['subregion']}";
            }
            if (!empty($countryData['population'])) {
                $descriptionParts[] = "Population: " . number_format($countryData['population']);
            }
            if (!empty($countryData['area_sq_km'])) {
                $descriptionParts[] = "Area: {$countryData['area_sq_km']} km²";
            }

            if (!empty($descriptionParts)) {
                $countryAttributes['description'] = implode(', ', $descriptionParts);
            }

            // Optional: Add URL if available
            if (!empty($countryData['wikiDataId'])) {
                $countryAttributes['url'] = "https://www.wikidata.org/wiki/{$countryData['wikiDataId']}";
            }

            // Skip if required fields are missing
            if (empty($countryAttributes['name']) || empty($countryAttributes['code'])) {
                $this->command->warn("Skipping country due to missing required data: " . json_encode($countryData));
                continue;
            }

            // Check if country already exists (skip existing)
            $existingCountry = Country::where('code', $countryAttributes['code'])
                ->orWhere('name', $countryAttributes['name'])
                ->first();

            if ($existingCountry) {
                $skipped++;
                continue;
            }

            try {
                Country::create($countryAttributes);
                $created++;
            } catch (\Exception $e) {
                $this->command->error("Failed to create country {$countryAttributes['name']}: " . $e->getMessage());
            }
        }

        $this->command->info("Countries seeding completed:");
        $this->command->info("- Created: {$created} countries");
        $this->command->info("- Skipped: {$skipped} existing countries");
    }
}