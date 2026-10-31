<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Database\Seeders\AllPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('loads collection work without a location selector or company filter', function () {
    (new AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $user->givePermissionTo(['sales.collections.view', 'sales.collections.verify']);
    $staff = Staff::factory()->create(['user_id' => $user->id]);
    $context = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $headers = [
        'X-Active-Context-Type' => 'staff',
        'X-Active-Context-Id' => $context->id,
    ];

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/sales/collection-work-company-options')->assertNotFound();

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/sales/collection-work-items?company_id=00000000-0000-0000-0000-000000000001')
        ->assertOk()->assertJsonCount(0, 'data.data');
});
