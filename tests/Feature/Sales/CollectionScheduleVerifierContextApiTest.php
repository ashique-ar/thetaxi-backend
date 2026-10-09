<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Database\Seeders\AllPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits collection verifier company options and work items to the active Staff context', function () {
    (new AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $user->givePermissionTo(['sales.collections.view', 'sales.collections.verify']);
    $company = Company::create(['name' => 'Verifier Context Company']);
    $otherCompany = Company::create(['name' => 'Other Verifier Company']);
    $staff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $foreignUser = User::factory()->create();
    $foreignStaff = Staff::factory()->create(['user_id' => $foreignUser->id, 'company_id' => $otherCompany->id]);
    $context = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $headers = [
        'X-Active-Context-Type' => 'staff',
        'X-Active-Context-Id' => $context->id,
    ];

    $options = actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/sales/collection-work-company-options')
        ->assertOk();
    expect(collect($options->json('data.data'))->pluck('value')->all())->toBe([(string) $company->id]);

    actingAs($user, 'api')->withHeaders($headers)
        ->getJson('/api/sales/collection-work-items?company_id='.$otherCompany->id)
        ->assertForbidden();
});
