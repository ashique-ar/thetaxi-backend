<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('stores private meal dietary data for the selected Staff identity', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.meals.use');
    config(['hr.features.meals' => true]);
    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondCompany = Company::create(['name' => 'Second Meal Programme company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $payload = [
        'opted_in' => true, 'fulfilment_label' => 'Selected context', 'restrictions' => 'Confidential dietary note',
        'consent' => true, 'effective_from' => today()->toDateString(),
    ];

    actingAs($admin, 'api')->putJson('/api/hr/meals/my-dietary-profile', $payload)->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->putJson('/api/hr/meals/my-dietary-profile', $payload)->assertCreated()
        ->assertJsonPath('data.staff_id', $secondStaff->id)
        ->assertJsonMissing(['restrictions' => 'Confidential dietary note']);

    $profile = DB::table('hr_meal_dietary_profiles')->where('staff_id', $secondStaff->id)->first();
    expect($profile)->not->toBeNull()
        ->and($profile->encrypted_restrictions)->not->toBe('Confidential dietary note')
        ->and(DB::table('hr_meal_dietary_profiles')->where('staff_id', $firstStaff->id)->exists())->toBeFalse();
});
