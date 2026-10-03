<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes HR analytics to the selected Staff company and writes definitions there', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.analytics.view', 'hr.analytics.configure']);
    config(['hr.features.engagement_analytics' => true]);
    $secondCompany = Company::create(['name' => 'Second Analytics company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $definitionIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$definitionIds[0], $firstCompany->id, 'FIRST'], [$definitionIds[1], $secondCompany->id, 'SECOND']] as [$id, $companyId, $code]) {
        DB::table('hr_analytics_definition_versions')->insert([
            'id' => $id, 'company_id' => $companyId, 'metric_code' => $code, 'version' => 1, 'name' => $code.' metric',
            'definition' => 'Count active Staff', 'source_contract' => json_encode(['metric_kind' => 'headcount'], JSON_THROW_ON_ERROR),
            'dimension_policy' => json_encode(['allowed_dimensions' => []], JSON_THROW_ON_ERROR), 'minimum_group_size' => 5,
            'effective_from' => today()->toDateString(), 'status' => 'approved', 'created_by' => $admin->id,
            'definition_checksum' => hash('sha256', $code), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/analytics/definitions')->assertForbidden();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/analytics/definitions')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $definitionIds[1]);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/analytics/definitions', [
        'metric_code' => 'SELECTED_CONTEXT', 'version' => 1, 'name' => 'Selected context metric',
        'definition' => 'Count active Staff',
        'source_contract' => ['metric_kind' => 'headcount', 'period_kind' => 'month', 'unit' => 'count'],
        'dimension_policy' => ['allowed_dimensions' => []], 'minimum_group_size' => 5,
        'effective_from' => today()->toDateString(),
    ])->assertCreated();
    expect(DB::table('hr_analytics_definition_versions')->where('metric_code', 'SELECTED_CONTEXT')->value('company_id'))
        ->toBe($secondCompany->id);
});
