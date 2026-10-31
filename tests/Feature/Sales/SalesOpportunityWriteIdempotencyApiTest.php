<?php

use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Sales\SalesFeatureSetting;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesOpportunityStageEvent;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays only the original opportunity transition and transfer command', function () {
    [$actor] = hr_seed_admin_actor();
    foreach (['sales.crm.view', 'sales.crm.view-all', 'sales.crm.manage', 'sales.crm.manage-all'] as $permission) {
        $actor->givePermissionTo(Permission::findByName($permission, 'api'));
    }
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
    $owner = $makeProfile('OPP-IDEM-OWNER');
    $newOwner = $makeProfile('OPP-IDEM-NEW-OWNER');
    $makeOpportunity = fn (string $number) => SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $owner->id,
        'opportunity_number' => $number, 'name' => $number, 'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $first = $makeOpportunity('OPP-IDEM-1');
    $second = $makeOpportunity('OPP-IDEM-2');

    $transition = [
        'to_stage' => 'contacted', 'expected_version' => 1, 'reason_code' => null,
        'reason' => null, 'idempotency_key' => 'opportunity-stage-idem-1',
    ];
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', $transition)
        ->assertOk()->assertJsonPath('data.version', 2);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', $transition)
        ->assertOk()->assertJsonPath('data.version', 2);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', [
        ...$transition, 'to_stage' => 'qualified',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', [
        ...$transition, 'expected_version' => 2,
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$second->id.'/transition', $transition)->assertConflict();
    $otherActor = User::factory()->create();
    foreach (['sales.crm.view', 'sales.crm.view-all', 'sales.crm.manage', 'sales.crm.manage-all'] as $permission) {
        $otherActor->givePermissionTo(Permission::findByName($permission, 'api'));
    }
    actingAs($otherActor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', $transition)->assertForbidden();

    $transfer = [
        'owner_sales_profile_id' => $newOwner->id, 'expected_version' => 2,
        'reason' => 'Approved owner change', 'idempotency_key' => 'opportunity-owner-idem-1',
    ];
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', $transfer)
        ->assertOk()->assertJsonPath('data.version', 3);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', $transfer)
        ->assertOk()->assertJsonPath('data.version', 3);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', [
        ...$transfer, 'reason' => 'Changed retry',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', [
        ...$transfer, 'owner_sales_profile_id' => $owner->id,
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', [
        ...$transfer, 'expected_version' => 3,
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$second->id.'/transfer', $transfer)->assertConflict();

    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transition', [
        'to_stage' => 'qualified', 'expected_version' => 3, 'idempotency_key' => 'opportunity-stage-idem-2',
    ])->assertOk();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/transfer', $transfer)->assertOk();
    $customer = Customer::create(['user_id' => $actor->id]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $newOwner->staff_id,
        'created_user_id' => $actor->id,
    ]);
    $otherBooking = Booking::create([
        'customer_id' => $customer->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $newOwner->staff_id,
        'created_user_id' => $actor->id,
    ]);
    $unmatchedStaff = Staff::factory()->create();
    $foreignBooking = Booking::create([
        'customer_id' => $customer->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $unmatchedStaff->id,
        'created_user_id' => $actor->id,
    ]);
    $link = ['booking_id' => $booking->id, 'idempotency_key' => 'opportunity-booking-idem-1'];
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/link-booking', $link)
        ->assertOk()->assertJsonPath('data.version', 5);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/link-booking', $link)
        ->assertOk()->assertJsonPath('data.version', 5);
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/link-booking', [
        ...$link, 'booking_id' => $otherBooking->id,
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$second->id.'/link-booking', $link)->assertConflict();
    actingAs($otherActor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/link-booking', $link)->assertForbidden();
    actingAs($actor, 'api')->postJson('/api/sales/opportunities/'.$first->id.'/link-booking', [
        'booking_id' => $foreignBooking->id, 'idempotency_key' => 'opportunity-booking-unmatched-owner',
    ])->assertUnprocessable();
    expect(SalesOpportunityStageEvent::query()->where('idempotency_key', 'opportunity-stage-idem-1')->count())->toBe(1)
        ->and(SalesOpportunityStageEvent::query()->where('idempotency_key', 'opportunity-owner-idem-1')->count())->toBe(1)
        ->and(SalesOpportunityStageEvent::query()->where('idempotency_key', 'opportunity-booking-idem-1')->count())->toBe(1)
        ->and($first->fresh()->owner_sales_profile_id)->toBe($newOwner->id)
        ->and($first->fresh()->stage)->toBe('qualified')
        ->and($first->fresh()->won_booking_id)->toBeNull()
        ->and($booking->fresh()->sales_opportunity_id)->toBe($first->id)
        ->and($otherBooking->fresh()->sales_opportunity_id)->toBeNull()
        ->and($foreignBooking->fresh()->sales_opportunity_id)->toBeNull()
        ->and($second->fresh()->state_version)->toBe(1);
});
