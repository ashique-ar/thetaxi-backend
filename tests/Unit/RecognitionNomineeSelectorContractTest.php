<?php

it('uses bounded tenant-scoped choices instead of preloading recognition nominees', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/EngagementController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $portal = base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/recognition');
    $page = file_get_contents($portal.'/recognition.component.ts');
    $dialog = file_get_contents($portal.'/recognition-nomination-dialog.component.ts');
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/hr-engagement.service.ts'));

    expect($routes)->toContain("recognition/nominee-options")->toContain('permission:hr.recognition.nominate')
        ->and($controller)->toContain("where('s.company_id', \$actor->company_id)")->toContain("where('s.id', '<>', \$actor->id)")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->and($dialog)->toContain('app-ui-managed-record-select')->toContain('recognition/nominee-options')
        ->and($page)->not->toContain('recognitionNominees()')->and($service)->not->toContain('recognitionNominees()');
});
