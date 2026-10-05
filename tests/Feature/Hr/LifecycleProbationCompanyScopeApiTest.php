<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('starts probation only from the active employment spell in the selected Staff company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.employee_self_service' => true]);

    $user = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_probation_company_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.lifecycle.manage');
    $user->assignRole($role);

    $company = Company::create(['name' => 'Probation Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Probation Company', 'is_active' => true, 'is_default' => false]);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $subject = Staff::factory()->create(['company_id' => $company->id]);

    $addSpell = function (string $companyId, int $number) use ($subject, $user): string {
        $id = (string) Str::uuid();
        DB::table('hr_employment_spells')->insert([
            'id' => $id, 'staff_id' => $subject->id, 'company_id' => $companyId, 'spell_number' => $number,
            'joined_at' => today()->toDateString(), 'service_date' => today()->toDateString(),
            'gratuity_service_start' => today()->toDateString(), 'status' => 'active', 'created_user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $addSpell($otherCompany->id, 1);
    $payload = [
        'staff_id' => $subject->id,
        'starts_at' => today()->toDateString(),
        'review_due_at' => today()->addDays(30)->toDateString(),
        'current_end_at' => today()->addDays(90)->toDateString(),
        'objectives' => [['code' => 'quality', 'target' => 'Documented review outcome']],
    ];

    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertStatus(422);
    $this->assertDatabaseCount('hr_probation_cases', 0);

    $companySpellId = $addSpell($company->id, 2);
    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertCreated();
    $this->assertDatabaseHas('hr_probation_cases', [
        'staff_id' => $subject->id, 'employment_spell_id' => $companySpellId, 'status' => 'active',
    ]);

    actingAs($user, 'api')->postJson('/api/hr/lifecycle/probation', $payload)->assertStatus(409);
    $this->assertDatabaseCount('hr_probation_cases', 1);
});
