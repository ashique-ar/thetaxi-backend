<?php

use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('stages an ambiguous historical booking and resolves its owner without selecting a location', function () {
    [$reviewer] = hr_seed_admin_actor();
    $reviewer->givePermissionTo(['sales.attributions.view', 'sales.attributions.view-all', 'sales.attributions.correct']);
    $profileStaff = Staff::factory()->create();
    $profile = SalesProfile::query()->create([
        'staff_id' => $profileStaff->id,
        'sales_code' => 'HIST-OWNER-1',
        'status' => 'active',
        'effective_from' => now()->subYear(),
        'staff_category_snapshot' => 'Sales',
        'reporting_currency' => 'LKR',
        'acquisition_eligible' => true,
        'created_user_id' => $reviewer->id,
    ]);
    $booking = Booking::create([
        'customer_id' => Customer::create(['user_id' => $reviewer->id])->id,
        'status' => 'confirmed',
        'confirmed' => true,
        'confirmed_at' => now()->subDays(30),
        'currency' => 'LKR',
        'created_user_id' => null,
        'commission_owner_staff_id' => null,
    ]);

    $unreviewed = actingAs($reviewer, 'api')->getJson('/api/sales/attribution-exceptions')->assertOk();
    expect(collect($unreviewed->json('data.data'))->pluck('booking_id'))->not->toContain((string) $booking->id);
    $preview = actingAs($reviewer, 'api')->postJson('/api/sales/attributions/dry-run', [
        'booking_ids' => [$booking->id],
    ])->assertOk()
        ->assertJsonPath('data.write_performed', false)
        ->assertJsonPath('data.rows.0.acquisition_sales_profile_id', null)
        ->assertJsonPath('data.rows.0.holds.2', 'historical_owner_review_required');

    $batch = actingAs($reviewer, 'api')->postJson('/api/sales/attributions/historical-batch', [
        'booking_ids' => [$booking->id],
        'preview_checksum' => $preview->json('data.preview_checksum'),
        'idempotency_key' => 'historical-owner-stage-1',
    ])->assertOk()->assertJsonPath('data.created', 1);

    $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($attribution->acquisition_sales_profile_id)->toBeNull()
        ->and($attribution->status)->toBe('held');
    actingAs($reviewer, 'api')->getJson('/api/sales/attribution-exceptions')
        ->assertOk()->assertJsonFragment(['exception_type' => 'historical_owner_review_required']);

    $ordinaryViewer = User::factory()->create();
    $ordinaryViewer->givePermissionTo('sales.attributions.view');
    $ordinaryResponse = actingAs($ordinaryViewer, 'api')->getJson('/api/sales/attribution-exceptions')->assertOk();
    expect(collect($ordinaryResponse->json('data.data'))->pluck('booking_id'))->not->toContain((string) $booking->id);

    $payload = [
        'to_sales_profile_id' => $profile->id,
        'reason' => 'Historical owner evidence reviewed and salesperson assigned.',
        'idempotency_key' => 'historical-owner-resolution-1',
    ];
    actingAs($reviewer, 'api')->postJson('/api/sales/attributions/'.$attribution->id.'/correct-owner', $payload)
        ->assertOk()->assertJsonPath('data.acquisition_sales_profile_id', $profile->id);
    expect(DB::table('sales_attribution_exceptions')->where('booking_id', $booking->id)
        ->where('exception_type', 'historical_owner_review_required')->value('status'))->toBe('resolved')
        ->and(DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)->count())->toBe(2);

    actingAs($reviewer, 'api')->postJson('/api/sales/attributions/'.$attribution->id.'/correct-owner', $payload)
        ->assertOk()->assertJsonPath('data.acquisition_sales_profile_id', $profile->id);
    actingAs($reviewer, 'api')->postJson('/api/sales/attributions/'.$attribution->id.'/correct-owner', [
        ...$payload,
        'to_sales_profile_id' => (string) Str::uuid(),
    ])->assertNotFound();

    expect(DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)
        ->where('idempotency_key', 'historical-owner-resolution-1')->count())->toBe(1)
        ->and($batch->json('data.created'))->toBe(1);
});
