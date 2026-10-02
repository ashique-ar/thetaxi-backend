<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable exact-hydrated report views within report access, owner and company scope', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $first = report_view_option_seed($company->id, $admin->id, 'Staff headcount', 'analytics_snapshots');
    $second = report_view_option_seed($company->id, User::factory()->create()->id, 'Staff plan', 'workforce_plans', 'company');
    $hidden = report_view_option_seed($company->id, User::factory()->create()->id, 'Staff private', 'analytics_snapshots');
    $inactive = report_view_option_seed($company->id, $admin->id, 'Staff retired', 'data_quality', 'private', 'inactive');
    $foreignCompany = Company::create(['name' => 'Other report tenant']);
    $foreign = report_view_option_seed($foreignCompany->id, $admin->id, 'Staff foreign', 'analytics_snapshots', 'company');
    $url = '/api/hr/analytics/report-view-options?search=staff';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'Staff headcount · analytics_snapshots')
        ->assertJsonPath('data.data.0.metadata.report_kind', 'analytics_snapshots');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$first)->assertOk()->assertJsonPath('data.0.value', $first);
    foreach ([$hidden, $inactive, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson($url.'&selected_id='.$excluded)->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function report_view_option_seed(string $companyId, string $ownerId, string $name, string $kind, string $visibility = 'private', string $status = 'active'): string
{
    $id = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::table('hr_report_saved_views')->insert([
        'id' => $id, 'company_id' => $companyId, 'owner_user_id' => $ownerId, 'name' => $name, 'report_kind' => $kind,
        'filter_contract' => json_encode([], JSON_THROW_ON_ERROR), 'column_contract' => json_encode(['metric_code'], JSON_THROW_ON_ERROR),
        'visibility' => $visibility, 'status' => $status, 'view_checksum' => hash('sha256', $id),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
