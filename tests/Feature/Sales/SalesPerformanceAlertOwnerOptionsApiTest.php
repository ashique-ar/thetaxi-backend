<?php

use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesAlertPolicyVersion;
use App\Services\Sales\SalesPerformanceService;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates active Staff alert owners inside the authorized company', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Alert Owner Company']);
    $profileStaff = Staff::factory()->create(['company_id' => $company->id]);
    SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $profileStaff->id,
        'sales_code' => 'ALERT-001', 'status' => 'active', 'effective_from' => now()->subDay(),
    ]);
    $owner = Staff::factory()->create(['company_id' => $company->id, 'code' => 'OWNER-001']);
    UserContext::create(['user_id' => $owner->user_id, 'context_type' => 'staff', 'context_id' => $owner->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $inactiveOwner = Staff::factory()->create(['company_id' => $company->id, 'code' => 'OWNER-INACTIVE']);
    $inactiveOwner->user->update(['is_active' => false]);
    UserContext::create(['user_id' => $inactiveOwner->user_id, 'context_type' => 'staff', 'context_id' => $inactiveOwner->id,
        'is_active' => true, 'created_user_id' => $admin->id]);
    $contextlessOwner = Staff::factory()->create(['company_id' => $company->id, 'code' => 'OWNER-CONTEXTLESS']);
    $foreignOwner = Staff::factory()->create(['code' => 'FOREIGN-001']);
    $url = '/api/sales/performance/alert-owner-options?company_id='.$company->id;

    $response = actingAs($admin, 'api')->getJson($url.'&search=OWNER-001&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $owner->user_id)
        ->assertJsonPath('data.data.0.metadata.staff_code', 'OWNER-001');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$owner->user_id.'&search=no-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $owner->user_id);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$foreignOwner->user_id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$inactiveOwner->user_id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$contextlessOwner->user_id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

it('refuses to save an alert policy for a deactivated owner', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $owner = Staff::factory()->create(['company_id' => $company->id]);
    $owner->user->update(['is_active' => false]);
    $rules = [
        'no_new_sales' => ['enabled' => true, 'severity' => 'low'],
        'no_sales_activity' => ['enabled' => true, 'severity' => 'low'],
        'overdue_collections' => ['enabled' => true, 'severity' => 'low', 'minimum_amount_lkr' => 0, 'minimum_age_days' => 1],
        'overdue_tasks' => ['enabled' => true, 'severity' => 'low', 'minimum_count' => 1, 'minimum_age_days' => 1,
            'status_basis' => 'open_or_in_progress_at_period_end', 'owner_basis' => 'owner_at_period_end'],
        'repeatedly_missed_next_actions' => ['enabled' => true, 'severity' => 'low', 'minimum_count' => 2,
            'lookback_completed_months' => 1, 'deadline_basis' => 'open_or_in_progress_at_due',
            'owner_basis' => 'owner_at_due', 'source' => 'governed_sales_task_event_history'],
        'recurring_commission_reliance' => ['enabled' => true, 'severity' => 'low',
            'prior_booking_commission_percent' => 0, 'new_sales_achievement_below_percent' => 0],
        'decline_against_completed_month_average' => ['enabled' => true, 'severity' => 'low', 'percent' => 0, 'baseline_months' => 1],
        'evaluation' => ['grain' => 'closed_calendar_month', 'schedule' => ['frequency' => 'once_after_close', 'local_time' => '09:00'],
            'grace_days' => 0, 'minimum_elapsed_days' => 1, 'baseline_completeness' => 'require_complete',
            'missing_data_behavior' => 'suppress_rule_and_flag', 'comparison_normalization' => 'completed_calendar_months_only',
            'recurring_reliance_basis' => 'prior_period_booking_commission', 'timezone_source' => 'sales_business_timezone',
            'owner_user_id' => $owner->user_id],
    ];

    expect(fn () => app(SalesPerformanceService::class)->storeAlertPolicy([
        'company_id' => $company->id, 'rules' => $rules, 'effective_from' => now()->toDateString(), 'effective_until' => null,
    ], (string) $admin->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SalesAlertPolicyVersion::query()->where('company_id', $company->id)->exists())->toBeFalse();
});
