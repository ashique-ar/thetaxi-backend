<?php

it('rechecks and locks active companies for leave and work policy writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/WorkforceController.php'));

    expect($controller)->toContain("where('is_active', true)->whereNull('deleted_at')->lockForUpdate()");

    foreach (['storeLeaveType', 'storeLeavePolicy', 'assignLeavePolicy', 'storeWorkPolicy', 'approveConfig'] as $method) {
        preg_match('/    (?:public|private) function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $match);
        $body = $match[1] ?? '';
        expect($body)->toContain('$this->lockActiveCompany(');
        if ($method === 'approveConfig') {
            $companyLock = strpos($body, '$this->lockActiveCompany(');
            $recordLock = strpos($body, 'lockForUpdate(');
            expect($companyLock)->not->toBeFalse();
            expect($recordLock)->not->toBeFalse();
            expect($companyLock)->toBeLessThan($recordLock);
        }
    }
});
