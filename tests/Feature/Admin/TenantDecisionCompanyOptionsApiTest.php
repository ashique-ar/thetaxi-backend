<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates only legal entities in the decision actors scope', function () {
    [, $company] = hr_seed_admin_actor(['name' => 'Scoped Decision Company', 'city' => 'Colombo']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    $actor->user->givePermissionTo('tenant-decisions.view');
    $foreign = Company::create(['name' => 'Foreign Decision Company', 'city' => 'Kandy']);
    $url = '/api/tenant-decisions/company-options';

    actingAs($actor->user, 'api')->getJson($url.'?search=Colombo&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $company->id)
        ->assertJsonPath('data.data.0.label', 'Scoped Decision Company')
        ->assertJsonPath('data.data.0.metadata.city', 'Colombo');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$company->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $company->id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});

it('does not offer companies through an ended Staff context', function () {
    [, $company] = hr_seed_admin_actor();
    $actor = Staff::factory()->create(['company_id' => $company->id, 'employment_ended_at' => now()]);
    $actor->user->givePermissionTo('tenant-decisions.view');

    actingAs($actor->user, 'api')->getJson('/api/tenant-decisions/company-options')
        ->assertOk()->assertJsonCount(0, 'data.data');
});

it('allows explicitly authorized decision administrators to search companies across legal entities', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo(['tenant-decisions.view', 'tenant-decisions.manage', 'tenant-decisions.manage-all']);
    $company = Company::create(['name' => 'New Decision Tenant', 'city' => 'Galle']);

    actingAs($admin, 'api')->getJson('/api/tenant-decisions/company-options?search=New%20Decision&per_page=50')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.value', $company->id)
        ->assertJsonPath('data.data.0.label', 'New Decision Tenant');

    actingAs($admin, 'api')->postJson('/api/tenant-decisions', [
        'company_id' => $company->id, 'key' => 'company.localization',
        'value' => ['currency' => 'LKR', 'timezone' => 'Asia/Colombo'],
        'effective_from' => now()->toDateString(), 'reason' => 'New legal entity setup',
        'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
    ])->assertOk()->assertJsonPath('data.status', 'draft');
    $checker = \App\Models\User::factory()->create();
    $checker->givePermissionTo(['tenant-decisions.approve', 'tenant-decisions.manage-all']);
    actingAs($checker, 'api')->postJson('/api/tenant-decisions/company.localization/approve', [
        'company_id' => $company->id, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
    ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.company_id', $company->id);
});

it('denies direct decision reads and exact hydration for a deleted legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo(['tenant-decisions.view', 'tenant-decisions.manage-all']);
    $company->delete();

    actingAs($admin, 'api')->getJson('/api/tenant-decisions?company_id='.$company->id)->assertNotFound();
    actingAs($admin, 'api')->getJson('/api/tenant-decisions/company-options?selected_id='.$company->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
});
