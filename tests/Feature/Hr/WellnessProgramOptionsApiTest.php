<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('serves bounded searchable exact-hydrated wellness choices within the actor audience across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $localId = wellness_option_seed('SUPPORT', 'Wellness support');
    $otherId = wellness_option_seed('PRIVATE', 'Private programme');

    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?search=SUPPORT')->assertOk()
        ->assertJsonPath('data.data.0.value', $localId)
        ->assertJsonMissingPath('data.data.0.description');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?selected_id='.$localId)->assertOk()
        ->assertJsonPath('data.data.0.value', $localId);
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/programs?selected_id='.$otherId)->assertOk()
        ->assertJsonPath('data.data.0.value', $otherId);
});

function wellness_option_seed(string $code, string $name): string
{
    $id = (string) Str::uuid();
    DB::table('hr_wellness_programs')->insert([
        'id' => $id, 'code' => $code, 'name' => $name,
        'description' => 'Confidential support detail', 'audience' => json_encode(['all' => true], JSON_THROW_ON_ERROR),
        'starts_at' => today()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
