<?php

it('locks an active legal entity before attendance identity writes', function () {
    $authorization = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/AuthorizesAttendanceRequests.php'));
    $mapping = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesDevicePeopleMapping.php'));
    $credentials = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesDeviceCredentials.php'));

    expect($authorization)->toContain("where('is_active', true)->whereNull('deleted_at')->lockForUpdate()");
    expect($authorization)->toContain("where('status', 'active')")
        ->toContain("where('integration_mode', 'direct_isapi')->lockForUpdate()");

    foreach (['provisionDevicePerson', 'updateDevicePerson', 'updateDevicePersonStatus', 'bulkMapping', 'storeMapping', 'approveMapping'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $mapping, $match);
        $source = $match[1] ?? '';
        expect($source)->toContain('$this->lockActiveAttendanceCompany(');
        if (in_array($method, ['provisionDevicePerson', 'updateDevicePerson', 'updateDevicePersonStatus', 'bulkMapping'], true)) {
            expect($source)->toContain('$this->lockActiveDirectIsapiDevice(');
        }
    }

    preg_match('/    public function disposition\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $credentials, $match);
    expect($match[1] ?? '')->toContain('$this->lockActiveAttendanceCompany(');
});
