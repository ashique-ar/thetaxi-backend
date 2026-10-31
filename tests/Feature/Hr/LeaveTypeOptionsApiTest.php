<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable, paginated installation-wide leave types for the requested status', function () {
    [$admin] = hr_seed_admin_actor();
    $first = hr_type_option_seed($admin->id, 'ANNUAL-A', 'Annual leave');
    $second = hr_type_option_seed($admin->id, 'ANNUAL-B', 'Annual leave B');
    $third = hr_type_option_seed($admin->id, 'ANNUAL-F', 'Annual leave other location');
    $inactive = hr_type_option_seed($admin->id, 'ANNUAL-X', 'Annual leave archived', 'inactive');
    $url = '/api/hr/workforce/leave-type-options?status=active&search=annual&company_id='.Str::uuid();

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'Annual leave - ANNUAL-A');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=3')->assertOk()
        ->assertJsonPath('data.data.0.value', $third);
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options?selected_id='.$second)
        ->assertOk()->assertJsonPath('data.0.value', $second);
    foreach ([$inactive] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options?status=active&selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-type-options')->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function hr_type_option_seed(string $actorId, string $code, string $name, string $status = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $id, 'code' => $code, 'name' => $name,
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->subDay()->toDateString(), 'status' => $status, 'created_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
