<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Models\Vehicle\VehicleGrade;
use App\Models\Vehicle\VehicleTransmission;
use App\Models\Vehicle\VehicleFuelType;
use App\Models\Vehicle\VehicleCategory;
use App\Models\Vehicle\VehicleClass;
use Illuminate\Support\Str;

class VehicleGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Groups...');

        // Get existing dependent records
        $makes = VehicleMake::all();
        $models = VehicleModel::all();
        $grades = VehicleGrade::all();
        $transmissions = VehicleTransmission::all();
        $fuelTypes = VehicleFuelType::all();
        $categories = VehicleCategory::all();
        $classes = VehicleClass::all();

        if ($makes->isEmpty() || $models->isEmpty() || $grades->isEmpty()) {
            $this->command->warn('Required dependent data not found. Please run make, model, and grade seeders first.');
            return;
        }

        $vehicleGroups = [
            // Sedan Groups
            [
                'name' => 'Toyota Camry Standard',
                'description' => 'Standard Toyota Camry vehicles for business travel',
                'make_name' => 'Toyota',
                'model_name' => 'Camry',
                'grade_name' => 'Grade 2',
                'category_name' => 'Sedan',
                'class_name' => 'Mid-size',
                'transmission_name' => 'Automatic',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '2.5L',
                    'power' => '203hp',
                    'torque' => '247Nm',
                    'top_speed' => '210km/h',
                    'acceleration' => '8.4s (0-100)',
                    'fuel_consumption' => '7.8L/100km',
                    'seating_capacity' => 5,
                    'luggage_capacity' => '524L',
                    'safety_rating' => '5 Star NCAP',
                    'features' => ['ABS', 'EBD', 'Airbags', 'Climate Control', 'Cruise Control']
                ],
                'images' => [
                    'main' => '/images/vehicles/camry-main.jpg',
                    'gallery' => [
                        '/images/vehicles/camry-interior.jpg',
                        '/images/vehicles/camry-exterior.jpg',
                        '/images/vehicles/camry-engine.jpg'
                    ]
                ]
            ],
            [
                'name' => 'Honda Accord Executive',
                'description' => 'Executive class Honda Accord for premium travel',
                'make_name' => 'Honda',
                'model_name' => 'Accord',
                'grade_name' => 'Grade 1',
                'category_name' => 'Sedan',
                'class_name' => 'Mid-size',
                'transmission_name' => 'Automatic',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '2.0L Turbo',
                    'power' => '252hp',
                    'torque' => '370Nm',
                    'top_speed' => '225km/h',
                    'acceleration' => '7.1s (0-100)',
                    'fuel_consumption' => '7.2L/100km',
                    'seating_capacity' => 5,
                    'luggage_capacity' => '473L',
                    'safety_rating' => '5 Star NCAP',
                    'features' => ['Honda Sensing', 'Leather Interior', 'Sunroof', 'Premium Audio']
                ]
            ],

            // SUV Groups
            [
                'name' => 'Toyota Land Cruiser VX',
                'description' => 'Premium Toyota Land Cruiser for luxury and adventure travel',
                'make_name' => 'Toyota',
                'model_name' => 'Land Cruiser',
                'grade_name' => 'Grade 1',
                'category_name' => 'SUV',
                'class_name' => 'Full-size',
                'transmission_name' => 'Automatic',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '4.6L V8',
                    'power' => '310hp',
                    'torque' => '439Nm',
                    'top_speed' => '210km/h',
                    'acceleration' => '9.2s (0-100)',
                    'fuel_consumption' => '13.4L/100km',
                    'seating_capacity' => 8,
                    'luggage_capacity' => '621L',
                    'ground_clearance' => '225mm',
                    'towing_capacity' => '3500kg',
                    'features' => ['4WD', 'Hill Start Assist', 'Multi-terrain Select', 'Crawl Control']
                ]
            ],
            [
                'name' => 'Honda CR-V Premium',
                'description' => 'Honda CR-V for comfortable family and business travel',
                'make_name' => 'Honda',
                'model_name' => 'CR-V',
                'grade_name' => 'Grade 2',
                'category_name' => 'SUV',
                'class_name' => 'Compact',
                'transmission_name' => 'Automatic',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '1.5L Turbo',
                    'power' => '190hp',
                    'torque' => '240Nm',
                    'top_speed' => '200km/h',
                    'acceleration' => '9.7s (0-100)',
                    'fuel_consumption' => '6.9L/100km',
                    'seating_capacity' => 5,
                    'luggage_capacity' => '561L',
                    'ground_clearance' => '198mm',
                    'features' => ['Real Time AWD', 'Honda Sensing', 'Panoramic Sunroof']
                ]
            ],

            // Van Groups
            [
                'name' => 'Toyota Hiace Standard',
                'description' => 'Toyota Hiace for group transportation and cargo',
                'make_name' => 'Toyota',
                'model_name' => 'Hiace',
                'grade_name' => 'Grade 3',
                'category_name' => 'Van',
                'class_name' => 'Commercial',
                'transmission_name' => 'Manual',
                'fuel_type_name' => 'Diesel',
                'specs' => [
                    'engine_capacity' => '2.8L Diesel',
                    'power' => '130hp',
                    'torque' => '300Nm',
                    'top_speed' => '160km/h',
                    'fuel_consumption' => '8.5L/100km',
                    'seating_capacity' => 15,
                    'cargo_capacity' => '6.0m³',
                    'payload' => '1350kg',
                    'features' => ['Power Steering', 'Air Conditioning', 'ABS']
                ]
            ],
            [
                'name' => 'Nissan Caravan Executive',
                'description' => 'Nissan Caravan for executive group transportation',
                'make_name' => 'Nissan',
                'model_name' => 'Caravan',
                'grade_name' => 'Grade 1',
                'category_name' => 'Van',
                'class_name' => 'Commercial',
                'transmission_name' => 'Automatic',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '2.5L',
                    'power' => '147hp',
                    'torque' => '241Nm',
                    'top_speed' => '170km/h',
                    'fuel_consumption' => '9.2L/100km',
                    'seating_capacity' => 12,
                    'cargo_capacity' => '5.2m³',
                    'features' => ['Captain Chairs', 'Climate Control', 'Entertainment System']
                ]
            ],

            // Economy Groups
            [
                'name' => 'Toyota Axio Economy',
                'description' => 'Economic Toyota Axio for budget-friendly travel',
                'make_name' => 'Toyota',
                'model_name' => 'Axio',
                'grade_name' => 'Grade 3',
                'category_name' => 'Sedan',
                'class_name' => 'Compact',
                'transmission_name' => 'Manual',
                'fuel_type_name' => 'Petrol',
                'specs' => [
                    'engine_capacity' => '1.5L',
                    'power' => '109hp',
                    'torque' => '141Nm',
                    'top_speed' => '180km/h',
                    'acceleration' => '11.8s (0-100)',
                    'fuel_consumption' => '5.9L/100km',
                    'seating_capacity' => 5,
                    'luggage_capacity' => '436L',
                    'features' => ['Dual Airbags', 'ABS', 'Power Steering']
                ]
            ],
            [
                'name' => 'Honda Fit Hybrid',
                'description' => 'Eco-friendly Honda Fit Hybrid for city travel',
                'make_name' => 'Honda',
                'model_name' => 'Fit',
                'grade_name' => 'Grade 2',
                'category_name' => 'Hatchback',
                'class_name' => 'Compact',
                'transmission_name' => 'CVT',
                'fuel_type_name' => 'Hybrid',
                'specs' => [
                    'engine_capacity' => '1.5L Hybrid',
                    'power' => '109hp (combined)',
                    'fuel_consumption' => '3.9L/100km',
                    'seating_capacity' => 5,
                    'luggage_capacity' => '363L',
                    'features' => ['Honda Sensing', 'Eco Mode', 'Smart Key']
                ]
            ]
        ];

        foreach ($vehicleGroups as $groupData) {
            // Find the related records
            $make = $makes->where('name', $groupData['make_name'])->first();
            $model = $models->where('name', $groupData['model_name'])->first();
            $grade = $grades->where('name', $groupData['grade_name'])->first();
            $category = $categories->where('name', $groupData['category_name'])->first();
            $class = $classes->where('name', $groupData['class_name'])->first();
            $transmission = $transmissions->where('name', $groupData['transmission_name'])->first();
            $fuelType = $fuelTypes->where('name', $groupData['fuel_type_name'])->first();

            // Skip if required related records not found
            if (!$make || !$model || !$grade) {
                $this->command->warn("Skipping {$groupData['name']} - missing required relations");
                continue;
            }

            $vehicleGroup = VehicleGroup::create([
                'name' => $groupData['name'],
                'description' => $groupData['description'],
                'make_id' => $make->id,
                'model_id' => $model->id,
                'grade_id' => $grade->id,
                'category_id' => $category?->id,
                'class_id' => $class?->id,
                'transmission_id' => $transmission?->id,
                'fuel_type_id' => $fuelType?->id,
                'specs' => $groupData['specs'] ?? null,
                'images' => $groupData['images'] ?? null,
                'created_user_id' => null, // Will be set by system
                'updated_user_id' => null,
            ]);

            $this->command->info("Created vehicle group: {$vehicleGroup->name}");
        }

        $this->command->info('Vehicle Groups seeding completed!');
    }
}
