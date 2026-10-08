<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates active Workforce Staff only inside the actor legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $employee = Staff::factory()->create(['company_id' => $company->id, 'code' => 'WF-STAFF-01']);
    $employee->user->update(['first_name' => 'Workforce', 'last_name' => 'Employee']);
    $former = Staff::factory()->former()->create(['company_id' => $company->id, 'code' => 'WF-STAFF-FORMER']);
    $deleted = Staff::factory()->create(['company_id' => $company->id, 'code' => 'WF-STAFF-DELETED']);
    $deleted->delete();
    $foreign = Staff::factory()->create(['company_id' => Company::create(['name' => 'Foreign Workforce Company'])->id, 'code' => 'WF-STAFF-FOREIGN']);
    $url = '/api/hr/workforce/staff-options?company_id='.$company->id;

    actingAs($admin, 'api')->getJson('/api/hr/workforce/staff-options?search=WF-STAFF-01')
        ->assertOk()->assertJsonPath('data.data.0.value', $employee->id);

    $response = actingAs($admin, 'api')->getJson($url.'&search=WF-STAFF-01')->assertOk()
        ->assertJsonPath('data.data.0.value', $employee->id)
        ->assertJsonPath('data.data.0.label', 'Workforce Employee · WF-STAFF-01');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$employee->id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $employee->id);
    foreach ([$former, $deleted, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson($url.'&selected_id='.$excluded->id)
            ->assertOk()->assertJsonCount(0, 'data.data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/workforce/references')
        ->assertOk()->assertJsonMissingPath('data.staff');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson('/api/hr/workforce/staff-options?company_id='.Company::create(['name' => 'Other Workforce Company'])->id)
        ->assertForbidden();
});
