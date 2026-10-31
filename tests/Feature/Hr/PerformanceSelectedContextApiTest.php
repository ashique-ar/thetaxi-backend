<?php

use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates installation-wide HR performance cycles under the selected Staff context', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.performance.configure');
    config(['hr.features.talent' => true]);

    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $payload = [
        'code' => 'SELECTED-2026', 'name' => 'Selected context cycle',
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'stages' => ['self', 'manager'],
    ];

    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/performance/cycles', $payload)->assertCreated()
        ->assertJsonPath('data.code', 'SELECTED-2026');

    expect(DB::table('hr_review_cycles')->where('code', 'SELECTED-2026')->exists())->toBeTrue();
});
