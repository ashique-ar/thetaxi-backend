<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes report views and creation to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.reporting.view', 'hr.analytics.view']);
    config(['hr.features.engagement_analytics' => true]);
    $secondCompany = Company::create(['name' => 'Second Reporting company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $viewIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$viewIds[0], $firstCompany->id, 'First company view'], [$viewIds[1], $secondCompany->id, 'Selected company view']] as [$id, $companyId, $name]) {
        DB::table('hr_report_saved_views')->insert([
            'id' => $id, 'company_id' => $companyId, 'owner_user_id' => $admin->id, 'name' => $name,
            'report_kind' => 'analytics_snapshots', 'filter_contract' => json_encode([], JSON_THROW_ON_ERROR),
            'column_contract' => json_encode(['metric_code'], JSON_THROW_ON_ERROR), 'visibility' => 'private',
            'status' => 'active', 'view_checksum' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/analytics/report-views')->assertForbidden();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/analytics/report-views')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $viewIds[1]);
    actingAs($admin, 'api')->withHeaders($headers)
        ->getJson('/api/hr/analytics/report-view-options?search=Selected')
        ->assertOk()->assertJsonPath('data.data.0.value', $viewIds[1]);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/analytics/report-views', [
        'name' => 'Created in selected company', 'report_kind' => 'analytics_snapshots',
        'filters' => [], 'columns' => ['metric_code'], 'visibility' => 'private',
    ])->assertCreated();
    expect(DB::table('hr_report_saved_views')->where('name', 'Created in selected company')->value('company_id'))
        ->toBe($secondCompany->id);
});
