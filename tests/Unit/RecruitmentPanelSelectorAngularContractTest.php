<?php

it('uses the shared readable multi-selector instead of comma-separated Staff UUIDs', function () {
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-lifecycle/components/recruitment/schedule-interview-dialog.component.ts'));

    expect($component)
        ->toContain('UiManagedRecordMultiSelectComponent')
        ->toContain('endpoint="/hr/recruitment/interview-panel-options"')
        ->toContain('formControlName="panel_staff_ids"')
        ->not->toContain('Panel Staff IDs (comma-separated)')
        ->not->toContain('panel_staff_ids_csv');
});
