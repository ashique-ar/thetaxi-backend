<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only eligible unused travel claims for the selected travel request', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $travel = travel_claim_options_request($company->id, $staff->id, $admin->id, 'USD');
    $eligible = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-001', 'USD');
    travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-002', 'LKR');
    travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-003', 'USD', 'pending_approval');
    $missingAmount = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-008', 'USD');
    DB::table('hr_expense_claims')->where('id', $missingAmount)->update(['approved_amount' => null]);
    $otherStaff = Staff::factory()->create(['company_id' => $company->id]);
    travel_claim_options_claim($company->id, $otherStaff->id, $admin->id, 'TRAVEL-004', 'USD');
    $linked = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-005', 'USD');
    travel_claim_options_request($company->id, $staff->id, $admin->id, 'USD', 'settled', $linked);
    $foreignCompany = Company::create(['name' => 'Other travel tenant']);
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $foreignTravel = travel_claim_options_request($foreignCompany->id, $foreignStaff->id, $admin->id, 'USD');
    $url = '/api/hr/travel/requests/'.$travel.'/settlement-claim-options?search=TRAVEL';

    $response = actingAs($admin, 'api')->getJson($url)->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.value', $eligible);
    expect($response->json('data.data.0.label'))->toStartWith('TRAVEL-001 · USD ');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$eligible)->assertOk()->assertJsonPath('data.0.value', $eligible);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$linked)->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson('/api/hr/travel/requests/'.$foreignTravel.'/settlement-claim-options')->assertForbidden();
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

it('rechecks settlement claim currency and single-use eligibility on write', function () {
    config()->set('hr.features.travel', true);
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $trip = travel_claim_options_request($company->id, $staff->id, $admin->id, 'USD');
    $wrongCurrency = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-006', 'LKR');
    $missingAmount = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-009', 'USD');
    DB::table('hr_expense_claims')->where('id', $missingAmount)->update(['approved_amount' => null]);
    $alreadyLinked = travel_claim_options_claim($company->id, $staff->id, $admin->id, 'TRAVEL-007', 'USD');
    travel_claim_options_request($company->id, $staff->id, $admin->id, 'USD', 'settled', $alreadyLinked);
    $url = '/api/hr/travel/requests/'.$trip.'/settle';
    $payload = ['unused_advance_amount' => 0, 'evidence' => []];

    actingAs($admin, 'api')->postJson($url, $payload + ['expense_claim_id' => $wrongCurrency])->assertUnprocessable();
    actingAs($admin, 'api')->postJson($url, $payload + ['expense_claim_id' => $missingAmount])->assertUnprocessable();
    actingAs($admin, 'api')->postJson($url, $payload + ['expense_claim_id' => $alreadyLinked])->assertUnprocessable();
});

function travel_claim_options_claim(string $companyId, string $staffId, string $actorId, string $number, string $currency, string $status = 'approved'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_expense_claims')->insert([
        'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'claim_number' => $number,
        'claim_type' => 'travel', 'currency' => $currency, 'claimed_amount' => 25, 'approved_amount' => 25,
        'status' => $status, 'allocation_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'content_checksum' => hash('sha256', $id),
        'submitted_by' => $actorId, 'approved_by' => $status === 'approved' ? $actorId : null,
        'approved_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function travel_claim_options_request(string $companyId, string $staffId, string $actorId, string $currency, string $status = 'approved', ?string $claimId = null): string
{
    $id = (string) Str::uuid();
    DB::table('hr_travel_requests')->insert([
        'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'request_number' => 'TR-'.$id,
        'purpose' => 'Test travel', 'origin' => 'Origin', 'destination' => 'Destination',
        'departs_at' => now()->addDay(), 'returns_at' => now()->addDays(2), 'timezone' => 'Asia/Colombo',
        'itinerary' => json_encode([], JSON_THROW_ON_ERROR), 'currency' => $currency, 'estimated_cost' => 100,
        'requested_advance' => 0, 'allocation_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'status' => $status,
        'requested_by' => $actorId, 'approved_by' => $status === 'approved' ? $actorId : null,
        'approved_at' => $status === 'approved' ? now() : null, 'settlement_claim_id' => $claimId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
