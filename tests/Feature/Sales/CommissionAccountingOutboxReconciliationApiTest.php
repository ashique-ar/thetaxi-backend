<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('exposes a read-only installation-wide accounting reconciliation summary', function () {
    [$admin] = hr_seed_admin_actor(['name' => 'Accounting reconciliation admin']);
    $admin->givePermissionTo(['sales.commission-accounting.acknowledge', 'sales.commission-statements.view-all']);

    actingAs($admin, 'api')->getJson('/api/sales/commission-accounting/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.summary.total', 0)
        ->assertJsonPath('data.summary.page_count', 0)
        ->assertJsonPath('data.data.total', 0);

    actingAs($admin, 'api')->postJson('/api/sales/commission-accounting/reconciliation')->assertStatus(405);
});
