<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Vehicle\VehicleGrade;

class VehicleGradeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Vehicle Grades...');

        $grades = [
            [
                'name' => 'Grade 1',
                'description' => 'Latest Vehicle Grade'
            ],
            [
                'name' => 'Grade 2',
                'description' => 'Second Latest Vehicle Grade'
            ],
            [
                'name' => 'Grade 3',
                'description' => 'Third Latest Vehicle Grade'
            ]
        ];

        foreach ($grades as $gradeData) {
            $grade = VehicleGrade::firstOrCreate(
                ['name' => $gradeData['name']],
                [
                    'description' => $gradeData['description'],
                    'created_user_id' => null,
                    'updated_user_id' => null,
                ]
            );

            $this->command->info("Created vehicle grade: {$grade->name}");
        }

        $this->command->info('Vehicle Grades seeding completed!');
    }
}
