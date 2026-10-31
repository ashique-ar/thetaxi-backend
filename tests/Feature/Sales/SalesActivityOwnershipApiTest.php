<?php

use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Booking\BookingActivity;
use App\Models\Sales\SalesActivity;
use App\Models\Sales\SalesFeatureSetting;
use App\Models\Sales\SalesMetricFact;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('records authorized opportunity activity once and rejects mismatched record links', function () {
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
        return SalesProfile::query()->create([
            'staff_id' => $staff->id, 'sales_code' => $code,
            'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
            'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
        ]);
    };
    $profile = $makeProfile('ACTIVITY-OWNER-A');
    $opportunity = SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $profile->id,
        'opportunity_number' => 'ACTIVITY-OPP-A', 'name' => 'Activity opportunity A',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $otherProfile = $makeProfile('ACTIVITY-OWNER-B');
    $otherOpportunity = SalesOpportunity::query()->create([
        'owner_sales_profile_id' => $otherProfile->id,
        'opportunity_number' => 'ACTIVITY-OPP-B', 'name' => 'Activity opportunity B',
        'source' => 'manual', 'created_user_id' => $actor->id,
    ]);
    $customer = Customer::create(['user_id' => $actor->id]);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'sales_opportunity_id' => $opportunity->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $profile->staff_id,
        'created_user_id' => $actor->id,
    ]);
    $bookingActivity = BookingActivity::create([
        'booking_id' => $booking->id, 'event_key' => 'test.event',
        'result_status' => 'logged', 'title' => 'Booking event', 'idempotency_key' => (string) Str::uuid(),
        'event_at' => now(),
    ]);

    $payload = [
        'sales_profile_id' => $profile->id, 'opportunity_id' => $opportunity->id,
        'booking_id' => $booking->id, 'booking_activity_id' => $bookingActivity->id,
        'activity_type' => 'call', 'subject' => 'Discussed requirements', 'occurred_at' => now()->toISOString(),
        'source_system' => 'manual', 'source_reference' => (string) Str::uuid(),
    ];
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)
        ->assertCreated()->assertJsonMissingPath('data.source_reference');
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)->assertCreated();
    expect(SalesActivity::query()->count())->toBe(1)
        ->and(SalesMetricFact::query()->where('source_type', 'sales_activity')->count())->toBe(1);
    actingAs($actor, 'api')->getJson('/api/sales/activities')->assertOk()->assertJsonCount(1, 'data.data');

    actingAs($actor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'subject' => 'Different facts with the same source key',
    ])->assertConflict();
    actingAs($actor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'opportunity_id' => $otherOpportunity->id, 'source_reference' => (string) Str::uuid(),
    ])->assertUnprocessable();

    $activityStaffId = $profile->staff_id;
    $foreignStaff = Staff::factory()->create();
    $profile->update(['staff_id' => $foreignStaff->id]);
    actingAs($actor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'source_reference' => (string) Str::uuid(),
    ])->assertUnprocessable();
    $profile->update(['staff_id' => $activityStaffId]);
    actingAs($actor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(1, 'data.data');

    $booking->update(['commission_owner_staff_id' => $foreignStaff->id]);
    actingAs($actor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)->assertUnprocessable();
    $booking->update(['commission_owner_staff_id' => $activityStaffId]);
    $otherBooking = Booking::create([
        'customer_id' => $customer->id,
        'sales_opportunity_id' => $otherOpportunity->id,
        'status' => 'pending', 'currency' => 'LKR', 'commission_owner_staff_id' => $otherProfile->staff_id,
        'created_user_id' => $actor->id,
    ]);
    $bookingActivity->update(['booking_id' => $otherBooking->id]);
    actingAs($actor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor, 'api')->postJson('/api/sales/activities', $payload)->assertUnprocessable();
    $bookingActivity->update(['booking_id' => $booking->id]);
    actingAs($actor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()->assertJsonCount(1, 'data.data');

    $scopedActor = User::factory()->create();
    $scopedActor->givePermissionTo(Permission::findByName('sales.crm.manage', 'api'));
    $scopedActor->givePermissionTo(Permission::findByName('sales.crm.view', 'api'));
    $scopedStaff = Staff::factory()->create(['user_id' => $scopedActor->id]);
    UserContext::create([
        'user_id' => $scopedActor->id, 'context_type' => 'staff', 'context_id' => $scopedStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $scopedProfile = SalesProfile::query()->create([
        'staff_id' => $scopedStaff->id, 'sales_code' => 'ACTIVITY-OWNER-SCOPED',
        'status' => 'active', 'effective_from' => now()->subDay(), 'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR', 'acquisition_eligible' => true, 'created_user_id' => $actor->id,
    ]);
    actingAs($scopedActor, 'api')->getJson('/api/sales/activities?company_id=00000000-0000-0000-0000-000000000001')->assertOk()
        ->assertJsonCount(0, 'data.data');
    actingAs($scopedActor, 'api')->postJson('/api/sales/activities', [
        ...$payload, 'sales_profile_id' => $scopedProfile->id, 'source_reference' => (string) Str::uuid(),
    ])->assertForbidden();

    expect(SalesActivity::query()->count())->toBe(1)
        ->and(SalesMetricFact::query()->where('source_type', 'sales_activity')->count())->toBe(1);
});
