<?php

use App\Models\Company;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Exercises the real HR People Core "employee directory" endpoint end to
 * end against a real (sqlite, in-memory) database: a Staff + an active
 * HrEmploymentAssignment are created via the new Hr factories, and the API
 * response is asserted to actually contain that employee — replacing the
 * former Hr*ContractTest.php pattern of just grepping controller source
 * text with a test that verifies real behavior.
 */
it('returns a Staff member created via the factory from the employee directory endpoint', function () {
    [$adminUser, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);

    $staff = Staff::factory()->create([
        'company_id' => $company->id,
        'staff_type' => 'operator',
        'code' => 'STF-90001',
    ]);
    $spell = HrEmploymentSpell::factory()->create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    HrEmploymentAssignment::factory()->create([
        'employment_spell_id' => $spell->id,
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'cost_centre_code' => 'CC-100',
        'effective_from' => now()->subDay(),
        'effective_until' => null,
    ]);

    $response = actingAs($adminUser, 'api')->getJson('/api/hr/people/directory');

    $response->assertOk();
    $rows = collect($response->json('data.data'));
    $row = $rows->firstWhere('id', $staff->id);

    expect($row)->not->toBeNull()
        ->and($row['employee_number'])->toBe('STF-90001')
        ->and($row['company_id'])->toBe($company->id)
        ->and($row['current_assignment']['cost_centre_code'])->toBe('CC-100');
});

it('excludes Staff belonging to a different company from the directory response', function () {
    [$adminUser] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);

    $otherCompany = Company::create(['name' => 'Other Co']);
    $otherStaff = Staff::factory()->create([
        'company_id' => $otherCompany->id,
        'code' => 'STF-OTHER-1',
    ]);

    $response = actingAs($adminUser, 'api')->getJson('/api/hr/people/directory');

    $response->assertOk();
    $rows = collect($response->json('data.data'));

    expect($rows->firstWhere('id', $otherStaff->id))->toBeNull();
});
