<?php

it('attributes commercial value adjustments to the frozen acquisition owner, never the collection handler', function () {
    $service = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));

    expect($service)
        ->toContain("'acquisition_sales_profile_id' => \$attribution->acquisition_sales_profile_id")
        ->not->toContain("'acquisition_sales_profile_id' => \$attribution->collection_sales_profile_id");
});

it('never edits the frozen gross New Sales snapshot on the attribution', function () {
    $service = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));

    // §5.28: "Never edits gross source" — only sales_booking_attributions itself may hold
    // contract_value_source/contract_value_lkr, and this service must never ::update() it.
    expect($service)->not->toContain('$attribution->update([');
});

it('excludes extension and cancellation fee from the New Sales adjustment metric, per §5.28 new-sale rules', function () {
    $service = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));

    expect($service)->toContain("private const NEW_SALES_ADJUSTMENT_TYPES = ['increase', 'decrease', 'cancellation'];");
});

it('requires a cancellation to retire remaining lines rather than replace them with new ones', function () {
    $service = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));

    expect($service)
        ->toContain("abort_unless(empty(\$payload['items'])")
        ->toContain("\$payload['contractual_override_mode'] = 'retained_only';");
});

it('binds apply() to a checksum-matched preview and de-duplicates by booking plus idempotency key', function () {
    $service = file_get_contents(app_path('Services/Sales/BookingCommercialValueAdjustmentService.php'));

    expect($service)
        ->toContain("->where('booking_id', \$booking->id)")
        ->toContain("->where('idempotency_key', \$data['idempotency_key'])")
        ->toContain('hash_equals((string) $duplicate->request_payload_checksum, $checksum)')
        ->toContain("'preview_checksum' => (string) \$data['preview_checksum']");
});

it('keeps the schedule-revision override additive and backward compatible when absent', function () {
    $service = file_get_contents(app_path('Services/Sales/CollectionScheduleWorkflowService.php'));

    expect($service)
        ->toContain("\$overrideMode = \$data['contractual_override_mode'] ?? null;")
        ->toContain("if (\$overrideMode === 'fixed')")
        ->toContain("elseif (\$overrideMode === 'retained_only')");
});

it('records the immutable adjustment ledger row and its history endpoint under the frozen acquisition owner scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain('function previewCommercialValueAdjustment')
        ->toContain('function applyCommercialValueAdjustment')
        ->toContain("'sales.attributions.adjust-value'");
    expect($routes)->toContain('commercial-value-adjustments');
});

it('marks the adjustment ledger and its snapshot columns immutable after creation', function () {
    $model = file_get_contents(app_path('Models/Booking/BookingCommercialValueAdjustment.php'));

    expect($model)
        ->toContain('static::updating(fn () => throw new \LogicException(')
        ->toContain('static::deleting(fn () => throw new \LogicException(');
});
