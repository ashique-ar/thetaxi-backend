<?php

namespace Database\Factories\Hr;

use App\Models\Company;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hr\HrEmploymentSpell>
 */
class HrEmploymentSpellFactory extends Factory
{
    protected $model = HrEmploymentSpell::class;

    /**
     * Define the model's default state.
     *
     * As with {@see \Database\Factories\StaffFactory}, `staff_id` and
     * `company_id` default independently (a fresh Staff / an existing or
     * minimal Company) for standalone use. Tests linking a spell to a
     * specific Staff member should override both to keep them consistent,
     * e.g. `HrEmploymentSpell::factory()->create(['staff_id' => $staff->id, 'company_id' => $staff->company_id])`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $joinedAt = fake()->dateTimeBetween('-5 years', '-1 month');

        return [
            'staff_id' => Staff::factory(),
            'company_id' => fn () => Company::query()->value('id') ?? Company::create(['name' => 'Factory Test Company', 'is_default' => true])->id,
            'employment_type_id' => null,
            'spell_number' => 1,
            'joined_at' => $joinedAt,
            'service_date' => $joinedAt,
            'confirmation_date' => null,
            'rehire_date' => null,
            'last_working_date' => null,
            'terminated_at' => null,
            'termination_reason_code' => null,
            'termination_reason' => null,
            'status' => 'active',
            'gratuity_qualified' => null,
            'gratuity_service_start' => $joinedAt,
            'gratuity_service_decision' => null,
            'prior_service_decisions' => null,
            'created_user_id' => User::factory(),
        ];
    }

    /**
     * Mark the spell as terminated.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'terminated_at' => now()->subDay(),
            'last_working_date' => now()->subDay(),
            'termination_reason_code' => 'resigned',
            'termination_reason' => 'Factory-generated termination.',
        ]);
    }
}
