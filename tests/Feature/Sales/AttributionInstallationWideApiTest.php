<?php

use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns attribution administration capabilities without a default location', function () {
    [$admin] = hr_seed_admin_actor(['name' => 'Attribution Admin']);
    $admin->givePermissionTo(['sales.attributions.view', 'sales.attributions.view-all', 'sales.attributions.correct']);

    actingAs($admin, 'api')->getJson('/api/sales/attribution-administration-context')
        ->assertOk()->assertJsonPath('data.can_correct', true)->assertJsonMissingPath('data.default_company_id');
    actingAs($admin, 'api')->getJson('/api/sales/attribution-company-options')->assertNotFound();
});

it('returns eligible Sales Profiles without company partitioning', function () {
    [$admin] = hr_seed_admin_actor();
    $profileIds = [];

    foreach (['ATTR-FIRST', 'ATTR-SECOND'] as $code) {
        $staff = Staff::factory()->create(['code' => $code.'-STAFF']);
        $profileIds[] = SalesProfile::query()->create([
            'id' => (string) Str::uuid(), 'staff_id' => $staff->id,
            'sales_code' => $code, 'status' => 'active', 'effective_from' => now()->subDay(),
            'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR',
            'acquisition_eligible' => true,
        ])->id;
    }

    $response = actingAs($admin, 'api')->getJson('/api/sales/attribution-profile-options?purpose=acquisition')->assertOk();
    expect(collect($response->json('data.data'))->pluck('value')->all())->toEqualCanonicalizing($profileIds);
});
