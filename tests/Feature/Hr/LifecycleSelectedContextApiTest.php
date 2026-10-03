<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes Lifecycle templates and writes to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.lifecycle.manage');
    config(['hr.features.employee_self_service' => true]);
    $secondCompany = Company::create(['name' => 'Second Lifecycle company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $templateIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$templateIds[0], $firstCompany->id], [$templateIds[1], $secondCompany->id]] as [$id, $companyId]) {
        DB::table('hr_lifecycle_templates')->insert([
            'id' => $id, 'company_id' => $companyId, 'case_type' => 'onboarding', 'code' => 'ONBOARD', 'version' => 1,
            'applicability' => json_encode([], JSON_THROW_ON_ERROR),
            'task_definitions' => json_encode([['code' => 'review', 'title' => 'Review']], JSON_THROW_ON_ERROR),
            'status' => 'approved', 'created_by' => $admin->id, 'approved_by' => null, 'approved_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    actingAs($admin, 'api')->getJson('/api/hr/lifecycle/templates')->assertForbidden();
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/lifecycle/templates')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $templateIds[1]);
    actingAs($admin, 'api')->withHeaders($headers)
        ->getJson('/api/hr/lifecycle/template-options?company_id='.$firstCompany->id)->assertForbidden();
    actingAs($admin, 'api')->withHeaders($headers)
        ->getJson('/api/hr/lifecycle/template-options?company_id='.$secondCompany->id.'&selected_id='.$templateIds[1])
        ->assertOk()->assertJsonPath('data.0.value', $templateIds[1]);

    $payload = [
        'company_id' => $firstCompany->id, 'case_type' => 'onboarding', 'code' => 'NEW', 'version' => 1,
        'applicability' => [], 'task_definitions' => [['code' => 'check', 'title' => 'Check']],
    ];
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/templates', $payload)->assertForbidden();
    $payload['company_id'] = $secondCompany->id;
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/templates', $payload)->assertCreated();
    expect(DB::table('hr_lifecycle_templates')->where('code', 'NEW')->value('company_id'))->toBe($secondCompany->id);
});
