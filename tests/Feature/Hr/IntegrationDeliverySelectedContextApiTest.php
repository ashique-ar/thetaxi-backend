<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes integration deliveries to the selected Staff company and keeps acknowledgements idempotent', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.integrations.deliver', 'hr.integrations.acknowledge']);
    $secondCompany = Company::create(['name' => 'Second Integration company']);
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $outboxIds = [(string) Str::uuid(), (string) Str::uuid()];
    $checksums = [hash('sha256', 'first payload'), hash('sha256', 'second payload')];
    foreach ([[$outboxIds[0], $firstCompany->id, $checksums[0]], [$outboxIds[1], $secondCompany->id, $checksums[1]]] as [$id, $companyId, $checksum]) {
        DB::table('hr_accounting_outbox')->insert([
            'id' => $id, 'company_id' => $companyId, 'event_type' => 'test_event', 'aggregate_type' => 'test_record',
            'aggregate_id' => (string) Str::uuid(), 'target_system' => 'accounting',
            'payload' => json_encode(['company_id' => $companyId], JSON_THROW_ON_ERROR), 'payload_checksum' => $checksum,
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $pendingUrl = '/api/hr/integration-deliveries/pending?target_system=accounting';

    actingAs($admin, 'api')->getJson($pendingUrl)->assertOk();
    actingAs($admin, 'api')->withHeaders($headers)->getJson($pendingUrl)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $outboxIds[1]);
    $payload = [
        'target_system' => 'accounting', 'delivery_key' => 'selected-context-delivery-1',
        'payload_checksum' => $checksums[1], 'outcome' => 'accepted', 'external_reference' => 'ACCT-1',
    ];
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/integration-deliveries/'.$outboxIds[0].'/acknowledge', $payload)
        ->assertForbidden();
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/integration-deliveries/'.$outboxIds[1].'/acknowledge', $payload)
        ->assertCreated();
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/integration-deliveries/'.$outboxIds[1].'/acknowledge', $payload)
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    expect(DB::table('hr_accounting_outbox')->where('id', $outboxIds[1])->value('status'))->toBe('acknowledged')
        ->and(DB::table('hr_integration_deliveries')->where('delivery_key', $payload['delivery_key'])->count())->toBe(1);
});
