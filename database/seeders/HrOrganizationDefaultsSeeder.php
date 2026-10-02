<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a minimal Organization & Fields / Jobs & Positions structure for the
 * default Company so the "Organization Units" and "Jobs & Positions" HR
 * screens show real data on a fresh install instead of empty tables.
 *
 * Schema reference: database/migrations/2026_08_12_147000_create_hr_people_core.php
 * (hr_organization_units, hr_job_families, hr_job_grades, hr_designations,
 * hr_positions) and database/migrations/2026_08_14_108000_govern_hr_job_and_position_administration.php
 * (adds version/effective_* columns; JobPositionAdministrationService creates
 * catalogue rows with status "active", which this seeder mirrors).
 *
 * Fully idempotent: every insert is guarded by a lookup on the same unique
 * key the table itself enforces (company_id + code, or company_id +
 * position_number), so re-running db:seed never duplicates rows.
 */
class HrOrganizationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command?->warn('HR organization defaults skipped: no default company exists.');
            return;
        }

        $actor = DB::table('staff')->where('company_id', $company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR organization defaults skipped: the default company has no active Staff user for audit ownership.');
            return;
        }

        $now = now();
        $from = CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();

        // --- Organization units -------------------------------------------------
        $unitIds = [];
        $units = [
            ['code' => 'HO', 'name' => 'Head Office', 'unit_type' => 'branch', 'parent' => null],
            ['code' => 'OPS', 'name' => 'Operations', 'unit_type' => 'department', 'parent' => 'HO'],
            ['code' => 'ADMIN', 'name' => 'Administration', 'unit_type' => 'department', 'parent' => 'HO'],
        ];
        $unitsCreated = 0;
        foreach ($units as $unit) {
            $existing = DB::table('hr_organization_units')->where('company_id', $company->id)->where('code', $unit['code'])->first();
            if ($existing) {
                $unitIds[$unit['code']] = $existing->id;
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('hr_organization_units')->insert([
                'id' => $id,
                'company_id' => $company->id,
                'parent_id' => $unit['parent'] ? ($unitIds[$unit['parent']] ?? null) : null,
                'unit_type' => $unit['unit_type'],
                'code' => $unit['code'],
                'name' => $unit['name'],
                'timezone' => 'Asia/Colombo',
                'status' => 'active',
                'effective_from' => $from,
                'created_user_id' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $unitIds[$unit['code']] = $id;
            $unitsCreated++;
        }

        // --- Job families ---------------------------------------------------------
        $familyIds = [];
        $families = [
            ['code' => 'OPS', 'name' => 'Operations'],
            ['code' => 'ADMIN', 'name' => 'Administration'],
        ];
        $familiesCreated = 0;
        foreach ($families as $family) {
            $existing = DB::table('hr_job_families')->where('company_id', $company->id)->where('code', $family['code'])->first();
            if ($existing) {
                $familyIds[$family['code']] = $existing->id;
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('hr_job_families')->insert([
                'id' => $id,
                'company_id' => $company->id,
                'code' => $family['code'],
                'name' => $family['name'],
                'status' => 'active',
                'effective_from' => $from,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $familyIds[$family['code']] = $id;
            $familiesCreated++;
        }

        // --- Job grade (single default grade) --------------------------------------
        $grade = DB::table('hr_job_grades')->where('company_id', $company->id)->where('code', 'G1')->first();
        $gradesCreated = 0;
        if (!$grade) {
            $gradeId = (string) Str::uuid();
            DB::table('hr_job_grades')->insert([
                'id' => $gradeId,
                'company_id' => $company->id,
                'code' => 'G1',
                'name' => 'Standard Grade',
                'rank' => 1,
                'status' => 'active',
                'effective_from' => $from,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $gradesCreated++;
        } else {
            $gradeId = $grade->id;
        }

        // --- Designations + positions -----------------------------------------------
        // A handful of generic roles covering the two departments above. Staff
        // members are round-robin assigned to these positions by
        // HrPeopleCoreDefaultsSeeder, which runs after this seeder.
        $designations = [
            ['code' => 'BRANCH_MANAGER', 'name' => 'Branch Manager', 'family' => 'ADMIN', 'unit' => 'ADMIN'],
            ['code' => 'OPERATIONS_COORDINATOR', 'name' => 'Operations Coordinator', 'family' => 'OPS', 'unit' => 'OPS'],
            ['code' => 'FLEET_SUPERVISOR', 'name' => 'Fleet Supervisor', 'family' => 'OPS', 'unit' => 'OPS'],
            ['code' => 'ACCOUNTANT', 'name' => 'Accountant', 'family' => 'ADMIN', 'unit' => 'ADMIN'],
            ['code' => 'RECEPTIONIST', 'name' => 'Receptionist', 'family' => 'ADMIN', 'unit' => 'ADMIN'],
            ['code' => 'QUALITY_INSPECTOR', 'name' => 'Quality Inspector', 'family' => 'OPS', 'unit' => 'OPS'],
        ];

        $designationsCreated = 0;
        $positionsCreated = 0;
        $positionSequence = DB::table('hr_positions')->where('company_id', $company->id)->count();
        foreach ($designations as $designation) {
            $existingDesignation = DB::table('hr_designations')->where('company_id', $company->id)->where('code', $designation['code'])->first();
            if ($existingDesignation) {
                $designationId = $existingDesignation->id;
            } else {
                $designationId = (string) Str::uuid();
                DB::table('hr_designations')->insert([
                    'id' => $designationId,
                    'company_id' => $company->id,
                    'job_family_id' => $familyIds[$designation['family']],
                    'job_grade_id' => $gradeId,
                    'code' => $designation['code'],
                    'name' => $designation['name'],
                    'status' => 'active',
                    'effective_from' => $from,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $designationsCreated++;
            }

            $existingPosition = DB::table('hr_positions')->where('company_id', $company->id)->where('designation_id', $designationId)->first();
            if (!$existingPosition) {
                $positionSequence++;
                DB::table('hr_positions')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'organization_unit_id' => $unitIds[$designation['unit']],
                    'designation_id' => $designationId,
                    'position_number' => sprintf('POS-%04d', $positionSequence),
                    'title' => $designation['name'],
                    'headcount_limit' => 5,
                    'status' => 'active',
                    'effective_from' => $from,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $positionsCreated++;
            }
        }

        $this->command?->info("HR organization defaults ready for {$company->name}: {$unitsCreated} organization units, {$familiesCreated} job families, {$gradesCreated} job grades, {$designationsCreated} designations, {$positionsCreated} positions created (existing rows retained).");
    }
}
