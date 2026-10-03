<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits expense policy options to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.expenses.submit');
    $secondCompany = Company::create(['name' => 'Selected service operations company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);

    $firstPolicy = (string) Str::uuid();
    $secondPolicy = (string) Str::uuid();
    foreach ([[$firstPolicy, $firstCompany->id, 'FIRST'], [$secondPolicy, $secondCompany->id, 'SELECTED']] as [$id, $companyId, $code]) {
        DB::table('hr_expense_policy_versions')->insert([
            'id' => $id, 'company_id' => $companyId, 'code' => $code, 'version' => 1, 'status' => 'approved',
            'rules' => '{}', 'effective_from' => today()->subDay()->toDateString(), 'created_by' => $admin->id,
            'approved_by' => $admin->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $url = '/api/hr/service-operations/expense-policy-options?search=';
    actingAs($admin, 'api')->getJson($url)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $secondPolicy)
        ->assertJsonPath('data.data.0.label', 'SELECTED v1');
});
