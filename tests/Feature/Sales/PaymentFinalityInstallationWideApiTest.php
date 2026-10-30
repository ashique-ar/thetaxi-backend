<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists finality policies installation-wide without a company selector', function () {
    [$admin] = hr_seed_admin_actor();
    $staff = Staff::factory()->create();
    UserContext::create(['user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $staff->user->givePermissionTo('sales.payment-finality.manage');
    DB::table('booking_payment_finality_policies')->insert([
        'id' => (string) Str::uuid(), 'payment_method' => 'cash', 'official_collection_state' => 'confirmed',
        'can_earn_before_final' => false, 'hold_payout_until_final' => true, 'effective_from' => now(),
        'version' => 1, 'status' => 'draft', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($staff->user, 'api')->getJson('/api/sales/payment-finality-policies?company_id=ignored')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.company_id');
    actingAs($staff->user, 'api')->getJson('/api/sales/payment-finality-company-options')->assertNotFound();
});
