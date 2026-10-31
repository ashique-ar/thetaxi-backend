<?php

it('locks the active attendance device before identity writes without a company scope', function () {
    $authorization = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/AuthorizesAttendanceRequests.php'));
    $mapping = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesDevicePeopleMapping.php'));
    $credentials = file_get_contents(app_path('Http/Controllers/Api/Hr/Concerns/ManagesDeviceCredentials.php'));

    expect($authorization)->toContain("where('status', 'active')")
        ->toContain("where('integration_mode', 'direct_isapi')->lockForUpdate()")
        ->not->toContain('company_id', 'lockActiveAttendanceCompany', 'legal entity');

    foreach (['provisionDevicePerson', 'updateDevicePerson', 'updateDevicePersonStatus', 'bulkMapping', 'storeMapping', 'approveMapping'] as $method) {
        preg_match('/    public function '.preg_quote($method, '/').'\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $mapping, $match);
        $source = $match[1] ?? '';
        expect($source)->not->toContain('$this->lockActiveAttendanceCompany(', 'company_id', 'companyId');
        if (in_array($method, ['provisionDevicePerson', 'updateDevicePerson', 'updateDevicePersonStatus', 'bulkMapping'], true)) {
            expect($source)->toContain('$this->lockActiveDirectIsapiDevice(');
        }
    }

    preg_match('/    public function disposition\\b(.*?)(?=\\n    (?:public|private) function |\\n})/s', $credentials, $match);
    expect($match[1] ?? '')->not->toContain('$this->lockActiveAttendanceCompany(', 'company_id', 'companyId');
});
