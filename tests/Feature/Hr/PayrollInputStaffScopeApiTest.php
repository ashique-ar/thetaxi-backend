<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits payroll input facts to the caller authorized Staff scope across the installation', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $user = User::factory()->create();
    $role = Role::create(['name' => 'payroll_input_staff_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.payroll-inputs.view');
    $user->assignRole($role);

    $actorStaff = Staff::factory()->create(['user_id' => $user->id]);
    UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true]);
    $peer = Staff::factory()->create();
    $otherStaff = Staff::factory()->create();

    $createFact = function (string $staffId) use ($user): string {
        $id = (string) Str::uuid();
        DB::table('hr_payroll_input_facts')->insert([
            'id' => $id, 'staff_id' => $staffId, 'fact_kind' => 'unpaid_leave',
            'effective_date' => today()->toDateString(), 'source_type' => 'scope_test', 'source_id' => (string) Str::uuid(),
            'status' => 'staged', 'source_snapshot' => json_encode(['private_reason' => 'confidential leave detail']), 'fact_checksum' => str_repeat('a', 64),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $actorFact = $createFact($actorStaff->id);
    $peerFact = $createFact($peer->id);
    $otherStaffFact = $createFact($otherStaff->id);

    $response = actingAs($user, 'api')->getJson('/api/hr/workforce/payroll-inputs')->assertOk();
    $facts = collect($response->json('data.data'));
    $visibleIds = $facts->pluck('id')->all();
    expect($visibleIds)->toBe([$actorFact]);
    expect(array_keys($facts->first()))->toEqualCanonicalizing([
        'id', 'staff_id', 'fact_kind', 'effective_date', 'quantity_minutes',
        'quantity_units', 'rate_category', 'status', 'created_at', 'updated_at',
    ]);

    $selectedPeerResponse = actingAs($user, 'api')->getJson('/api/hr/workforce/payroll-inputs?' . http_build_query([
        'company_id' => 'legacy-company-filter',
        'staff_id' => $peer->id,
    ]))->assertOk();
    expect($selectedPeerResponse->json('data.data'))->toBe([]);
    $this->assertDatabaseHas('hr_payroll_input_facts', ['id' => $peerFact]);
    $this->assertDatabaseHas('hr_payroll_input_facts', ['id' => $otherStaffFact]);
});
