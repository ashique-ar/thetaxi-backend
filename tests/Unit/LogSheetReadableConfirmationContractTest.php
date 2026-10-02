<?php

it('does not expose a log sheet database ID in its delete confirmation', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/logsheet/components/logsheet-list/logsheet-list.component.ts'));

    expect($source)->toContain("'without a reference'")
        ->not->toContain('logSheet.reference_number ?? logSheet.id');
});
