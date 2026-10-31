<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds one installation-wide payroll group for fresh installs.
 *
 * Schema reference: database/migrations/2026_08_15_100000_create_hr_payroll_groups.php
 * (hr_payroll_groups: code, name, pay_frequency, status,
 * effective_from, created_user_id). pay_frequency is validated elsewhere
 * (PeopleCoreController::catalogCommandRules callers) against
 * ['monthly','semi_monthly','bi_weekly','weekly','four_weekly'] — this uses
 * 'monthly', the standard Sri Lanka office payroll cadence.
 *
 * Idempotent: reuses an existing installation-wide payroll group with this code.
 */
class HrPayrollDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $actor = DB::table('staff')->whereNotNull('user_id')->whereNull('employment_ended_at')
            ->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR payroll defaults skipped: no active Staff user exists for audit ownership.');
            return;
        }

        $existing = DB::table('hr_payroll_groups')->where('code', 'MONTHLY-STD')->first();
        if ($existing) {
            $this->command?->info('HR payroll defaults ready for this installation: default payroll group already exists.');
            return;
        }

        $now = now();
        $from = CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();

        DB::table('hr_payroll_groups')->insert([
            'id' => (string) Str::uuid(),
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

        $this->command?->info('HR payroll defaults ready for this installation: 1 payroll group created.');
    }
}
