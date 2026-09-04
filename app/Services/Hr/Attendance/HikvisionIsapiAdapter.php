<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\Attendance\AttendanceProviderAdapter;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Carbon\CarbonImmutable;

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
                'direction' => match ($attendanceStatus) { 'checkIn', 'breakIn', 'overtimeIn' => 'in', 'checkOut', 'breakOut', 'overtimeOut' => 'out', default => null },
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
        $configuration=(array)$device->encrypted_configuration;$position=max(0,$position);$limit=max(1,min(30,$limit));
        $response=$this->client($configuration)->asJson()->post($this->baseUrl($configuration).'/ISAPI/AccessControl/UserInfo/Search?format=json',['UserInfoSearchCond'=>['searchID'=>substr(hash('sha256',$device->id.'|people'),0,32),'searchResultPosition'=>$position,'maxResults'=>$limit]]);$response->throw();
        $result=(array)$response->json('UserInfoSearch',[]);$rows=array_values((array)($result['UserInfo']??[]));$people=[];
        foreach($rows as$row){if(!is_array($row)||empty($row['employeeNo']))continue;$valid=(array)($row['Valid']??[]);$people[]=['employee_no'=>(string)$row['employeeNo'],'display_name'=>trim((string)($row['name']??'')),'enabled'=>(bool)($valid['enable']??true),'valid_from'=>$valid['beginTime']??null,'valid_until'=>$valid['endTime']??null,'enrollment'=>['fingerprints'=>(int)($row['numOfFP']??0),'cards'=>(int)($row['numOfCard']??0),'faces'=>(int)($row['numOfFace']??0)]];}
        $total=(int)($result['totalMatches']??count($people));$next=$position+count($rows);
        return ['people'=>$people,'total_matches'=>$total,'has_more'=>strtoupper((string)($result['responseStatusStrg']??''))==='MORE'&&$next<$total,'next_position'=>$next];
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

    private function authenticationMethod(string $mode): ?string
    {
        $mode = strtolower($mode);
        if ($mode === '' || $mode === 'invalid') return null;
        $factors = (int) str_contains($mode, 'fp') + (int) str_contains($mode, 'card') + (int) str_contains($mode, 'face') + (int) str_contains($mode, 'pw');
        if ($factors > 1) return 'multi_factor';
        if (str_contains($mode, 'fp')) return 'fingerprint';
        if (str_contains($mode, 'card')) return 'card';
        if (str_contains($mode, 'face')) return 'face';
        if (str_contains($mode, 'pw')) return 'pin';
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
