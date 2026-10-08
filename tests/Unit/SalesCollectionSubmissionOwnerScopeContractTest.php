<?php

it('locks the authenticated collection owner and revalidates bound evidence on exact submission retries', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $evidenceController = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $start = strpos($service, 'public function submit(');
    $end = strpos($service, 'public function verify(', $start);
    $submit = substr($service, $start, $end - $start);
    $uploadStart = strpos($evidenceController, "if (\$type === 'booking')");
    $uploadEnd = strpos($evidenceController, "} elseif (\$type === 'booking_payment_receipt')", $uploadStart);
    $uploadScope = substr($evidenceController, $uploadStart, $uploadEnd - $uploadStart);

    expect($controller)
        ->toContain('$this->workflow->submit($booking, $profile, $data, (string) $request->user()->id)')
        ->and($submit)
        ->toContain("where('staff.user_id', \$actorUserId)")
        ->toContain("whereColumn('profile.company_id', 'staff.company_id')")
        ->toContain("(string) \$duplicate->company_id === (string) \$profile->company_id")
        ->toContain("(string) \$duplicate->submitted_by_sales_profile_id === (string) \$profile->id")
        ->toContain("(string) \$duplicate->submitted_by_staff_id === (string) \$owner->staff_id")
        ->toContain("(string) \$duplicate->submitted_by_user_id === (string) \$owner->staff_user_id")
        ->toContain('different facts or ownership.')
        ->toContain("where('profile.collection_eligible', true)")
        ->toContain("where('profile.status', 'active')")
        ->toContain("whereNull('staff.deleted_at')")
        ->toContain("'submitted_by_staff_id' => \$owner->staff_id")
        ->toContain("'submitted_by_user_id' => \$owner->staff_user_id")
        ->toContain('lockForUpdate()')
        ->toContain('The collection Sales Profile and active Staff owner must match the booking legal entity.')
        ->toContain('if ($duplicate->evidence_file_id)')
        ->toContain("where('company_id', \$duplicate->company_id)->whereNull('deleted_at')")
        ->toContain("where('subject_type', 'booking')->where('subject_id', \$duplicate->booking_id)")
        ->toContain('The stored collection evidence is no longer active or bound to this booking and legal entity.')
        ->toContain("where('domain', 'sales')->where('company_id', \$profile->company_id)")
        ->toContain('BookingCollectionSubmission::create([')
        ->and(strpos($submit, 'abort_unless($owner'))
        ->toBeLessThan(strpos($submit, "if (! empty(\$data['evidence_file_id']))"));

    expect($submit)
        ->toContain("DB::table('companies')->where('id', \$profile->company_id)")
        ->toContain("->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first()")
        ->toContain('Collection submission requires an active legal entity.')
        ->and(strpos($submit, "DB::table('companies')"))
        ->toBeLessThan(strpos($submit, 'Booking::query()->lockForUpdate()'));

    expect($uploadScope)
        ->toContain("whereColumn('profile.company_id', 'staff.company_id')")
        ->toContain("where('profile.collection_eligible', true)")
        ->toContain("whereNull('staff.deleted_at')")
        ->toContain("where('staff.user_id', \$request->user()->id)");
});

it('prevents submitted collection source facts and evidence from being edited or deleted', function () {
    $model = file_get_contents(app_path('Models/Booking/BookingCollectionSubmission.php'));

    expect($model)
        ->toContain('private const IMMUTABLE_SUBMISSION_FIELDS = [')
        ->toContain("'company_id', 'booking_id', 'booking_payment_schedule_id', 'submitted_by_sales_profile_id'")
        ->toContain("'submitted_by_staff_id', 'submitted_by_user_id'")
        ->toContain("'evidence_file_id', 'staff_notes', 'idempotency_key', 'request_payload_checksum'")
        ->toContain('static::updating(function (BookingCollectionSubmission $submission): void')
        ->toContain('$submission->isDirty($field)')
        ->toContain("private const DECISION_FIELDS = ['status', 'verification_notes', 'verified_by', 'verified_at', 'booking_payment_receipt_id']")
        ->toContain("\$submission->getOriginal('status') !== 'submitted'")
        ->toContain('A collection submission decision is immutable once recorded.')
        ->toContain('static::deleting(')
        ->toContain('Collection submissions are immutable evidence and cannot be deleted.')
        ->not->toContain("'status', 'verification_notes', 'verified_by'");
});

it('uses immutable submitter evidence for separation of duties and guards migration rollback', function () {
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));
    $start = strpos($service, 'public function verify(');
    $end = strpos($service, 'private function assertCollectionReceiptMatches(', $start);
    $verify = substr($service, $start, $end - $start);
    $migration = file_get_contents(base_path('database/migrations/2026_10_04_000003_add_immutable_submitter_identity_to_collection_submissions.php'));

    expect($verify)
        ->toContain("DB::table('companies')->where('id', \$submission->company_id)")
        ->toContain("->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first()")
        ->toContain('Collection verification requires an active legal entity.')
        ->and(strpos($verify, "DB::table('companies')"))
        ->toBeLessThan(strpos($verify, 'Booking::query()->whereKey($submission->booking_id)->lockForUpdate()'))
        ->and($verify)
        ->toContain('$submission->submitted_by_staff_id && $submission->submitted_by_user_id')
        ->toContain('(string) $submission->submitted_by_user_id === (string) $actorUserId')
        ->toContain('immutable submitter identity; resolve its history before deciding.')
        ->and($migration)
        ->toContain("foreignUuid('submitted_by_staff_id')->nullable()")
        ->toContain("foreignUuid('submitted_by_user_id')->nullable()")
        ->toContain("whereNotNull('submitted_by_staff_id')->orWhereNotNull('submitted_by_user_id')")
        ->toContain('Cannot remove immutable collection submitter identity after it has been recorded.');
});
