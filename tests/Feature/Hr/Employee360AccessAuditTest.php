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

    actingAs($admin, 'api')->getJson('/api/hr/employees/'.$employee->id)->assertOk();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-sensitive-data',
        'description' => 'employee_360_viewed',
        'subject_type' => Staff::class,
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
