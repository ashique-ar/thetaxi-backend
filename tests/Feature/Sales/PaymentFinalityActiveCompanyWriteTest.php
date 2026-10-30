<?php

use App\Models\Staff;
use App\Models\Company;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates payment-finality policies without a company assignment', function () {
    [, $company] = hr_seed_admin_actor();
    $maker = Staff::factory()->create(['company_id' => $company->id]);
    $checker = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $maker->user_id, 'context_type' => 'staff', 'context_id' => $maker->id, 'is_active' => true, 'created_user_id' => $maker->user_id]);
    UserContext::create(['user_id' => $checker->user_id, 'context_type' => 'staff', 'context_id' => $checker->id, 'is_active' => true, 'created_user_id' => $maker->user_id]);
    $maker->user->givePermissionTo('sales.payment-finality.manage');
    $checker->user->givePermissionTo('sales.payment-finality.approve');

    $policyId = (string) Str::uuid();
    DB::table('booking_payment_finality_policies')->insert([
        'id' => $policyId,
        'payment_method' => 'cash',
        'official_collection_state' => 'confirmed',
        'can_earn_before_final' => false,
        'hold_payout_until_final' => true,
        'effective_from' => now(),
        'version' => 1,
        'status' => 'draft',
        'created_by' => $maker->user_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $storePayload = [
        'payment_method' => 'cash',
        'official_collection_state' => 'confirmed',
        'can_earn_before_final' => false,
        'hold_payout_until_final' => true,
        'effective_from' => now()->toDateString(),
    ];
    $company->update(['is_active' => false]);
    actingAs($maker->user, 'api')->postJson('/api/sales/payment-finality-policies', $storePayload)->assertCreated();
    actingAs($checker->user, 'api')->postJson('/api/sales/payment-finality-policies/'.$policyId.'/approve')
        ->assertOk();
    $this->assertDatabaseHas('booking_payment_finality_policies', [
        'payment_method' => 'cash', 'status' => 'draft', 'created_by' => $maker->user_id,
    ]);
});

it('replays finality-policy approval for its original checker and rejects another checker', function () {
    [, $company] = hr_seed_admin_actor();
    $maker = Staff::factory()->create(['company_id' => $company->id]);
    $checker = Staff::factory()->create(['company_id' => $company->id]);
    $otherChecker = Staff::factory()->create(['company_id' => $company->id]);
    foreach ([$maker, $checker, $otherChecker] as $staff) {
        UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
            'is_active' => true, 'created_user_id' => $maker->user_id]);
    }
    $maker->user->givePermissionTo('sales.payment-finality.manage');
    $checker->user->givePermissionTo('sales.payment-finality.approve');
    $otherChecker->user->givePermissionTo('sales.payment-finality.approve');
    $policyId = (string) Str::uuid();
    DB::table('booking_payment_finality_policies')->insert([
        'id' => $policyId, 'payment_method' => 'cash',
        'official_collection_state' => 'confirmed', 'can_earn_before_final' => false,
        'hold_payout_until_final' => true, 'effective_from' => now()->toDateString(),
        'version' => 1, 'status' => 'draft', 'created_by' => $maker->user_id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/sales/payment-finality-policies/'.$policyId.'/approve';

    actingAs($checker->user, 'api')->postJson($url)->assertOk();
    $approved = DB::table('booking_payment_finality_policies')->where('id', $policyId)->first();
    actingAs($checker->user, 'api')->postJson($url)->assertOk();
    expect(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('approved_by'))->toBe($checker->user_id)
        ->and(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('approved_at'))->toBe($approved->approved_at)
        ->and(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('updated_at'))->toBe($approved->updated_at)
        ->and(DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->count())->toBe(1);
    expect(json_decode((string) DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->value('properties'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['status' => 'approved',
            'effective_from' => \Illuminate\Support\Carbon::parse($approved->effective_from)->toISOString(), 'version' => 1]);

    actingAs($otherChecker->user, 'api')->postJson($url)->assertStatus(409);
    expect(DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->count())->toBe(1);
});
