<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits payroll input facts to the caller company and authorized Staff scope', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $user = User::factory()->create();
    $role = Role::create(['name' => 'payroll_input_staff_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.payroll-inputs.view');
    $user->assignRole($role);

    $company = Company::create(['name' => 'Payroll Scope Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Payroll Scope Company', 'is_active' => true, 'is_default' => false]);
    $actorStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true]);
    $peer = Staff::factory()->create(['company_id' => $company->id]);
    $foreignStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);

    $createFact = function (string $staffId, string $companyId) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_payroll_input_facts')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'fact_kind' => 'unpaid_leave',
            'effective_date' => today()->toDateString(), 'source_type' => 'scope_test', 'source_id' => (string) Str::uuid(),
            'status' => 'staged', 'source_snapshot' => json_encode(['private_reason' => 'confidential leave detail']), 'fact_checksum' => str_repeat('a', 64),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $actorFact = $createFact($actorStaff->id, $company->id);
    $peerFact = $createFact($peer->id, $company->id);
    $foreignFact = $createFact($foreignStaff->id, $company->id);
    $otherCompanyFact = $createFact($foreignStaff->id, $otherCompany->id);

    $response = actingAs($user, 'api')->getJson('/api/hr/workforce/payroll-inputs')->assertOk();
    $facts = collect($response->json('data.data'));
    $visibleIds = $facts->pluck('id')->all();
    expect($visibleIds)->toBe([$actorFact]);
    expect(array_keys($facts->first()))->toEqualCanonicalizing([
        'id', 'staff_id', 'fact_kind', 'effective_date', 'quantity_minutes',
        'quantity_units', 'rate_category', 'status', 'created_at', 'updated_at',
    ]);

    $selectedPeerResponse = actingAs($user, 'api')->getJson('/api/hr/workforce/payroll-inputs?' . http_build_query([
        'company_id' => $company->id,
        'staff_id' => $peer->id,
    ]))->assertOk();
    expect($selectedPeerResponse->json('data.data'))->toBe([]);
    $this->assertDatabaseHas('hr_payroll_input_facts', ['id' => $peerFact]);
    $this->assertDatabaseHas('hr_payroll_input_facts', ['id' => $foreignFact]);
    $this->assertDatabaseHas('hr_payroll_input_facts', ['id' => $otherCompanyFact]);
});
