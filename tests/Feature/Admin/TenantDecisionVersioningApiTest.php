<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('versions drafts and approvals with checksum bound idempotency and audit', function () {
    [, $company] = hr_seed_admin_actor();
    $maker = Staff::factory()->create(['company_id' => $company->id]);
    $checker = Staff::factory()->create(['company_id' => $company->id]);
    $maker->user->givePermissionTo('tenant-decisions.manage');
    $checker->user->givePermissionTo('tenant-decisions.approve');
    $key = (string) Str::uuid();
    $payload = ['company_id' => $company->id, 'key' => 'company.localization', 'value' => ['currency' => 'LKR', 'timezone' => 'Asia/Colombo'], 'effective_from' => now()->toDateString(), 'effective_until' => null, 'reason' => 'Approved operating locale evidence', 'idempotency_key' => $key];

    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', array_replace($payload, ['value' => ['currency' => 'lkr', 'timezone' => 'Invented/Zone'], 'idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', $payload)->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'draft');
    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', $payload)->assertOk()->assertJsonPath('data.version', 1);
    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', array_replace_recursive($payload, ['value' => ['currency' => 'USD']]))->assertStatus(409);

    $approval = ['company_id' => $company->id, 'idempotency_key' => (string) Str::uuid()];
    actingAs($checker->user, 'api')->postJson('/api/tenant-decisions/company.localization/approve', $approval)->assertOk()->assertJsonPath('data.status', 'approved');
    actingAs($checker->user, 'api')->postJson('/api/tenant-decisions/company.localization/approve', $approval)->assertOk()->assertJsonPath('data.version', 1);

    $change = array_replace($payload, ['value' => ['currency' => 'USD', 'timezone' => 'Asia/Colombo'], 'reason' => 'Pending locale change evidence', 'idempotency_key' => (string) Str::uuid()]);
    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', $change)->assertOk()->assertJsonPath('data.status', 'draft');
    actingAs($maker->user, 'api')->getJson('/api/tenant-decisions?company_id='.$company->id)
        ->assertOk()->assertJsonPath('data.definitions.0.configured', true)->assertJsonPath('data.definitions.0.pending_approval', true)
        ->assertJsonPath('data.readiness.configured', 1)->assertJsonPath('data.readiness.draft', 1);
    expect(app(\App\Services\TenantDecisionService::class)->get('company.localization', $company->id))->toBe(['currency' => 'LKR', 'timezone' => 'Asia/Colombo']);

    $future = array_replace($payload, ['value' => ['currency' => 'GBP', 'timezone' => 'Europe/London'], 'effective_from' => now()->addDay()->toDateString(), 'reason' => 'Approved future locale evidence', 'idempotency_key' => (string) Str::uuid()]);
    actingAs($maker->user, 'api')->postJson('/api/tenant-decisions', $future)->assertOk()->assertJsonPath('data.status', 'draft');
    actingAs($checker->user, 'api')->postJson('/api/tenant-decisions/company.localization/approve', ['company_id' => $company->id, 'idempotency_key' => (string) Str::uuid()])->assertOk();
    expect(app(\App\Services\TenantDecisionService::class)->get('company.localization', $company->id))->toBe(['currency' => 'LKR', 'timezone' => 'Asia/Colombo']);

    expect(DB::table('tenant_decision_versions')->where('company_id', $company->id)->count())->toBe(3)
        ->and(DB::table('domain_audit_events')->where('domain', 'tenant_configuration')->count())->toBe(5);
});
