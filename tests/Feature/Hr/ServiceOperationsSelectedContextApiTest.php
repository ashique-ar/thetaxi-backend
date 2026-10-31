<?php

use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows installation-wide expense policy options under the selected Staff context', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.expenses.submit');
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $firstPolicy = (string) Str::uuid();
    $secondPolicy = (string) Str::uuid();
    foreach ([[$firstPolicy, 'FIRST'], [$secondPolicy, 'SELECTED']] as [$id, $code]) {
        DB::table('hr_expense_policy_versions')->insert([
            'id' => $id, 'code' => $code, 'version' => 1, 'status' => 'approved',
            'rules' => '{}', 'effective_from' => today()->subDay()->toDateString(), 'created_by' => $admin->id,
            'approved_by' => $admin->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $url = '/api/hr/service-operations/expense-policy-options?search=';
    actingAs($admin, 'api')->getJson($url)->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson($url)->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['value' => $firstPolicy])
        ->assertJsonFragment(['value' => $secondPolicy]);
});
