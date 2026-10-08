<?php

use App\Models\Company;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrRehireCase;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\PeopleCoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('replays an approved rehire only for its original approver and assignment facts', function () {
    config(['hr.features.people_core' => true]);
    $company = Company::create(['name' => 'Rehire replay company', 'is_active' => true, 'is_default' => true]);
    $preparedBy = User::factory()->create();
    $approver = User::factory()->create();
    $otherApprover = User::factory()->create();
    $staff = Staff::factory()->create([
        'company_id' => $company->id,
        'employment_ended_at' => now()->subDay(),
        'termination_reason' => 'End of employment',
    ]);
    $priorSpell = HrEmploymentSpell::create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'spell_number' => 1,
        'joined_at' => '2020-01-01',
        'service_date' => '2020-01-01',
        'last_working_date' => now()->subDay()->toDateString(),
        'terminated_at' => now()->subDay()->toDateString(),
        'termination_reason' => 'End of employment',
        'status' => 'terminated',
        'gratuity_service_start' => '2020-01-01',
        'created_user_id' => $preparedBy->id,
    ]);
    $case = HrRehireCase::create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'prior_spell_id' => $priorSpell->id,
        'status' => 'pending_approval',
        'proposed_rehire_date' => now()->toDateString(),
        'duplicate_match_snapshot' => [],
        'eligibility_snapshot' => [],
        'prior_service_decisions' => ['gratuity' => 'New service clock', 'leave' => 'New leave balance'],
        'benefit_statutory_review' => [],
        'prepared_by' => $preparedBy->id,
        'idempotency_key' => 'rehire-replay-case',
        'request_payload_checksum' => hash('sha256', 'rehire-replay-case'),
    ]);
    $service = app(PeopleCoreService::class);

    $approved = $service->approveRehire($case, [], (string) $approver->id);
    $auditCount = DB::table('activity_log')->where('subject_type', HrRehireCase::class)->where('subject_id', $approved->id)->count();
    $replayed = $service->approveRehire($approved, [], (string) $approver->id);
    $replayedAuditCount = DB::table('activity_log')->where('subject_type', HrRehireCase::class)->where('subject_id', $approved->id)->count();

    expect($replayed->new_spell_id)->toBe($approved->new_spell_id)
        ->and($replayedAuditCount)->toBe($auditCount)
        ->and(fn () => $service->approveRehire($approved, [], (string) $otherApprover->id))->toThrow(HttpException::class)
        ->and(fn () => $service->approveRehire($approved, ['location_code' => 'different'], (string) $approver->id))->toThrow(HttpException::class);

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);

    expect(fn () => $service->approveRehire($approved, [], (string) $approver->id))->toThrow(HttpException::class)
        ->and(fn () => $service->prepareRehire($staff, [], (string) $approver->id))->toThrow(HttpException::class);
});
