<?php

it('uses readable contract-location labels without rendering the internal location ID', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/corporate-distance-pricing-management/corporate-distance-pricing-management.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/corporate/components/corporate-distance-pricing-management/corporate-distance-pricing-management.component.ts'));

    expect($template)->toContain('{{ location.name }}')
        ->toContain('{{ location.owner_type }}')
        ->toContain('{{ location.address }}')
        ->not->toContain('<code>{{ location.id }}</code>')
        ->and($component)->toContain('track location.id');
});
