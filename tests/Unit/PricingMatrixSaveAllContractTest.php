<?php

it('keeps every pending vehicle price until save all finishes', function () {
    $source = file_get_contents(
        dirname(__DIR__, 3).'/portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/pricing-matrix-page/pricing-matrix-page.component.ts'
    );

    expect($source)
        ->toContain('reloadAfterSave = true')
        ->toContain('saveGroupChanges(groupId, false)')
        ->toContain('if (reloadAfterSave) {')
        ->toContain('await this.loadAllData();');
});
