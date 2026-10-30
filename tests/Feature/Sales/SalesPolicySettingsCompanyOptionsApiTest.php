<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('does not expose a company selector for installation-wide policy settings', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('sales.policy-settings.view');

    actingAs($admin, 'api')->getJson('/api/sales/policy-settings/company-options')->assertNotFound();
    actingAs($admin, 'api')->getJson('/api/sales/policy-settings')
        ->assertOk()
        ->assertJsonMissingPath('data.company_features')
        ->assertJsonMissingPath('data.company_options');
});