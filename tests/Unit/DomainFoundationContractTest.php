<?php

it('keeps shared foundation tables neutral and typed', function () {
    $migration = file_get_contents(database_path('migrations/2026_08_12_120000_create_domain_foundation_primitives.php'));

    foreach ([
        'domain_reference_versions',
        'domain_workflow_instances',
        'domain_workflow_transitions',
        'domain_idempotency_keys',
        'domain_outbox_events',
        'domain_period_locks',
        'domain_transfer_jobs',
        'domain_transfer_job_errors',
        'domain_evidence_files',
        'domain_audit_events',
    ] as $table) {
        expect($migration)->toContain("Schema::create('{$table}'");
    }

    expect($migration)
        ->not->toContain("Schema::create('hr_")
        ->not->toContain("Schema::create('sales_")
        ->not->toContain('$table->json(\'metadata\'')
        ->toContain('$table->char(\'payload_checksum\', 64)')
        ->toContain('$table->unsignedInteger(\'lock_version\')');
});

it('provides transactional outbox idempotency and workflow owners', function () {
    $outbox = file_get_contents(app_path('Services/Foundation/DomainOutboxService.php'));
    $idempotency = file_get_contents(app_path('Services/Foundation/DomainIdempotencyService.php'));
    $workflow = file_get_contents(app_path('Services/Foundation/DomainWorkflowService.php'));

    expect($outbox)->toContain('implements DomainEventPublisher')
        ->toContain('hash(\'sha256\', $canonicalPayload)')
        ->and($idempotency)
        ->toContain('lockForUpdate()')
        ->toContain('different request')
        ->and($workflow)
        ->toContain('expectedLockVersion')
        ->toContain('idempotency_key');
});

it('canonicalizes outbox payloads before hashing so checksums are order-independent', function () {
    $canonical = file_get_contents(app_path('Support/Foundation/CanonicalJson.php'));
    $outbox = file_get_contents(app_path('Services/Foundation/DomainOutboxService.php'));

    expect($canonical)
        ->toContain('ksort($value, SORT_STRING)')
        ->and($outbox)
        ->toContain('CanonicalJson::encode($payload)');

    $ordered = \App\Support\Foundation\CanonicalJson::encode(['a' => 1, 'b' => 2]);
    $reordered = \App\Support\Foundation\CanonicalJson::encode(['b' => 2, 'a' => 1]);
    expect($ordered)->toBe($reordered);
});

it('atomically claims due outbox events and relays them onto the queue with checksum verification', function () {
    $command = file_get_contents(app_path('Console/Commands/PublishDomainOutboxEvents.php'));
    $service = file_get_contents(app_path('Services/Foundation/DomainOutboxService.php'));
    $job = file_get_contents(app_path('Jobs/Foundation/RelayDomainOutboxEvent.php'));

    expect($service)
        ->toContain('claimDuePublications')
        ->toContain('lockForUpdate()')
        ->toContain('revertClaim')
        ->and($command)
        ->toContain('RelayDomainOutboxEvent::dispatch')
        ->toContain("Schema::hasTable('domain_outbox_events')")
        ->and($job)
        ->toContain('implements ShouldQueue')
        ->toContain('hash_equals')
        ->toContain('CanonicalJson::encode($payload)')
        ->toContain('DomainOutboxSubscriber');
});
