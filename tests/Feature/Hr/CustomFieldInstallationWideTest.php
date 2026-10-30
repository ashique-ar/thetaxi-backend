<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Hr\OrganizationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates shared custom-field definitions and audit events without a company', function () {
    $actor = User::factory()->create();
    $service = app(OrganizationAdministrationService::class);
    $data = [
        'applies_to' => 'staff',
        'field_key' => 'uniform_size',
        'label' => 'Uniform size',
        'data_type' => 'text',
        'confidentiality' => 'internal',
        'required' => false,
        'reason' => 'Shared staff profile configuration',
        'idempotency_key' => 'custom-field-create-1',
    ];

    $created = $service->createDefinition($data, $actor->id);
    $replayed = $service->createDefinition($data, $actor->id);

    expect($created)->not->toHaveKey('company_id')
        ->and($replayed['id'])->toBe($created['id'])
        ->and(DB::table('hr_organization_change_events')->where('idempotency_key', 'custom-field-create-1')->value('company_id'))->toBeNull();
});

it('replays a legacy definition command after its company is removed from the response snapshot', function () {
    $actor = User::factory()->create();
    $company = Company::create(['name' => 'Legacy definition owner']);
    $id = (string) Str::uuid();
    $data = [
        'applies_to' => 'staff',
        'field_key' => 'legacy_uniform_size',
        'label' => 'Legacy uniform size',
        'data_type' => 'text',
        'confidentiality' => 'internal',
        'required' => false,
        'reason' => 'Legacy shared staff configuration',
        'idempotency_key' => 'legacy-custom-field-create',
    ];
    $payload = array_intersect_key($data, array_flip(['applies_to', 'field_key', 'label', 'data_type', 'validation_rules', 'confidentiality', 'required', 'active']));
    $legacyChecksum = hash('sha256', json_encode([
        'command' => 'create_definition',
        'payload' => ['company_id' => $company->id] + $payload,
        'reason' => $data['reason'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $snapshot = ['id' => $id, 'company_id' => $company->id] + $payload + ['version' => 1, 'active' => true];
    DB::table('hr_organization_change_events')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'aggregate_type' => 'custom_field_definition',
        'aggregate_id' => $id, 'event_type' => 'created', 'aggregate_version' => 1,
        'after_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'reason' => $data['reason'],
        'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'],
        'request_checksum' => $legacyChecksum, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $replayed = app(OrganizationAdministrationService::class)->createDefinition($data, $actor->id);

    expect($replayed['id'])->toBe($id)->and($replayed)->not->toHaveKey('company_id');
});
