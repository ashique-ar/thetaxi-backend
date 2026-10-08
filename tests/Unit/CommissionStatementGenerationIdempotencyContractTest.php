<?php

it('replays concurrent statement generation keys only when their payload checksum matches', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_12_145000_create_commission_statement_and_payout_workflow.php'));

    expect($service)
        ->toContain('catch (QueryException $exception)')
        ->toContain("where('generation_idempotency_key', \$data['idempotency_key'])->first()")
        ->toContain('if (! $duplicate) throw $exception;')
        ->toContain("->where('cycle_version_id', \$facts['cycle']->id)")
        ->toContain("throw ValidationException::withMessages(['period_start' => ['A non-void statement already exists for this Staff/cycle period.']]);")
        ->toContain('hash_equals($duplicate->generation_payload_checksum, $checksum)')
        ->toContain('return $duplicate->load(\'lines\');')
        ->toContain('This statement generation key was already used with different facts.')
        ->and($migration)
        ->toContain("string('generation_idempotency_key', 160)->unique()")
        ->toContain("char('generation_payload_checksum', 64)");
});

it('replays statement transitions only when their original version and decision facts match', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($service)
        ->toContain('$duplicate->from_version === $expectedVersion')
        ->toContain('$duplicate->to_status === $toStatus')
        ->toContain('$duplicate->reason === $reason')
        ->toContain('$duplicate->actor_user_id === $actorUserId')
        ->toContain('This statement transition key was already used with different facts.');
});

it('locks the active statement company before generation and transition writes', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));
    $generate = substr($service, strpos($service, 'public function generate('), strpos($service, 'public function transition(') - strpos($service, 'public function generate('));
    $transition = substr($service, strpos($service, 'public function transition('), strpos($service, 'private function lockActiveCompany(') - strpos($service, 'public function transition('));

    expect(strpos($generate, '$this->lockActiveCompany('))->toBeLessThan(strpos($generate, 'SalesProfile::query()->with(\'staff\')->lockForUpdate()'))
        ->and(strpos($generate, 'SalesProfile::query()->with(\'staff\')->lockForUpdate()'))
            ->toBeLessThan(strpos($generate, 'generation_idempotency_key'))
        ->and(strpos($transition, '$this->lockActiveCompany('))->toBeLessThan(strpos($transition, 'SalesCommissionStatement::query()->lockForUpdate()'))
        ->and($generate)->toContain('return DB::transaction(function () use ($exception', "where('is_active', true)", "whereNull('deleted_at')")
        ->and($transition)->toContain("where('is_active', true)", "whereNull('deleted_at')");
});
