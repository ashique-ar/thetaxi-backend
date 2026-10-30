<?php

use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps report views installation-wide across selected location contexts', function () {
    [$admin] = hr_seed_admin_actor(['name' => 'Selected reporting location']);
    $admin->givePermissionTo(['hr.reporting.view', 'hr.analytics.view']);
    config(['hr.features.engagement_analytics' => true]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $viewIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$viewIds[0], 'First location view'], [$viewIds[1], 'Second location view']] as [$id, $name]) {
        DB::table('hr_report_saved_views')->insert([
            'id' => $id, 'owner_user_id' => $admin->id, 'name' => $name,
            'report_kind' => 'analytics_snapshots', 'filter_contract' => json_encode([], JSON_THROW_ON_ERROR),
            'column_contract' => json_encode(['metric_code'], JSON_THROW_ON_ERROR), 'visibility' => 'private',
            'status' => 'active', 'view_checksum' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/analytics/report-views')
        ->assertOk()->assertJsonCount(2, 'data.data');
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/analytics/report-views')
        ->assertOk()->assertJsonCount(2, 'data.data');
    actingAs($admin, 'api')->withHeaders($headers)
        ->postJson('/api/hr/analytics/report-views', [
            'name' => 'Shared installation view', 'report_kind' => 'analytics_snapshots',
            'filters' => [], 'columns' => ['metric_code'], 'visibility' => 'private',
        ])->assertCreated();
    expect(DB::table('hr_report_saved_views')->where('name', 'Shared installation view')->exists())->toBeTrue()
        ->and(Schema::hasColumn('hr_report_saved_views', 'company_id'))->toBeFalse();
});
