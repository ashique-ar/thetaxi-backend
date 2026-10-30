<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * Define the model's default state.
     *
     * Staff records are shared across locations in a single installation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'staff_type' => fake()->randomElement(['operator', 'driver-coordinator', 'supervisor', 'agent', 'accountant']),
            'code' => 'STF-'.fake()->unique()->numerify('#####'),
            'nic' => null,
            'dob' => null,
            'license_no' => null,
            'license_expiry' => null,
            'address' => null,
            'country_id' => null,
            'state_id' => null,
            'city' => null,
            'employment_ended_at' => null,
            'termination_reason' => null,
            'terminated_by' => null,
        ];
    }

    /**
     * Mark the Staff as a former employee (employment ended in the past).
     */
    public function former(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_ended_at' => now()->subDays(30),
            'termination_reason' => 'Factory-generated former employee.',
        ]);
    }
}
