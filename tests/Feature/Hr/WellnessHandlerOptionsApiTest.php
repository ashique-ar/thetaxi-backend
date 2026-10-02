<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('paginates only authorized wellness handlers and exactly hydrates eligible selections', function () {
    [$admin, $company] = hr_seed_admin_actor();
    Staff::factory()->create(['company_id' => $company->id, 'code' => 'LOOKUP-HANDLER-00']);
    $first = wellness_handler_seed($company, 'LOOKUP-HANDLER-01');
    $second = wellness_handler_seed($company, 'LOOKUP-HANDLER-02');
    $foreignCompany = Company::create(['name' => 'Other wellness handlers']);
    $foreign = wellness_handler_seed($foreignCompany, 'LOOKUP-HANDLER-03');
    $url = '/api/hr/engagement/wellness/handler-options?company_id='.$company->id.'&search=LOOKUP-HANDLER';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first->id);
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $second->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?company_id='.$company->id.'&selected_id='.$second->id)
        ->assertOk()->assertJsonPath('data.0.value', $second->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?company_id='.$company->id.'&selected_id='.$foreign->id)
        ->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?company_id='.$company->id.'&per_page=51')
        ->assertUnprocessable();
});

function wellness_handler_seed(Company $company, string $code): Staff
{
    $staff = Staff::factory()->create(['company_id' => $company->id, 'code' => $code]);
    $staff->user->givePermissionTo('hr.wellness.case.manage');

    return $staff;
}
