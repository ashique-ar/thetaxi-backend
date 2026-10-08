<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\Hr\Attendance\AttendanceResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('fails closed on attendance correction submission and approval until the field mapping is approved', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_id', $staff->id)->firstOrFail();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $correctionsBefore = DB::table('hr_attendance_correction_requests')->count();

    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/attendance/corrections', [
        'staff_id' => $staff->id,
        'work_date' => '2026-06-01',
        'correction_type' => 'worked_minutes',
        'requested_values' => ['worked_minutes' => 420],
        'reason' => 'Corrected from approved evidence',
        'idempotency_key' => 'attendance-correction-1',
    ])->assertStatus(409)
        ->assertJsonPath('message', 'Attendance correction submission is unavailable until the correction-type-to-field mapping is approved.');
    expect(DB::table('hr_attendance_correction_requests')->count())->toBe($correctionsBefore)
        ->and(DB::table('hr_attendance_correction_requests')->where('idempotency_key', 'attendance-correction-1')->exists())->toBeFalse();

    $correctionId = (string) Str::uuid();
    DB::table('hr_attendance_correction_requests')->insert([
        'id' => $correctionId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'work_date' => '2026-06-01',
        'correction_type' => 'worked_minutes',
        'requested_values' => json_encode(['worked_minutes' => 420], JSON_THROW_ON_ERROR),
        'reason' => 'Legacy pending correction',
        'status' => 'pending_approval',
        'idempotency_key' => 'legacy-attendance-correction-1',
        'request_checksum' => str_repeat('a', 64),
        'requested_by' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $foreignCorrectionId = (string) Str::uuid();
    $otherCompany = Company::create(['name' => 'Other Correction Company']);
    DB::table('hr_attendance_correction_requests')->insert([
        'id' => $foreignCorrectionId,
        'company_id' => $otherCompany->id,
        'staff_id' => $staff->id,
        'work_date' => '2026-06-02',
        'correction_type' => 'worked_minutes',
        'requested_values' => json_encode(['worked_minutes' => 480], JSON_THROW_ON_ERROR),
        'reason' => 'Mismatched historical entity.',
        'status' => 'pending_approval',
        'idempotency_key' => 'foreign-attendance-correction-1',
        'request_checksum' => str_repeat('b', 64),
        'requested_by' => $admin->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $correctionList = actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/attendance/corrections?company_id='.$company->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.is_requester', true)
        ->assertJsonMissingPath('data.data.0.requested_values')
        ->assertJsonMissingPath('data.data.0.staff_id')
        ->assertJsonMissingPath('data.data.0.requested_by')
        ->assertJsonMissingPath('data.data.0.decided_by');
    expect(collect($correctionList->json('data.data'))->pluck('id')->all())->toContain($correctionId)
        ->not->toContain($foreignCorrectionId);

    expect(fn () => app(AttendanceResultService::class)->approveCorrection(
        $correctionId,
        User::factory()->create()->id,
        'Review correction.',
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->assertDatabaseHas('hr_attendance_correction_requests', [
        'id' => $correctionId,
        'status' => 'pending_approval',
    ]);

    $rejector = User::factory()->create();
    $rejector->assignRole('admin');
    $reviewerStaff = Staff::factory()->create([
        'user_id' => $rejector->id,
        'company_id' => $company->id,
        'staff_type' => 'admin',
    ]);
    $rejectorContext = UserContext::create([
        'user_id' => $rejector->id,
        'context_type' => 'staff',
        'context_id' => $reviewerStaff->id,
        'is_active' => true,
        'created_user_id' => $rejector->id,
    ]);
    $rejectorHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $rejectorContext->id];
    $decisionNote = 'Reject pending request while mapping is unresolved.';
    $rejectPath = '/api/hr/attendance/corrections/'.$correctionId.'/reject';
    $rejectedResponse = actingAs($rejector, 'api')->withHeaders($rejectorHeaders)->postJson($rejectPath, ['decision_note' => $decisionNote])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonMissingPath('data.requested_values')->assertJsonMissingPath('data.decision_note');
    $eventsAfterRejection = DB::table('activity_log')->where('description', 'attendance_correction_rejected')->count();
    $rejectionAudit = DB::table('activity_log')->where('description', 'attendance_correction_rejected')->latest('id')->value('properties');
    expect(json_decode((string) $rejectionAudit, true, 512, JSON_THROW_ON_ERROR))->toBe([
        'correction_id' => $correctionId,
        'company_id' => $company->id,
        'status' => 'rejected',
    ]);
    $replayResponse = actingAs($rejector, 'api')->withHeaders($rejectorHeaders)->postJson($rejectPath, ['decision_note' => $decisionNote])->assertOk();
    expect($replayResponse->json('data.decided_at'))->toBe($rejectedResponse->json('data.decided_at'))
        ->and(DB::table('activity_log')->where('description', 'attendance_correction_rejected')->count())->toBe($eventsAfterRejection);
    expect(fn () => app(AttendanceResultService::class)->rejectCorrection($correctionId, (string) $company->id, (string) User::factory()->create()->id, $decisionNote))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(AttendanceResultService::class)->rejectCorrection($correctionId, (string) $company->id, (string) $rejector->id, 'Changed decision note.'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(AttendanceResultService::class)->rejectCorrection($correctionId, (string) $otherCompany->id, (string) $rejector->id, $decisionNote))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    actingAs($rejector, 'api')->withHeaders($rejectorHeaders)->postJson('/api/hr/attendance/corrections/'.$foreignCorrectionId.'/reject', ['decision_note' => $decisionNote])
        ->assertNotFound();
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    expect(fn () => app(AttendanceResultService::class)->rejectCorrection($correctionId, (string) $company->id, (string) $rejector->id, $decisionNote))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->assertDatabaseHas('hr_attendance_correction_requests', [
        'id' => $correctionId,
        'status' => 'rejected',
    ]);
});
