<?php

it('uses the authorized searchable QC inspector selector across booking flows', function () {
    $base = base_path('../portal-thetaxi/src/app/modules/booking/components');
    $management = file_get_contents($base.'/booking-management/booking-management.component.html');
    $staffWorkspace = file_get_contents($base.'/staff-booking-workspace/staff-booking-workspace.component.html');
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/services/booking-lifecycle.service.ts'));
    $returnInspection = file_get_contents($base.'/return-inspection-management/return-inspection-management.component.ts');
    $returnInspectionTemplate = file_get_contents($base.'/return-inspection-management/return-inspection-management.component.html');
    $startInspectionDialog = file_get_contents($base.'/return-inspection-management/dialogs/start-inspection-dialog.component.ts');

    expect($management)->toContain('<app-ui-managed-record-select', 'endpoint="/booking-lifecycle/inspectors/available"')
        ->and($management)->not->toContain('availableInspectors()')
        ->and($staffWorkspace)->toContain('<app-ui-managed-record-select', 'endpoint="/booking-lifecycle/inspectors/available"')
        ->and($staffWorkspace)->not->toContain('qc_inspector_id', 'availableInspectors()')
        ->and($startInspectionDialog)->toContain('endpoint="/booking-lifecycle/inspectors/available"', 'form.inspector_id')
        ->not->toContain('inspector_name', '<input matInput')
        ->and($returnInspection)->toContain('inspector_id: result.inspector_id')
        ->not->toContain('result.inspector_name')
        ->and($returnInspectionTemplate)->not->toContain('Add inspector options', 'formControlName="inspector_id"')
        ->and($service)->not->toContain('getAvailableInspectors()');
});
