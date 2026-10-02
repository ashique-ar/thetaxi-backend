<?php

it('keeps attendance correction type options aligned with the API allowlist', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AttendanceResultController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-attendance/components/attendance-results/attendance-results.component.html'));

    preg_match("/'correction_type'\s*=>\s*\['required',\s*Rule::in\(\[([^\]]+)\]\)/", $controller, $apiMatch);
    preg_match('/<mat-select formControlName="correction_type"(.*?)<\/mat-select>/s', $template, $selectMatch);
    preg_match_all('/<mat-option value="([^"]+)"/', $selectMatch[1] ?? '', $optionMatches);

    $apiTypes = [];
    preg_match_all("/'([^']+)'/", $apiMatch[1] ?? '', $apiTypes);

    expect($apiTypes[1])->not->toBeEmpty()
        ->and($optionMatches[1])->toBe($apiTypes[1]);
});
