<?php

it('does not expose segment record IDs in ongoing-hire analytics labels', function () {
    $source = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/dialogs/segment-analytics-dialog.component.ts'));

    expect($source)->toContain("seg.name || ('Segment ' + (i + 1))")
        ->not->toContain('seg.name || seg.id');
});
