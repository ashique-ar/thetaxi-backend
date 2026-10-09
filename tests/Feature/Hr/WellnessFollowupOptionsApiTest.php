<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates scheduled follow-ups only within the authorized wellness referral', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $actor = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $referralId = wellness_followup_options_referral($company->id, $actor->id, $admin->id);
    $first = wellness_followup_options_seed($referralId, $actor->id, $admin->id, now()->addDays(1));
    $second = wellness_followup_options_seed($referralId, $actor->id, $admin->id, now()->addDays(2));
    $completed = wellness_followup_options_seed($referralId, $actor->id, $admin->id, now()->addDays(3), 'completed');
    $otherReferral = wellness_followup_options_referral($company->id, $actor->id, $admin->id);
    $otherCase = wellness_followup_options_seed($otherReferral, $actor->id, $admin->id, now()->addDays(4));

    $foreignCompany = Company::create(['name' => 'Other wellness referral company']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignReferral = wellness_followup_options_referral($foreignCompany->id, $foreignStaff->id, $admin->id);
    $foreignCase = wellness_followup_options_seed($foreignReferral, $foreignStaff->id, $admin->id, now()->addDays(5));

    $url = '/api/hr/engagement/wellness/referrals/'.$referralId.'/followup-options?search=Asia%2FColombo';
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.metadata.timezone', 'Asia/Colombo')->assertDontSee('Private test purpose');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$second)->assertOk()
        ->assertJsonPath('data.0.value', $second);
    foreach ([$completed, $otherCase, $foreignCase] as $unavailable) {
        actingAs($admin, 'api')->getJson($url.'&selected_id='.$unavailable)->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/engagement/wellness/referrals/'.$foreignReferral.'/followup-options')
        ->assertNotFound();
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
    $unauthorized = Staff::factory()->create(['company_id' => $company->id])->user;
    actingAs($unauthorized, 'api')->getJson($url)->assertForbidden();
    $this->assertDatabaseHas('activity_log', [
        'description' => 'restricted_wellness_followup_options_viewed', 'causer_id' => $admin->id,
    ]);
});

function wellness_followup_options_referral(string $companyId, string $staffId, string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('hr_wellness_referrals')->insert([
        'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'referral_type' => 'support',
        'encrypted_details' => encrypt('Test details'), 'status' => 'in_support', 'consent_status' => 'granted',
        'requested_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function wellness_followup_options_seed(string $referralId, string $ownerId, string $userId, $dueAt, string $status = 'scheduled'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_wellness_followups')->insert([
        'id' => $id, 'referral_id' => $referralId, 'due_at' => $dueAt, 'timezone' => 'Asia/Colombo',
        'owner_staff_id' => $ownerId, 'encrypted_purpose' => encrypt('Private test purpose'),
        'employee_visible' => false, 'status' => $status, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
