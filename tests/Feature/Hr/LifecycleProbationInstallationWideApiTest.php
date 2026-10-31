<?php

use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('starts probation only when Staff has an active installation-wide employment spell', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.employee_self_service' => true]);

    $user = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_probation_installation_wide_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.lifecycle.manage');
    $user->assignRole($role);

    $actorStaff = Staff::factory()->create(['user_id' => $user->id]);
    UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $user->id]);
    $subject = Staff::factory()->create();
    $payload = [
        'staff_id' => $subject->id,
        'starts_at' => today()->toDateString(),
        'review_due_at' => today()->addDays(30)->toDateString(),
        'current_end_at' => today()->addDays(90)->toDateString(),
        'objectives' => [['code' => 'quality', 'target' => 'Documented review outcome']],
    ];

    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertStatus(422);
    $this->assertDatabaseCount('hr_probation_cases', 0);

    $spell = HrEmploymentSpell::factory()->create(['staff_id' => $subject->id, 'status' => 'active']);
    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertCreated();
    $this->assertDatabaseHas('hr_probation_cases', [
        'staff_id' => $subject->id, 'employment_spell_id' => $spell->id, 'status' => 'active',
    ]);

    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertStatus(409);
    $this->assertDatabaseCount('hr_probation_cases', 1);
});
