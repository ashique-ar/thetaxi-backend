<?php

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\HrNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues notifications across the installation and blocks delivery to inactive recipients', function () {
    $systemUser = User::factory()->create();
    $deletedUser = User::factory()->create();
    $inactiveUser = User::factory()->create();
    $deletedStaff = Staff::factory()->create(['user_id' => $deletedUser->id]);
    $inactiveStaff = Staff::factory()->create(['user_id' => $inactiveUser->id]);
    $templateId = (string) Str::uuid();
    DB::table('hr_notification_template_versions')->insert([
        'id' => $templateId, 'code' => 'recipient-lifecycle', 'version' => 1,
        'event_type' => 'test_event', 'channel' => 'in_app', 'body_template' => 'Test notification',
        'allowed_placeholders' => json_encode([]), 'mandatory' => true, 'effective_from' => '2026-01-01',
        'status' => 'approved', 'template_checksum' => str_repeat('a', 64), 'created_by' => $systemUser->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(HrNotificationService::class);
    $deletedOutbox = $service->queue('test_event', 'test', (string) Str::uuid(), $deletedStaff->id, [])[0];
    $inactiveOutbox = $service->queue('test_event', 'test', (string) Str::uuid(), $inactiveStaff->id, [])[0];
    $deletedStaff->delete();
    $inactiveUser->update(['is_active' => false]);

    expect($service->recipientIsActive($deletedStaff->id, $deletedUser->id))->toBeFalse()
        ->and($service->recipientIsActive($inactiveStaff->id, $inactiveUser->id))->toBeFalse()
        ->and($service->queue('test_event', 'test', (string) Str::uuid(), $deletedStaff->id, []))->toBe([])
        ->and($service->queue('test_event', 'test', (string) Str::uuid(), $inactiveStaff->id, []))->toBe([]);

    config(['hr.features.engagement_analytics' => true, 'hr.system_user_id' => $systemUser->id]);
    Artisan::call('hr:process-notifications', ['--commit' => true]);

    foreach ([$deletedOutbox, $inactiveOutbox] as $outbox) {
        $this->assertDatabaseHas('hr_notification_outbox', ['id' => $outbox->id, 'status' => 'failed']);
        $this->assertDatabaseHas('hr_notification_delivery_events', [
            'outbox_id' => $outbox->id, 'event_type' => 'failed',
            'message' => 'Notification recipient is no longer active.',
        ]);
    }
    expect(DB::table('notifications')->whereIn('notifiable_id', [$deletedUser->id, $inactiveUser->id])->exists())->toBeFalse();
});
