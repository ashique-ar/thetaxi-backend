<?php

it('accepts only the original verifier decision retry and requires the linked receipt to match immutable collection facts', function () {
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $start = strpos($service, 'public function verify(');
    $end = strpos($service, 'private function assertCollectionReceiptMatches(', $start);
    $verify = substr($service, $start, $end - $start);
    $receiptCheckStart = strpos($service, 'private function assertCollectionReceiptMatches(');
    $receiptCheckEnd = strpos($service, 'private function ensureWorkItem(', $receiptCheckStart);
    $receiptCheck = substr($service, $receiptCheckStart, $receiptCheckEnd - $receiptCheckStart);

    expect($verify)
        ->toContain("in_array(\$submission->status, ['verified', 'rejected'], true)")
        ->toContain("\$data['decision'] === \$expectedDecision")
        ->toContain("\$submission->verified_by === \$actorUserId")
        ->toContain("\$submission->verified_at !== null")
        ->toContain("\$submission->verification_notes === (\$data['verification_notes'] ?? null)")
        ->toContain('whereKey($submission->booking_payment_receipt_id)')
        ->toContain('assertCollectionReceiptMatches($booking, $attribution->company_id, $submission, $receipt, $data)')
        ->toContain('Only submitted collection evidence can be verified.')
        ->and($receiptCheck)
        ->toContain("\$receipt->idempotency_key === 'collection-submission:'.\$submission->id")
        ->toContain("\$receipt->company_id === \$companyId")
        ->toContain("\$receipt->received_by === \$actorUserId")
        ->toContain("\$receipt->payment_purpose === 'booking_payment'")
        ->toContain("\$receipt->payment_stage === 'account_payment'")
        ->toContain("\$receipt->source_currency) === \$currency")
        ->toContain("\$receipt->lkr_amount, 4, '.', '')")
        ->toContain("preg_match('/^[a-f0-9]{64}$/i', \$checksum) === 1")
        ->toContain("DB::table('booking_payment_schedule_allocations as allocation')")
        ->toContain("->orWhere('schedule.booking_id', '!=', \$booking->id)")
        ->toContain("->orWhere('schedule.company_id', '!=', \$companyId)")
        ->and($verify)
        ->toContain("->where('booking_id', \$booking->id)")
        ->toContain("->where('company_id', \$attribution->company_id)")
        ->toContain("where('booking_payment_schedule_id', \$schedule->id)");
});
