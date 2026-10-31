<?php

it('keeps collection submissions bound to an active staff owner and subject-bound evidence', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $evidenceController = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $start = strpos($service, 'public function submit(');
    $end = strpos($service, 'public function verify(', $start);
    $submit = substr($service, $start, $end - $start);
    $verifyStart = strpos($service, 'public function verify(');
    $verifyEnd = strpos($service, 'private function assertCollectionReceiptMatches(', $verifyStart);
    $verify = substr($service, $verifyStart, $verifyEnd - $verifyStart);

    expect($controller)
        ->toContain('$this->workflow->submit($booking, $profile, $data, (string) $request->user()->id)')
        ->and($submit)
        ->toContain("where('staff.user_id', \$actorUserId)")
        ->toContain("where('profile.collection_eligible', true)")
        ->toContain("where('profile.status', 'active')")
        ->toContain("whereNull('staff.deleted_at')")
        ->toContain("'submitted_by_staff_id' => \$owner->staff_id")
        ->toContain("'submitted_by_user_id' => \$owner->staff_user_id")
        ->toContain('if ($duplicate->evidence_file_id)')
        ->toContain("(string) \$duplicate->submitted_by_sales_profile_id === (string) \$profile->id")
        ->toContain("(string) \$duplicate->submitted_by_staff_id === (string) \$owner->staff_id")
        ->toContain("(string) \$duplicate->submitted_by_user_id === (string) \$owner->staff_user_id")
        ->not->toContain('company_id', 'CompanyIdScope', 'companies')
        ->and($verify)
        ->toContain('immutable submitter identity; resolve its history before deciding.')
        ->toContain('(string) $submission->submitted_by_user_id === (string) $actorUserId')
        ->not->toContain('company_id', 'CompanyIdScope', 'companies')
        ->and($evidenceController)
        ->toContain("where('subject_type', \$data['subject_type'])->where('subject_id', \$data['subject_id'])")
        ->not->toContain('actorCompanyIds', 'evidence subject is outside your legal entity');
});

it('keeps collection submission source facts immutable without tenant metadata', function (): void {
    $model = file_get_contents(app_path('Models/Booking/BookingCollectionSubmission.php'));

    expect($model)
        ->toContain('private const IMMUTABLE_SUBMISSION_FIELDS = [')
        ->toContain("'booking_id', 'booking_payment_schedule_id', 'submitted_by_sales_profile_id'")
        ->toContain("'submitted_by_staff_id', 'submitted_by_user_id'")
        ->toContain("'evidence_file_id', 'staff_notes', 'idempotency_key', 'request_payload_checksum'")
        ->toContain('static::updating(function (BookingCollectionSubmission $submission): void')
        ->toContain('$submission->isDirty($field)')
        ->toContain("private const DECISION_FIELDS = ['status', 'verification_notes', 'verified_by', 'verified_at', 'booking_payment_receipt_id']")
        ->toContain("\$submission->getOriginal('status') !== 'submitted'")
        ->toContain('Collection submissions are immutable evidence and cannot be deleted.')
        ->not->toContain('company_id');
});
