<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows and creates Lifecycle templates across the installation under the selected Staff context', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.lifecycle.manage');
    config(['hr.features.employee_self_service' => true]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $templateIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($templateIds as $id) {
        DB::table('hr_lifecycle_templates')->insert([
            'id' => $id, 'case_type' => 'onboarding', 'code' => 'ONBOARD-'.$id, 'version' => 1,
            'applicability' => json_encode([], JSON_THROW_ON_ERROR),
            'task_definitions' => json_encode([['code' => 'review', 'title' => 'Review']], JSON_THROW_ON_ERROR),
            'status' => 'approved', 'created_by' => $admin->id, 'approved_by' => null, 'approved_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];

    actingAs($admin, 'api')->getJson('/api/hr/lifecycle/templates')->assertOk();
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/lifecycle/templates')
        ->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['id' => $templateIds[0]])->assertJsonFragment(['id' => $templateIds[1]]);
    actingAs($admin, 'api')->withHeaders($headers)
        ->getJson('/api/hr/lifecycle/template-options?selected_id='.$templateIds[1])
        ->assertOk()->assertJsonPath('data.0.value', $templateIds[1]);

    $payload = [
        'case_type' => 'onboarding', 'code' => 'NEW', 'version' => 1,
        'applicability' => [], 'task_definitions' => [['code' => 'check', 'title' => 'Check']],
        'idempotency_key' => (string) Str::uuid(),
    ];
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/templates', $payload)->assertCreated();
    expect(DB::table('hr_lifecycle_templates')->where('code', 'NEW')->exists())->toBeTrue();
});
