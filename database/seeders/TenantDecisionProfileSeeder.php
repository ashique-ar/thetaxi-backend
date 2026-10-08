<?php

namespace Database\Seeders;

use App\Services\TenantDecisionMutationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TenantDecisionProfileSeeder extends Seeder
{
    /** @return array{name: string, scope: string} */
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

        $today = now()->toDateString();
        $this->seedDraft($company->id, 'company.localization', [
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
        ], $actorId, $today, "Initial Sri Lankan operating defaults for {$profile['name']}.");

        $this->seedDraft($company->id, 'operations.service_scope', [
            'scope' => $profile['scope'],
        ], $actorId, $today, "Initial service scope for {$profile['name']}; update this decision as the company's services change.");

        $this->command?->info("{$profile['name']} tenant decision drafts seeded for this hosting. Existing decisions were retained.");
    }

    private function seedDraft(string $companyId, string $key, array $value, string $actorId, string $effectiveFrom, string $reason): void
    {
        if (DB::table('tenant_decision_versions')->where('company_id', $companyId)->where('decision_key', $key)->exists()) return;

        app(TenantDecisionMutationService::class)->draft([
            'company_id' => $companyId,
            'key' => $key,
            'value' => $value,
            'effective_from' => $effectiveFrom,
            'effective_until' => null,
            'reason' => $reason,
            'idempotency_key' => (string) Str::uuid(),
        ], (string) $actorId, false);
    }
}
