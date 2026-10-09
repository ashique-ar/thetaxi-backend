<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits development plan visibility to the selected Staff company and relationship', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.development.view');
    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondCompany = Company::create(['name' => 'Selected talent company']);
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $planIds = [];
    foreach ([[$firstCompany->id, $firstStaff->id], [$secondCompany->id, $secondStaff->id]] as [$companyId, $staffId]) {
        $id = (string) Str::uuid();
        DB::table('hr_development_plans')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'plan_type' => 'development',
            'starts_at' => today()->toDateString(), 'objectives' => '[]', 'support_actions' => '[]',
            'status' => 'draft', 'confidential_notes' => 'Private coaching note', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planIds[] = $id;
    }

    actingAs($admin, 'api')->getJson('/api/hr/talent/development-plans')->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/talent/development-plans')->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $planIds[1])
        ->assertJsonMissing(['confidential_notes' => 'Private coaching note']);
});
