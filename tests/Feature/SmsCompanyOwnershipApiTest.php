<?php

use App\Models\Company;
use App\Models\Booking\Booking;
use App\Models\Sms\SmsCampaign;
use App\Models\Sms\SmsMessage;
use App\Models\Staff;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits SMS overview and message reads to the active default company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo(['sms.overview.view', 'sms.messages.view']);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $foreignCompany = Company::create(['name' => 'Foreign SMS company', 'is_active' => true]);

    $makeMessage = function (?string $ownerCompanyId, string $name) use ($admin): string {
        $campaignId = $ownerCompanyId
            ? SmsCampaign::query()->create([
                'company_id' => $ownerCompanyId,
                'name' => $name,
                'message' => 'Campaign content',
                'status' => 'draft',
                'audience_type' => 'manual',
                'created_user_id' => $admin->id,
            ])->id
            : null;
        $messageId = (string) Str::uuid();
        DB::table('sms_messages')->insert([
            'id' => $messageId,
            'company_id' => $ownerCompanyId,
            'campaign_id' => $campaignId,
            'recipient' => '+94770000000',
            'normalized_recipient' => '94770000000',
            'message' => $name,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    };

    $ownedMessageId = $makeMessage($company->id, 'Owned SMS');
    $foreignMessageId = $makeMessage($foreignCompany->id, 'Foreign SMS');
    $unownedMessageId = $makeMessage(null, 'Unowned legacy SMS');
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => DB::table('user_contexts')
        ->where('user_id', $admin->id)->where('context_id', $staff->id)->value('id')];

    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/overview')->assertOk()
        ->assertJsonPath('data.messages.total', 1)
        ->assertJsonPath('data.campaigns.total', 1)
        ->assertJsonPath('data.recent_messages.0.id', $ownedMessageId);
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/messages')
        ->assertOk()->assertJsonPath('data.0.id', $ownedMessageId);
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/messages/'.$unownedMessageId)
        ->assertNotFound();
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/messages/'.$foreignMessageId)
        ->assertNotFound();

    app(SmsService::class)->processQueuedMessage(SmsMessage::query()->findOrFail($unownedMessageId));
    $this->assertDatabaseHas('sms_messages', [
        'id' => $unownedMessageId,
        'status' => 'failed',
        'error_message' => 'SMS ownership is unavailable for the active default company.',
    ]);
});

it('limits booking timeline SMS entries to the active default company', function () {
    [, $company] = hr_seed_admin_actor();
    $foreignCompany = Company::create(['name' => 'Foreign timeline company', 'is_active' => true]);
    $booking = Booking::create(['status' => 'confirmed']);
    $messageIds = [];

    foreach ([$company->id, $foreignCompany->id] as $ownerCompanyId) {
        $messageId = (string) Str::uuid();
        DB::table('sms_messages')->insert([
            'id' => $messageId,
            'company_id' => $ownerCompanyId,
            'booking_id' => $booking->id,
            'recipient' => '+94770000000',
            'normalized_recipient' => '94770000000',
            'message' => 'Timeline SMS',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $messageIds[] = $messageId;
    }
    expect(fn () => app(\App\Services\Sms\BookingCommunicationActivityService::class)
        ->recordMessage(SmsMessage::query()->findOrFail($messageIds[1]), 'queued'))
        ->toThrow(RuntimeException::class, 'Booking communication activity requires the active default company.');
    $this->assertDatabaseMissing('booking_activities', ['sms_message_id' => $messageIds[1]]);

    $activityIds = [];
    foreach ([$company->id, $foreignCompany->id, null] as $ownerCompanyId) {
        $activityId = (string) Str::uuid();
        DB::table('booking_activities')->insert([
            'id' => $activityId,
            'company_id' => $ownerCompanyId,
            'booking_id' => $booking->id,
            'event_key' => 'booking.confirmed',
            'channel' => 'sms',
            'result_status' => 'skipped',
            'source' => 'automation',
            'title' => 'SMS decision',
            'idempotency_key' => $activityId,
            'event_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $activityIds[] = $activityId;
    }

    DB::table('booking_activities')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $foreignCompany->id,
        'booking_id' => $booking->id,
        'event_key' => 'booking.communication.test',
        'channel' => 'timeline',
        'result_status' => 'recorded',
        'source' => 'automation',
        'title' => 'Foreign activity',
        'idempotency_key' => 'cross-company-activity-key',
        'event_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(fn () => app(\App\Services\Sms\BookingCommunicationActivityService::class)->record([
        'company_id' => $company->id,
        'booking_id' => $booking->id,
        'event_key' => 'booking.communication.test',
        'result_status' => 'recorded',
        'title' => 'Replay attempt',
        'idempotency_key' => 'cross-company-activity-key',
    ]))->toThrow(RuntimeException::class, 'This booking communication activity key belongs to another company.');

    $items = collect(app(\App\Services\BookingObservabilityService::class)->communications($booking, null)['items']);

    expect($items->pluck('sms_message_id')->all())
        ->toContain($messageIds[0])
        ->not->toContain($messageIds[1]);
    expect($items->pluck('id')->all())
        ->toContain('booking-activity-'.$activityIds[0])
        ->not->toContain('booking-activity-'.$activityIds[1], 'booking-activity-'.$activityIds[2]);
});

it('does not apply provider callbacks to another company message', function () {
    hr_seed_admin_actor();
    $foreignCompany = Company::create(['name' => 'Foreign callback company', 'is_active' => true]);
    $messageId = (string) Str::uuid();
    DB::table('sms_messages')->insert([
        'id' => $messageId,
        'company_id' => $foreignCompany->id,
        'provider_transaction_id' => 'foreign-provider-transaction',
        'recipient' => '+94770000000',
        'normalized_recipient' => '94770000000',
        'message' => 'Foreign company message',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(SmsService::class)->markDelivery([
        'transaction_id' => 'foreign-provider-transaction',
        'status' => 'delivered',
    ]);

    expect($result)->toBe(['updated' => false]);
    $this->assertDatabaseHas('sms_messages', [
        'id' => $messageId,
        'company_id' => $foreignCompany->id,
        'status' => 'sent',
    ]);
});
