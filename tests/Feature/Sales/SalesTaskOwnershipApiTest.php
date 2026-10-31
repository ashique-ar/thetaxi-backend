<?php

use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Sales\SalesFeatureSetting;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTask;
use App\Models\Sales\SalesTaskEvent;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('validates task links and makes create, transition, and transfer commands replay-safe', function () {
    [$actor] = hr_seed_admin_actor();
    $actor->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.view-all', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $actor->givePermissionTo(Permission::findByName('sales.crm.manage-all', 'api'));
    config()->set('sales.features.crm', true);
    SalesFeatureSetting::query()->create([
        'feature_key' => 'crm', 'version' => 1,
        'enabled' => true, 'status' => 'approved', 'reason' => 'Feature test setup',
        'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
    ]);

    $makeProfile = function (string $code) use ($actor): SalesProfile {
        $staff = Staff::factory()->create();
        UserContext::create([
            'user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
            'is_active' => true, 'created_user_id' => $actor->id,
        ]);
        return SalesProfile::query()->create([
            'staff_id' => $staff->id, 'sales_code' => $code,
            'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
            'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
        ]);
    };
    $owner = $makeProfile('TASK-OWNER-A');
    $opportunity = SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $owner->id,
        'opportunity_number' => 'TASK-OPP-A', 'name' => 'Task opportunity A',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $otherOwner = $makeProfile('TASK-OWNER-B');
    $otherOpportunity = SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $otherOwner->id,
        'opportunity_number' => 'TASK-OPP-B', 'name' => 'Task opportunity B',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $customer = Customer::create(['user_id' => $actor->id]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'sales_opportunity_id' => $opportunity->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $owner->staff_id,
        'created_user_id' => $actor->id,
    ]);

    $payload = [
        'owner_sales_profile_id' => $owner->id, 'opportunity_id' => $opportunity->id,
        'booking_id' => $booking->id,
        'creation_idempotency_key' => (string) Str::uuid(), 'title' => 'Call the prospect', 'priority' => 'normal',
        'due_at' => now()->addDay()->toIso8601String(),
    ];
    actingAs($actor, 'api')->postJson('/api/sales/tasks', $payload)
        ->assertCreated()->assertJsonMissingPath('data.creation_idempotency_key');
    actingAs($actor, 'api')->postJson('/api/sales/tasks', $payload)->assertCreated();
    expect(SalesTask::query()->count())->toBe(1)
        ->and(SalesTaskEvent::query()->where('event_type', 'created')->count())->toBe(1);
    actingAs($actor, 'api')->getJson('/api/sales/tasks')->assertOk()->assertJsonCount(1, 'data.data');

    actingAs($actor, 'api')->postJson('/api/sales/tasks', [
        ...$payload, 'title' => 'Different task with the same key',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/tasks', [
        ...$payload, 'opportunity_id' => $otherOpportunity->id, 'creation_idempotency_key' => (string) Str::uuid(),
    ])->assertUnprocessable();

    $task = SalesTask::query()->firstOrFail();
    $transition = [
        'to_status' => 'completed', 'expected_version' => 1, 'reason' => 'Done',
        'idempotency_key' => (string) Str::uuid(),
    ];
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transition', $transition)->assertOk();
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transition', $transition)->assertOk();
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transition', [
        ...$transition, 'to_status' => 'cancelled',
    ])->assertConflict();
    expect(SalesTask::query()->count())->toBe(1)
        ->and(SalesTaskEvent::query()->where('event_type', 'status_changed')->count())->toBe(1);

    $scopedActor = User::factory()->create();
    $scopedActor->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $scopedActor->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $scopedStaff = Staff::factory()->create(['user_id' => $scopedActor->id]);
    UserContext::create([
        'user_id' => $scopedActor->id, 'context_type' => 'staff', 'context_id' => $scopedStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $scopedOwner = SalesProfile::query()->create([
        'staff_id' => $scopedStaff->id, 'sales_code' => 'TASK-OWNER-SCOPED',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
    ]);
    actingAs($scopedActor, 'api')->getJson('/api/sales/tasks?company_id=00000000-0000-0000-0000-000000000001')->assertOk()
        ->assertJsonCount(0, 'data.data');
    actingAs($scopedActor, 'api')->postJson('/api/sales/tasks', [
        ...$payload, 'owner_sales_profile_id' => $scopedOwner->id,
        'creation_idempotency_key' => (string) Str::uuid(),
    ])->assertForbidden();

    $transfer = [
        'owner_sales_profile_id' => $scopedOwner->id, 'expected_version' => 2,
        'reason' => 'Approved owner reassignment', 'idempotency_key' => (string) Str::uuid(),
    ];
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transfer', $transfer)->assertOk();
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transfer', $transfer)->assertOk();
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transfer', [
        ...$transfer, 'reason' => 'Different command',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/tasks', $payload)->assertCreated();
    expect(SalesTaskEvent::query()->where('event_type', 'reassigned')->count())->toBe(1);

    $booking->update(['commission_owner_staff_id' => $otherOwner->staff_id]);
    actingAs($actor, 'api')->getJson('/api/sales/tasks?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor, 'api')->postJson('/api/sales/tasks/'.$task->id.'/transition', [
        'to_status' => 'open', 'expected_version' => 3, 'reason' => 'Foreign booking must fail closed',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertConflict();
    $booking->update(['commission_owner_staff_id' => $owner->staff_id]);

    $migration = require database_path('migrations/2026_10_03_000001_add_sales_task_write_idempotency.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

});
