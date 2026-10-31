<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('paginates only authorized wellness handlers and exactly hydrates eligible selections', function () {
    [$admin] = hr_seed_admin_actor();
    Staff::factory()->create(['code' => 'LOOKUP-HANDLER-00']);
    $first = wellness_handler_seed('LOOKUP-HANDLER-01');
    $second = wellness_handler_seed('LOOKUP-HANDLER-02');
    $third = wellness_handler_seed('LOOKUP-HANDLER-03');
    $url = '/api/hr/engagement/wellness/handler-options?company_id='.\Illuminate\Support\Str::uuid().'&search=LOOKUP-HANDLER';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.data.0.value', $first->id);
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.data.0.value', $second->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?selected_id='.$second->id)
        ->assertOk()->assertJsonPath('data.0.value', $second->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?selected_id='.$third->id)
        ->assertOk()->assertJsonPath('data.0.value', $third->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/handler-options?per_page=51')
        ->assertUnprocessable();
});

function wellness_handler_seed(string $code): Staff
{
    $staff = Staff::factory()->create(['code' => $code]);
    $staff->user->givePermissionTo('hr.wellness.case.manage');

    return $staff;
}
