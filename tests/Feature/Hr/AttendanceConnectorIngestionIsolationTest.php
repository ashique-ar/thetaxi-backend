<?php

use App\Models\Hr\Attendance\AttendanceConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('does not replay another connector’s ingestion request status', function () {
    \App\Models\Website\WebsiteSetting::setValue('feature_hr_attendance_ingestion_enabled', 'true');
    $connectorA = AttendanceConnector::factory()->create();
    $connectorB = AttendanceConnector::factory()->create();
    $requestId = (string) Str::uuid();
    $nonce = (string) Str::uuid();
    $signedAt = now()->toIso8601String();
    $body = json_encode(['events' => []], JSON_THROW_ON_ERROR);
    $checksum = hash('sha256', $body);
    DB::table('hr_attendance_ingestion_requests')->insert([
        'id' => (string) Str::uuid(),
        'connector_id' => $connectorB->id,
        'request_id' => $requestId,
        'nonce' => (string) Str::uuid(),
        'signed_at' => $signedAt,
        'received_at' => now(),
        'payload_checksum' => $checksum,
        'event_count' => 0,
        'status' => 'private-connector-state',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->call('POST', '/api/hr/attendance/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CONNECTOR_KEY' => $connectorA->connector_key,
        'HTTP_X_REQUEST_ID' => $requestId,
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_SIGNED_AT' => $signedAt,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $signedAt.'.'.$nonce.'.'.$body, $connectorA->signing_secret),
    ], $body);

    $response->assertStatus(409);
    expect($response->getContent())->not->toContain('private-connector-state');
});

it('rejects signed attendance ingestion through a deleted connector', function () {
    \App\Models\Website\WebsiteSetting::setValue('feature_hr_attendance_ingestion_enabled', 'true');
    $connector = AttendanceConnector::factory()->create();
    DB::table('hr_attendance_connectors')->where('id', $connector->id)->update(['deleted_at' => now()]);
    $requestId = (string) Str::uuid();
    $nonce = (string) Str::uuid();
    $signedAt = now()->toIso8601String();
    $body = json_encode(['events' => []], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/api/hr/attendance/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CONNECTOR_KEY' => $connector->connector_key,
        'HTTP_X_REQUEST_ID' => $requestId,
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_SIGNED_AT' => $signedAt,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', $signedAt.'.'.$nonce.'.'.$body, $connector->signing_secret),
    ], $body);

    $response->assertNotFound();
    $this->assertDatabaseMissing('hr_attendance_ingestion_requests', ['request_id' => $requestId]);
});
