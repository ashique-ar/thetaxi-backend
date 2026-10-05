<?php

use App\Models\Sales\SalesPortfolioStatusPolicyVersion;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only policy fields the Sales administration screen needs', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Portfolio Policy Privacy Company']);
    $admin->givePermissionTo([
        'sales.performance.view', 'sales.performance.view-all',
        'sales.performance.portfolio-status-policies.approve',
    ]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    SalesProfile::query()->create([
        'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'PORTFOLIO-PRIVACY-01', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $policy = SalesPortfolioStatusPolicyVersion::query()->create([
        'company_id' => $company->id, 'version' => 1, 'active_booking_statuses' => ['confirmed'],
        'effective_from' => today()->toDateString(), 'effective_until' => null, 'status' => 'draft',
        'reason' => 'Contracted confirmed bookings are reportable.', 'idempotency_key' => (string) Str::uuid(),
        'request_checksum' => str_repeat('a', 64), 'prepared_by' => $admin->id,
    ]);

    $contextPolicy = actingAs($admin, 'api')->getJson('/api/sales/performance/administration-context')
        ->assertOk()->json('data.portfolio_status_policies.0');
    $listPolicy = actingAs($admin, 'api')->getJson('/api/sales/performance/portfolio-status-policies?company_id='.$company->id)
        ->assertOk()->json('data.data.0');

    foreach ([$contextPolicy, $listPolicy] as $row) {
        expect(array_keys($row))->toBe(['id', 'company_id', 'version', 'active_booking_statuses', 'effective_from', 'effective_until', 'status', 'reason']);
        expect($row)->toMatchArray(['id' => $policy->id, 'reason' => 'Contracted confirmed bookings are reportable.']);
    }
});
