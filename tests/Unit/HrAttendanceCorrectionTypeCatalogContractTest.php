<?php

it('keeps attendance correction writes closed until the type-to-field mapping is approved', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $service = file_get_contents(app_path('Services/Hr/Attendance/AttendanceResultService.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-results/attendance-results.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-results/attendance-results.component.ts'));

    expect($controller)->toContain('Attendance correction submission is unavailable until the correction-type-to-field mapping is approved.')
        ->and($service)->toContain('Attendance correction approval is unavailable until the correction-type-to-field mapping is approved.')
        ->and($component)->toContain('readonly correctionTypeMappingAvailable = false;')
        ->and($template)->toContain('correction type')
        ->and($template)->not->toContain('requested_values_json', 'formControlName="correction_type"');
});
