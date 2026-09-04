<?php

it('credits a collection adjustment to the original receipt-credit handler, not the current attribution handler', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));

    expect($service)
        ->toContain("->where('source_type', 'receipt_component')")
        ->toContain("->where('source_event', 'confirmed')")
        ->toContain('$originalHandlerId ?? $attribution->collection_sales_profile_id');
});
