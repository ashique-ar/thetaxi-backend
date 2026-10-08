<?php

use App\Models\Company;
use App\Models\Hr\HrEmployeeRecord;
use App\Models\Hr\HrEmployeeNumberAlias;
use App\Models\Hr\HrEmployeeTimelineEvent;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrRehireCase;
use App\Models\Hr\HrReportingLine;
use App\Models\Hr\HrPeopleExport;
use App\Models\Hr\HrPeopleImportJob;
use App\Models\Hr\HrStaffProfileVersion;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\PeopleCoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps encrypted employee records and profile details out of activity logs', function () {
    config(['hr.features.people_core' => true]);
    $actor = User::factory()->create();
    $company = Company::create(['name' => 'HR audit privacy company', 'is_active' => true, 'is_default' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    actingAs($actor, 'api');

    $payloadMarker = 'private-employee-record-payload';
    $reasonMarker = 'private-profile-change-reason';
    $record = app(PeopleCoreService::class)->addEmployeeRecord($staff, [
        'record_type' => 'identity_document',
        'title' => $payloadMarker,
        'encrypted_data' => ['number' => $payloadMarker],
        'source' => 'staff_self_service',
    ], $actor->id);
    $profile = app(PeopleCoreService::class)->addProfileVersion($staff, ['address' => $payloadMarker], $reasonMarker, $actor->id);
    DB::table('hr_employee_records')->where('id', $record->id)->update(['verified_by' => $actor->id]);
    $record->refresh();
    $record->makeVisible('encrypted_data');
    $profile->makeVisible('encrypted_profile');
    expect($record->toArray())->toHaveKey('encrypted_data')->not->toHaveKey('verified_by')
        ->and($profile->toArray())->toHaveKey('encrypted_profile')
        ->not->toHaveKey('profile_checksum')
        ->not->toHaveKey('change_reason')
        ->not->toHaveKey('changed_by');

    $aliasNumber = 'private-employee-number-alias';
    $alias = HrEmployeeNumberAlias::create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'employee_number' => $aliasNumber,
        'alias_type' => 'manual_canonical',
        'is_canonical' => true,
        'effective_from' => now()->toDateString(),
        'reason' => $reasonMarker,
        'approved_by' => $actor->id,
    ]);
    expect(fn () => $alias->update(['employee_number' => 'reassigned-number']))
        ->toThrow(LogicException::class, 'Employee-number aliases are immutable history.');
    expect(fn () => $alias->delete())
        ->toThrow(LogicException::class, 'This record cannot be deleted.');
    $this->assertDatabaseHas('hr_employee_number_aliases', [
        'id' => $alias->id,
        'employee_number' => $aliasNumber,
        'is_canonical' => true,
    ]);

    $import = HrPeopleImportJob::create([
        'company_id' => $company->id,
        'mode' => 'preview',
        'status' => 'ready',
        'original_file_name' => 'private-import-file.csv',
        'disk' => 'hr_private',
        'path' => 'private/import/path',
        'file_checksum' => hash('sha256', $payloadMarker),
        'mapping_version' => '1',
        'row_count' => 1,
        'accepted_count' => 1,
        'rejected_count' => 0,
        'reconciliation_totals' => ['private' => $payloadMarker],
        'request_checksum' => hash('sha256', $reasonMarker),
        'idempotency_key' => 'private-import-idempotency-key',
        'created_by' => $actor->id,
    ]);
    $export = HrPeopleExport::create([
        'company_id' => $company->id,
        'disk' => 'hr_private',
        'path' => 'private/export/path',
        'file_name' => 'private-export-file.csv',
        'file_checksum' => hash('sha256', $reasonMarker),
        'file_size' => 12,
        'row_count' => 1,
        'scope_checksum' => hash('sha256', $payloadMarker),
        'idempotency_key' => 'private-export-idempotency-key',
        'generated_by' => $actor->id,
        'generated_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $recordProperties = DB::table('activity_log')->where('subject_type', HrEmployeeRecord::class)->where('subject_id', $record->id)->value('properties');
    $profileProperties = DB::table('activity_log')->where('subject_type', HrStaffProfileVersion::class)->where('subject_id', $profile->id)->value('properties');
    $aliasProperties = DB::table('activity_log')->where('subject_type', HrEmployeeNumberAlias::class)->where('subject_id', $alias->id)->value('properties');
    $importProperties = DB::table('activity_log')->where('subject_type', HrPeopleImportJob::class)->where('subject_id', $import->id)->value('properties');
    $exportProperties = DB::table('activity_log')->where('subject_type', HrPeopleExport::class)->where('subject_id', $export->id)->value('properties');
    $audit = $recordProperties.' '.$profileProperties.' '.$aliasProperties.' '.$importProperties.' '.$exportProperties;

    expect($recordProperties)->not->toBeNull()
        ->and($profileProperties)->not->toBeNull()
        ->and($audit)->toContain('identity_document')
        ->and($audit)->toContain('staff_self_service')
        ->and($audit)->not->toContain($payloadMarker)
        ->and($audit)->not->toContain($reasonMarker)
        ->and($audit)->not->toContain((string) $staff->id)
        ->and($audit)->not->toContain((string) $actor->id)
        ->and($audit)->not->toContain($aliasNumber)
        ->and($aliasProperties)->toContain('manual_canonical')
        ->and($importProperties)->toContain('ready')
        ->and($exportProperties)->not->toContain('private/export/path')
        ->and($audit)->not->toContain('private/import/path')
        ->and($audit)->not->toContain('private-import-file.csv')
        ->and($audit)->not->toContain('private-export-file.csv')
        ->and($audit)->not->toContain('private-import-idempotency-key')
        ->and($audit)->not->toContain('private-export-idempotency-key');
});

it('keeps HR history snapshots out of activity logs', function () {
    $actor = User::factory()->create();
    $company = Company::create(['name' => 'HR history audit company', 'is_active' => true, 'is_default' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $privateMarker = 'private-hr-history-snapshot';
    $spell = HrEmploymentSpell::create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'spell_number' => 1,
        'joined_at' => '2025-01-01',
        'service_date' => '2025-01-01',
        'gratuity_service_start' => '2025-01-01',
        'termination_reason' => $privateMarker,
        'gratuity_service_decision' => $privateMarker,
        'prior_service_decisions' => ['private' => $privateMarker],
        'created_user_id' => $actor->id,
    ]);
    $manager = Staff::factory()->create(['company_id' => $company->id]);
    $reportingLine = HrReportingLine::create([
        'company_id' => $company->id,
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $staff->id,
        'line_type' => 'primary',
        'effective_from' => '2025-01-01',
        'reason' => $privateMarker,
        'idempotency_key' => 'private-reporting-line-key',
        'created_user_id' => $actor->id,
    ]);
    $assignment = HrEmploymentAssignment::create([
        'employment_spell_id' => $spell->id,
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'assignment_type' => 'primary',
        'effective_from' => '2025-01-01',
        'change_reason' => $privateMarker,
        'snapshot' => ['private' => $privateMarker],
        'created_user_id' => $actor->id,
        'updated_user_id' => $actor->id,
        'approved_by' => $actor->id,
    ]);
    $spell->refresh();
    $assignment->refresh();
    expect($spell->toArray())->not->toHaveKey('created_user_id')
        ->not->toHaveKey('termination_reason')
        ->not->toHaveKey('gratuity_service_decision')
        ->not->toHaveKey('prior_service_decisions')
        ->and($assignment->toArray())->not->toHaveKey('created_user_id')
        ->not->toHaveKey('updated_user_id')
        ->not->toHaveKey('approved_by')
        ->not->toHaveKey('change_reason')
        ->not->toHaveKey('snapshot');
    $event = HrEmployeeTimelineEvent::create([
        'staff_id' => $staff->id,
        'employment_spell_id' => $spell->id,
        'domain' => 'people',
        'event_type' => 'profile_changed',
        'source_type' => 'profile',
        'source_id' => $spell->id,
        'title' => $privateMarker,
        'safe_summary' => ['private' => $privateMarker],
        'confidentiality' => 'hr_private',
        'effective_at' => now(),
        'recorded_at' => now(),
        'idempotency_key' => 'private-timeline-event-key',
    ]);
    expect($event->toArray())->not->toHaveKey('source_id')
        ->not->toHaveKey('safe_summary')
        ->not->toHaveKey('idempotency_key');
    $case = HrRehireCase::create([
        'staff_id' => $staff->id,
        'company_id' => $company->id,
        'prior_spell_id' => $spell->id,
        'status' => 'pending_approval',
        'proposed_rehire_date' => '2026-01-01',
        'duplicate_match_snapshot' => ['staff_id' => $staff->id, 'private' => $privateMarker],
        'eligibility_snapshot' => ['private' => $privateMarker],
        'prior_service_decisions' => ['private' => $privateMarker],
        'access_reactivation_plan' => ['private' => $privateMarker],
        'benefit_statutory_review' => ['private' => $privateMarker],
        'prepared_by' => $actor->id,
        'idempotency_key' => 'private-rehire-idempotency-key',
        'request_payload_checksum' => hash('sha256', $privateMarker),
    ]);

    $spellAudit = DB::table('activity_log')->where('subject_type', HrEmploymentSpell::class)->where('subject_id', $spell->id)->value('properties');
    $reportingAudit = DB::table('activity_log')->where('subject_type', HrReportingLine::class)->where('subject_id', $reportingLine->id)->value('properties');
    $assignmentAudit = DB::table('activity_log')->where('subject_type', HrEmploymentAssignment::class)->where('subject_id', $assignment->id)->value('properties');
    $timelineAudit = DB::table('activity_log')->where('subject_type', HrEmployeeTimelineEvent::class)->where('subject_id', $event->id)->value('properties');
    $rehireAudit = DB::table('activity_log')->where('subject_type', HrRehireCase::class)->where('subject_id', $case->id)->value('properties');

    expect($reportingAudit)->not->toBeNull()
        ->and($reportingAudit)->toContain((string) $company->id)
        ->and($reportingAudit)->toContain('primary')
        ->and($reportingAudit)->not->toContain((string) $manager->id)
        ->and($reportingAudit)->not->toContain((string) $staff->id)
        ->and($reportingAudit)->not->toContain($privateMarker)
        ->and($reportingAudit)->not->toContain('private-reporting-line-key')
        ->and($spellAudit)->not->toBeNull()
        ->and($spellAudit)->toContain((string) $company->id)
        ->and($spellAudit)->toContain('active')
        ->and($spellAudit)->not->toContain((string) $staff->id)
        ->and($assignmentAudit)->not->toBeNull()
        ->and($assignmentAudit)->toContain('primary')
        ->and($assignmentAudit)->not->toContain((string) $staff->id)
        ->and($assignmentAudit)->not->toContain($privateMarker)
        ->and($timelineAudit)->not->toBeNull()
        ->and($timelineAudit)->toContain('profile_changed')
        ->and($timelineAudit)->not->toContain((string) $staff->id)
        ->and($timelineAudit)->not->toContain((string) $spell->id)
        ->and($timelineAudit)->not->toContain($privateMarker)
        ->and($timelineAudit)->not->toContain('private-timeline-event-key')
        ->and($rehireAudit)->not->toBeNull()
        ->and($rehireAudit)->toContain((string) $company->id)
        ->and($rehireAudit)->toContain('pending_approval')
        ->and($rehireAudit)->not->toContain((string) $staff->id)
        ->and($rehireAudit)->not->toContain((string) $actor->id)
        ->and($rehireAudit)->not->toContain($privateMarker)
        ->and($rehireAudit)->not->toContain('private-rehire-idempotency-key')
        ->and($rehireAudit)->not->toContain(hash('sha256', $privateMarker));
});

it('refuses to add an employee record to an inactive company', function () {
    config(['hr.features.people_core' => true]);
    $company = Company::create(['name' => 'Inactive HR records company', 'is_active' => false, 'is_default' => false]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);

    expect(fn () => app(PeopleCoreService::class)->addEmployeeRecord($staff, [
        'record_type' => 'qualification',
        'title' => 'Qualification record',
        'encrypted_data' => ['value' => 'private'],
        'confidentiality' => 'hr_private',
        'source' => 'hr_entry',
    ], (string) $staff->user_id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->assertDatabaseMissing('hr_employee_records', ['staff_id' => $staff->id]);
});
