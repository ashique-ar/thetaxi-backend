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

it('replays exit approval for its recorded approver without recreating clearance evidence', function () {
    config(['hr.features.employee_self_service' => true]);
    $company = Company::create(['name' => 'Exit approval replay company', 'is_active' => true, 'is_default' => true]);
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
        'reason_code' => 'resignation',
        'status' => 'pending_approval',
        'impact_snapshot' => '{}',
        'opened_by' => $initiator->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(HrRequestIndexService::class)->register(
        (string) $company->id,
        (string) $staff->id,
        'exit',
        'exit_case',
        $exitId,
        'pending_approval',
        'Employment exit request',
        null,
        48,
        (string) $initiator->id,
    );
    $service = app(LifecycleService::class);

    $approved = $service->approveExit($exitId, (string) $approver->id, (string) $company->id);
    $clearanceCount = DB::table('hr_exit_clearance_items')->where('exit_case_id', $exitId)->count();
    $requestEventCount = DB::table('hr_request_events')->count();
    $replayed = $service->approveExit($exitId, (string) $approver->id, (string) $company->id);

    expect($replayed->status)->toBe($approved->status)
        ->and(isset($approved->impact_snapshot))->toBeFalse()
        ->and(DB::table('hr_request_index')->where('source_id', $exitId)->value('status'))->toBe('clearance')
        ->and($clearanceCount)->toBe(4)
        ->and(DB::table('hr_exit_clearance_items')->where('exit_case_id', $exitId)->count())->toBe($clearanceCount)
        ->and(DB::table('hr_request_events')->count())->toBe($requestEventCount)
        ->and(fn () => $service->approveExit($exitId, (string) $otherApprover->id, (string) $company->id))
        ->toThrow(HttpException::class);

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    expect(fn () => $service->approveExit($exitId, (string) $approver->id, (string) $company->id))
        ->toThrow(HttpException::class);
});
