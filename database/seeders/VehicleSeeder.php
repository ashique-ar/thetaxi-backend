<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Models\Vehicle\VehicleCategory;
use App\Models\Vehicle\VehicleClass;
use App\Models\Vehicle\VehicleGrade;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehicleTransmission;
use App\Models\Vehicle\VehicleFuelType;

class VehicleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicles...');

        // Get reference data
        $makes = VehicleMake::all()->keyBy('name');
        $models = VehicleModel::all()->keyBy('name');
        $categories = VehicleCategory::all()->keyBy('name');
        $classes = VehicleClass::all()->keyBy('name');
        $grades = VehicleGrade::all()->keyBy('name');
        $groups = VehicleGroup::all()->keyBy('name');
        $transmissions = VehicleTransmission::all()->keyBy('name');
        $fuelTypes = VehicleFuelType::all()->keyBy('name');

        $vehicles = [
            // Toyota Vehicles
            [
                'name' => 'Toyota Camry 2024',
                'registration_number' => 'CAB-2024',
                'license_plate' => 'CAB-2024',
                'vin' => 'JTMA12345678901234',
                'group' => 'Toyota Camry Standard',
                'year' => 2024,
                'color' => 'Pearl White',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            [
                'name' => 'Toyota Corolla 2023',
                'registration_number' => 'COR-2023',
                'license_plate' => 'COR-2023',
                'vin' => 'JTMA12345678901235',
                'group' => 'Toyota Axio Economy',
                'year' => 2023,
                'color' => 'Silver Metallic',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            // Honda Vehicles
            [
                'name' => 'Honda Accord 2024',
                'registration_number' => 'ACC-2024',
                'license_plate' => 'ACC-2024',
                'vin' => 'JHMA12345678901236',
                'group' => 'Honda Accord Executive',
                'year' => 2024,
                'color' => 'Obsidian Blue',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            [
                'name' => 'Honda CR-V 2023',
                'registration_number' => 'CRV-2023',
                'license_plate' => 'CRV-2023',
                'vin' => 'JHMA12345678901237',
                'group' => 'Honda CR-V Premium',
                'year' => 2023,
                'color' => 'Radiant Red',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            [
                'name' => 'Honda Fit 2023',
                'registration_number' => 'FIT-2023',
                'license_plate' => 'FIT-2023',
                'vin' => 'JHMA12345678901238',
                'group' => 'Honda Fit Hybrid',
                'year' => 2023,
                'color' => 'Crystal Blue',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            // Toyota Commercial Vehicles
            [
                'name' => 'Toyota Hiace 2022',
                'registration_number' => 'HIE-2022',
                'license_plate' => 'HIE-2022',
                'vin' => 'JTMA12345678901239',
                'group' => 'Toyota Hiace Standard',
                'year' => 2022,
                'color' => 'Super White',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            [
                'name' => 'Toyota Land Cruiser 2024',
                'registration_number' => 'LCR-2024',
                'license_plate' => 'LCR-2024',
                'vin' => 'JTMA12345678901240',
                'group' => 'Toyota Land Cruiser VX',
                'year' => 2024,
                'color' => 'Attitude Black',
                'company' => 'Casons Rent A Car - Head Office'
            ],
            // Nissan Vehicle
            [
                'name' => 'Nissan Caravan 2023',
                'registration_number' => 'CAR-2023',
                'license_plate' => 'CAR-2023',
                'vin' => 'JNMA12345678901241',
                'group' => 'Nissan Caravan Executive',
                'year' => 2023,
                'color' => 'Brilliant Silver',
                'company' => 'Casons Rent A Car - BIA Branch'
            ]
        ];

        Vehicle::truncate();
        foreach ($vehicles as $vehicleData) {
            $vehicle = Vehicle::firstOrCreate(
                ['registration_no' => $vehicleData['registration_number']],
                [
                    'title' => $vehicleData['name'],
                    'chasis_no' => $vehicleData['vin'],
                    'license_plate' => $vehicleData['license_plate'],
                    'year' => $vehicleData['year'],
                    'vehicle_group_id' => $groups[$vehicleData['group']]->id ?? null,
                    'company_id' => $companies[$vehicleData['company']]->id ?? null,
                    'color' => $vehicleData['color'],
                    'model_year' => $vehicleData['year'],
                    'status' => 'active',
                    'is_active' => true,
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle: {$vehicle->title} ({$vehicle->registration_no}) - Company: {$vehicleData['company']}");
        }
        $this->command->info('Vehicles seeding completed!');
    }
}
