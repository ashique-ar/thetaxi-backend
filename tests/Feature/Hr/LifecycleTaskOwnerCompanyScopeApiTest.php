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

it('opens lifecycle tasks only for active owners in the case company', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.employee_self_service' => true]);

    $user = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_task_owner_company_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.lifecycle.manage');
    $user->assignRole($role);

    $company = Company::create(['name' => 'Lifecycle Task Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Lifecycle Company', 'is_active' => true, 'is_default' => false]);
    $actor = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $context = UserContext::create(['user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actor->id,
        'is_active' => true, 'created_user_id' => $user->id]);
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $activeOwner = Staff::factory()->create(['company_id' => $company->id]);
    $formerOwner = Staff::factory()->former()->create(['company_id' => $company->id]);
    $otherCompanyOwner = Staff::factory()->create(['company_id' => $otherCompany->id]);

    $templateId = (string) Str::uuid();
    DB::table('hr_lifecycle_templates')->insert([
        'id' => $templateId, 'company_id' => $company->id, 'case_type' => 'onboarding',
        'code' => 'TASK-OWNER-TEST', 'version' => 1, 'applicability' => '{}',
        'task_definitions' => json_encode([['code' => 'setup', 'title' => 'Set up employee record', 'owner_staff_id' => $otherCompanyOwner->id]], JSON_THROW_ON_ERROR),
        'status' => 'approved', 'created_by' => $user->id, 'approved_by' => User::factory()->create()->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = [
        'company_id' => $company->id, 'staff_id' => $subject->id,
        'template_id' => $templateId, 'effective_date' => today()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ];

    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/cases', $payload)->assertStatus(422);
    $this->assertDatabaseCount('hr_lifecycle_cases', 0);
    $this->assertDatabaseCount('hr_lifecycle_tasks', 0);

    DB::table('hr_lifecycle_templates')->where('id', $templateId)->update([
        'task_definitions' => json_encode([['code' => 'setup', 'title' => 'Set up employee record', 'owner_staff_id' => $formerOwner->id]], JSON_THROW_ON_ERROR),
    ]);
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/cases', $payload)->assertStatus(422);
    $this->assertDatabaseCount('hr_lifecycle_cases', 0);
    $this->assertDatabaseCount('hr_lifecycle_tasks', 0);

    DB::table('hr_lifecycle_templates')->where('id', $templateId)->update([
        'task_definitions' => json_encode([['code' => 'setup', 'title' => 'Set up employee record', 'owner_staff_id' => $activeOwner->id]], JSON_THROW_ON_ERROR),
    ]);
    actingAs($user, 'api')->withHeaders($headers)->postJson('/api/hr/lifecycle/cases', $payload)->assertCreated();
    $this->assertDatabaseHas('hr_lifecycle_tasks', ['owner_staff_id' => $activeOwner->id]);
});
