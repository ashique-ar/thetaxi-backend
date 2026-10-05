<?php

it('locks dispute workflows in statement-first order and enforces statement, line, and Staff ownership', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDisputeService.php'));
    $resolve = substr($service, strpos($service, 'public function resolve('));

    expect($service)
        ->toContain("where('id', \$staffId)->where('company_id', \$statement->company_id)")
        ->toContain("->where('statement_id', \$statement->id)->lockForUpdate()->first()")
        ->toContain('The statement beneficiary Staff record does not match its legal entity.')
        ->toContain('The dispute statement does not match its legal entity.')
        ->toContain('The dispute line or claimant Staff record does not match its statement and legal entity.')
        ->toContain('The statement contested hold does not reconcile to this dispute.');

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
