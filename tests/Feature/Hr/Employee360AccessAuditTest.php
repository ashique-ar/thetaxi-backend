<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('records an HR employee detail view after authorized scope succeeds', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $employee = Staff::factory()->create(['company_id' => $company->id]);
    $manager = Staff::factory()->create(['company_id' => $company->id]);
    $privateMarker = 'private-employment-history-data';
    $spellId = (string) \Illuminate\Support\Str::uuid();
    $assignmentId = (string) \Illuminate\Support\Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spellId, 'staff_id' => $employee->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => '2025-01-01', 'service_date' => '2025-01-01', 'gratuity_service_start' => '2025-01-01',
        'status' => 'active', 'termination_reason' => $privateMarker, 'gratuity_service_decision' => $privateMarker,
        'prior_service_decisions' => json_encode(['note' => $privateMarker], JSON_THROW_ON_ERROR),
        'created_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_employment_assignments')->insert([
        'id' => $assignmentId, 'employment_spell_id' => $spellId, 'staff_id' => $employee->id,
        'company_id' => $company->id, 'manager_staff_id' => $manager->id, 'location_code' => 'COLOMBO',
        'assignment_type' => 'primary', 'effective_from' => '2025-01-01', 'change_reason' => $privateMarker,
        'snapshot' => json_encode(['private' => $privateMarker], JSON_THROW_ON_ERROR), 'approved_by' => $admin->id,
        'created_user_id' => $admin->id, 'updated_user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_reporting_lines')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'company_id' => $company->id,
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $employee->id,
        'line_type' => 'primary',
        'effective_from' => now()->toDateString(),
        'created_user_id' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $detail = actingAs($admin, 'api')->getJson('/api/hr/employees/'.$employee->id)->assertOk()
        ->assertJsonMissingPath('data.current_reporting_lines.0.manager_staff_id')
        ->assertJsonPath('data.current_reporting_lines.0.manager_employee_number', $manager->code)
        ->assertJsonMissingPath('data.employee.id')
        ->assertJsonMissingPath('data.employee.company_id')
        ->assertJsonPath('data.employee.company_name', $company->name)
        ->assertJsonMissingPath('data.employee.current_spell_id')
        ->assertJsonMissingPath('data.employee.current_assignment.id')
        ->assertJsonMissingPath('data.employee.current_assignment.manager_staff_id')
        ->assertJsonPath('data.employee.current_assignment.location_code', 'COLOMBO')
        ->assertJsonPath('data.employment_history.0.id', $spellId)
        ->assertJsonMissingPath('data.employment_history.0.staff_id')
        ->assertJsonMissingPath('data.employment_history.0.company_id')
        ->assertJsonMissingPath('data.employment_history.0.termination_reason')
        ->assertJsonMissingPath('data.employment_history.0.gratuity_service_decision')
        ->assertJsonMissingPath('data.employment_history.0.prior_service_decisions')
        ->assertJsonMissingPath('data.employment_history.0.assignments');
    expect($detail->getContent())->not->toContain($privateMarker)
        ->not->toContain((string) $employee->id)
        ->not->toContain((string) $manager->id)
        ->not->toContain((string) $company->id);

    $history = actingAs($admin, 'api')->getJson('/api/hr/employees/'.$employee->id.'/employment-history')->assertOk();
    $spell = $history->json('data.0');
    expect($spell)->toHaveKey('id', $spellId)
        ->not->toHaveKey('staff_id')
        ->not->toHaveKey('company_id')
        ->not->toHaveKey('termination_reason')
        ->not->toHaveKey('gratuity_service_decision')
        ->not->toHaveKey('prior_service_decisions')
        ->not->toHaveKey('assignments')
        ->and($history->getContent())->not->toContain($privateMarker);

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-sensitive-data',
        'description' => 'employee_360_viewed',
        'subject_type' => 'staff',
        'subject_id' => $employee->id,
        'causer_type' => User::class,
        'causer_id' => $admin->id,
    ]);
    $properties = json_decode(DB::table('activity_log')->where('description', 'employee_360_viewed')->value('properties'), true);
    expect(array_keys($properties))->toEqualCanonicalizing(['company_id', 'ip']);
});

it('does not load or audit an Employee 360 view outside the actor People scope', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $outside = Staff::factory()->create(['company_id' => Company::create(['name' => 'Outside People Scope'])->id]);

    actingAs($admin, 'api')->getJson('/api/hr/employees/'.$outside->id)->assertForbidden();

    $this->assertDatabaseMissing('activity_log', [
        'description' => 'employee_360_viewed',
        'subject_id' => $outside->id,
    ]);
});
