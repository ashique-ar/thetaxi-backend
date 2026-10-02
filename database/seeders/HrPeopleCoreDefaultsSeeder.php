<?php

namespace Database\Seeders;

use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links every Staff row for the default Company into the HR people-core
 * domain (hr_employment_types, hr_employment_spells, hr_employment_assignments)
 * so the Employee Directory shows real employment history/assignment data
 * instead of bare `staff` rows.
 *
 * Must run after CompanySeeder, StaffSeeder and HrOrganizationDefaultsSeeder
 * (positions created there are round-robin assigned to staff here).
 *
 * Schema reference: database/migrations/2026_08_12_147000_create_hr_people_core.php
 * and database/migrations/2026_08_31_173000_align_employment_models_with_base_model.php.
 *
 * Idempotent: a Staff member already carrying a spell #1 / primary assignment
 * is left untouched on re-run.
 */
class HrPeopleCoreDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command?->warn('HR people-core defaults skipped: no default company exists.');
            return;
        }

        $actor = DB::table('staff')->where('company_id', $company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR people-core defaults skipped: the default company has no active Staff user for audit ownership.');
            return;
        }

        $positions = DB::table('hr_positions')->where('company_id', $company->id)->orderBy('position_number')->get();
        if ($positions->isEmpty()) {
            $this->command?->warn('HR people-core defaults skipped: no HR positions exist yet. Run HrOrganizationDefaultsSeeder first.');
            return;
        }

        $from = CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();
        $now = now();

        // Default employment type ("Permanent"), created once for the company.
        $employmentType = DB::table('hr_employment_types')->where('company_id', $company->id)->where('code', 'PERM')->first();
        if (!$employmentType) {
            $employmentTypeId = (string) Str::uuid();
            DB::table('hr_employment_types')->insert([
                'id' => $employmentTypeId,
                'company_id' => $company->id,
                'code' => 'PERM',
                'name' => 'Permanent',
                'is_employee' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $employmentTypeId = $employmentType->id;
        }

        $staffMembers = Staff::where('company_id', $company->id)->whereNull('deleted_at')->whereNull('employment_ended_at')->orderBy('code')->get();

        $spellsCreated = 0;
        $assignmentsCreated = 0;
        $positionCount = $positions->count();

        foreach ($staffMembers as $index => $staff) {
            $joinedAt = optional($staff->created_at)->toDateString() ?? $from;

            $spell = HrEmploymentSpell::firstOrCreate(
                ['staff_id' => $staff->id, 'spell_number' => 1],
                [
                    'company_id' => $company->id,
                    'employment_type_id' => $employmentTypeId,
                    'joined_at' => $joinedAt,
                    'service_date' => $joinedAt,
                    'status' => 'active',
                    'gratuity_service_start' => $joinedAt,
                    'created_user_id' => $actor,
                ]
            );
            if ($spell->wasRecentlyCreated) {
                $spellsCreated++;
            }

            $alreadyAssigned = HrEmploymentAssignment::where('staff_id', $staff->id)->where('assignment_type', 'primary')->exists();
            if (!$alreadyAssigned) {
                $position = $positions[$index % $positionCount];
                HrEmploymentAssignment::create([
                    'employment_spell_id' => $spell->id,
                    'staff_id' => $staff->id,
                    'company_id' => $company->id,
                    'position_id' => $position->id,
                    'organization_unit_id' => $position->organization_unit_id,
                    'cost_centre_code' => null,
                    'location_code' => null,
                    'assignment_type' => 'primary',
                    'effective_from' => $from,
                    'effective_until' => null,
                    'change_reason' => 'Initial HR people-core seeding',
                    'snapshot' => [
                        'staff_type' => $staff->staff_type,
                        'staff_code' => $staff->code,
                        'position_number' => $position->position_number,
                        'position_title' => $position->title,
                    ],
                    'approved_by' => $actor,
                ]);
                $assignmentsCreated++;
            }
        }

        $this->command?->info("HR people-core defaults ready for {$company->name}: {$spellsCreated} employment spells, {$assignmentsCreated} employment assignments created (existing rows retained).");
    }
}
