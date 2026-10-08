<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows a connector key only in the one-time provisioning response', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.attendance_ingestion' => true]);

    $response = actingAs($admin, 'api')->postJson('/api/hr/attendance/connectors', [
        'company_id' => $company->id,
        'name' => 'Attendance gateway',
        'topology' => 'local_connector',
    ])->assertCreated()
        ->assertJsonPath('data.secret_display', 'one_time_only')
        ->assertJsonMissingPath('data.connector.connector_key')
        ->assertJsonMissingPath('data.connector.signing_secret');

    expect($response->json('data.connector_key'))->toStartWith('ATC-')
        ->and($response->json('data.signing_secret'))->toHaveLength(64);
});
