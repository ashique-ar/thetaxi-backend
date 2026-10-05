<?php

use App\Models\Staff;
use App\Models\UserContext;
use App\Services\Hr\HrNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('stops enqueue and worker delivery when a notification company is inactive', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Notification company lifecycle', 'is_active' => true]);
    $actor->givePermissionTo('hr.notifications.adapter.read');
    $staff = Staff::query()->where('company_id', $company->id)->where('user_id', $actor->id)->firstOrFail();
    $templateId = (string) Str::uuid();
    $sourceId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'code' => 'inactive-company', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'in_app', 'body_template' => 'Test notification',
        'allowed_placeholders' => json_encode([]), 'mandatory' => true, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('a', 64), 'created_by' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(HrNotificationService::class);
    $queued = $service->queue($company->id, 'test_event', 'test', $sourceId, $staff->id, []);
    expect($queued)->toHaveCount(1);

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    expect($service->queue($company->id, 'test_event', 'test', (string) Str::uuid(), $staff->id, []))->toBe([]);

    config(['hr.features.engagement_analytics' => true, 'hr.system_user_id' => $actor->id]);
    $context = UserContext::query()->where('user_id', $actor->id)->where('context_type', 'staff')->where('context_id', $staff->id)->firstOrFail();
    actingAs($actor, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/notifications/outbox/'.$queued[0]->id.'/claim')
        ->assertConflict()->assertJsonMissingPath('data.payload');

    $workerOutbox = (array) $queued[0];
    $workerOutbox['id'] = (string) Str::uuid();
    $workerOutbox['idempotency_key'] = 'inactive-company-worker-replay';
    $workerOutbox['status'] = 'queued';
    $workerOutbox['attempt_count'] = 0;
    $workerOutbox['available_at'] = now();
    $workerOutbox['created_at'] = now();
    $workerOutbox['updated_at'] = now();
    DB::table('hr_notification_outbox')->insert($workerOutbox);
    Artisan::call('hr:process-notifications', ['--commit' => true]);

    $outboxId = $queued[0]->id;
    $this->assertDatabaseHas('hr_notification_outbox', ['id' => $outboxId, 'status' => 'failed']);
    $this->assertDatabaseHas('hr_notification_delivery_events', [
        'outbox_id' => $outboxId,
        'event_type' => 'failed',
        'message' => 'Notification company is no longer active.',
    ]);
    $this->assertDatabaseHas('hr_notification_outbox', ['id' => $workerOutbox['id'], 'status' => 'failed']);
    expect(DB::table('notifications')->where('notifiable_id', $actor->id)->exists())->toBeFalse();
});
