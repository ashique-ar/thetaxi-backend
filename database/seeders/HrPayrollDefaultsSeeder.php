<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds one default payroll group for the default Company so the Payroll
 * screens have somewhere to attach staff/assignments out of the box.
 *
 * Schema reference: database/migrations/2026_08_15_100000_create_hr_payroll_groups.php
 * (hr_payroll_groups: company_id, code, name, pay_frequency, status,
 * effective_from, created_user_id). pay_frequency is validated elsewhere
 * (PeopleCoreController::catalogCommandRules callers) against
 * ['monthly','semi_monthly','bi_weekly','weekly','four_weekly'] — this uses
 * 'monthly', the standard Sri Lanka office payroll cadence.
 *
 * Idempotent: guarded by the table's own unique key (company_id, code).
 */
class HrPayrollDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command?->warn('HR payroll defaults skipped: no default company exists.');
            return;
        }

        $actor = DB::table('staff')->where('company_id', $company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR payroll defaults skipped: the default company has no active Staff user for audit ownership.');
            return;
        }

        $existing = DB::table('hr_payroll_groups')->where('company_id', $company->id)->where('code', 'MONTHLY-STD')->first();
        if ($existing) {
            $this->command?->info("HR payroll defaults ready for {$company->name}: default payroll group already exists.");
            return;
        }

        $now = now();
        $from = CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();

        DB::table('hr_payroll_groups')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'code' => 'MONTHLY-STD',
            'name' => 'Standard Monthly Payroll',
            'pay_frequency' => 'monthly',
            'description' => 'Default monthly payroll group seeded for a fresh install.',
            'status' => 'active',
            'effective_from' => $from,
            'created_user_id' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info("HR payroll defaults ready for {$company->name}: 1 payroll group created.");
    }
}
