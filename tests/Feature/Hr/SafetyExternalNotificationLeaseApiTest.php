<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('exposes and closes an expired final safety notification lease with an audit event', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.relations_safety' => true, 'hr.safety_external_max_attempts' => 2]);

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'safety_external_lease_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.safety.external.acknowledge');
    $actor->assignRole($role);
    $approver = User::factory()->create();
    $company = Company::create(['name' => 'Safety notification company', 'is_active' => true, 'is_default' => true]);
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $actor->id]);

    $incidentId = (string) Str::uuid();
    DB::table('hr_safety_incidents')->insert([
        'id' => $incidentId, 'company_id' => $company->id, 'incident_number' => 'SAFE-LEASE-001',
        'incident_type' => 'injury', 'severity' => 'low', 'location_code' => 'SITE-1',
        'occurred_at' => now(), 'source_timezone' => 'Asia/Colombo', 'encrypted_narrative' => encrypt('Restricted incident'),
        'report_checksum' => str_repeat('a', 64), 'reported_by' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $notificationId = (string) Str::uuid();
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $notificationId, 'incident_id' => $incidentId, 'recipient_type' => 'regulator',
        'notification_type' => 'incident_report', 'payload_snapshot' => json_encode(['summary' => 'Restricted'], JSON_THROW_ON_ERROR),
        'payload_checksum' => str_repeat('b', 64), 'status' => 'delivering', 'attempt_count' => 2,
        'available_at' => now()->subMinutes(5), 'lease_token' => str_repeat('c', 64), 'leased_until' => null,
        'prepared_by' => $actor->id, 'approved_by' => $approver->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tamperedId = (string) Str::uuid();
    $originalSnapshot = ['summary' => 'Original'];
    $payloadChecksum = hash('sha256', json_encode([
        'incident_id' => $incidentId, 'incident_number' => 'SAFE-LEASE-001',
        'recipient_type' => 'regulator', 'notification_type' => 'integrity_check', 'payload' => $originalSnapshot,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $tamperedId, 'incident_id' => $incidentId, 'recipient_type' => 'regulator',
        'notification_type' => 'integrity_check', 'payload_snapshot' => json_encode(['summary' => 'Tampered'], JSON_THROW_ON_ERROR),
        'payload_checksum' => $payloadChecksum, 'status' => 'approved_pending_delivery', 'attempt_count' => 0,
        'available_at' => now()->subMinute(), 'prepared_by' => $actor->id,
        'approved_by' => $approver->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $acknowledgementId = (string) Str::uuid();
    $ackSnapshot = ['summary' => 'Approved external report'];
    $ackChecksum = hash('sha256', json_encode([
        'incident_id' => $incidentId, 'incident_number' => 'SAFE-LEASE-001',
        'recipient_type' => 'insurer', 'notification_type' => 'delivery_receipt', 'payload' => $ackSnapshot,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $ackLease = str_repeat('d', 64);
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $acknowledgementId, 'incident_id' => $incidentId, 'recipient_type' => 'insurer',
        'notification_type' => 'delivery_receipt', 'payload_snapshot' => json_encode($ackSnapshot, JSON_THROW_ON_ERROR),
        'payload_checksum' => $ackChecksum, 'status' => 'delivering', 'attempt_count' => 1,
        'available_at' => now()->subMinute(), 'lease_token' => $ackLease, 'leased_until' => now()->addMinutes(5),
        'prepared_by' => $actor->id, 'approved_by' => $approver->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $failureId = (string) Str::uuid();
    $failureLease = str_repeat('e', 64);
    $failureChecksum = str_repeat('f', 64);
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $failureId, 'incident_id' => $incidentId, 'recipient_type' => 'regulator',
        'notification_type' => 'failure_retry', 'payload_snapshot' => json_encode(['summary' => 'Retry'], JSON_THROW_ON_ERROR),
        'payload_checksum' => $failureChecksum, 'status' => 'delivering', 'attempt_count' => 1,
        'available_at' => now()->subMinute(), 'lease_token' => $failureLease, 'leased_until' => now()->addMinutes(5),
        'prepared_by' => $actor->id, 'approved_by' => $approver->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($actor, 'api')->getJson('/api/hr/safety/external-notification-queue')
        ->assertOk()->assertJsonFragment(['id' => $notificationId])->assertJsonFragment(['id' => $tamperedId]);
    actingAs($actor, 'api')->postJson('/api/hr/safety/external-notifications/'.$notificationId.'/claim')
        ->assertConflict();
    actingAs($actor, 'api')->postJson('/api/hr/safety/external-notifications/'.$tamperedId.'/claim')
        ->assertConflict()->assertJsonPath('message', 'External safety notification integrity check failed.');
    $sameApproverId = (string) Str::uuid();
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $sameApproverId, 'incident_id' => $incidentId, 'recipient_type' => 'insurer',
        'notification_type' => 'same_approver', 'payload_snapshot' => json_encode(['summary' => 'Unapproved'], JSON_THROW_ON_ERROR),
        'payload_checksum' => hash('sha256', json_encode([
            'incident_id' => $incidentId, 'incident_number' => 'SAFE-LEASE-001',
            'recipient_type' => 'insurer', 'notification_type' => 'same_approver',
            'payload' => ['summary' => 'Unapproved'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'status' => 'approved_pending_delivery', 'attempt_count' => 0, 'available_at' => now()->subMinute(),
        'prepared_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    actingAs($actor, 'api')->postJson('/api/hr/safety/external-notifications/'.$sameApproverId.'/claim')
        ->assertConflict()->assertJsonPath('message', 'External safety notification requires independent approval.');
    $this->assertDatabaseHas('hr_safety_external_notifications', [
        'id' => $sameApproverId, 'status' => 'approved_pending_delivery', 'attempt_count' => 0,
    ]);
    $claimId = (string) Str::uuid();
    $claimSnapshot = ['summary' => 'Approved for delivery'];
    $claimChecksum = hash('sha256', json_encode([
        'incident_id' => $incidentId, 'incident_number' => 'SAFE-LEASE-001',
        'recipient_type' => 'regulator', 'notification_type' => 'claim_audit', 'payload' => $claimSnapshot,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    DB::table('hr_safety_external_notifications')->insert([
        'id' => $claimId, 'incident_id' => $incidentId, 'recipient_type' => 'regulator',
        'notification_type' => 'claim_audit', 'payload_snapshot' => json_encode($claimSnapshot, JSON_THROW_ON_ERROR),
        'payload_checksum' => $claimChecksum, 'status' => 'approved_pending_delivery', 'attempt_count' => 0,
        'available_at' => now()->subMinute(), 'prepared_by' => $approver->id,
        'approved_by' => $actor->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $claimResponse = actingAs($actor, 'api')->postJson('/api/hr/safety/external-notifications/'.$claimId.'/claim')
        ->assertOk();
    $this->assertDatabaseHas('hr_safety_external_notifications', [
        'id' => $claimId, 'status' => 'delivering', 'attempt_count' => 1,
    ]);
    $claimToken = $claimResponse->json('data.lease_token');
    $claimEvent = DB::table('hr_safety_register_events')->where('register_id', $claimId)
        ->where('event_type', 'delivery_attempt_claimed')->first();
    expect($claimEvent)->not->toBeNull()
        ->and($claimEvent->to_status)->toBe('delivering')
        ->and(json_decode($claimEvent->safe_details, true)['attempt_count'])->toBe(1)
        ->and(json_decode($claimEvent->safe_details, true)['lease_token_hash'])->toBe(hash('sha256', $claimToken))
        ->and($claimEvent->safe_details)->not->toContain($claimToken);
    $acknowledgement = [
        'payload_checksum' => $ackChecksum, 'lease_token' => $ackLease, 'external_reference' => 'receipt-001',
    ];
    $ackUrl = '/api/hr/safety/external-notifications/'.$acknowledgementId.'/acknowledge';
    actingAs($actor, 'api')->postJson($ackUrl, $acknowledgement)
        ->assertOk()->assertJsonPath('data.status', 'acknowledged');
    actingAs($actor, 'api')->postJson($ackUrl, $acknowledgement)
        ->assertOk()->assertJsonPath('data.status', 'acknowledged');
    actingAs($actor, 'api')->postJson($ackUrl, array_replace($acknowledgement, ['external_reference' => 'other-receipt']))
        ->assertConflict();
    $failureMessage = 'Provider failure?token=private-provider-token';
    $safeFailureMessage = 'Provider failure?token=[REDACTED]';
    $failure = ['payload_checksum' => $failureChecksum, 'lease_token' => $failureLease, 'message' => $failureMessage];
    $failureUrl = '/api/hr/safety/external-notifications/'.$failureId.'/fail';
    $failureResponse = actingAs($actor, 'api')->postJson($failureUrl, $failure)
        ->assertOk()->assertJsonPath('data.status', 'approved_pending_delivery')
        ->assertJsonPath('data.last_error', $safeFailureMessage);
    actingAs($actor, 'api')->postJson($failureUrl, array_replace($failure, [
        'message' => 'Provider failure?token=rotated-provider-token',
    ]))
        ->assertOk()->assertJsonPath('data.status', 'approved_pending_delivery');
    actingAs($actor, 'api')->postJson($failureUrl, array_replace($failure, ['message' => 'Different failure.']))
        ->assertConflict();

    $this->assertDatabaseHas('hr_safety_external_notifications', [
        'id' => $notificationId, 'status' => 'failed', 'lease_token' => null, 'leased_until' => null,
    ]);
    $this->assertDatabaseHas('hr_safety_external_notifications', [
        'id' => $failureId, 'last_error' => $safeFailureMessage,
    ]);
    $this->assertDatabaseHas('hr_safety_register_events', [
        'company_id' => $company->id, 'register_type' => 'external_notification',
        'register_id' => $notificationId, 'event_type' => 'delivery_failed', 'to_status' => 'failed',
    ]);
    $this->assertDatabaseHas('hr_safety_external_notifications', [
        'id' => $tamperedId, 'status' => 'approved_pending_delivery', 'attempt_count' => 0,
        'lease_token' => null, 'leased_until' => null,
    ]);
    expect(DB::table('hr_safety_register_events')->where('register_id', $acknowledgementId)
        ->where('event_type', 'delivery_acknowledged')->count())->toBe(1)
        ->and(DB::table('hr_safety_register_events')->where('register_id', $failureId)
            ->where('event_type', 'delivery_attempt_failed')->count())->toBe(1);

    $failureEvent = DB::table('hr_safety_register_events')->where('register_id', $failureId)
        ->where('event_type', 'delivery_attempt_failed')->first();
    expect($failureResponse->getContent())->not->toContain('private-provider-token')
        ->and($failureEvent->safe_details)->not->toContain('private-provider-token')
        ->and(json_decode($failureEvent->safe_details, true)['message_hash'])->toBe(hash('sha256', $safeFailureMessage));
});
