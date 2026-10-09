<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('derives phone references and subscription ownership from the selected Staff context', function () {
    [$user, $firstCompany] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $secondCompany = Company::create(['name' => 'Second phone company']);
    $actorStaff = Staff::query()->where('user_id', $user->id)->where('company_id', $firstCompany->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')
        ->where('context_id', $actorStaff->id)->where('is_active', true)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/hr/assets/phone-reference-options?record_type=staff')
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $actorStaff->id);

    $subscriptionResponse = actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/assets/phone-subscriptions', [
        'subscription_code' => 'CTX-PHONE-01', 'carrier' => 'Carrier', 'masked_msisdn' => '07X XXX XX01',
        'identifiers' => ['imei' => '123456789012345'], 'roaming_allowed' => false, 'data_allowed' => true,
    ])->assertCreated();

    DB::table('hr_phone_usage_allocations')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'company_id' => $firstCompany->id,
        'phone_subscription_id' => $subscriptionResponse->json('data.id'),
        'staff_id' => $actorStaff->id,
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'company_amount' => 100,
        'employee_excess_amount' => 0,
        'currency' => 'LKR',
        'policy_snapshot' => '{}',
        'status' => 'approved',
        'reviewed_by' => $user->id,
        'reviewed_at' => now(),
        'source_checksum' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAs($user, 'api')->withHeaders($headers)->getJson('/api/hr/assets/phone-usage')
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonMissingPath('data.data.0.reviewed_by')
        ->assertJsonPath('data.data.0.status', 'approved');

    expect(DB::table('hr_phone_subscriptions')->where('subscription_code', 'CTX-PHONE-01')->value('company_id'))
        ->toBe($firstCompany->id)->not->toBe($secondCompany->id);
});
