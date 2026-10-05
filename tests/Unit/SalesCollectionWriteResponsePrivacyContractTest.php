<?php

it('returns only collection submission and verification identifiers and statuses', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect($controller)
        ->toContain("'id' => (string) \$submission->id")
        ->toContain("'status' => \$submission->status")
        ->toContain("'id' => (string) \$updated->id")
        ->toContain("'status' => \$updated->status")
        ->not->toContain("'data' => \$this->workflow->submit(")
        ->not->toContain("'data' => \$this->workflow->verify(");
});

it('projects only portal collection submission fields after company integrity checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect($controller)
        ->toContain("'booking_number' => \$row->booking?->booking_number")
        ->toContain("'evidence_file_id' => \$row->evidence_file_id")
        ->not->toContain("'company_id' => \$row->company_id")
        ->not->toContain("'submitted_by_sales_profile_id' => \$row->submitted_by_sales_profile_id")
        ->not->toContain("'verified_by' => \$row->verified_by")
        ->not->toContain("'booking_payment_receipt_id' => \$row->booking_payment_receipt_id");
});

it('does not return an unneeded company identifier in the scheduled booking list', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $start = strpos($controller, 'public function scheduleBookings');
    $end = strpos($controller, 'public function schedule(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain("->select(['booking.id', 'booking.booking_number'])")
        ->not->toContain('attribution.company_id');
});

it('omits unused internal identifiers from collection schedule details', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $start = strpos($controller, 'public function schedule(');
    $end = strpos($controller, 'public function workItems(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain("->groupBy(['schedule.id', ...\$scheduleColumns])")
        ->not->toContain("'id' => \$booking->id")
        ->not->toContain("'id' => \$rule->id")
        ->not->toContain("'id', 'revision_number'")
        ->not->toContain("'collection_sales_profile_id' =>");
});

it('returns minimal confirmations for collection schedule configuration writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect($controller)
        ->toContain("'status' => \$result['status'], 'version' => \$result['version']")
        ->toContain("'revision_number' => \$revision->revision_number")
        ->not->toContain("'data' => \$this->ledger->createRollingScheduleRule(")
        ->not->toContain("'data' => \$this->ledger->transitionRollingScheduleRule(")
        ->not->toContain("'data' => \$this->workflow->reviseFutureUnpaid(");
});

it('limits collection revision previews to fields the portal renders or submits', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));

    expect($controller)
        ->toContain("'preview_checksum', 'write_performed'")
        ->toContain('array_intersect_key($preview, array_flip([')
        ->toContain('revisionPreviewProjection($preview)')
        ->not->toContain("'replaceable_lines',")
        ->not->toContain("'locked_allocated_lines',")
        ->not->toContain("'collection_sales_profile_id',")
        ->and($service)
        ->toContain("'locked_allocated_lines' =>")
        ->toContain("'replaceable_lines' =>");
});
