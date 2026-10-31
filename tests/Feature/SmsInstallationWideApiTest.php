<?php

use App\Models\Booking\Booking;
use App\Models\Sms\SmsCampaign;
use App\Models\Sms\SmsMessage;
use App\Models\Staff;
use App\Services\BookingObservabilityService;
use App\Services\Sms\BookingCommunicationActivityService;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('reads installation-wide SMS campaigns and messages under permissions', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo(['sms.overview.view', 'sms.messages.view']);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $contextId = DB::table('user_contexts')
        ->where('user_id', $admin->id)
        ->where('context_id', $staff->id)
        ->value('id');
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $contextId];

    $campaign = SmsCampaign::query()->create([
        'name' => 'Installation campaign',
        'message' => 'Campaign content',
        'status' => 'draft',
        'audience_type' => 'manual',
        'created_user_id' => $admin->id,
    ]);
    $firstMessage = SmsMessage::query()->create([
        'campaign_id' => $campaign->id,
        'channel' => 'single',
        'recipient' => '+94770000000',
        'normalized_recipient' => '94770000000',
        'message' => 'First SMS',
        'status' => 'queued',
    ]);
    $secondMessage = SmsMessage::query()->create([
        'channel' => 'single',
        'recipient' => '+94770000001',
        'normalized_recipient' => '94770000001',
        'message' => 'Second SMS',
        'status' => 'sent',
    ]);

    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/overview')->assertOk()
        ->assertJsonPath('data.messages.total', 2)
        ->assertJsonPath('data.campaigns.total', 1);

    $list = actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/messages')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())
        ->toContain($firstMessage->id, $secondMessage->id);
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/sms/messages/'.$secondMessage->id)->assertOk()
        ->assertJsonPath('data.message.id', $secondMessage->id);
});

it('keeps booking communication history installation-wide and idempotent', function () {
    $booking = Booking::query()->create(['status' => 'confirmed']);
    $messages = collect(['first', 'second'])->map(fn (string $recipient) => SmsMessage::query()->create([
        'booking_id' => $booking->id,
        'channel' => 'transactional',
        'event_key' => 'booking.confirmed',
        'recipient' => '+94770000000',
        'normalized_recipient' => '94770000000',
        'message' => 'Booking confirmation',
        'status' => 'sent',
    ]));

    $activities = app(BookingCommunicationActivityService::class);
    $first = $activities->recordMessage($messages[1], 'sent');
    $replay = $activities->recordMessage($messages[1], 'sent');

    expect($replay->id)->toBe($first->id);
    $items = collect(app(BookingObservabilityService::class)->communications($booking, null)['items']);
    expect($items->pluck('sms_message_id')->all())->toContain($messages[0]->id, $messages[1]->id);
    $this->assertDatabaseCount('booking_activities', 1);
});

it('applies provider callbacks to the matching installation-wide message', function () {
    $message = SmsMessage::query()->create([
        'provider_transaction_id' => 'provider-transaction-1',
        'channel' => 'single',
        'recipient' => '+94770000000',
        'normalized_recipient' => '94770000000',
        'message' => 'Callback target',
        'status' => 'sent',
    ]);

    expect(app(SmsService::class)->markDelivery([
        'transaction_id' => 'provider-transaction-1',
        'status' => 'delivered',
    ]))->toBe(['updated' => true, 'message_id' => $message->id]);
    $this->assertDatabaseHas('sms_messages', ['id' => $message->id, 'status' => 'delivered']);
});
