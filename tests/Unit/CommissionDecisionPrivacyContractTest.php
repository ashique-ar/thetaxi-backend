<?php

it('projects commission decision reads instead of serializing immutable decision models', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionDecisionController.php'));

    expect($controller)
        ->toContain('$page->getCollection()->transform(fn (SalesCommissionDecision $decision): array => $this->projection($decision))')
        ->toContain("'booking_number' => \$decision->booking?->booking_number")
        ->toContain("\$this->projection(\$query->with('booking:id,booking_number')->firstOrFail())")
        ->not->toContain("'data' => $query->firstOrFail()");
});
