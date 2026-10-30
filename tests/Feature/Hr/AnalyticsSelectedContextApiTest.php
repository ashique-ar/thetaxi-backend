<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shares HR analytics definitions across locations and creates installation-wide definitions', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.analytics.view', 'hr.analytics.configure']);
    config(['hr.features.engagement_analytics' => true]);
    $definitionIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$definitionIds[0], 'FIRST'], [$definitionIds[1], 'SECOND']] as [$id, $code]) {
        DB::table('hr_analytics_definition_versions')->insert([
            'id' => $id, 'metric_code' => $code, 'version' => 1, 'name' => $code.' metric',
            'definition' => 'Count active Staff', 'source_contract' => json_encode(['metric_kind' => 'headcount'], JSON_THROW_ON_ERROR),
            'dimension_policy' => json_encode(['allowed_dimensions' => []], JSON_THROW_ON_ERROR), 'minimum_group_size' => 5,
            'effective_from' => today()->toDateString(), 'status' => 'approved', 'created_by' => $admin->id,
            'definition_checksum' => hash('sha256', $code), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/analytics/definitions')
        ->assertOk()->assertJsonCount(2, 'data.data');
    actingAs($admin, 'api')->postJson('/api/hr/analytics/definitions', [
        'metric_code' => 'INSTALLATION_WIDE', 'version' => 1, 'name' => 'Installation-wide metric',
        'definition' => 'Count active Staff',
        'source_contract' => ['metric_kind' => 'headcount', 'period_kind' => 'month', 'unit' => 'count'],
        'dimension_policy' => ['allowed_dimensions' => []], 'minimum_group_size' => 5,
        'effective_from' => today()->toDateString(),
    ])->assertCreated();
    expect(DB::table('hr_analytics_definition_versions')->where('metric_code', 'INSTALLATION_WIDE')->exists())->toBeTrue()
        ->and(Schema::hasColumn('hr_analytics_definition_versions', 'company_id'))->toBeFalse();
});
