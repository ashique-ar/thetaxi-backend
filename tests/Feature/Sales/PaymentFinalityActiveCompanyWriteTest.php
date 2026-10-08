<?php

use App\Models\Staff;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Booking\BookingPaymentReceipt;
use App\Services\BookingPaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('creates an audited default-company attribution when payment handling finds none', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $customer = Customer::create(['user_id' => $admin->id]);
    $bookingId = (string) Str::uuid();
    DB::table('bookings')->insert([
        'id' => $bookingId, 'booking_number' => 'BK-DEFAULT-FALLBACK-1', 'customer_id' => $customer->id,
        'status' => 'confirmed', 'currency' => 'LKR', 'total_estimated' => 1500,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = Booking::query()->findOrFail($bookingId);

    $ledger = app(BookingPaymentLedgerService::class);
    expect($ledger->ensureBookingCompanyAttribution($booking))->toBe($company->id);
    expect($ledger->ensureBookingCompanyAttribution($booking))->toBe($company->id);

    $attribution = DB::table('sales_booking_attributions')->where('booking_id', $booking->id)->first();
    expect($attribution->company_id)->toBe($company->id)
        ->and($attribution->status)->toBe('held')
        ->and(DB::table('sales_booking_attribution_events')->where('attribution_id', $attribution->id)
            ->where('event_type', 'default_company_fallback')->count())->toBe(1);
});

it('blocks payment-finality policy writes when the company is inactive or deleted', function () {
    [, $company] = hr_seed_admin_actor();
    $maker = Staff::factory()->create(['company_id' => $company->id]);
    $checker = Staff::factory()->create(['company_id' => $company->id]);
    $maker->user->givePermissionTo('sales.payment-finality.manage');
    $checker->user->givePermissionTo('sales.payment-finality.approve');

    $policyId = (string) Str::uuid();
    DB::table('booking_payment_finality_policies')->insert([
        'id' => $policyId,
        'company_id' => $company->id,
        'payment_method' => 'cash',
        'official_collection_state' => 'confirmed',
        'can_earn_before_final' => false,
        'hold_payout_until_final' => true,
        'effective_from' => now(),
        'version' => 1,
        'status' => 'draft',
        'created_by' => $maker->user_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $storePayload = [
        'company_id' => $company->id,
        'payment_method' => 'cash',
        'official_collection_state' => 'confirmed',
        'can_earn_before_final' => false,
        'hold_payout_until_final' => true,
        'effective_from' => now()->toDateString(),
    ];
    $company->update(['is_active' => false]);

    actingAs($maker->user, 'api')->postJson('/api/sales/payment-finality-policies', $storePayload)
        ->assertUnprocessable();
    actingAs($checker->user, 'api')->postJson('/api/sales/payment-finality-policies/'.$policyId.'/approve')
        ->assertUnprocessable();
    $this->assertDatabaseHas('booking_payment_finality_policies', [
        'id' => $policyId,
        'status' => 'draft',
        'approved_by' => null,
    ]);
    expect(DB::table('booking_payment_finality_policies')->where('company_id', $company->id)->count())->toBe(1);

    $company->update(['is_active' => true]);
    $company->delete();
    actingAs($maker->user, 'api')->postJson('/api/sales/payment-finality-policies', $storePayload)
        ->assertUnprocessable();
    expect(DB::table('booking_payment_finality_policies')->where('company_id', $company->id)->count())->toBe(1);
});

it('replays finality-policy approval for its original checker and rejects another checker', function () {
    [, $company] = hr_seed_admin_actor();
    $maker = Staff::factory()->create(['company_id' => $company->id]);
    $checker = Staff::factory()->create(['company_id' => $company->id]);
    $otherChecker = Staff::factory()->create(['company_id' => $company->id]);
    $maker->user->givePermissionTo('sales.payment-finality.manage');
    $checker->user->givePermissionTo('sales.payment-finality.approve');
    $otherChecker->user->givePermissionTo('sales.payment-finality.approve');
    $policyId = (string) Str::uuid();
    DB::table('booking_payment_finality_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'payment_method' => 'cash',
        'official_collection_state' => 'confirmed', 'can_earn_before_final' => false,
        'hold_payout_until_final' => true, 'effective_from' => now()->toDateString(),
        'version' => 1, 'status' => 'draft', 'created_by' => $maker->user_id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/sales/payment-finality-policies/'.$policyId.'/approve';

    actingAs($checker->user, 'api')->postJson($url)->assertOk();
    $approved = DB::table('booking_payment_finality_policies')->where('id', $policyId)->first();
    actingAs($checker->user, 'api')->postJson($url)->assertOk();
    expect(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('approved_by'))->toBe($checker->user_id)
        ->and(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('approved_at'))->toBe($approved->approved_at)
        ->and(DB::table('booking_payment_finality_policies')->where('id', $policyId)->value('updated_at'))->toBe($approved->updated_at)
        ->and(DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->count())->toBe(1);
    expect(json_decode((string) DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->value('properties'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['company_id' => $company->id, 'status' => 'approved', 'effective_from' => $approved->effective_from, 'version' => 1]);

    actingAs($otherChecker->user, 'api')->postJson($url)->assertStatus(409);
    expect(DB::table('activity_log')->where('description', 'payment_finality_policy_approved')->count())->toBe(1);
});

it('blocks receipt finality transitions for an inactive or deleted company before touching its booking', function () {
    foreach (['inactive', 'deleted'] as $state) {
        [, $company] = hr_seed_admin_actor();
        if ($state === 'inactive') $company->update(['is_active' => false]);
        else $company->delete();

        $receipt = new BookingPaymentReceipt([
            'company_id' => $company->id,
            'booking_id' => (string) Str::uuid(),
        ]);

        expect(fn () => app(BookingPaymentLedgerService::class)->transitionReceiptFinality(
            $receipt, 'failed', 'test transition', null, (string) Str::uuid(), (string) Str::uuid(),
        ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
});
