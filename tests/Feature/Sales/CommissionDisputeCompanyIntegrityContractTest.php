<?php

it('locks dispute workflows in statement-first order and enforces statement, line, and Staff ownership without company boundaries', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $resolve = substr($service, strpos($service, 'public function resolve('));

    expect($service)
        ->toContain("->where('statement_id', \$statement->id)->lockForUpdate()->first()")
        ->toContain('Only the statement beneficiary may dispute this line.')
        ->toContain('The dispute does not belong to its statement.')
        ->toContain('The dispute line or claimant Staff record does not match its statement.')
        ->toContain('The statement contested hold does not reconcile to this dispute.')
        ->not->toContain('CompanyIdScope', 'lockActiveCompany', "'company_id' => \$companyId");

    expect(strpos($resolve, 'SalesCommissionStatement::query()->lockForUpdate()'))
        ->toBeLessThan(strpos($resolve, 'SalesCommissionDispute::query()->lockForUpdate()'));
});

it('checks exact dispute retries before mutable statement status and policy gates', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $raise = substr($service, strpos($service, 'public function raise('), strpos($service, 'public function resolve(') - strpos($service, 'public function raise('));

    expect(strpos($raise, 'if ($duplicate)'))
        ->toBeLessThan(strpos($raise, 'disputeResponseDays('))
        ->toBeLessThan(strpos($raise, "in_array(\$statement->status, ['paid', 'void']"));
});

it('recovers globally unique dispute-key races only for checksum-matched replays', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $raise = substr($service, strpos($service, 'public function raise('), strpos($service, 'public function resolve(') - strpos($service, 'public function raise('));
    $resolve = substr($service, strpos($service, 'public function resolve('));

    expect($raise)
        ->toContain('catch (QueryException $exception)')
        ->toContain("where('idempotency_key', \$data['idempotency_key'])->first()")
        ->toContain('hash_equals($duplicate->request_payload_checksum, $requestChecksum)');

    expect($resolve)
        ->toContain('catch (QueryException $exception)')
        ->toContain("where('resolution_idempotency_key', \$data['idempotency_key'])->first()")
        ->toContain('$duplicate->id === $dispute->id')
        ->toContain('hash_equals((string) $duplicate->resolution_payload_checksum, $checksum)');
});

it('locks statement identities before dispute writes and idempotent race recovery', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $raise = substr($service, strpos($service, 'public function raise('), strpos($service, 'public function resolve(') - strpos($service, 'public function raise('));
    $resolve = substr($service, strpos($service, 'public function resolve('));

    expect(strpos($raise, 'SalesCommissionStatement::query()->lockForUpdate()'))
        ->toBeLessThan(strpos($raise, 'SalesCommissionStatementLine::query()'))
        ->and(strpos($resolve, 'SalesCommissionStatement::query()->lockForUpdate()'))
        ->toBeLessThan(strpos($resolve, 'SalesCommissionDispute::query()->lockForUpdate()'))
        ->and($raise)->toContain('return DB::transaction(function () use ($exception')
        ->and($resolve)->toContain('return DB::transaction(function () use ($exception');
});

it('removes tenant metadata from immutable statement adjustments', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionStatementAdjustment.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_30_000090_remove_statement_adjustment_tenant_company.php'));
    $disputeMigration = file_get_contents(database_path('migrations/2026_10_30_000091_remove_commission_dispute_tenant_company.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $disputes = substr($controller, strpos($controller, 'public function disputes('), strpos($controller, 'public function payouts(') - strpos($controller, 'public function disputes('));

    expect($service)->not->toContain("'company_id' => \$companyId, 'staff_id' => \$statement->staff_id", 'CompanyIdScope', 'lockActiveCompany')
        ->and($model)->not->toContain("'company_id'")
        ->and($migration)->toContain("'sales_commission_statement_adjustments'", "dropColumn('company_id')")
        ->and($disputeMigration)->toContain("'sales_commission_disputes'", "dropColumn('company_id')")
        ->and($disputes)->toContain('applyScope($statements, $request)', "whereIn('statement_id', \$statements->select('id'))")
        ->not->toContain('company_id', 'actorCompanyIds');
});
