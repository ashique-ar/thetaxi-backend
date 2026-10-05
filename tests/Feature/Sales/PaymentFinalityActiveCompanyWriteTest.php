<?php

use App\Models\Staff;
use App\Models\Booking\BookingPaymentReceipt;
use App\Services\BookingPaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

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
