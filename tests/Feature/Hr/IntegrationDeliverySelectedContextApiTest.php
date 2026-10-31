<?php

use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('delivers integration events installation-wide and keeps acknowledgements idempotent', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.integrations.deliver', 'hr.integrations.acknowledge']);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $outboxIds = [(string) Str::uuid(), (string) Str::uuid()];
    $checksums = [hash('sha256', 'first payload'), hash('sha256', 'second payload')];
    foreach ([[$outboxIds[0], $checksums[0]], [$outboxIds[1], $checksums[1]]] as [$id, $checksum]) {
        DB::table('hr_accounting_outbox')->insert([
            'id' => $id, 'event_type' => 'test_event', 'aggregate_type' => 'test_record',
            'aggregate_id' => (string) Str::uuid(), 'target_system' => 'accounting',
            'payload' => json_encode(['payload' => $id], JSON_THROW_ON_ERROR), 'payload_checksum' => $checksum,
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $pendingUrl = '/api/hr/integration-deliveries/pending?target_system=accounting';

    actingAs($admin, 'api')->getJson($pendingUrl)->assertOk();
    actingAs($admin, 'api')->withHeaders($headers)->getJson($pendingUrl)
        ->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $outboxIds[0]])->assertJsonFragment(['id' => $outboxIds[1]]);
    $payload = [
        'target_system' => 'accounting', 'delivery_key' => 'installation-delivery-1',
        'payload_checksum' => $checksums[0], 'outcome' => 'accepted', 'external_reference' => 'ACCT-1',
        'response_checksum' => hash('sha256', 'provider accepted response'),
    ];
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/integration-deliveries/'.$outboxIds[0].'/acknowledge', $payload)
        ->assertCreated();
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/integration-deliveries/'.$outboxIds[0].'/acknowledge', $payload)
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    expect(DB::table('hr_accounting_outbox')->where('id', $outboxIds[0])->value('status'))->toBe('acknowledged')
        ->and(DB::table('hr_integration_deliveries')->where('delivery_key', $payload['delivery_key'])->count())->toBe(1);
});
