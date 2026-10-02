<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates tenant Staff types and location audience options', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    engagement_seed_location($admin, $company, $staff, 'COLOMBO');

    actingAs($admin, 'api')->getJson('/api/hr/engagement/audience-options?record_type=staff_type&search=adm')
        ->assertOk()->assertJsonPath('data.data.0.value', 'admin');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/audience-options?record_type=staff_type&selected_ids[]=admin')
        ->assertOk()->assertJsonPath('data.0.value', 'admin');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/audience-options?record_type=location&search=COLO')
        ->assertOk()->assertJsonPath('data.data.0.value', 'COLOMBO');
    actingAs($admin, 'api')->getJson('/api/hr/engagement/audience-options?record_type=location&selected_ids[]=COLOMBO')
        ->assertOk()->assertJsonPath('data.0.value', 'COLOMBO');
});

function engagement_seed_location($user, Company $company, Staff $staff, string $code): void
{
    $today = today()->toDateString();
    $spell = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spell, 'staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => $today, 'service_date' => $today, 'gratuity_service_start' => $today,
        'status' => 'active', 'created_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_employment_assignments')->insert([
        'id' => (string) Str::uuid(), 'employment_spell_id' => $spell, 'staff_id' => $staff->id,
        'company_id' => $company->id, 'location_code' => $code, 'effective_from' => $today,
        'change_reason' => 'test_setup', 'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
