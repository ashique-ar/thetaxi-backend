<?php

it('locks an active company throughout incident and inspection writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));

    expect($controller)->toContain("where('is_active', true)->whereNull('deleted_at')->lockForUpdate()");

    foreach (['report', 'assignInvestigator', 'storeInvestigation', 'approveInvestigation', 'storeAction', 'completeAction', 'verifyAction', 'closeIncident', 'storeInspection', 'completeInspection', 'verifyInspection'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $match);
        $body = $match[1] ?? '';
        expect($body)->toContain('$this->lockActiveCompany(');
        $companyLock = strpos($body, '$this->lockActiveCompany(');
        $recordLock = strpos($body, 'lockForUpdate(');
        expect($companyLock)->not->toBeFalse();
        expect($recordLock)->not->toBeFalse();
        expect($companyLock)->toBeLessThan($recordLock);
    }

    preg_match('/    public function completeInspection\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $inspection);
    expect($inspection[1] ?? '')->toContain("where('is_active', true)");
});
