<?php

it('does not expose remittance IDs when a business reference is missing', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/financial-settlement-dashboard/financial-settlement-dashboard.component.ts'));

    expect($source)->toContain("remittance.reference || 'Unreferenced remittance'")
        ->not->toContain('remittance.reference || remittance.id');
});
