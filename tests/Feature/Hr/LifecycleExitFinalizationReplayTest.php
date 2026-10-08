<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Ess\HrRequestIndexService;
use App\Services\Hr\Lifecycle\LifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('finalizes an exit once and replays only for the recorded actor after complete clearances', function () {
    config(['hr.features.employee_self_service' => true]);
    $company = Company::create(['name' => 'Exit finalization company', 'is_active' => true, 'is_default' => true]);
    $initiator = User::factory()->create();
    $approver = User::factory()->create();
    $otherApprover = User::factory()->create();
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $exitId = (string) Str::uuid();
    DB::table('hr_exit_cases')->insert([
        'id' => $exitId,
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'exit_type' => 'resignation',
        'proposed_last_working_date' => today()->toDateString(),
        'approved_last_working_date' => today()->toDateString(),
        'reason_code' => 'resignation',
        'status' => 'clearance',
        'impact_snapshot' => '{}',
        'opened_by' => $initiator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(HrRequestIndexService::class)->register(
        (string) $company->id,
        (string) $staff->id,
        'exit',
        'exit_case',
        $exitId,
        'clearance',
        'Employment exit request',
        null,
        48,
        (string) $approver->id,
    );
    $service = app(LifecycleService::class);

    expect(fn () => $service->finalizeExit($exitId, $approver, (string) $company->id))->toThrow(HttpException::class);
    foreach (['handover', 'attendance', 'payroll', 'access'] as $type) {
        DB::table('hr_exit_clearance_items')->insert([
            'id' => (string) Str::uuid(),
            'exit_case_id' => $exitId,
            'clearance_type' => $type,
            'title' => ucfirst($type),
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $result = $service->finalizeExit($exitId, $approver, (string) $company->id);
    $terminationEventCount = DB::table('staff_context_termination_events')->count();
    $requestEventCount = DB::table('hr_request_events')->count();
    $replay = $service->finalizeExit($exitId, $approver, (string) $company->id);

    expect($replay)->toBe($result)
        ->and($terminationEventCount)->toBe(1)
        ->and(DB::table('staff_context_termination_events')->count())->toBe($terminationEventCount)
        ->and(DB::table('hr_request_index')->where('source_id', $exitId)->value('status'))->toBe('completed')
        ->and(DB::table('hr_request_events')->count())->toBe($requestEventCount)
        ->and(fn () => $service->finalizeExit($exitId, $otherApprover, (string) $company->id))->toThrow(HttpException::class);

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    expect(fn () => $service->finalizeExit($exitId, $approver, (string) $company->id))->toThrow(HttpException::class);
    expect(fn () => app(\App\Services\StaffIdentityService::class)->terminate($staff, $approver, 'direct termination', (string) Str::uuid()))
        ->toThrow(HttpException::class);
});
