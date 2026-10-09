<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates HR performance cycles for the selected Staff company', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Selected performance company']);
    $admin->givePermissionTo('hr.performance.configure');
    config(['hr.features.talent' => true]);

    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $payload = [
        'company_id' => $company->id, 'code' => 'SELECTED-2026', 'name' => 'Selected context cycle',
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'stages' => ['self', 'manager'],
    ];

    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/performance/cycles', $payload)->assertCreated()
        ->assertJsonPath('data.company_id', $company->id);

    expect(DB::table('hr_review_cycles')->where('code', 'SELECTED-2026')->value('company_id'))
        ->toBe($company->id);
});
