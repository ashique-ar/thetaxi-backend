<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays a lifecycle template submission without duplicating rows or audit', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.manage', 'api');
    Permission::findOrCreate('hr.lifecycle.view', 'api');

    $user = User::factory()->create();
    $user->givePermissionTo(['hr.lifecycle.manage', 'hr.lifecycle.view']);
    $company = Company::create(['name' => 'Template Idempotency Company', 'is_active' => true, 'is_default' => true]);
    $userStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $userStaff->id, 'is_active' => true, 'created_user_id' => $user->id]);
    $payload = [
        'company_id' => $company->id, 'case_type' => 'onboarding', 'code' => 'SAFE-ONBOARDING', 'version' => 1,
        'applicability' => ['staff_type' => 'employee'],
        'task_definitions' => [['code' => 'account', 'title' => 'Create employee account']],
        'idempotency_key' => (string) Str::uuid(),
    ];
    $url = '/api/hr/lifecycle/templates';

    $first = actingAs($user, 'api')->postJson($url, $payload)->assertCreated();
    $replay = actingAs($user, 'api')->postJson($url, $payload)->assertOk();
    actingAs($user, 'api')->postJson($url, array_replace($payload, ['applicability' => ['staff_type' => 'contractor']]))
        ->assertConflict();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_lifecycle_templates')->where('company_id', $company->id)->count())->toBe(1)
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'lifecycle_template_submitted')->count())->toBe(1);

    actingAs($user, 'api')->getJson('/api/hr/lifecycle/templates')->assertOk()
        ->assertJsonMissingPath('data.data.0.idempotency_key')
        ->assertJsonMissingPath('data.data.0.request_payload_checksum');
});
