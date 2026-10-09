<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesCommissionPlanFamily;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionCycleVersion;
use App\Models\Sales\SalesCommissionBusinessCalendar;
use App\Models\Sales\SalesCommissionPlanAssignment;
use App\Models\Sales\SalesCommissionStaffOverride;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates only non-deleted companies belonging to an active Staff identity', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Scoped Commission Company', 'city' => 'Colombo']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('sales.commission-config.view');
    $foreign = Company::create(['name' => 'Foreign Commission Company']);
    $deleted = Company::create(['name' => 'Deleted Commission Company']);
    $deleted->delete();
    $url = '/api/sales/commission-configuration/company-options';

    $response = actingAs($actor->user, 'api')->getJson($url.'?search=Scoped&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)->assertJsonPath('data.data.0.label', 'Scoped Commission Company')
        ->assertJsonPath('data.data.0.metadata.is_default', true)
        ->assertJsonPath('default_company_id', $company->id);
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $company->id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$deleted->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    DB::table('staff')->where('id', $actor->id)->update(['employment_ended_at' => now()]);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id)->assertForbidden();
});

it('searches and exactly hydrates active commission targets only inside the selected legal entity', function () {
    config()->set('sales.features.sales_profiles', true);
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Commission Options Company']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('sales.commission-config.manage');
    $employee = Staff::factory()->create(['company_id' => $company->id, 'code' => 'COM-EMP-01']);
    $profileStaff = Staff::factory()->create(['company_id' => $company->id, 'code' => 'COM-SALES-01']);
    $profile = SalesProfile::create(['company_id' => $company->id, 'staff_id' => $profileStaff->id,
        'sales_code' => 'COM-SALES', 'status' => 'active', 'effective_from' => now()->subDay()]);
    $family = SalesCommissionPlanFamily::create(['company_id' => $company->id, 'code' => 'COM-PLAN-A', 'name' => 'One-time Plan',
        'commission_category' => 'one_time', 'status' => 'approved', 'created_by' => $admin->id]);
    $secondFamily = SalesCommissionPlanFamily::create(['company_id' => $company->id, 'code' => 'COM-PLAN-B', 'name' => 'Recurring Plan',
        'commission_category' => 'long_term', 'status' => 'approved', 'created_by' => $admin->id]);
    $draftFamily = SalesCommissionPlanFamily::create(['company_id' => $company->id, 'code' => 'COM-PLAN-DRAFT', 'name' => 'Draft Plan',
        'commission_category' => 'one_time', 'status' => 'draft', 'created_by' => $admin->id]);
    $cycle = SalesCommissionCycleVersion::create(['company_id' => $company->id, 'code' => 'COM-CYCLE-A', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'calendar_month', 'cutoff_day' => 28, 'finalization_day' => 2,
        'approval_deadline_day' => 5, 'settlement_day' => 10, 'holiday_rule' => 'next_working_day', 'effective_from' => now()->subDay(),
        'status' => 'approved', 'created_by' => $admin->id]);
    $secondCycle = SalesCommissionCycleVersion::create(['company_id' => $company->id, 'code' => 'COM-CYCLE-B', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'calendar_month', 'cutoff_day' => 28, 'finalization_day' => 2,
        'approval_deadline_day' => 5, 'settlement_day' => 10, 'holiday_rule' => 'next_working_day', 'effective_from' => now()->subDay(),
        'status' => 'approved', 'created_by' => $admin->id]);
    $draftCycle = SalesCommissionCycleVersion::create(['company_id' => $company->id, 'code' => 'COM-CYCLE-DRAFT', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'calendar_month', 'cutoff_day' => 28, 'finalization_day' => 2,
        'approval_deadline_day' => 5, 'settlement_day' => 10, 'holiday_rule' => 'next_working_day', 'effective_from' => now()->subDay(),
        'status' => 'draft', 'created_by' => $admin->id]);
    $approvedCalendar = SalesCommissionBusinessCalendar::create(['company_id' => $company->id, 'code' => 'COM-CAL-APPROVED',
        'name' => 'Approved Calendar', 'timezone' => 'Asia/Colombo', 'weekly_working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'effective_from' => now()->toDateString(), 'status' => 'approved', 'created_by' => $admin->id]);
    $draftCalendar = SalesCommissionBusinessCalendar::create(['company_id' => $company->id, 'code' => 'COM-CAL-DRAFT',
        'name' => 'Draft Calendar', 'timezone' => 'Asia/Colombo', 'weekly_working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'effective_from' => now()->toDateString(), 'status' => 'draft', 'created_by' => $admin->id]);
    $former = Staff::factory()->former()->create(['company_id' => $company->id, 'code' => 'COM-FORMER']);
    $formerProfile = SalesProfile::create(['company_id' => $company->id, 'staff_id' => $former->id,
        'sales_code' => 'COM-FORMER-SALES', 'status' => 'active', 'effective_from' => now()->subDays(40)]);
    $foreign = Company::create(['name' => 'Foreign Commission Options Company']);
    $foreignFamily = SalesCommissionPlanFamily::create(['company_id' => $foreign->id, 'code' => 'COM-PLAN-FOREIGN', 'name' => 'Foreign Plan',
        'commission_category' => 'one_time', 'status' => 'approved', 'created_by' => $admin->id]);
    $foreignCycle = SalesCommissionCycleVersion::create(['company_id' => $foreign->id, 'code' => 'COM-CYCLE-FOREIGN', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'calendar_month', 'cutoff_day' => 28, 'finalization_day' => 2,
        'approval_deadline_day' => 5, 'settlement_day' => 10, 'holiday_rule' => 'next_working_day', 'effective_from' => now()->subDay(),
        'status' => 'approved', 'created_by' => $admin->id]);
    $foreignCalendar = SalesCommissionBusinessCalendar::create(['company_id' => $foreign->id, 'code' => 'COM-CAL-FOREIGN',
        'name' => 'Foreign Calendar', 'timezone' => 'Asia/Colombo', 'weekly_working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'effective_from' => now()->toDateString(), 'status' => 'approved', 'created_by' => $admin->id]);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreign->id, 'code' => 'COM-FOREIGN']);
    $base = '/api/sales/commission-configuration/reference-options?company_id='.$company->id;

    actingAs($actor->user, 'api')->getJson($base.'&record_type=sales_profile&search=COM-SALES&per_page=1')
        ->assertOk()->assertJsonPath('data.data.0.value', $profile->id)->assertJsonPath('data.data.0.label', 'COM-SALES')
        ->assertJsonPath('data.data.0.metadata.staff_code', 'COM-SALES-01');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=employee&selected_id='.$employee->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $employee->id)->assertJsonPath('data.data.0.metadata.staff_code', 'COM-EMP-01');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=employee&selected_id='.$foreignStaff->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=sales_profile&selected_id='.$formerProfile->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=plan_family&search=COM-PLAN&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $family->id)->assertJsonPath('data.data.0.label', 'COM-PLAN-A · One-time Plan')
        ->assertJsonPath('data.data.0.metadata.category', 'one_time')->assertJsonPath('data.last_page', 2);
    actingAs($actor->user, 'api')->getJson($base.'&record_type=plan_family&selected_id='.$secondFamily->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $secondFamily->id);
    actingAs($actor->user, 'api')->getJson($base.'&record_type=plan_family&selected_id='.$draftFamily->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=plan_family&selected_id='.$foreignFamily->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/reference-options?company_id='.$foreign->id.'&record_type=plan_family')
        ->assertForbidden();
    actingAs($actor->user, 'api')->getJson($base.'&record_type=cycle_version&search=COM-CYCLE&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $cycle->id)->assertJsonPath('data.data.0.label', 'COM-CYCLE-A v1')
        ->assertJsonPath('data.data.0.metadata.timezone', 'Asia/Colombo')->assertJsonPath('data.last_page', 2);
    actingAs($actor->user, 'api')->getJson($base.'&record_type=cycle_version&selected_id='.$secondCycle->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $secondCycle->id);
    actingAs($actor->user, 'api')->getJson($base.'&record_type=cycle_version&selected_id='.$draftCycle->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=cycle_version&selected_id='.$foreignCycle->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=approved_calendar&search=COM-CAL')->assertOk()
        ->assertJsonPath('data.data.0.value', $approvedCalendar->id)
        ->assertJsonPath('data.data.0.label', 'COM-CAL-APPROVED · Asia/Colombo')
        ->assertJsonPath('data.data.0.metadata.name', 'Approved Calendar');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=approved_calendar&selected_id='.$draftCalendar->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=approved_calendar&selected_id='.$foreignCalendar->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=draft_calendar&selected_id='.$draftCalendar->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $draftCalendar->id);
    actingAs($actor->user, 'api')->getJson($base.'&record_type=draft_calendar&selected_id='.$approvedCalendar->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($base.'&record_type=employee&per_page=51')->assertUnprocessable();
});

it('searches and hydrates formula-preview versions only inside the selected legal entity', function () {
    config()->set('sales.features.sales_profiles', true);
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Formula Preview Company']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('sales.commission-config.view');
    $family = SalesCommissionPlanFamily::create([
        'company_id' => $company->id, 'code' => 'PREVIEW-PLAN', 'name' => 'Preview Plan',
        'commission_category' => 'one_time', 'status' => 'approved', 'created_by' => $admin->id,
    ]);
    $version = SalesCommissionPlanVersion::create([
        'plan_family_id' => $family->id, 'version' => 1, 'formula_kind' => 'percentage',
        'effective_from' => now()->subDay(), 'status' => 'approved', 'created_by' => $admin->id,
    ]);
    SalesCommissionPlanVersion::create([
        'plan_family_id' => $family->id, 'version' => 2, 'formula_kind' => 'fixed',
        'effective_from' => now(), 'status' => 'draft', 'created_by' => $admin->id,
    ]);
    $foreign = Company::create(['name' => 'Foreign Preview Company']);
    $foreignFamily = SalesCommissionPlanFamily::create([
        'company_id' => $foreign->id, 'code' => 'FOREIGN-PLAN', 'name' => 'Foreign Plan',
        'commission_category' => 'one_time', 'status' => 'approved', 'created_by' => $admin->id,
    ]);
    $foreignVersion = SalesCommissionPlanVersion::create([
        'plan_family_id' => $foreignFamily->id, 'version' => 1, 'formula_kind' => 'percentage',
        'effective_from' => now()->subDay(), 'status' => 'approved', 'created_by' => $admin->id,
    ]);
    $url = '/api/sales/commission-configuration/version-options?company_id='.$company->id;

    $response = actingAs($actor->user, 'api')->getJson($url.'&search=PREVIEW-PLAN&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $version->id)
        ->assertJsonPath('data.data.0.label', 'PREVIEW-PLAN v1 · percentage')
        ->assertJsonPath('data.data.0.metadata.family', 'Preview Plan')
        ->assertJsonPath('data.last_page', 2);
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$version->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $version->id);
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$foreignVersion->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration/version-options?company_id='.$foreign->id)
        ->assertForbidden();
    actingAs($actor->user, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

it('returns readable commission targets without employee IDs, approver IDs, or private override reasons', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Commission Privacy Company']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('sales.commission-config.view');
    $employee = Staff::factory()->create(['company_id' => $company->id, 'code' => 'COM-PRIVATE-01']);
    $family = SalesCommissionPlanFamily::create(['company_id' => $company->id, 'code' => 'COM-PRIVACY', 'name' => 'Privacy Plan',
        'commission_category' => 'one_time', 'status' => 'draft', 'created_by' => $admin->id]);
    SalesCommissionPlanAssignment::create(['company_id' => $company->id, 'plan_family_id' => $family->id,
        'scope_type' => 'employee', 'staff_id' => $employee->id, 'precedence' => 1, 'effective_from' => now(),
        'status' => 'draft', 'created_by' => $admin->id]);
    SalesCommissionStaffOverride::create(['company_id' => $company->id, 'staff_id' => $employee->id,
        'percentage_rate' => 1.25, 'effective_from' => now(), 'reason' => 'Confidential compensation rationale',
        'status' => 'draft', 'created_by' => $admin->id]);

    $response = actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration')->assertOk();
    $response->assertJsonPath('data.assignments.0.target_type', 'employee')
        ->assertJsonPath('data.assignments.0.target_label', fn ($label) => str_contains($label, $employee->code))
        ->assertJsonPath('data.overrides.0.target_type', 'employee')
        ->assertJsonPath('data.overrides.0.target_label', fn ($label) => str_contains($label, $employee->code))
        ->assertJsonMissingPath('data.assignments.0.staff_id')
        ->assertJsonMissingPath('data.assignments.0.created_by')
        ->assertJsonMissingPath('data.assignments.0.company_id')
        ->assertJsonMissingPath('data.overrides.0.staff_id')
        ->assertJsonMissingPath('data.overrides.0.created_by')
        ->assertJsonMissingPath('data.overrides.0.approved_by')
        ->assertJsonMissingPath('data.overrides.0.reason');
    $actor->user->givePermissionTo('sales.commission-config.approve');
    actingAs($actor->user, 'api')->getJson('/api/sales/commission-configuration?company_id='.$company->id)
        ->assertOk()->assertJsonPath('data.overrides.0.reason', 'Confidential compensation rationale');
    $actor->user->givePermissionTo('sales.commission-config.manage');
    $write = actingAs($actor->user, 'api')->postJson('/api/sales/commission-staff-overrides', [
        'staff_id' => $employee->id, 'percentage_rate' => 2.5,
        'effective_from' => today()->toDateString(), 'reason' => 'Another private compensation rationale',
    ])->assertCreated();
    expect(array_keys($write->json('data')))->toBe(['id', 'status']);
});

it('replays commission approval only for its original approver while the company is active', function () {
    [$creator, $company] = hr_seed_admin_actor(['name' => 'Commission Approval Company']);
    $approver = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $approver->user_id, 'context_type' => 'staff', 'context_id' => $approver->id,
        'is_active' => true, 'created_user_id' => $creator->id]);
    $approver->user->givePermissionTo('sales.commission-config.approve');
    $family = SalesCommissionPlanFamily::create(['company_id' => $company->id, 'code' => 'COM-APPROVAL',
        'name' => 'Approval Plan', 'commission_category' => 'one_time', 'status' => 'draft', 'created_by' => $creator->id]);
    $url = '/api/sales/commission-plan-families/'.$family->id.'/approve';

    actingAs($approver->user, 'api')->postJson($url)->assertOk()->assertJsonPath('data.status', 'approved');
    $approvedAt = $family->fresh()->approved_at;
    actingAs($approver->user, 'api')->postJson($url)->assertOk()->assertJsonPath('data.status', 'approved');
    expect($family->fresh()->approved_at->equalTo($approvedAt))->toBeTrue();

    $otherApprover = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $otherApprover->user_id, 'context_type' => 'staff', 'context_id' => $otherApprover->id,
        'is_active' => true, 'created_user_id' => $creator->id]);
    $otherApprover->user->givePermissionTo('sales.commission-config.approve');
    actingAs($otherApprover->user, 'api')->postJson($url)->assertConflict();

    $company->update(['is_active' => false]);
    actingAs($approver->user, 'api')->postJson($url)->assertUnprocessable();
});
