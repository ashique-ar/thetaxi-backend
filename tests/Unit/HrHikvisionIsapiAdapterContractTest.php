<?php

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Illuminate\Support\Facades\Http;

it('discovers a direct ISAPI terminal with digest auth without exposing credentials', function () {
    Http::fake(['10.0.0.2/*' => Http::response('<?xml version="1.0"?><DeviceInfo><model>DS-K1T808MFWX-B</model><serialNumber>SERIAL-1</serialNumber><firmwareVersion>V3.25.20</firmwareVersion><firmwareReleasedDate>build 241227</firmwareReleasedDate><manufacturer>hikvision</manufacturer><deviceType>ACS</deviceType></DeviceInfo>')]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'port' => 80, 'username' => 'admin', 'password' => 'secret']]);
    $facts = app(HikvisionIsapiAdapter::class)->discover($device);
    expect($facts['serial_number'])->toBe('SERIAL-1')->and($facts['capabilities']['access_control_events'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'http://10.0.0.2/ISAPI/System/deviceInfo');
});

it('rejects public addresses to prevent the device admin surface becoming an SSRF proxy', function () {
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'encrypted_configuration' => ['ip_address' => '8.8.8.8', 'username' => 'admin', 'password' => 'secret']]);
    expect(fn () => app(HikvisionIsapiAdapter::class)->discover($device))->toThrow(RuntimeException::class, 'private-network');
});

it('maps the proven AcsEvent pagination contract without retaining names or pictures', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['AcsEvent' => ['totalMatches' => 31, 'responseStatusStrg' => 'MORE', 'numOfMatches' => 1, 'InfoList' => [['major' => 5, 'minor' => 38, 'time' => '2026-08-31T08:15:00+05:30', 'name' => 'PRIVATE NAME', 'employeeNoString' => '34', 'serialNo' => 17731, 'currentVerifyMode' => 'cardOrFpOrPw', 'pictureURL' => '/sensitive.jpg']]]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $page = app(HikvisionIsapiAdapter::class)->attendanceEvents($device, now()->subDay(), now(), 0, 30);
    $event = $page['events'][0];
    expect($page['has_more'])->toBeTrue()->and($page['next_position'])->toBe(1)->and($event['provider_event_id'])->toBe('17731')->and($event['provider_person_id'])->toBe('34')->and($event['authentication_method'])->toBe('multi_factor')->and($event['vendor'])->not->toHaveKey('name')->not->toHaveKey('pictureURL');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data()['AcsEventCond']['maxResults'] === 30);
});

it('caps event pages at the terminal proven maximum of thirty', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['AcsEvent' => ['totalMatches' => 0, 'responseStatusStrg' => 'OK', 'InfoList' => []]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    app(HikvisionIsapiAdapter::class)->attendanceEvents($device, now()->subDay(), now(), 0, 500);
    Http::assertSent(fn ($request) => $request->data()['AcsEventCond']['maxResults'] === 30);
});

it('retrieves only the minimum device-person directory fields and enrollment counts', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['UserInfoSearch' => ['totalMatches' => 1, 'responseStatusStrg' => 'OK', 'UserInfo' => [['employeeNo' => '5', 'name' => 'SUGI', 'password' => 'must-not-return', 'doorRight' => '1', 'numOfFP' => 1, 'numOfCard' => 0, 'numOfFace' => 0, 'Valid' => ['enable' => true, 'beginTime' => '2026-01-01T00:00:00', 'endTime' => '2036-01-01T00:00:00']]]]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $page = app(HikvisionIsapiAdapter::class)->people($device);
    expect($page['people'][0])->toMatchArray(['employee_no' => '5', 'display_name' => 'SUGI', 'enabled' => true, 'enrollment' => ['fingerprints' => 1, 'cards' => 0, 'faces' => 0]])->not->toHaveKey('password')->not->toHaveKey('doorRight');
});

it('provisions a Staff identity through ISAPI without requiring the Hikvision portal', function () {
    Http::fake(['10.0.0.2/*' => Http::sequence()
        ->push(['UserInfoSearch' => ['totalMatches' => 0, 'responseStatusStrg' => 'OK', 'UserInfo' => []]])
        ->push(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $person = app(HikvisionIsapiAdapter::class)->provisionPerson($device, 'EMP-005', 'Sugi Perera');
    expect($person)->toMatchArray(['employee_no' => 'EMP-005', 'display_name' => 'Sugi Perera', 'created' => true]);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Record') && $request->data()['UserInfo']['employeeNo'] === 'EMP-005' && $request->data()['UserInfo']['name'] === 'Sugi Perera');
});

it('updates or disables a Staff identity through ISAPI without changing biometric templates', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $person = app(HikvisionIsapiAdapter::class)->updatePerson($device, 'EMP-005', 'Sugi Perera', false);
    expect($person)->toMatchArray(['employee_no' => 'EMP-005', 'display_name' => 'Sugi Perera', 'enabled' => false]);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Modify')
        && $request->data()['UserInfo']['employeeNo'] === 'EMP-005'
        && $request->data()['UserInfo']['Valid']['enable'] === false
        && ! isset($request->data()['UserInfo']['fingerPrint'])
        && ! isset($request->data()['UserInfo']['faceData']));
});

it('updates employment status without sending or changing the Hikvision display name', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $result = app(HikvisionIsapiAdapter::class)->setPersonEnabled($device, 'EMP-005', false);
    expect($result)->toMatchArray(['employee_no' => 'EMP-005', 'enabled' => false]);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->data()['UserInfo']['employeeNo'] === 'EMP-005'
        && $request->data()['UserInfo']['Valid']['enable'] === false
        && ! array_key_exists('name', $request->data()['UserInfo']));
});

it('lists, assigns and revokes cards without returning unmasked numbers from the portal contract', function () {
    Http::fake(['10.0.0.2/*' => Http::sequence()
        ->push(['CardInfoSearch' => ['totalMatches' => 1, 'responseStatusStrg' => 'OK', 'CardInfo' => [['employeeNo' => 'EMP-005', 'cardNo' => '12345678', 'cardType' => 'normalCard']]]])
        ->push(['statusCode' => 1, 'statusString' => 'OK'])
        ->push(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $adapter = app(HikvisionIsapiAdapter::class);
    expect($adapter->cards($device, 'EMP-005')[0])->toMatchArray(['card_number' => '12345678', 'card_type' => 'normalCard']);
    expect($adapter->setCard($device, 'EMP-005', '87654321', 'normalCard')['configured'])->toBeTrue();
    expect($adapter->deleteCard($device, '12345678')['deleted'])->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'CardInfo/SetUp') && $request->data()['CardInfo']['cardNo'] === '87654321');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'CardInfo/Delete') && $request->data()['CardInfoDelCond']['CardNoList'][0]['cardNo'] === '12345678');
});

it('sets a PIN through UserInfo Modify without retaining it in the adapter result', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $result = app(HikvisionIsapiAdapter::class)->setPin($device, 'EMP-005', '2468');
    expect($result)->toMatchArray(['employee_no' => 'EMP-005', 'configured' => true])->not->toHaveKey('pin');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'UserInfo/Modify') && $request->data()['UserInfo']['password'] === '2468');
});

it('finds the existing terminal owner before assigning a duplicate card', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['CardInfoSearch' => ['totalMatches' => 1, 'responseStatusStrg' => 'OK', 'CardInfo' => [['employeeNo' => 'EMP-009', 'cardNo' => '12345678', 'cardType' => 'normalCard']]]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    expect(app(HikvisionIsapiAdapter::class)->cardOwner($device, '12345678'))->toBe('EMP-009');
    Http::assertSent(fn ($request) => $request->data()['CardInfoSearchCond']['CardNoList'][0]['cardNo'] === '12345678');
});

it('applies and revokes one-door access without changing attendance identity state', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $a = app(HikvisionIsapiAdapter::class);
    expect($a->applyAccess($device, 'EMP-005', 1, 1))->toMatchArray(['granted' => true, 'door_no' => 1, 'plan_template_no' => 1]);
    expect($a->applyAccess($device, 'EMP-005', null, null)['granted'])->toBeFalse();
    Http::assertSent(fn ($r) => data_get($r->data(), 'UserInfo.RightPlan.0.planTemplateNo') === '1');
    Http::assertSent(fn ($r) => data_get($r->data(), 'UserInfo.doorRight') === '');
});

it('reads terminal time and only the approved privacy and buzzer settings', function () {
    Http::fake(['10.0.0.2/ISAPI/System/time' => Http::response('<?xml version="1.0"?><Time><timeMode>manual</timeMode><localTime>2026-09-01T09:00:00+05:30</localTime><timeZone>CST-5:30:00</timeZone></Time>'), '10.0.0.2/ISAPI/AccessControl/AcsCfg*' => Http::response(['AcsCfg' => ['showEmployeeNo' => false, 'showName' => true, 'desensitiseEmployeeNo' => true, 'desensitiseName' => true, 'buzzerEnabled' => true, 'dangerousUnknownSetting' => true]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $a = app(HikvisionIsapiAdapter::class);
    expect($a->timeFacts($device))->toMatchArray(['mode' => 'manual', 'timezone' => 'CST-5:30:00'])->and($a->safeSettings($device))->not->toHaveKey('dangerousUnknownSetting')->toHaveKey('buzzerEnabled');
});

it('reads user and card capacity without retrieving credentials', function () {
    Http::fake(['10.0.0.2/ISAPI/AccessControl/UserInfo/Count*' => Http::response(['UserInfoCount' => ['userNumber' => 47]]), '10.0.0.2/ISAPI/AccessControl/CardInfo/Count*' => Http::response(['CardInfoCount' => ['cardNumber' => 3]])]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    expect(app(HikvisionIsapiAdapter::class)->capacityFacts($device))->toBe(['users' => 47, 'user_capacity' => 3000, 'cards' => 3, 'card_capacity' => 3000]);
});

it('discovers reboot capability and sends only the restricted reboot command', function () {
    Http::fake([
        '10.0.0.2/ISAPI/System/capabilities' => Http::response('<?xml version="1.0"?><DeviceCap><isSupportReboot>true</isSupportReboot></DeviceCap>'),
        '10.0.0.2/ISAPI/System/reboot' => Http::response('', 200),
    ]);
    $device = new AttendanceDevice(['provider' => 'hikvision', 'integration_mode' => 'direct_isapi', 'timezone' => 'Asia/Colombo', 'encrypted_configuration' => ['ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'secret']]);
    $device->id = 'device-1';
    $adapter = app(HikvisionIsapiAdapter::class);
    expect($adapter->maintenanceCapabilities($device))->toBe(['reboot' => true]);
    expect($adapter->reboot($device))->toBe(['accepted' => true, 'status' => 200]);
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/ISAPI/System/reboot'));
});
