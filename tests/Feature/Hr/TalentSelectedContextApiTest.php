<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows development plans across the installation to an authorized Staff actor', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.development.view');
    $firstStaff = Staff::factory()->create();
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $planIds = [];
    foreach ([$firstStaff->id, $secondStaff->id] as $staffId) {
        $id = (string) Str::uuid();
        DB::table('hr_development_plans')->insert([
            'id' => $id, 'staff_id' => $staffId, 'plan_type' => 'development',
            'starts_at' => today()->toDateString(), 'objectives' => '[]', 'support_actions' => '[]',
            'status' => 'draft', 'confidential_notes' => 'Private coaching note', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planIds[] = $id;
    }

    actingAs($admin, 'api')->getJson('/api/hr/talent/development-plans')->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/talent/development-plans')->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonFragment(['id' => $planIds[0]])
        ->assertJsonFragment(['id' => $planIds[1]])
        ->assertJsonMissing(['confidential_notes' => 'Private coaching note']);
});
