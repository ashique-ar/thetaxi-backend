<?php

it('locks an active company across the external safety notification lifecycle', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/SafetyController.php'));

    expect($controller)->toContain("where('is_active', true)->whereNull('deleted_at')->lockForUpdate()");

    foreach (['prepareExternalNotification', 'approveExternalNotification', 'claimExternalNotification', 'acknowledgeExternalNotification', 'failExternalNotification'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $controller, $match);
        expect($match[1] ?? '')->toContain('$this->lockActiveCompany(');
    }
});
