<?php

use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates eligible Sales Profiles without a company selector', function () {
    [$admin] = hr_seed_admin_actor();
    $staff = Staff::factory()->create(['code' => 'PERF-STAFF-01']);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $staff->id,
        'sales_code' => 'PERF-OPTIONS-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $former = Staff::factory()->former()->create(['code' => 'PERF-STAFF-FORMER']);
    $formerProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $former->id,
        'sales_code' => 'PERF-FORMER', 'status' => 'active', 'effective_from' => now()->subDays(40),
    ]);
    $otherStaff = Staff::factory()->create(['code' => 'PERF-STAFF-02']);
    $otherProfile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'staff_id' => $otherStaff->id,
        'sales_code' => 'PERF-OPTIONS-02', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $url = '/api/sales/performance/profile-options';

    actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')->assertOk()->assertJsonMissingPath('data.profiles');
    actingAs($admin, 'api')->getJson($url.'?search=PERF-OPTIONS&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $profile->id)->assertJsonPath('data.data.0.label', 'PERF-OPTIONS-01')
        ->assertJsonPath('data.data.0.metadata.staff_code', 'PERF-STAFF-01');
    actingAs($admin, 'api')->getJson($url.'?selected_ids%5B%5D='.$profile->id.'&selected_ids%5B%5D='.$otherProfile->id)
        ->assertOk()->assertJsonCount(2, 'data');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$formerProfile->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
