<?php

uses(Tests\TestCase::class);

it('reuses a canonical draft for approval and confirmation transitions', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain('$draft = $this->prepareDraftForTransition($params);')
        ->toContain('$booking = $draft ?: new Booking();')
        ->toContain("abort(409, 'Only a draft booking can be submitted through the draft transition flow.')")
        ->toContain("abort(409, 'Only an existing draft can be saved through the draft endpoint.')");
});

it('preserves the selected payment arrangement when saving a draft', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));
    $draft = Str::between($service, 'public function saveBookingDraft(', 'private function hasDraftPricingInputs(');

    expect($draft)->toContain('$this->applyBookingPaymentFields($booking, $params);');
});

it('does not recreate synchronized draft items during direct confirmation', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain('saveBookingDraft already synchronized the canonical booking items for this ID')
        ->toContain("\$this->createBookingAssignments(\$booking, \$params, 'active');");
});

it('accepts canonical booking items without requiring the legacy service type field', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));

    expect(substr_count($controller, "'service_type' => 'required_without:booking_items|string'"))->toBeGreaterThanOrEqual(2);
});

it('promotes the first canonical booking item for dynamic validation', function () {
    $service = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($service)
        ->toContain("\$firstBookingItem = is_array(\$params['booking_items'] ?? null)")
        ->toContain("'from_date'")
        ->toContain("'to_date'")
        ->toContain("\$params[\$canonicalKey] = \$firstBookingItem[\$canonicalKey];");
});
