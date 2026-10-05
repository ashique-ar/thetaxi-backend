<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Hr\HrNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists selected-company notifications with a readable recipient label and no managed-record IDs', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo(['hr.notifications.manage', 'hr.notifications.adapter.read']);
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'outbox-list-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('8', 64), 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => encrypt(['body' => 'private']),
        'payload_checksum' => str_repeat('8', 64), 'idempotency_key' => 'notification-outbox-list-test',
        'status' => 'delivered', 'attempt_count' => 1, 'available_at' => now(),
        'last_error' => 'Provider response included private details.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $foreignCompany = Company::create(['name' => 'Foreign outbox company']);
    $foreignRecipient = User::factory()->create();
    $foreignStaff = Staff::factory()->create([
        'user_id' => $foreignRecipient->id, 'company_id' => $foreignCompany->id,
    ]);
    $foreignTemplateId = (string) Str::uuid();
    $foreignOutboxId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $foreignTemplateId, 'company_id' => $foreignCompany->id, 'code' => 'foreign-outbox-list-test',
        'version' => 1, 'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('9', 64), 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $foreignOutboxId, 'company_id' => $foreignCompany->id,
        'template_version_id' => $foreignTemplateId, 'recipient_staff_id' => $foreignStaff->id,
        'recipient_user_id' => $foreignRecipient->id, 'event_type' => 'test_event', 'channel' => 'email',
        'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'encrypted_rendered_payload' => encrypt(['body' => 'foreign']),
        'payload_checksum' => str_repeat('9', 64), 'idempotency_key' => 'foreign-notification-outbox-list-test',
        'status' => 'delivered', 'attempt_count' => 1, 'available_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $mismatchedOutboxId = (string) Str::uuid();
    DB::table('hr_notification_outbox')->insert([
        'id' => $mismatchedOutboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $foreignStaff->id, 'recipient_user_id' => $foreignRecipient->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => encrypt(['body' => 'mismatched']),
        'payload_checksum' => str_repeat('a', 64), 'idempotency_key' => 'mismatched-notification-outbox-list-test',
        'status' => 'pending_delivery', 'attempt_count' => 0, 'available_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $name = trim($admin->first_name.' '.$admin->last_name) ?: $staff->code;
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/notifications/outbox')->assertOk()
        ->assertJsonPath('data.data.0.id', $outboxId)
        ->assertJsonPath('data.data.0.recipient_label', $name)
        ->assertJsonMissingPath('data.data.0.recipient_staff_id')
        ->assertJsonMissingPath('data.data.0.template_version_id')
        ->assertJsonMissingPath('data.data.0.source_id')
        ->assertJsonMissingPath('data.data.0.last_error')
        ->assertJsonMissingPath('data.data.0.encrypted_rendered_payload')
        ->assertJsonMissing(['id' => $foreignOutboxId])
        ->assertJsonMissing(['id' => $mismatchedOutboxId]);

    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/notifications/delivery-queue')->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $mismatchedOutboxId)
        ->assertJsonMissingPath('data.data.0.recipient_staff_id')
        ->assertJsonMissingPath('data.data.0.recipient_user_id');
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/notifications/outbox/'.$mismatchedOutboxId.'/claim')->assertConflict();
    $this->assertDatabaseHas('hr_notification_outbox', ['id' => $mismatchedOutboxId, 'status' => 'failed']);
    $this->assertDatabaseHas('hr_notification_delivery_events', [
        'outbox_id' => $mismatchedOutboxId, 'event_type' => 'failed', 'attempt_number' => 0,
    ]);
});

it('reads and saves notification preferences for only the selected Staff identity', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.notifications.preferences');
    config(['hr.features.engagement_analytics' => true]);
    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondCompany = Company::create(['name' => 'Second Notification company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $preferenceIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$preferenceIds[0], $firstStaff, $firstCompany->id], [$preferenceIds[1], $secondStaff, $secondCompany->id]] as [$id, $staff, $companyId]) {
        DB::table('hr_notification_preferences')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staff->id,
            'event_type' => 'optional_update', 'channel' => 'email', 'enabled' => true,
            'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $mismatchedPreferenceId = (string) Str::uuid();
    DB::table('hr_notification_preferences')->insert([
        'id' => $mismatchedPreferenceId, 'company_id' => $firstCompany->id, 'staff_id' => $secondStaff->id,
        'event_type' => 'malformed_company_link', 'channel' => 'email', 'enabled' => true,
        'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->getJson('/api/hr/notifications/preferences')->assertForbidden();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/notifications/preferences')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.enabled', true);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/preferences', [
        'event_type' => 'optional_update', 'channel' => 'email', 'enabled' => false,
    ])->assertOk();
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/preferences', [
        'event_type' => 'malformed_company_link', 'channel' => 'email', 'enabled' => false,
    ])->assertConflict();

    expect(DB::table('hr_notification_preferences')->where('id', $preferenceIds[0])->value('enabled'))->toBe(1)
        ->and(DB::table('hr_notification_preferences')->where('id', $preferenceIds[1])->value('enabled'))->toBe(0)
        ->and(DB::table('hr_notification_preferences')->where('id', $mismatchedPreferenceId)->value('enabled'))->toBe(1)
        ->and(DB::table('hr_notification_preferences')->where('id', $mismatchedPreferenceId)->value('company_id'))->toBe($firstCompany->id);
});

it('hides a foreign notification receipt before checking provider-event replay', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.acknowledge');
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    $foreignCompany = Company::create(['name' => 'Foreign notification company']);
    $recipient = User::factory()->create();
    $foreignStaff = Staff::factory()->create(['user_id' => $recipient->id, 'company_id' => $foreignCompany->id]);
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    $providerEventId = 'foreign-provider-receipt';
    $checksum = str_repeat('a', 64);
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $foreignCompany->id, 'code' => 'test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $foreignCompany->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $foreignStaff->id, 'recipient_user_id' => $recipient->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => 'encrypted',
        'payload_checksum' => $checksum, 'idempotency_key' => 'foreign-notification-test',
        'status' => 'delivered', 'attempt_count' => 1, 'available_at' => now(),
        'accepted_at' => now(), 'external_reference' => 'provider-reference',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_delivery_events')->insert([
        'id' => (string) Str::uuid(), 'outbox_id' => $outboxId, 'event_type' => 'delivered',
        'attempt_number' => 1, 'payload_checksum' => $checksum, 'external_reference' => 'provider-reference',
        'provider_event_id' => $providerEventId, 'actor_user_id' => $admin->id, 'occurred_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/outbox/'.$outboxId.'/receipt', [
        'outcome' => 'delivered', 'payload_checksum' => $checksum,
        'external_reference' => 'provider-reference', 'provider_event_id' => $providerEventId,
    ])->assertNotFound();

    $recipient->givePermissionTo('hr.notifications.adapter.acknowledge');
    $foreignContext = UserContext::create([
        'user_id' => $recipient->id, 'context_type' => 'staff', 'context_id' => $foreignStaff->id,
        'is_active' => true, 'created_user_id' => $recipient->id,
    ]);
    $secondOutboxId = (string) Str::uuid();
    DB::table('hr_notification_outbox')->insert([
        'id' => $secondOutboxId, 'company_id' => $foreignCompany->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $foreignStaff->id, 'recipient_user_id' => $recipient->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => 'encrypted',
        'payload_checksum' => $checksum, 'idempotency_key' => 'foreign-notification-test-second',
        'status' => 'accepted_pending_receipt', 'attempt_count' => 1, 'available_at' => now(),
        'accepted_at' => now(), 'external_reference' => 'provider-reference',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($recipient, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $foreignContext->id,
    ])->postJson('/api/hr/notifications/outbox/'.$secondOutboxId.'/receipt', [
        'outcome' => 'delivered', 'payload_checksum' => $checksum,
        'external_reference' => 'provider-reference', 'provider_event_id' => $providerEventId,
    ])->assertConflict();

    $this->assertDatabaseHas('hr_notification_outbox', [
        'id' => $secondOutboxId, 'status' => 'accepted_pending_receipt',
    ]);
});

it('returns a privacy-safe projection for provider receipt writes and replays', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.acknowledge');
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $checksum = str_repeat('f', 64);
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'receipt-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => encrypt(['body' => 'private']),
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-receipt-projection-test',
        'status' => 'accepted_pending_receipt', 'attempt_count' => 1, 'available_at' => now(),
        'accepted_at' => now(), 'external_reference' => 'provider-reference',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/hr/notifications/outbox/'.$outboxId.'/receipt';
    $receipt = [
        'outcome' => 'failed', 'payload_checksum' => $checksum,
        'external_reference' => 'provider-reference', 'provider_event_id' => 'receipt-projection-test',
        'message' => 'Provider failure with Bearer provider-secret',
    ];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        actingAs($admin, 'api')->withHeaders($headers)->postJson($url, $receipt)->assertOk()
            ->assertJsonPath('data.id', $outboxId)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.last_error', 'Provider failure with [REDACTED]')
            ->assertJsonMissingPath('data.recipient_user_id')->assertJsonMissingPath('data.recipient_staff_id')
            ->assertJsonMissingPath('data.encrypted_rendered_payload');
    }
    $this->assertDatabaseHas('hr_notification_delivery_events', [
        'outbox_id' => $outboxId, 'message' => 'Provider failure with [REDACTED]',
    ]);
});

it('replays only an identical acknowledgement for the completed selected-company lease', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.acknowledge');
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $checksum = str_repeat('c', 64);
    $leaseToken = str_repeat('b', 64);
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'ack-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => 'encrypted',
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-ack-retry-test',
        'status' => 'delivering', 'attempt_count' => 1, 'available_at' => now(),
        'lease_token' => $leaseToken, 'leased_until' => now()->addMinutes(5),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $acknowledgement = [
        'outcome' => 'failed', 'payload_checksum' => $checksum, 'lease_token' => $leaseToken,
        'message' => 'Temporary adapter failure. Bearer adapter-secret',
    ];
    $url = '/api/hr/notifications/outbox/'.$outboxId.'/acknowledge';

    actingAs($admin, 'api')->withHeaders($headers)->postJson($url, $acknowledgement)
        ->assertOk()->assertJsonPath('data.status', 'retryable')
        ->assertJsonPath('data.last_error', 'Temporary adapter failure. [REDACTED]')
        ->assertJsonMissingPath('data.recipient_user_id')->assertJsonMissingPath('data.encrypted_rendered_payload');
    actingAs($admin, 'api')->withHeaders($headers)->postJson($url, $acknowledgement)
        ->assertOk()->assertJsonPath('data.status', 'retryable');
    actingAs($admin, 'api')->withHeaders($headers)->postJson($url, array_replace($acknowledgement, [
        'message' => 'Changed adapter failure.',
    ]))->assertConflict();

    expect(DB::table('hr_notification_delivery_events')->where('outbox_id', $outboxId)
        ->where('lease_token_hash', hash('sha256', $leaseToken))->value('message'))
        ->toBe('Temporary adapter failure. [REDACTED]');
});

it('returns the notification destination without a separate recipient user UUID', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.read');
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $payload = [
        'destination' => 'staff@example.test', 'subject' => 'Schedule update', 'body' => 'A schedule changed.',
        'source_type' => 'test', 'source_id' => (string) Str::uuid(),
    ];
    $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'claim-test', 'version' => 1,
        'event_type' => 'schedule_changed', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'schedule_changed', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => $payload['source_id'], 'encrypted_rendered_payload' => encrypt($payload),
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-claim-privacy-test',
        'status' => 'pending_delivery', 'attempt_count' => 0, 'available_at' => now()->subMinute(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/outbox/'.$outboxId.'/claim')
        ->assertOk()->assertJsonPath('data.payload.destination', 'staff@example.test')
        ->assertJsonMissingPath('data.recipient_user_id')->assertJsonMissingPath('data.encrypted_rendered_payload');

    $corruptOutboxId = (string) Str::uuid();
    DB::table('hr_notification_outbox')->insert([
        'id' => $corruptOutboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'schedule_changed', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(),
        'encrypted_rendered_payload' => encrypt(array_replace($payload, ['body' => 'Tampered body'])),
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-claim-corrupt-test',
        'status' => 'pending_delivery', 'attempt_count' => 0, 'available_at' => now()->subMinute(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/outbox/'.$corruptOutboxId.'/claim')
        ->assertConflict()->assertJsonPath('message', 'Queued HR notification integrity check failed.');
    $this->assertDatabaseHas('hr_notification_outbox', [
        'id' => $corruptOutboxId, 'status' => 'pending_delivery', 'attempt_count' => 0,
        'lease_token' => null, 'leased_until' => null,
    ]);
    expect(DB::table('hr_notification_delivery_events')->where('outbox_id', $corruptOutboxId)->count())->toBe(0);
});

it('reclaims a delivering notification whose lease expiry is missing', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.read');
    config(['hr.features.engagement_analytics' => true, 'hr.notification_max_attempts' => 3]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    $payload = ['destination' => 'staff@example.test', 'subject' => 'Update', 'body' => 'A work update.'];
    $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'missing-lease-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => encrypt($payload),
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-missing-lease-test',
        'status' => 'delivering', 'attempt_count' => 1, 'available_at' => now()->subMinute(),
        'lease_token' => str_repeat('a', 64), 'leased_until' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/notifications/delivery-queue')
        ->assertOk()->assertJsonPath('data.data.0.id', $outboxId);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/outbox/'.$outboxId.'/claim')
        ->assertOk()->assertJsonPath('data.attempt_count', 2);

    $this->assertDatabaseHas('hr_notification_outbox', [
        'id' => $outboxId, 'status' => 'delivering', 'attempt_count' => 2,
    ]);
    expect(DB::table('hr_notification_delivery_events')->where('outbox_id', $outboxId)
        ->where('event_type', 'reclaimed')->exists())->toBeTrue();
});

it('exposes an expired final-attempt lease so the adapter can close it as failed', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    $admin->givePermissionTo('hr.notifications.adapter.read');
    config(['hr.features.engagement_analytics' => true, 'hr.notification_max_attempts' => 2]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    $leaseToken = str_repeat('a', 64);
    $checksum = str_repeat('b', 64);
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'expired-lease-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'email', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $staff->id, 'recipient_user_id' => $admin->id,
        'event_type' => 'test_event', 'channel' => 'email', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'encrypted_rendered_payload' => 'unused-at-attempt-limit',
        'payload_checksum' => $checksum, 'idempotency_key' => 'notification-expired-final-lease-test',
        'status' => 'delivering', 'attempt_count' => 2, 'available_at' => now()->subMinutes(3),
        'lease_token' => $leaseToken, 'leased_until' => now()->subMinute(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/notifications/delivery-queue')
        ->assertOk()->assertJsonPath('data.data.0.id', $outboxId);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/outbox/'.$outboxId.'/claim')
        ->assertConflict();

    $this->assertDatabaseHas('hr_notification_outbox', [
        'id' => $outboxId, 'status' => 'failed', 'lease_token' => null, 'leased_until' => null,
    ]);
    $this->assertDatabaseHas('hr_notification_delivery_events', [
        'outbox_id' => $outboxId, 'event_type' => 'failed', 'attempt_number' => 2,
    ]);
});

it('honors an integer false opt-out and keeps mandatory notifications enabled', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $templateId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'preference-test', 'version' => 1,
        'event_type' => 'optional_update', 'channel' => 'email', 'body_template' => 'A change occurred.',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('d', 64), 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_preferences')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'event_type' => 'optional_update', 'channel' => 'email', 'enabled' => false,
        'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $notifications = app(HrNotificationService::class);
    expect($notifications->queue($company->id, 'optional_update', 'test', (string) Str::uuid(), $staff->id, []))
        ->toBe([]);

    DB::table('hr_notification_template_versions')->where('id', $templateId)->update(['mandatory' => true]);
    $rows = $notifications->queue($company->id, 'optional_update', 'test', (string) Str::uuid(), $staff->id, []);
    expect($rows)->toHaveCount(1)->and($rows[0]->channel)->toBe('email');
});

it('reuses only an identical notification queue fact for its idempotency key', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $templateId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'queue-idempotency-test', 'version' => 1,
        'event_type' => 'schedule_changed', 'channel' => 'email', 'body_template' => '{{message}}',
        'allowed_placeholders' => json_encode(['message']), 'mandatory' => true, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('e', 64), 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sourceId = (string) Str::uuid();
    $service = app(HrNotificationService::class);
    $first = $service->queue($company->id, 'schedule_changed', 'schedule', $sourceId, $staff->id, ['message' => 'Original']);
    $retry = $service->queue($company->id, 'schedule_changed', 'schedule', $sourceId, $staff->id, ['message' => 'Original']);

    expect($retry)->toHaveCount(1)->and($retry[0]->id)->toBe($first[0]->id)
        ->and(DB::table('hr_notification_outbox')->where('idempotency_key', 'hr-notification:'.$templateId.':schedule:'.$sourceId.':'.$staff->id)->count())->toBe(1);

    expect(fn () => $service->queue($company->id, 'schedule_changed', 'schedule', $sourceId, $staff->id, ['message' => 'Changed']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Notification idempotency key was reused with different content.');
});

it('returns only minimal confirmation fields when notification templates are created or decided', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.notifications.manage', 'hr.notifications.approve']);
    config(['hr.features.engagement_analytics' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->where('company_id', $company->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $author = User::factory()->create();
    $created = actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/templates', [
        'code' => 'minimal-create', 'version' => 1, 'event_type' => 'minimal_create', 'channel' => 'email',
        'subject_template' => 'Private subject', 'body_template' => 'Private body', 'allowed_placeholders' => [],
        'mandatory' => false, 'effective_from' => '2026-01-01',
    ]);
    $created->assertCreated()->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.created_by')
        ->assertJsonMissingPath('data.subject_template')->assertJsonMissingPath('data.body_template')
        ->assertJsonMissingPath('data.allowed_placeholders')->assertJsonMissingPath('data.template_checksum');
    expect(array_keys($created->json('data')))->toEqualCanonicalizing(['id', 'status']);

    foreach (['approve', 'reject'] as $decision) {
        $id = (string) Str::uuid();
        DB::table('hr_notification_template_versions')->insert([
            'id' => $id, 'company_id' => $company->id, 'code' => 'minimal-'.$decision, 'version' => 1,
            'event_type' => 'minimal_'.$decision, 'channel' => 'email', 'subject_template' => 'Private subject',
            'body_template' => 'Private body', 'allowed_placeholders' => json_encode([]), 'mandatory' => false,
            'effective_from' => '2026-01-01', 'status' => 'pending_approval', 'template_checksum' => str_repeat('c', 64),
            'created_by' => $author->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = '/api/hr/notifications/templates/'.$id.'/'.$decision;
        $response = $decision === 'approve'
            ? actingAs($admin, 'api')->withHeaders($headers)->postJson($url, [])
            : actingAs($admin, 'api')->withHeaders($headers)->postJson($url, ['reason' => 'Private decision reason']);
        $response->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', $decision === 'approve' ? 'approved' : 'rejected')
            ->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.approved_by')->assertJsonMissingPath('data.decision_reason')
            ->assertJsonMissingPath('data.subject_template')->assertJsonMissingPath('data.body_template');
        expect(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'status']);
    }
});

it('fails queued notifications when the recipient Staff belongs to another company', function () {
    [$admin, $company] = hr_seed_admin_actor([], true);
    config(['hr.features.engagement_analytics' => true, 'hr.system_user_id' => $admin->id]);
    $foreignCompany = Company::create(['name' => 'Foreign queued-notification company']);
    $recipient = User::factory()->create();
    $foreignStaff = Staff::factory()->create(['user_id' => $recipient->id, 'company_id' => $foreignCompany->id]);
    $templateId = (string) Str::uuid();
    $outboxId = (string) Str::uuid();
    $payload = [
        'destination' => $recipient->email, 'subject' => 'Private subject', 'body' => 'Private notification',
        'source_type' => 'test', 'source_id' => (string) Str::uuid(),
    ];
    $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'foreign-recipient-test', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'in_app', 'body_template' => 'test',
        'allowed_placeholders' => json_encode([]), 'mandatory' => false, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => $checksum, 'created_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_notification_outbox')->insert([
        'id' => $outboxId, 'company_id' => $company->id, 'template_version_id' => $templateId,
        'recipient_staff_id' => $foreignStaff->id, 'recipient_user_id' => $recipient->id,
        'event_type' => 'test_event', 'channel' => 'in_app', 'source_type' => 'test',
        'source_id' => $payload['source_id'], 'encrypted_rendered_payload' => encrypt($payload),
        'payload_checksum' => $checksum, 'idempotency_key' => 'foreign-recipient-queued-test',
        'status' => 'queued', 'attempt_count' => 0, 'available_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('hr:process-notifications', ['--commit' => true]);

    $this->assertDatabaseHas('hr_notification_outbox', ['id' => $outboxId, 'status' => 'failed']);
    $this->assertDatabaseHas('hr_notification_delivery_events', [
        'outbox_id' => $outboxId, 'event_type' => 'failed', 'attempt_number' => 0,
        'message' => 'Notification recipient is no longer active in the notification company.',
    ]);
    expect(DB::table('hr_notification_delivery_events')->where('outbox_id', $outboxId)->where('event_type', 'delivered')->exists())
        ->toBeFalse();
});
