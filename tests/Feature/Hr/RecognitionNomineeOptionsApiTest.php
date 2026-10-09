<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates only active colleagues in the actor company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $self = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $first = Staff::factory()->create(['company_id' => $company->id, 'code' => 'LOOKUP-NOMINEE-01']);
    $second = Staff::factory()->create(['company_id' => $company->id, 'code' => 'LOOKUP-NOMINEE-02']);
    $first->user->update(['first_name' => 'Ada', 'last_name' => 'Able']);
    $second->user->update(['first_name' => 'Bola', 'last_name' => 'Baker']);
    $inactive = Staff::factory()->create(['company_id' => $company->id, 'code' => 'LOOKUP-NOMINEE-03']);
    $inactive->user->update(['is_active' => false]);
    $ended = Staff::factory()->former()->create(['company_id' => $company->id, 'code' => 'LOOKUP-NOMINEE-04']);
    $foreign = Staff::factory()->create(['company_id' => Company::create(['name' => 'Other nominee tenant'])->id, 'code' => 'LOOKUP-NOMINEE-05']);
    $url = '/api/hr/engagement/recognition/nominee-options?search=LOOKUP-NOMINEE';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first->id)
        ->assertJsonPath('data.data.0.metadata.staff_code', 'LOOKUP-NOMINEE-01');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second->id);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/recognition/nominee-options?selected_id='.$second->id)
        ->assertOk()->assertJsonPath('data.0.value', $second->id);
    foreach ([$self, $inactive, $ended, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/engagement/recognition/nominee-options?selected_id='.$excluded->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/engagement/recognition/nominee-options?per_page=51')->assertUnprocessable();
});
