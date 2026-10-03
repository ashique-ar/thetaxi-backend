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
    $secondStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    actingAs($user, 'api')->getJson('/api/hr/assets/phone-reference-options?record_type=staff')
        ->assertForbidden()->assertJsonPath('message', 'Select an active Staff context.');

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/hr/assets/phone-reference-options?record_type=staff')
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $secondStaff->id);

    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/assets/phone-subscriptions', [
        'subscription_code' => 'CTX-PHONE-01', 'carrier' => 'Carrier', 'masked_msisdn' => '07X XXX XX01',
        'identifiers' => ['imei' => '123456789012345'], 'roaming_allowed' => false, 'data_allowed' => true,
    ])->assertCreated();

    expect(DB::table('hr_phone_subscriptions')->where('subscription_code', 'CTX-PHONE-01')->value('company_id'))
        ->toBe($secondCompany->id)->not->toBe($firstCompany->id);
});
