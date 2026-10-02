<?php

use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates employment spells only for the authorized employee', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $spell = HrEmploymentSpell::factory()->create(['staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 2]);
    HrEmploymentSpell::factory()->create(['staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 1]);
    $otherStaff = Staff::factory()->create(['company_id' => $company->id]);
    $otherSpell = HrEmploymentSpell::factory()->create(['staff_id' => $otherStaff->id, 'company_id' => $company->id, 'spell_number' => 2]);
    $url = "/api/hr/employees/{$staff->id}/employment-spell-options";

    actingAs($admin, 'api')->getJson($url.'?search=2&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $spell->id)
        ->assertJsonPath('data.data.0.label', 'Spell 2');
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$spell->id)->assertOk()->assertJsonPath('data.data.0.value', $spell->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$otherSpell->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
