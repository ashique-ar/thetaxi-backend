<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\Attendance\AttendanceProviderAdapter;
use App\Models\Hr\Attendance\AttendanceDevice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HikvisionIsapiAdapter implements AttendanceProviderAdapter
{
    public function discover(AttendanceDevice $device): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->get($this->baseUrl($configuration).'/ISAPI/System/deviceInfo');
        $response->throw();
        $xml = @simplexml_load_string($response->body());
        if ($xml === false) {
            throw new RuntimeException('Hikvision returned invalid device information XML.');
        }

        $value = static fn (string $name): string => trim((string) ($xml->{$name} ?? ''));
        $serial = $value('serialNumber');
        if ($serial === '') {
            throw new RuntimeException('Hikvision device information did not include a serial number.');
        }

        return [
            'model' => $value('model'),
            'serial_number' => $serial,
            'firmware' => trim($value('firmwareVersion').' '.$value('firmwareReleasedDate')),
            'manufacturer' => $value('manufacturer'),
            'device_type' => $value('deviceType'),
            'capabilities' => [
                'isapi_device_info' => true,
                'access_control_events' => strcasecmp($value('deviceType'), 'ACS') === 0,
            ],
        ];
    }

    public function attendanceEvents(AttendanceDevice $device, \DateTimeInterface $from, \DateTimeInterface $to, int $position = 0, int $limit = 30): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $timezone = new \DateTimeZone($device->timezone);
        $limit = max(1, min(30, $limit));
        $position = max(0, $position);
        $searchId = substr(hash('sha256', $device->id.'|'.$from->format(DATE_ATOM).'|'.$to->format(DATE_ATOM)), 0, 32);
        $response = $this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/AcsEvent?format=json', [
            'AcsEventCond' => [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => $limit,
                'major' => 0,
                'minor' => 0,
                'startTime' => CarbonImmutable::instance(\DateTime::createFromInterface($from))->setTimezone($timezone)->format(DATE_ATOM),
                'endTime' => CarbonImmutable::instance(\DateTime::createFromInterface($to))->setTimezone($timezone)->format(DATE_ATOM),
            ],
        ]);
        $response->throw();
        $result = (array) $response->json('AcsEvent', []);
        $rows = array_values((array) ($result['InfoList'] ?? []));
        $events = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['serialNo']) || empty($row['time'])) {
                continue;
            }
            $personId = (string) ($row['employeeNoString'] ?? $row['employeeNo'] ?? $row['cardNo'] ?? '');
            $occurred = CarbonImmutable::parse((string) $row['time']);
            $attendanceStatus = (string) ($row['attendanceStatus'] ?? 'undefined');
            $events[] = [
                'provider_event_id' => (string) $row['serialNo'],
                'provider_person_id' => $personId !== '' ? $personId : null,
                'employee_number' => $personId !== '' ? $personId : null,
                'occurred_at' => $occurred->toIso8601String(),
                'source_timezone' => $device->timezone,
                'source_utc_offset_minutes' => (int) ($occurred->utcOffset() / 60),
                'event_kind' => $personId !== '' ? 'punch' : 'access_control',
                'direction' => match ($attendanceStatus) {
                    'checkIn', 'breakIn', 'overtimeIn' => 'in', 'checkOut', 'breakOut', 'overtimeOut' => 'out', default => null
                },
                'authentication_method' => $this->authenticationMethod((string) ($row['currentVerifyMode'] ?? '')),
                'verification_result' => ((int) ($row['major'] ?? 0) === 5 && $personId !== '') ? 'accepted' : 'device_event',
                'vendor' => $this->redactedEvent($row),
            ];
        }
        $total = (int) ($result['totalMatches'] ?? count($events));
        $next = $position + count($rows);

        return ['events' => $events, 'total_matches' => $total, 'has_more' => strtoupper((string) ($result['responseStatusStrg'] ?? '')) === 'MORE' && $next < $total, 'next_position' => $next];
    }

    public function people(AttendanceDevice $device, int $position = 0, int $limit = 30): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $position = max(0, $position);
        $limit = max(1, min(30, $limit));
        $response = $this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Search?format=json', ['UserInfoSearchCond' => ['searchID' => substr(hash('sha256', $device->id.'|people'), 0, 32), 'searchResultPosition' => $position, 'maxResults' => $limit]]);
        $response->throw();
        $result = (array) $response->json('UserInfoSearch', []);
        $rows = array_values((array) ($result['UserInfo'] ?? []));
        $people = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['employeeNo'])) {
                continue;
            }$valid = (array) ($row['Valid'] ?? []);
            $people[] = ['employee_no' => (string) $row['employeeNo'], 'display_name' => trim((string) ($row['name'] ?? '')), 'enabled' => (bool) ($valid['enable'] ?? true), 'valid_from' => $valid['beginTime'] ?? null, 'valid_until' => $valid['endTime'] ?? null, 'enrollment' => ['fingerprints' => (int) ($row['numOfFP'] ?? 0), 'cards' => (int) ($row['numOfCard'] ?? 0), 'faces' => (int) ($row['numOfFace'] ?? 0)]];
        }
        $total = (int) ($result['totalMatches'] ?? count($people));
        $next = $position + count($rows);

        return ['people' => $people, 'total_matches' => $total, 'has_more' => strtoupper((string) ($result['responseStatusStrg'] ?? '')) === 'MORE' && $next < $total, 'next_position' => $next];
    }

    public function provisionPerson(AttendanceDevice $device, string $employeeNumber, string $displayName): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $position = 0;
        do {
            $page = $this->people($device, $position, 30);
            $existing = collect($page['people'])->firstWhere('employee_no', $employeeNumber);
            if ($existing) {
                return ['employee_no' => $employeeNumber, 'display_name' => $existing['display_name'], 'created' => false];
            }
            $position = $page['next_position'];
        } while ($page['has_more']);

        $response = $this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Record?format=json', [
            'UserInfo' => [
                'employeeNo' => $employeeNumber,
                'name' => mb_substr(trim($displayName), 0, 32),
                'userType' => 'normal',
                'Valid' => ['enable' => true, 'beginTime' => now($device->timezone)->startOfDay()->format('Y-m-d\TH:i:s'), 'endTime' => now($device->timezone)->addYears(10)->endOfDay()->format('Y-m-d\TH:i:s')],
                'doorRight' => '1',
                'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => '1']],
            ],
        ]);
        $response->throw();

        return ['employee_no' => $employeeNumber, 'display_name' => trim($displayName), 'created' => true];
    }

    public function updatePerson(AttendanceDevice $device, string $employeeNumber, string $displayName, bool $enabled): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->put($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Modify?format=json', [
            'UserInfo' => [
                'employeeNo' => $employeeNumber,
                'name' => mb_substr(trim($displayName), 0, 32),
                'Valid' => [
                    'enable' => $enabled,
                    'beginTime' => now($device->timezone)->startOfDay()->format('Y-m-d\TH:i:s'),
                    'endTime' => now($device->timezone)->addYears(10)->endOfDay()->format('Y-m-d\TH:i:s'),
                ],
            ],
        ]);
        $response->throw();

        return ['employee_no' => $employeeNumber, 'display_name' => trim($displayName), 'enabled' => $enabled];
    }

    public function setPersonEnabled(AttendanceDevice $device, string $employeeNumber, bool $enabled): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->put($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Modify?format=json', [
            'UserInfo' => [
                'employeeNo' => $employeeNumber,
                'Valid' => [
                    'enable' => $enabled,
                    'beginTime' => now($device->timezone)->startOfDay()->format('Y-m-d\TH:i:s'),
                    'endTime' => now($device->timezone)->addYears(10)->endOfDay()->format('Y-m-d\TH:i:s'),
                ],
            ],
        ]);
        $response->throw();

        return ['employee_no' => $employeeNumber, 'enabled' => $enabled];
    }

    public function cards(AttendanceDevice $device, string $employeeNumber): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $position = 0;
        $cards = [];
        do {
            $response = $this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/CardInfo/Search?format=json', ['CardInfoSearchCond' => ['searchID' => substr(hash('sha256', $device->id.'|cards|'.$employeeNumber), 0, 32), 'searchResultPosition' => $position, 'maxResults' => 30, 'EmployeeNoList' => [['employeeNo' => $employeeNumber]]]]);
            $response->throw();
            $result = (array) $response->json('CardInfoSearch', []);
            $rows = array_values((array) ($result['CardInfo'] ?? []));
            foreach ($rows as $row) {
                if (is_array($row) && filled($row['cardNo'] ?? null)) {
                    $cards[] = ['card_number' => (string) $row['cardNo'], 'card_type' => (string) ($row['cardType'] ?? 'normalCard')];
                }
            }
            $position += count($rows);
            $total = (int) ($result['totalMatches'] ?? count($cards));
            $more = strtoupper((string) ($result['responseStatusStrg'] ?? '')) === 'MORE' && $position < $total;
        } while ($more);

        return $cards;
    }

    public function setCard(AttendanceDevice $device, string $employeeNumber, string $cardNumber, string $cardType): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->put($this->baseUrl($configuration).'/ISAPI/AccessControl/CardInfo/SetUp?format=json', ['CardInfo' => ['employeeNo' => $employeeNumber, 'cardNo' => $cardNumber, 'cardType' => $cardType]]);
        $response->throw();

        return ['employee_no' => $employeeNumber, 'card_type' => $cardType, 'configured' => true];
    }

    public function cardOwner(AttendanceDevice $device, string $cardNumber): ?string
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/CardInfo/Search?format=json', ['CardInfoSearchCond' => ['searchID' => substr(hash('sha256', $device->id.'|card-owner|'.$cardNumber), 0, 32), 'searchResultPosition' => 0, 'maxResults' => 30, 'CardNoList' => [['cardNo' => $cardNumber]]]]);
        $response->throw();
        $rows = (array) $response->json('CardInfoSearch.CardInfo', []);

        return filled($rows[0]['employeeNo'] ?? null) ? (string) $rows[0]['employeeNo'] : null;
    }

    public function deleteCard(AttendanceDevice $device, string $cardNumber): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->put($this->baseUrl($configuration).'/ISAPI/AccessControl/CardInfo/Delete?format=json', ['CardInfoDelCond' => ['CardNoList' => [['cardNo' => $cardNumber]]]]);
        $response->throw();

        return ['deleted' => true];
    }

    public function setPin(AttendanceDevice $device, string $employeeNumber, string $pin): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $response = $this->client($configuration)->asJson()->put($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Modify?format=json', ['UserInfo' => ['employeeNo' => $employeeNumber, 'password' => $pin]]);
        $response->throw();

        return ['employee_no' => $employeeNumber, 'configured' => true];
    }

    public function credentialCapabilities(AttendanceDevice $device): array
    {
        $configuration = (array) $device->encrypted_configuration;
        $client = $this->client($configuration)->acceptJson();
        $base = $this->baseUrl($configuration);
        $cards = $client->get($base.'/ISAPI/AccessControl/CardInfo/capabilities?format=json');
        $users = $client->get($base.'/ISAPI/AccessControl/UserInfo/capabilities?format=json');

        return ['cards' => $cards->successful() && str_contains((string) $cards->json('CardInfo.supportFunction.@opt', ''), 'put'), 'pin' => $users->successful() && data_get($users->json(), 'UserInfo.password') !== null];
    }

    public function accessCapabilities(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->acceptJson()->get($this->baseUrl($c).'/ISAPI/AccessControl/UserInfo/capabilities?format=json');
        $r->throw();
        $j = (array) $r->json('UserInfo', []);

        return ['door_right' => isset($j['doorRight']), 'right_plan' => isset($j['RightPlan']), 'max_doors' => (int) data_get($j, 'doorRight.@max', 1) ?: 1];
    }

    public function applyAccess(AttendanceDevice $device, string $employeeNumber, ?int $doorNo, ?int $planTemplateNo): array
    {
        $c = (array) $device->encrypted_configuration;
        $grant = $doorNo !== null && $planTemplateNo !== null;
        $user = ['employeeNo' => $employeeNumber, 'doorRight' => $grant ? (string) $doorNo : '', 'RightPlan' => $grant ? [['doorNo' => $doorNo, 'planTemplateNo' => (string) $planTemplateNo]] : []];
        $r = $this->client($c)->asJson()->put($this->baseUrl($c).'/ISAPI/AccessControl/UserInfo/Modify?format=json', ['UserInfo' => $user]);
        $r->throw();

        return ['employee_no' => $employeeNumber, 'granted' => $grant, 'door_no' => $doorNo, 'plan_template_no' => $planTemplateNo];
    }

    public function timeFacts(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->get($this->baseUrl($c).'/ISAPI/System/time');
        $r->throw();
        $x = @simplexml_load_string($r->body());
        if (! $x) {
            throw new RuntimeException('Hikvision returned invalid time configuration XML.');
        }$local = (string) ($x->localTime ?? '');
        $at = $local !== '' ? CarbonImmutable::parse($local, $device->timezone) : null;

        return ['mode' => (string) ($x->timeMode ?? ''), 'local_time' => $at?->toIso8601String(), 'timezone' => (string) ($x->timeZone ?? ''), 'drift_seconds' => $at ? abs(now($device->timezone)->diffInSeconds($at, false)) : null];
    }

    public function setTimeConfiguration(AttendanceDevice $device, string $mode, string $timezone, ?string $ntpHost): array
    {
        $c = (array) $device->encrypted_configuration;
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Time version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><timeMode>'.htmlspecialchars($mode, ENT_XML1).'</timeMode><localTime>'.now($device->timezone)->format(DATE_ATOM).'</localTime><timeZone>'.htmlspecialchars($timezone, ENT_XML1).'</timeZone>'.($ntpHost ? '<ntpServerAddress>'.htmlspecialchars($ntpHost, ENT_XML1).'</ntpServerAddress>' : '').'</Time>';
        $r = $this->client($c)->withBody($xml, 'application/xml')->put($this->baseUrl($c).'/ISAPI/System/time');
        $r->throw();

        return $this->timeFacts($device);
    }

    public function safeSettings(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->acceptJson()->get($this->baseUrl($c).'/ISAPI/AccessControl/AcsCfg?format=json');
        $r->throw();
        $j = (array) $r->json('AcsCfg', []);

        return array_intersect_key($j, array_flip(['showEmployeeNo', 'showName', 'desensitiseEmployeeNo', 'desensitiseName', 'buzzerEnabled']));
    }

    public function setSafeSettings(AttendanceDevice $device, array $settings): array
    {
        $allowed = array_intersect_key($settings, array_flip(['showEmployeeNo', 'showName', 'desensitiseEmployeeNo', 'desensitiseName', 'buzzerEnabled']));
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->asJson()->put($this->baseUrl($c).'/ISAPI/AccessControl/AcsCfg?format=json', ['AcsCfg' => $allowed]);
        $r->throw();

        return $this->safeSettings($device);
    }

    public function capacityFacts(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $client = $this->client($c)->acceptJson();
        $base = $this->baseUrl($c);
        $users = $client->get($base.'/ISAPI/AccessControl/UserInfo/Count?format=json');
        $users->throw();
        $cards = $client->get($base.'/ISAPI/AccessControl/CardInfo/Count?format=json');
        $cards->throw();

        return ['users' => (int) $users->json('UserInfoCount.userNumber', 0), 'user_capacity' => 3000, 'cards' => (int) $cards->json('CardInfoCount.cardNumber', 0), 'card_capacity' => 3000];
    }

    public function maintenanceCapabilities(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->get($this->baseUrl($c).'/ISAPI/System/capabilities');
        $r->throw();
        $body = strtolower($r->body());

        return ['reboot' => preg_match('/<(?:issupport)?reboot[^>]*>\s*(?:true|1|yes)\s*</i', $body) === 1 || str_contains($body, 'reboot')];
    }

    public function reboot(AttendanceDevice $device): array
    {
        $c = (array) $device->encrypted_configuration;
        $r = $this->client($c)->put($this->baseUrl($c).'/ISAPI/System/reboot');
        $r->throw();

        return ['accepted' => true, 'status' => $r->status()];
    }

    private function authenticationMethod(string $mode): ?string
    {
        $mode = strtolower($mode);
        if ($mode === '' || $mode === 'invalid') {
            return null;
        }
        $factors = (int) str_contains($mode, 'fp') + (int) str_contains($mode, 'card') + (int) str_contains($mode, 'face') + (int) str_contains($mode, 'pw');
        if ($factors > 1) {
            return 'multi_factor';
        }
        if (str_contains($mode, 'fp')) {
            return 'fingerprint';
        }
        if (str_contains($mode, 'card')) {
            return 'card';
        }
        if (str_contains($mode, 'face')) {
            return 'face';
        }
        if (str_contains($mode, 'pw')) {
            return 'pin';
        }

        return 'other';
    }

    private function redactedEvent(array $row): array
    {
        return array_intersect_key($row, array_flip(['major', 'minor', 'time', 'doorNo', 'cardReaderNo', 'serialNo', 'employeeNoString', 'employeeNo', 'attendanceStatus', 'currentVerifyMode', 'userType']));
    }

    private function client(array $configuration): PendingRequest
    {
        $username = (string) ($configuration['username'] ?? '');
        $password = (string) ($configuration['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Hikvision ISAPI credentials are not configured.');
        }

        return Http::withOptions(['auth' => [$username, $password, 'digest']])
            ->accept('application/xml')
            ->connectTimeout((int) config('hr.hikvision.connect_timeout_seconds', 3))
            ->timeout((int) config('hr.hikvision.request_timeout_seconds', 10))
            ->retry(2, 250, throw: false);
    }

    private function baseUrl(array $configuration): string
    {
        $host = trim((string) ($configuration['ip_address'] ?? ''));
        $port = (int) ($configuration['port'] ?? 80);
        if (filter_var($host, FILTER_VALIDATE_IP) === false || ! $this->isPrivateAddress($host)) {
            throw new RuntimeException('Direct ISAPI requires a private-network IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Hikvision ISAPI port is invalid.');
        }

        return 'http://'.$host.($port === 80 ? '' : ':'.$port);
    }

    private function isPrivateAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
