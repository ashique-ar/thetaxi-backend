<?php

namespace Database\Factories;

use App\Models\Company;
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
     * `company_id` is nullable on the `staff` table itself, but almost every
     * HR feature (People Core scoping, attendance device mapping, etc.)
     * requires it to be set, so this factory defaults it to an existing
     * Company row (or creates a minimal one) rather than leaving it null.
     * Tests that need a specific legal entity should still override it
     * explicitly, e.g. `Staff::factory()->create(['company_id' => $company->id])`,
     * so the Staff lands in the same company as the acting user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => fn () => Company::query()->value('id') ?? Company::create(['name' => 'Factory Test Company', 'is_default' => true])->id,
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
