<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('does not expose a company selector for payment adjustments', function () {
    [$user] = hr_seed_admin_actor();

    actingAs($user, 'api')->getJson('/api/sales/payment-adjustment-company-options')->assertNotFound();
});
