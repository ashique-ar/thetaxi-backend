<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Workforce\WorkforceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('returns the original work request on retry and rejects key reuse with changed evidence', function () {
    config(['hr.features.leave_overtime' => true]);
    $user = User::factory()->create();
    $company = Company::create(['name' => 'Work Request Company']);
    $staff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $policyId = (string) Str::uuid();
    DB::table('hr_work_request_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'request_kind' => 'overtime', 'code' => 'OT-1',
        'version' => 1, 'rules' => '{}', 'effective_from' => '2026-01-01', 'status' => 'approved',
        'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $data = [
        'company_id' => $company->id, 'staff_id' => $staff->id, 'policy_id' => $policyId,
        'request_kind' => 'overtime', 'starts_at' => '2026-11-02T09:00:00+05:30',
        'ends_at' => '2026-11-02T10:00:00+05:30', 'rate_category' => null, 'settlement_kind' => 'pay',
        'reason' => 'Support call', 'idempotency_key' => 'work-request-retry-1',
    ];
    $service = app(WorkforceWorkflowService::class);

    $first = $service->submitWorkRequest($data, $user->id);
    $retry = $service->submitWorkRequest($data, $user->id);

    expect($retry->id)->toBe($first->id);
    expect(DB::table('hr_work_request_events')->where('work_request_id', $first->id)->count())->toBe(1);

    try {
        $service->submitWorkRequest([...$data, 'reason' => 'Changed reason'], $user->id);
        $this->fail('Changed evidence must not reuse a work request idempotency key.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }

    try {
        $service->submitWorkRequest($data, User::factory()->create()->id);
        $this->fail('Another actor must not replay this work request.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
});
