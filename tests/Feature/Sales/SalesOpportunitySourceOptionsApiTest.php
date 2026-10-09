<?php

use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\Company;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates only the actor scoped unlinked opportunity sources', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config()->set('sales.features.sales_profiles', true);
    config()->set('sales.features.crm', true);
    SalesCompanyFeatureSetting::query()->create([
        'company_id' => $company->id, 'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
    ]);
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $staff = Staff::factory()->create(['user_id' => $manager->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $manager->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $manager->id]);
    $profile = SalesProfile::query()->create([
        'company_id' => $company->id, 'staff_id' => $staff->id, 'sales_code' => 'SOURCE-OWNER',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);
    $otherCompany = Company::create(['name' => 'Other CRM source company']);
    $otherUser = User::factory()->create();
    $otherStaff = Staff::factory()->create(['user_id' => $otherUser->id, 'company_id' => $otherCompany->id]);
    UserContext::create(['user_id' => $otherUser->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    SalesProfile::query()->create([
        'company_id' => $otherCompany->id, 'staff_id' => $otherStaff->id, 'sales_code' => 'OTHER-SOURCE-OWNER',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true,
    ]);

    $insertInquiry = function (string $number, ?string $assignedTo, ?string $createdBy, ?string $deletedAt = null): string {
        $id = (string) Str::uuid();
        DB::table('inquiries')->insert([
            'id' => $id, 'inquiry_number' => $number, 'inquiry_type' => 'sales', 'message' => 'Do not expose this message.',
            'name' => 'Prospect '.$number, 'email' => $number.'@example.test', 'phone' => '0770000000',
            'subject' => 'Request '.$number, 'status' => 'open', 'source' => 'web',
            'assigned_to' => $assignedTo, 'created_user_id' => $createdBy, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
        ]);
        return $id;
    };
    $ownInquiry = $insertInquiry('INQ-OWN-001', $manager->id, $admin->id);
    $insertInquiry('INQ-OWN-002', $manager->id, null);
    $linkedInquiry = $insertInquiry('INQ-LINKED-001', $manager->id, null);
    $foreignInquiry = $insertInquiry('INQ-OTHER-001', $otherUser->id, $otherUser->id);
    $insertInquiry('INQ-DELETED-001', $manager->id, null, now());

    $insertPhoneCall = function (string $name, string $createdBy): string {
        $id = (string) Str::uuid();
        DB::table('phone_calls')->insert([
            'id' => $id, 'client_name' => $name, 'phone' => '0771111111', 'summary' => 'Private call summary',
            'call_time' => now()->toDateString(), 'created_user_id' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };
    $ownCall = $insertPhoneCall('Scoped caller', $manager->id);
    $linkedCall = $insertPhoneCall('Linked caller', $manager->id);
    $foreignCall = $insertPhoneCall('Other caller', $otherUser->id);
    foreach ([['INQ-LINKED-OPP', $linkedInquiry, null], ['CALL-LINKED-OPP', null, $linkedCall]] as [$number, $inquiryId, $callId]) {
        SalesOpportunity::query()->create([
            'company_id' => $company->id, 'owner_sales_profile_id' => $profile->id,
            'inquiry_id' => $inquiryId, 'source_phone_call_id' => $callId,
            'opportunity_number' => $number, 'name' => $number, 'source' => 'sales',
            'expected_value_source' => 0, 'source_currency' => 'LKR', 'expected_value_lkr' => 0,
            'created_user_id' => $admin->id,
        ]);
    }

    $duplicatePayload = [
        'company_id' => $company->id, 'owner_sales_profile_id' => $profile->id,
        'name' => 'Duplicate source test', 'expected_value_source' => 0, 'source_currency' => 'LKR',
        'expected_value_lkr' => 0, 'probability_percent' => 0,
    ];
    actingAs($manager, 'api')->postJson('/api/sales/opportunities', $duplicatePayload + ['inquiry_id' => $linkedInquiry])
        ->assertStatus(409)->assertJsonPath('message', 'This inquiry is already linked to an opportunity.');
    actingAs($manager, 'api')->postJson('/api/sales/opportunities', $duplicatePayload + ['source_phone_call_id' => $linkedCall])
        ->assertStatus(409)->assertJsonPath('message', 'This Phone Call is already linked to an opportunity.');
    actingAs($manager, 'api')->postJson('/api/sales/opportunities', $duplicatePayload + ['inquiry_id' => $foreignInquiry])
        ->assertForbidden();
    actingAs($manager, 'api')->postJson('/api/sales/opportunities', $duplicatePayload + ['source_phone_call_id' => $foreignCall])
        ->assertForbidden();
    actingAs($manager, 'api')->postJson('/api/sales/opportunities', $duplicatePayload + [
        'inquiry_id' => $ownInquiry, 'source_phone_call_id' => $ownCall,
    ])->assertUnprocessable();
    expect(SalesOpportunity::query()->count())->toBe(2);

    $url = '/api/sales/opportunity-source-options?company_id='.$company->id;
    actingAs($manager, 'api')->getJson('/api/sales/opportunity-source-options?source_type=inquiry')
        ->assertOk()->assertJsonPath('data.data.0.value', $ownInquiry);
    $searched = actingAs($manager, 'api')->getJson($url.'&source_type=inquiry&search=INQ-OWN&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $ownInquiry)
        ->assertJsonPath('data.data.0.record', null)
        ->assertJsonPath('data.last_page', 2);
    expect(json_encode($searched->json('data.data.0')))->not->toContain('example.test', 'Do not expose this message.');

    actingAs($manager, 'api')->getJson($url.'&source_type=inquiry&selected_id='.$ownInquiry.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $ownInquiry)
        ->assertJsonPath('data.data.0.record.email', 'INQ-OWN-001@example.test');
    actingAs($manager, 'api')->getJson($url.'&source_type=inquiry&selected_id='.$foreignInquiry)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($manager, 'api')->getJson($url.'&source_type=inquiry&selected_id='.$linkedInquiry)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($manager, 'api')->getJson($url.'&source_type=phone_call&search=Scoped')
        ->assertOk()->assertJsonPath('data.data.0.value', $ownCall)
        ->assertJsonPath('data.data.0.record', null);
    actingAs($manager, 'api')->getJson($url.'&source_type=phone_call&selected_id='.$ownCall)
        ->assertOk()->assertJsonPath('data.data.0.record.summary', 'Private call summary');
    actingAs($manager, 'api')->getJson($url.'&source_type=phone_call&selected_id='.$foreignCall)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($manager, 'api')->getJson($url.'&source_type=phone_call&selected_id='.$linkedCall)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($manager, 'api')->getJson($url.'&source_type=other')->assertUnprocessable();
    actingAs($manager, 'api')->getJson($url.'&source_type=phone_call&per_page=51')->assertUnprocessable();

    $ambiguousCompany = Company::create(['name' => 'Ambiguous CRM source company']);
    actingAs($manager, 'api')->getJson('/api/sales/opportunity-source-options?company_id='.$ambiguousCompany->id.'&source_type=inquiry')
        ->assertUnprocessable();
});
