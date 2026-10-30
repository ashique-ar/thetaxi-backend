<?php

it('removes the company scope selector and its lookup service', function () {
    $root = base_path('../portal-thetaxi/src/app');

    expect(is_file($root.'/shared/services/legal-entity-lookup.service.ts'))->toBeFalse()
        ->and(is_file($root.'/shared/components/ui/legal-entity-select/legal-entity-select.component.ts'))->toBeFalse();
});
