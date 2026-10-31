<?php

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists lifecycle cases across locations when the actor has global Staff visibility', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_cases_installation_wide_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.lifecycle.view', 'staff.view-all']);
    $actor->assignRole($role);

    $subjects = [Staff::factory()->create(), Staff::factory()->create()];
    $caseIds = [];

    foreach ($subjects as $subject) {
        $templateId = (string) Str::uuid();
        $caseId = (string) Str::uuid();
        DB::table('hr_lifecycle_templates')->insert([
            'id' => $templateId, 'case_type' => 'onboarding',
            'code' => 'CASE-' . substr($templateId, 0, 8), 'version' => 1, 'applicability' => '{}',
            'task_definitions' => '[]', 'status' => 'approved', 'created_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_lifecycle_cases')->insert([
            'id' => $caseId, 'staff_id' => $subject->id,
            'template_id' => $templateId, 'case_type' => 'onboarding', 'status' => 'open',
            'effective_date' => today()->toDateString(), 'case_snapshot' => '{}', 'opened_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $caseIds[] = $caseId;
    }

    $response = actingAs($actor, 'api')->getJson('/api/hr/lifecycle/cases')->assertOk();
    $visibleIds = collect($response->json('data.data'))->pluck('id')->all();

    expect($visibleIds)->toContain(...$caseIds);
});
