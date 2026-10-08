<?php

namespace Database\Seeders;

use App\Services\TenantDecisionMutationService;
use App\Services\TenantDecisionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TenantDecisionProfileSeeder extends Seeder
{
    /** @return array{name: string, service_scope: string} */
    abstract protected function profile(): array;

    final public function run(): void
    {
        $profile = $this->profile();
        if (! DB::getSchemaBuilder()->hasTable('tenant_decision_versions')) {
            $this->command?->warn('Run migrations before seeding tenant decision defaults.');
            return;
        }

        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (! $company) {
            $this->command?->warn("{$profile['name']} tenant decisions skipped: no default company exists on this hosting.");
            return;
        }

        $actorId = DB::table('staff')->where('company_id', $company->id)
            ->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')
            ->orderBy('created_at')->value('user_id');
        $actorId ??= DB::table('users')->where('is_active', true)->orderBy('created_at')->value('id');
        if (! $actorId) {
            $this->command?->warn("{$profile['name']} tenant decisions skipped: no active user is available for audit ownership.");
            return;
        }

        $today = CarbonImmutable::today('Asia/Colombo')->toDateString();
        $values = $this->baselineValues($profile, $today);
        $decisions = app(TenantDecisionService::class);
        $mutation = app(TenantDecisionMutationService::class);
        $definitions = config('tenant_decisions', []);
        $missingKeys = collect($definitions)->pluck('key')->diff(array_keys($values));
        if ($missingKeys->isNotEmpty()) {
            throw new \LogicException("Missing tenant decision baselines for {$profile['name']}: ".$missingKeys->implode(', '));
        }
        foreach ($definitions as $definition) {
            $decisions->validate($definition['key'], $values[$definition['key']]);
        }

        $seeded = 0;
        $retained = 0;

        foreach ($definitions as $definition) {
            $key = $definition['key'];

            if ($decisions->isApproved($key, (string) $company->id)) {
                $retained++;
                continue;
            }

            $latest = DB::table('tenant_decision_versions')
                ->where('company_id', $company->id)->where('decision_key', $key)
                ->orderByDesc('version')->first();
            if ($latest && $latest->status === 'draft' && ! $this->isEarlierSeederDraft((string) $latest->reason, $profile['name'])) {
                $this->command?->warn("{$profile['name']} {$key} skipped: an unapproved company draft already exists.");
                continue;
            }

            $blocked = collect($definition['depends_on'] ?? [])->filter(
                fn (string $dependency) => ! $decisions->isApproved($dependency, (string) $company->id)
            )->values();
            if ($blocked->isNotEmpty()) {
                $blockers = $blocked->implode(', ');
                $this->command?->warn("{$profile['name']} {$key} skipped: approve or seed prerequisites first ({$blockers}).");
                continue;
            }

            $value = $decisions->validate($key, $values[$key]);
            $mutation->draft([
                'company_id' => (string) $company->id,
                'key' => $key,
                'value' => $value,
                'effective_from' => $today,
                'effective_until' => null,
                'reason' => "Initial active baseline for {$profile['name']}; later changes require the normal independent approval workflow.",
                'idempotency_key' => (string) Str::uuid(),
            ], (string) $actorId, true);
            $seeded++;
        }

        $this->command?->info("{$profile['name']} tenant decisions ready: {$seeded} baselines activated, {$retained} existing active decisions retained.");
    }

    private function isEarlierSeederDraft(string $reason, string $companyName): bool
    {
        return str_starts_with($reason, "Initial Sri Lankan operating defaults for {$companyName}.")
            || str_starts_with($reason, "Initial service scope for {$companyName};")
            || str_starts_with($reason, "Initial active baseline for {$companyName};");
    }

    private function baselineValues(array $profile, string $today): array
    {
        return [
            'company.localization' => ['currency' => 'LKR', 'timezone' => 'Asia/Colombo'],
            'operations.service_scope' => ['scope' => $profile['service_scope']],
            'hr.employee_numbering' => ['policy_reference' => 'Use the existing STF six-digit staff sequence. HR owns issuance; exceptions require a recorded HR approval.'],
            'hr.work_calendars' => ['policy_reference' => 'Use Asia/Colombo time. Schedule drivers and operations through approved rosters; HR maintains Sri Lankan public-holiday calendar entries.'],
            'hr.retention_privacy' => ['policy_reference' => 'Restrict employee records to authorized HR access. Do not delete records under legal hold; dispose only under a company-approved retention schedule.'],
            'device.biometric_privacy' => ['policy_reference' => 'Do not collect biometric data unless the company documents purpose, notice, lawful basis, retention, access controls and independent approval first.'],
            'device.integration' => ['topology' => 'csv', 'evidence_reference' => 'Manual CSV attendance import only. No live device connection is approved until provider, model and firmware evidence are reviewed.'],
            'employment.driver_relationship' => ['relationship' => 'mixed', 'policy_reference' => 'Before assignment, record a signed employee or contractor arrangement for each driver and have HR verify that the recorded relationship matches it.'],
            'hr.attendance_policy' => ['policy_reference' => 'Use approved rosters as the attendance schedule. Supervisors review corrections and exceptions; device events alone do not establish payable time.'],
            'hr.leave_overtime_policy' => ['policy_reference' => 'Record and approve leave and overtime under signed employment terms and applicable Sri Lankan requirements. Do not apply automatic overtime rounding.'],
            'hr.payroll_calendar_policy' => ['policy_reference' => 'Use the existing MONTHLY-STD calendar. Finance confirms each period cutoff and reconciles approved employee and attendance facts before processing.'],
            'hr.document_governance' => ['policy_reference' => 'Restrict employee documents to authorized HR access; retain versioned records under the company schedule and preserve legal holds.'],
            'hr.approval_access' => ['policy_reference' => 'Use least-privilege access and maker-checker approval. A preparer cannot approve their own change; record emergency access reasons and audit all decisions.'],
            'hr.notification_policy' => ['policy_reference' => 'Use in-app notifications for governed HR events. Do not include payroll, medical, biometric or other sensitive personal details in SMS or email.'],
            'hr.migration_scope' => ['policy_reference' => 'Do not import opening balances or historical employee data until source ownership, cutover, rejected-row handling and reconciliation are approved.'],
            'sales.commission_policy' => ['plan_reference' => 'No commission payout without a separately approved written plan.', 'cycle_reference' => 'Finance must approve the cycle and cutoff before the first payout.', 'activation_date' => $today],
            'payroll.statutory' => ['adviser_reference' => 'A qualified Sri Lankan payroll adviser must confirm the statutory basis before statutory payroll is processed.', 'policy_reference' => 'Do not calculate statutory payroll obligations from this baseline; record the adviser-approved policy before the first payroll run.'],
            'integrations.approved_scope' => ['policy_reference' => 'Deny external integrations by default. Approve provider, purpose, data boundary, credential owner and disablement procedure before enabling any integration.'],
        ];
    }
}
