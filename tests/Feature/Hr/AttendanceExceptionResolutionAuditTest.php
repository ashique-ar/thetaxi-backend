<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('records one audit event when an installation-wide attendance exception is resolved', function () {
    [$admin] = hr_seed_admin_actor([], true);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $now = now();
    $resultId = (string) Str::uuid();
    $exceptionId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $resultId, 'staff_id' => $staff->id, 'work_date' => '2026-06-01', 'result_version' => 1,
        'day_status' => 'absent', 'worked_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'payable_minutes' => 0,
        'source_kind' => 'calculated', 'calculated_at' => $now, 'input_checksum' => str_repeat('a', 64),
        'result_checksum' => str_repeat('b', 64), 'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_exceptions')->insert([
        'id' => $exceptionId, 'staff_id' => $staff->id, 'daily_result_id' => $resultId,
        'exception_type' => 'absent', 'severity' => 'high', 'status' => 'open', 'evidence' => json_encode(['day_status' => 'absent'], JSON_THROW_ON_ERROR),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $resolution = actingAs($admin, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/exceptions/{$exceptionId}/resolve", ['resolution_note' => 'Reviewed source evidence.'])
        ->assertOk()->assertJsonPath('data.id', $exceptionId)->assertJsonPath('data.status', 'resolved')
        ->assertJsonMissingPath('data.company_id')->assertJsonMissingPath('data.staff_id')
        ->assertJsonMissingPath('data.daily_result_id')->assertJsonMissingPath('data.resolved_by')
        ->assertJsonMissingPath('data.evidence')->assertJsonMissingPath('data.resolution_note');
    expect(array_keys($resolution->json('data')))->toBe(['id', 'status', 'resolved_at']);
    $event = DB::table('activity_log')->where('description', 'attendance_exception_resolved')->first();
    expect($event)->not->toBeNull()
        ->and(json_decode($event->properties, true))->toBe(['status' => 'resolved', 'exception_type' => 'absent'])
        ->and(json_decode($event->properties, true))->not->toHaveKey('resolution_note');

    actingAs($admin, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/exceptions/{$exceptionId}/resolve", ['resolution_note' => 'Reviewed source evidence.'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');
    expect(DB::table('activity_log')->where('description', 'attendance_exception_resolved')->count())->toBe(1);

    actingAs($admin, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/exceptions/{$exceptionId}/resolve", ['resolution_note' => 'Duplicate resolution.'])
        ->assertStatus(409);
    expect(DB::table('activity_log')->where('description', 'attendance_exception_resolved')->count())->toBe(1);

    $otherStaff = Staff::factory()->create();
    $otherResultId = (string) Str::uuid();
    $otherExceptionId = (string) Str::uuid();
    DB::table('hr_attendance_daily_results')->insert([
        'id' => $otherResultId, 'staff_id' => $otherStaff->id, 'work_date' => '2026-06-01', 'result_version' => 1,
        'day_status' => 'absent', 'worked_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'payable_minutes' => 0,
        'source_kind' => 'calculated', 'calculated_at' => $now, 'input_checksum' => str_repeat('c', 64),
        'result_checksum' => str_repeat('d', 64), 'rule_snapshot' => json_encode([], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_attendance_exceptions')->insert([
        'id' => $otherExceptionId, 'staff_id' => $otherStaff->id, 'daily_result_id' => $otherResultId,
        'exception_type' => 'absent', 'severity' => 'high', 'status' => 'open', 'evidence' => json_encode(['day_status' => 'absent'], JSON_THROW_ON_ERROR),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    actingAs($admin, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/exceptions/{$otherExceptionId}/resolve", ['resolution_note' => 'Reviewed installation-wide evidence.'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->assertDatabaseHas('hr_attendance_exceptions', ['id' => $otherExceptionId, 'status' => 'resolved']);
    expect(DB::table('activity_log')->where('description', 'attendance_exception_resolved')->count())->toBe(2);

    $mismatchedExceptionId = (string) Str::uuid();
    DB::table('hr_attendance_exceptions')->insert([
        'id' => $mismatchedExceptionId, 'staff_id' => $staff->id, 'daily_result_id' => $otherResultId,
        'exception_type' => 'absent', 'severity' => 'high', 'status' => 'open', 'evidence' => json_encode(['day_status' => 'absent'], JSON_THROW_ON_ERROR),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $openExceptions = actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/exceptions?status=open')->assertOk();
    expect(collect($openExceptions->json('data.data'))->pluck('id'))->not->toContain($mismatchedExceptionId);
    actingAs($admin, 'api')->withHeaders($headers)->postJson("/api/hr/attendance/exceptions/{$mismatchedExceptionId}/resolve", ['resolution_note' => 'Mismatched evidence attempt.'])
        ->assertUnprocessable();
    $this->assertDatabaseHas('hr_attendance_exceptions', ['id' => $mismatchedExceptionId, 'status' => 'open']);
    expect(DB::table('activity_log')->where('description', 'attendance_exception_resolved')->count())->toBe(2);
});
