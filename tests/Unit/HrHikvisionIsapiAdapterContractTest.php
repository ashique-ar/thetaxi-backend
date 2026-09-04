<?php

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\HikvisionIsapiAdapter;
use Illuminate\Support\Facades\Http;

it('discovers a direct ISAPI terminal with digest auth without exposing credentials', function () {
    Http::fake(['10.0.0.2/*' => Http::response('<?xml version="1.0"?><DeviceInfo><model>DS-K1T808MFWX-B</model><serialNumber>SERIAL-1</serialNumber><firmwareVersion>V3.25.20</firmwareVersion><firmwareReleasedDate>build 241227</firmwareReleasedDate><manufacturer>hikvision</manufacturer><deviceType>ACS</deviceType></DeviceInfo>')]);
    $device = new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','encrypted_configuration'=>['ip_address'=>'10.0.0.2','port'=>80,'username'=>'admin','password'=>'secret']]);
    $facts = app(HikvisionIsapiAdapter::class)->discover($device);
    expect($facts['serial_number'])->toBe('SERIAL-1')->and($facts['capabilities']['access_control_events'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'http://10.0.0.2/ISAPI/System/deviceInfo');
});

it('rejects public addresses to prevent the device admin surface becoming an SSRF proxy', function () {
    $device = new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','encrypted_configuration'=>['ip_address'=>'8.8.8.8','username'=>'admin','password'=>'secret']]);
    expect(fn () => app(HikvisionIsapiAdapter::class)->discover($device))->toThrow(RuntimeException::class, 'private-network');
});

it('maps the proven AcsEvent pagination contract without retaining names or pictures', function () {
    Http::fake(['10.0.0.2/*' => Http::response(['AcsEvent'=>['totalMatches'=>31,'responseStatusStrg'=>'MORE','numOfMatches'=>1,'InfoList'=>[['major'=>5,'minor'=>38,'time'=>'2026-08-31T08:15:00+05:30','name'=>'PRIVATE NAME','employeeNoString'=>'34','serialNo'=>17731,'currentVerifyMode'=>'cardOrFpOrPw','pictureURL'=>'/sensitive.jpg']]]])]);
    $device = new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','timezone'=>'Asia/Colombo','encrypted_configuration'=>['ip_address'=>'10.0.0.2','username'=>'admin','password'=>'secret']]);$device->id='device-1';
    $page=app(HikvisionIsapiAdapter::class)->attendanceEvents($device,now()->subDay(),now(),0,30);$event=$page['events'][0];
    expect($page['has_more'])->toBeTrue()->and($page['next_position'])->toBe(1)->and($event['provider_event_id'])->toBe('17731')->and($event['provider_person_id'])->toBe('34')->and($event['authentication_method'])->toBe('multi_factor')->and($event['vendor'])->not->toHaveKey('name')->not->toHaveKey('pictureURL');
    Http::assertSent(fn($request)=>$request->method()==='POST'&&$request->data()['AcsEventCond']['maxResults']===30);
});

it('caps event pages at the terminal proven maximum of thirty', function () {
    Http::fake(['10.0.0.2/*'=>Http::response(['AcsEvent'=>['totalMatches'=>0,'responseStatusStrg'=>'OK','InfoList'=>[]]])]);
    $device=new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','timezone'=>'Asia/Colombo','encrypted_configuration'=>['ip_address'=>'10.0.0.2','username'=>'admin','password'=>'secret']]);$device->id='device-1';
    app(HikvisionIsapiAdapter::class)->attendanceEvents($device,now()->subDay(),now(),0,500);
    Http::assertSent(fn($request)=>$request->data()['AcsEventCond']['maxResults']===30);
});

it('retrieves only the minimum device-person directory fields and enrollment counts', function () {
    Http::fake(['10.0.0.2/*'=>Http::response(['UserInfoSearch'=>['totalMatches'=>1,'responseStatusStrg'=>'OK','UserInfo'=>[['employeeNo'=>'5','name'=>'SUGI','password'=>'must-not-return','doorRight'=>'1','numOfFP'=>1,'numOfCard'=>0,'numOfFace'=>0,'Valid'=>['enable'=>true,'beginTime'=>'2026-01-01T00:00:00','endTime'=>'2036-01-01T00:00:00']]]]])]);
    $device=new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','timezone'=>'Asia/Colombo','encrypted_configuration'=>['ip_address'=>'10.0.0.2','username'=>'admin','password'=>'secret']]);$device->id='device-1';
    $page=app(HikvisionIsapiAdapter::class)->people($device);
    expect($page['people'][0])->toMatchArray(['employee_no'=>'5','display_name'=>'SUGI','enabled'=>true,'enrollment'=>['fingerprints'=>1,'cards'=>0,'faces'=>0]])->not->toHaveKey('password')->not->toHaveKey('doorRight');
});

it('provisions a Staff identity through ISAPI without requiring the Hikvision portal', function () {
    Http::fake(['10.0.0.2/*' => Http::sequence()
        ->push(['UserInfoSearch'=>['totalMatches'=>0,'responseStatusStrg'=>'OK','UserInfo'=>[]]])
        ->push(['statusCode'=>1,'statusString'=>'OK'])]);
    $device=new AttendanceDevice(['provider'=>'hikvision','integration_mode'=>'direct_isapi','timezone'=>'Asia/Colombo','encrypted_configuration'=>['ip_address'=>'10.0.0.2','username'=>'admin','password'=>'secret']]);$device->id='device-1';
    $person=app(HikvisionIsapiAdapter::class)->provisionPerson($device,'EMP-005','Sugi Perera');
    expect($person)->toMatchArray(['employee_no'=>'EMP-005','display_name'=>'Sugi Perera','created'=>true]);
    Http::assertSent(fn($request)=>str_contains($request->url(),'/ISAPI/AccessControl/UserInfo/Record')&&$request->data()['UserInfo']['employeeNo']==='EMP-005'&&$request->data()['UserInfo']['name']==='Sugi Perera');
});
