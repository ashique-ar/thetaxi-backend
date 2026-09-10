<?php

namespace App\Services;

use App\Models\Website\WebsiteSetting;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantDecisionMutationService
{
    public function __construct(private readonly TenantDecisionService $decisions) {}

    public function draft(array $data, string $actorId, bool $approveImmediately): array
    {
        $checksum = hash('sha256', CanonicalJson::encode(['company_id' => $data['company_id'], 'key' => $data['key'], 'value' => $data['value'], 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null, 'reason' => $data['reason']]));
        return DB::transaction(function () use ($data, $actorId, $approveImmediately, $checksum): array {
            DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            $replay = DB::table('tenant_decision_versions')->where('company_id', $data['company_id'])->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($replay) {
                abort_unless(hash_equals($replay->request_checksum, $checksum), 409, 'The idempotency key was reused with different decision facts.');
                return $this->response($replay);
            }

            $type = 'decision.'.$data['key'];
            $current = WebsiteSetting::query()->where('company_id', $data['company_id'])->where('type', $type)->lockForUpdate()->first();
            $previous = $current ? json_decode((string) $current->value, true) : null;
            $latest = DB::table('tenant_decision_versions')->where('company_id', $data['company_id'])->where('decision_key', $data['key'])->orderByDesc('version')->lockForUpdate()->first();
            $version = ((int) DB::table('tenant_decision_versions')->where('company_id', $data['company_id'])->where('decision_key', $data['key'])->max('version')) + 1;
            $versionId = (string) Str::uuid();
            $status = $approveImmediately ? 'approved' : 'draft';
            $now = now();
            if ($approveImmediately) $this->assertDependenciesApproved($data['company_id'], $data['key'], $data['effective_from'], $data['effective_until'] ?? null);
            DB::table('tenant_decision_versions')->insert([
                'id' => $versionId, 'company_id' => $data['company_id'], 'decision_key' => $data['key'], 'version' => $version,
                'value' => CanonicalJson::encode($data['value']), 'status' => $status, 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null, 'reason' => $data['reason'],
                'request_checksum' => $checksum, 'idempotency_key' => $data['idempotency_key'], 'prepared_by' => $actorId,
                'supersedes_id' => $latest->id ?? null, 'approved_by' => $approveImmediately ? $actorId : null,
                'approved_at' => $approveImmediately ? $now : null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($latest?->status === 'draft') DB::table('tenant_decision_versions')->where('id', $latest->id)->update(['status' => 'superseded', 'updated_at' => $now]);
            $payload = ['value' => $data['value'], 'status' => $status, 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key'], 'updated_by' => $actorId, 'version_id' => $versionId, 'version' => $version];
            $payload += ['effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null];
            if ($approveImmediately) $payload += ['approved_by' => $actorId, 'approved_at' => $now->toISOString()];
            if ($approveImmediately && $this->isEffective($data['effective_from'], $data['effective_until'] ?? null)) $this->projectApproved($type, $data['company_id'], $payload, $actorId, $current);
            $this->audit($data['company_id'], $versionId, $approveImmediately ? 'tenant_decision_approved' : 'tenant_decision_drafted', $actorId, $previous ? hash('sha256', CanonicalJson::encode($previous)) : null, hash('sha256', CanonicalJson::encode($payload)), 'Reason retained on the protected tenant decision version.', $data['idempotency_key']);
            return $this->response(DB::table('tenant_decision_versions')->find($versionId));
        });
    }

    public function approve(string $companyId, string $key, string $actorId, string $idempotencyKey): array
    {
        $checksum = hash('sha256', CanonicalJson::encode(compact('companyId', 'key')));
        return DB::transaction(function () use ($companyId, $key, $actorId, $idempotencyKey, $checksum): array {
            DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            $replay = DB::table('tenant_decision_versions')->where('company_id', $companyId)->where('approval_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($replay) {
                abort_unless(hash_equals((string) $replay->approval_request_checksum, $checksum), 409, 'The approval idempotency key was reused for another decision.');
                return $this->response($replay);
            }
            $setting = WebsiteSetting::query()->where('type', 'decision.'.$key)->where('company_id', $companyId)->lockForUpdate()->first();
            $before = $setting ? json_decode((string) $setting->value, true) : null;
            $version = DB::table('tenant_decision_versions')->where('company_id', $companyId)->where('decision_key', $key)->where('status', 'draft')->orderByDesc('version')->lockForUpdate()->first();
            abort_unless($version, 409, 'Only the latest pending draft can be approved.');
            abort_unless(! DB::table('tenant_decision_versions')->where('company_id', $companyId)->where('decision_key', $key)->where('version', '>', $version->version)->exists(), 409, 'A newer decision version exists; refresh before approving.');
            $value = json_decode((string) $version->value, true, 512, JSON_THROW_ON_ERROR);
            $this->decisions->validate($key, $value);
            $this->assertDependenciesApproved($companyId, $key, $version->effective_from, $version->effective_until);
            abort_if($version->prepared_by === $actorId, 409, 'The decision author cannot approve the same change.');
            $now = now();
            DB::table('tenant_decision_versions')->where('id', $version->id)->update(['status' => 'approved', 'approval_idempotency_key' => $idempotencyKey, 'approval_request_checksum' => $checksum, 'approved_by' => $actorId, 'approved_at' => $now, 'updated_at' => $now]);
            $after = ['value' => $value, 'status' => 'approved', 'effective_from' => $version->effective_from, 'effective_until' => $version->effective_until, 'reason' => $version->reason, 'idempotency_key' => $version->idempotency_key, 'updated_by' => $version->prepared_by, 'version_id' => $version->id, 'version' => (int) $version->version, 'approved_by' => $actorId, 'approved_at' => $now->toISOString()];
            if ($this->isEffective($version->effective_from, $version->effective_until)) $this->projectApproved('decision.'.$key, $companyId, $after, $actorId, $setting);
            $this->audit($companyId, $version->id, 'tenant_decision_approved', $actorId, $before ? hash('sha256', CanonicalJson::encode($before)) : null, hash('sha256', CanonicalJson::encode($after)), 'Approval of the protected tenant decision version.', $idempotencyKey);
            return $this->response(DB::table('tenant_decision_versions')->find($version->id));
        });
    }

    private function response(object $version): array
    {
        return ['id' => (string) $version->id, 'company_id' => (string) $version->company_id, 'key' => $version->decision_key, 'version' => (int) $version->version, 'status' => $version->status, 'effective_from' => $version->effective_from, 'effective_until' => $version->effective_until, 'value' => json_decode((string) $version->value, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function projectApproved(string $type, string $companyId, array $payload, string $actorId, ?WebsiteSetting $setting): void
    {
        if ($setting) {
            $setting->update(['value' => CanonicalJson::encode($payload), 'updated_user_id' => $actorId]);
            return;
        }
        WebsiteSetting::create(['type' => $type, 'company_id' => $companyId, 'value' => CanonicalJson::encode($payload), 'created_user_id' => $actorId, 'updated_user_id' => $actorId]);
    }

    private function isEffective(?string $from, ?string $until): bool
    {
        $today = now()->toDateString();
        return ($from === null || $from <= $today) && ($until === null || $until >= $today);
    }

    private function assertDependenciesApproved(string $companyId, string $key, ?string $from, ?string $until): void
    {
        $definition = collect(config('tenant_decisions', []))->firstWhere('key', $key);
        $from ??= now()->toDateString();
        foreach ($definition['depends_on'] ?? [] as $dependency) {
            $version = DB::table('tenant_decision_versions')
                ->where('company_id', $companyId)
                ->where('decision_key', $dependency)
                ->where('status', 'approved')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $from))
                ->when($until, fn ($query, $date) => $query->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date)), fn ($query) => $query->whereNull('effective_until'))
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();
            $valid = false;
            if ($version) {
                try {
                    $this->decisions->validate($dependency, json_decode((string) $version->value, true, 512, JSON_THROW_ON_ERROR));
                    $valid = true;
                } catch (\JsonException|\Illuminate\Validation\ValidationException) {
                    // Invalid retained configuration must not authorize a dependent decision.
                }
            }
            $label = collect(config('tenant_decisions', []))->firstWhere('key', $dependency)['label'] ?? $dependency;
            abort_unless($valid, 409, "Approve {$label} for the full effective period first.");
        }
    }

    private function audit(string $companyId, string $subjectId, string $event, string $actorId, ?string $before, string $after, ?string $reason, string $correlationId): void
    {
        DB::table('domain_audit_events')->insert(['id' => (string) Str::uuid(), 'domain' => 'tenant_configuration', 'company_id' => $companyId, 'subject_type' => 'tenant_decision_version', 'subject_id' => $subjectId, 'event_type' => $event, 'actor_user_id' => $actorId, 'actor_type' => 'user', 'correlation_id' => $correlationId, 'before_checksum' => $before, 'after_checksum' => $after, 'reason' => $reason, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
