<?php

namespace Database\Factories\Hr;

use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hr\HrEmploymentAssignment>
 */
class HrEmploymentAssignmentFactory extends Factory
{
    protected $model = HrEmploymentAssignment::class;

    /**
     * Define the model's default state.
     *
     * `employment_spell_id` and `staff_id` default independently for
     * standalone use. Override both together for a specific Staff member.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employment_spell_id' => HrEmploymentSpell::factory(),
            'staff_id' => Staff::factory(),
            'position_id' => null,
            'organization_unit_id' => null,
            'manager_staff_id' => null,
            'dotted_line_manager_staff_id' => null,
            'hr_partner_staff_id' => null,
            'cost_centre_code' => null,
            'location_code' => null,
            'payroll_group_code' => null,
            'default_shift_code' => null,
            'work_pattern_code' => null,
            'assignment_type' => 'primary',
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now'),
            'effective_until' => null,
            'change_reason' => 'Factory-generated assignment.',
            'snapshot' => ['source' => 'factory'],
            'approved_by' => null,
        ];
    }
}
