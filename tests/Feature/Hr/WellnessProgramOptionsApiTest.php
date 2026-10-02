<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('serves bounded searchable exact-hydrated wellness choices within the actor audience and company', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $localId = wellness_option_seed($company, 'SUPPORT', 'Wellness support');
    $other = Company::create(['name' => 'Other wellness tenant']);
    $foreignId = wellness_option_seed($other, 'PRIVATE', 'Private programme');

    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?search=SUPPORT')->assertOk()
        ->assertJsonPath('data.data.0.value', $localId)
        ->assertJsonMissingPath('data.data.0.description');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?selected_id='.$localId)->assertOk()
        ->assertJsonPath('data.data.0.value', $localId);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?selected_id='.$foreignId)->assertOk()
        ->assertJsonPath('data.total', 0);
});

function wellness_option_seed(Company $company, string $code, string $name): string
{
    $id = (string) Str::uuid();
    DB::table('hr_wellness_programs')->insert([
        'id' => $id, 'company_id' => $company->id, 'code' => $code, 'name' => $name,
        'description' => 'Confidential support detail', 'audience' => json_encode(['all' => true], JSON_THROW_ON_ERROR),
        'starts_at' => today()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
