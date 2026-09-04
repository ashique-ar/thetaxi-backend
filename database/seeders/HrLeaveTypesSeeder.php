<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the standard set of Sri Lanka leave types for the default Company so
 * the Leave & Overtime screens have selectable leave types out of the box.
 *
 * Schema reference: database/migrations/2026_08_13_102000_create_hr_leave_overtime_and_timesheets.php
 * (hr_leave_types: company_id, code, name, category, unit, paid,
 * medical_confidential, effective_from, effective_until, status, created_by).
 * No leave policies are created here — approving pay/accrual rules for these
 * types is a deliberate policy decision left to an authorized HR admin via
 * the Leave & Overtime screens.
 *
 * Idempotent: guarded by the table's own unique key (company_id, code,
 * effective_from).
 */
class HrLeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command?->warn('HR leave type defaults skipped: no default company exists.');
            return;
        }

        $actor = DB::table('staff')->where('company_id', $company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR leave type defaults skipped: the default company has no active Staff user for audit ownership.');
            return;
        }

        $now = now();
        $from = CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();

        $leaveTypes = [
            ['code' => 'ANNUAL', 'name' => 'Annual Leave', 'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false],
            ['code' => 'CASUAL', 'name' => 'Casual Leave', 'category' => 'casual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false],
            ['code' => 'SICK', 'name' => 'Sick Leave', 'category' => 'sick', 'unit' => 'day', 'paid' => true, 'medical_confidential' => true],
            ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'category' => 'maternity', 'unit' => 'day', 'paid' => true, 'medical_confidential' => true],
            ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'category' => 'paternity', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false],
        ];

        $created = 0;
        foreach ($leaveTypes as $leaveType) {
            $existing = DB::table('hr_leave_types')
                ->where('company_id', $company->id)
                ->where('code', $leaveType['code'])
                ->where('effective_from', $from)
                ->first();
            if ($existing) {
                continue;
            }
            DB::table('hr_leave_types')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'code' => $leaveType['code'],
                'name' => $leaveType['name'],
                'category' => $leaveType['category'],
                'unit' => $leaveType['unit'],
                'paid' => $leaveType['paid'],
                'medical_confidential' => $leaveType['medical_confidential'],
                'effective_from' => $from,
                'status' => 'active',
                'created_by' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created++;
        }

        $this->command?->info("HR leave type defaults ready for {$company->name}: {$created} leave types created (existing rows retained).");
    }
}
