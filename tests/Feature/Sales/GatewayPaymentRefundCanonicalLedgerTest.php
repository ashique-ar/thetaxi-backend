<?php

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Customer;
use App\Models\Currency;
use App\Models\Finance\FinancialAuditEvent;
use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\BookingPaymentLedgerService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Exceptions\HttpResponseException;

uses(RefreshDatabase::class);

function seedGatewayRefundFixture(): array
{
    [$actor, $company] = hr_seed_admin_actor();
    Currency::query()->create(['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'exrate' => '1']);
    $customer = Customer::create(['user_id' => $actor->id]);
    $bookingId = (string) Str::uuid();
    DB::table('bookings')->insert([
        'id' => $bookingId,
        'booking_number' => 'BK-REFUND-'.Str::upper(Str::random(8)),
        'customer_id' => $customer->id,
        'status' => 'confirmed',
        'currency' => 'LKR',
        'total_estimated' => 1000,
        'created_user_id' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $booking = Booking::query()->findOrFail($bookingId);
    app(BookingPaymentLedgerService::class)->receive($booking, [
        'amount' => 1000,
        'source_amount' => 1000,
        'source_currency' => 'LKR',
        'payment_method' => 'webxpay',
        'payment_stage' => 'gateway_settlement',
        'payment_purpose' => 'booking_payment',
        'reference' => 'provider-refund-fixture',
        'idempotency_key' => 'gateway:gateway-refund-fixture',
        'provider_event_id' => 'gateway-refund-fixture',
        'provider_payload_checksum' => hash('sha256', 'payment fixture'),
        'received_at' => now(),
        'received_via' => 'company',
        'finalized_at' => now(),
        'notes' => 'Canonical payment fixture',
    ], $actor->id);

    $transactionId = (string) Str::uuid();
    $providerTransactionId = 'gateway-refund-fixture';
    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid(),
        'attempt_id' => (string) Str::uuid(),
        'booking_id' => $bookingId,
        'amount' => 1000,
        'currency' => 'LKR',
        'payment_method' => 'webxpay',
        'gateway' => 'webxpay',
        'status' => 'success',
        'transaction_id' => $transactionId,
        'gateway_transaction_id' => $providerTransactionId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$actor, $booking, $transactionId];
}

function paymentRefundRequest($actor, array $payload): Request
{
    $request = Request::create('/api/payments/refund-fixture/refund', 'POST', $payload);
    $request->setUserResolver(fn () => $actor);

    return $request;
}

function signedPaymentCallbackRequest($actor, array $payload, string $secret): Request
{
    ksort($payload);
    $payload['signature'] = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
    $request = Request::create('/api/payments/callback', 'POST', $payload);
    $request->setUserResolver(fn () => $actor);

    return $request;
}

it('persists the legacy payment schema fields needed by online settlement', function () {
    expect(Schema::hasColumns('payment_transactions', [
        'attempt_id', 'gateway', 'booking_id', 'currency', 'payment_method',
        'transaction_id', 'gateway_transaction_id', 'payment_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('payment_refunds', [
            'transaction_id', 'amount', 'status', 'gateway_refund_id', 'idempotency_key',
            'request_payload_checksum', 'created_by',
        ]))->toBeTrue();
});

it('reserves manual refunds, replays idempotently, and rejects key reuse with changed facts', function () {
    [$actor, $booking, $transactionId] = seedGatewayRefundFixture();
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('refund')->once()->with('gateway-refund-fixture', 400.0, 'Customer cancellation')
        ->andReturn(['success' => false, 'manual' => true, 'message' => 'Manual processing required']);
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('webxpay')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $controller = app(PaymentController::class);
    $payload = ['amount' => 400, 'reason' => 'Customer cancellation', 'idempotency_key' => 'refund-fixture-1'];

    $first = $controller->refundPayment(paymentRefundRequest($actor, $payload), $transactionId);
    $firstData = $first->getData(true)['data'];
    $replay = $controller->refundPayment(paymentRefundRequest($actor, $payload), $transactionId);

    expect($first->getStatusCode())->toBe(201)
        ->and($firstData['status'])->toBe('manual_required')
        ->and($replay->getStatusCode())->toBe(200)
        ->and($replay->getData(true)['data']['refund_id'])->toBe($firstData['refund_id'])
        ->and(DB::table('payment_refunds')->where('transaction_id', DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('id'))->count())->toBe(1)
        ->and(FinancialAuditEvent::query()->where('subject_id', $firstData['refund_id'])->count())->toBe(2)
        ->and(BookingPaymentReceipt::query()->where('booking_id', $booking->id)->value('refunded_amount'))->toBe('0.00');

    expect(fn () => $controller->refundPayment(paymentRefundRequest($actor, [
        ...$payload,
        'reason' => 'Different reason',
    ]), $transactionId))->toThrow(HttpException::class);

    expect(fn () => $controller->refundPayment(paymentRefundRequest($actor, [
        'amount' => 700,
        'reason' => 'Exceeds remaining refundable amount',
        'idempotency_key' => 'refund-fixture-2',
    ]), $transactionId))->toThrow(HttpException::class);
    expect(DB::table('payment_refunds')->count())->toBe(1);
});

it('posts a provider-confirmed refund exactly once to the canonical receipt ledger', function () {
    [$actor, $booking, $transactionId] = seedGatewayRefundFixture();
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('refund')->once()->with('gateway-refund-fixture', 250.0, 'Partial refund')
        ->andReturn(['success' => true, 'refund_id' => 'provider-refund-1']);
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('webxpay')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $controller = app(PaymentController::class);
    $payload = ['amount' => 250, 'reason' => 'Partial refund', 'idempotency_key' => 'refund-confirmed-1'];

    $first = $controller->refundPayment(paymentRefundRequest($actor, $payload), $transactionId);
    $replay = $controller->refundPayment(paymentRefundRequest($actor, $payload), $transactionId);
    $refundRow = DB::table('payment_refunds')->where('id', $first->getData(true)['data']['refund_id'])->first();

    expect($first->getStatusCode())->toBe(201)
        ->and($first->getData(true)['data']['status'])->toBe('completed', (string) $refundRow->notes)
        ->and($replay->getStatusCode())->toBe(200)
        ->and($refundRow->status)->toBe('completed')
        ->and(BookingPaymentReceipt::query()->where('booking_id', $booking->id)->value('refunded_amount'))->toBe('250.00')
        ->and(BookingPaymentAdjustment::query()->where('booking_id', $booking->id)->count())->toBe(1)
        ->and(FinancialAuditEvent::query()->where('subject_id', $first->getData(true)['data']['refund_id'])->count())->toBe(2);
});

it('denies refund attempts from a different active Staff legal entity before contacting the gateway', function () {
    [, , $transactionId] = seedGatewayRefundFixture();
    $otherCompany = Company::query()->create(['name' => 'Other refund company', 'is_default' => false]);
    $otherUser = User::factory()->create();
    $otherStaff = Staff::factory()->create(['user_id' => $otherUser->id, 'company_id' => $otherCompany->id]);
    UserContext::query()->create([
        'user_id' => $otherUser->id,
        'context_type' => 'staff',
        'context_id' => $otherStaff->id,
        'is_active' => true,
        'created_user_id' => $otherUser->id,
    ]);

    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldNotReceive('resolve');
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $controller = app(PaymentController::class);

    expect(fn () => $controller->refundPayment(paymentRefundRequest($otherUser, [
        'amount' => 100,
        'reason' => 'Cross-company attempt',
        'idempotency_key' => 'refund-cross-company',
    ]), $transactionId))->toThrow(HttpResponseException::class);
    expect(DB::table('payment_refunds')->count())->toBe(0);
});

it('scopes payment transaction reads to the active Sales legal entity and exposes refund state in detail', function () {
    [$actor, $booking, $transactionId] = seedGatewayRefundFixture();
    $transaction = DB::table('payment_transactions')->where('transaction_id', $transactionId)->first();

    $detailRequest = Request::create('/api/payment-transactions/'.$transaction->id, 'GET');
    $detailRequest->setUserResolver(fn () => $actor);
    $detail = app(PaymentController::class)->getTransactionDetails($detailRequest, $transaction->id);
    expect($detail->getData(true)['data']['transaction']['refunds'])->toBeEmpty();
    $statusRequest = Request::create('/api/payments/'.$transactionId.'/status', 'GET');
    $statusRequest->setUserResolver(fn () => $actor);
    expect(app(PaymentController::class)->getPaymentStatus($statusRequest, $transactionId)->getStatusCode())->toBe(200);

    DB::table('payment_refunds')->insert([
        'id' => (string) Str::uuid(),
        'transaction_id' => $transaction->id,
        'amount' => 100,
        'reason' => 'Provider outcome pending',
        'status' => 'manual_required',
        'idempotency_key' => 'reconciliation-visible',
        'request_payload_checksum' => hash('sha256', 'manual refund'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $detail = app(PaymentController::class)->getTransactionDetails($detailRequest, $transaction->id);
    expect($detail->getData(true)['data']['transaction']['refunds'])->toHaveCount(1)
        ->and($detail->getData(true)['data']['transaction']['refunds'][0]['status'])->toBe('manual_required');
    $queueRequest = Request::create('/api/payment-transactions/refunds/reconciliation', 'GET');
    $queueRequest->setUserResolver(fn () => $actor);
    $queue = app(PaymentController::class)->getRefundReconciliationQueue($queueRequest)->getData(true)['data'];
    expect($queue['total'])->toBe(1)
        ->and($queue['data'][0]['status'])->toBe('manual_required')
        ->and($queue['data'][0]['booking_number'])->toBe($booking->booking_number);

    $otherCompany = Company::query()->create(['name' => 'Payment read other company', 'is_default' => false]);
    $otherUser = User::factory()->create();
    $otherStaff = Staff::factory()->create(['user_id' => $otherUser->id, 'company_id' => $otherCompany->id]);
    UserContext::query()->create([
        'user_id' => $otherUser->id,
        'context_type' => 'staff',
        'context_id' => $otherStaff->id,
        'is_active' => true,
        'created_user_id' => $otherUser->id,
    ]);

    $otherRequest = Request::create('/api/payment-transactions/'.$transaction->id, 'GET');
    $otherRequest->setUserResolver(fn () => $otherUser);
    $list = Request::create('/api/payment-transactions', 'GET');
    $list->setUserResolver(fn () => $otherUser);
    $otherQueue = Request::create('/api/payment-transactions/refunds/reconciliation', 'GET');
    $otherQueue->setUserResolver(fn () => $otherUser);
    expect(app(PaymentController::class)->getPaymentTransactions($list)->getData(true)['data']['transactions'])->toBeEmpty()
        ->and(app(PaymentController::class)->getRefundReconciliationQueue($otherQueue)->getData(true)['data']['data'])->toBeEmpty()
        ->and(fn () => app(PaymentController::class)->getTransactionDetails($otherRequest, $transaction->id))
        ->toThrow(HttpResponseException::class)
        ->and(fn () => app(PaymentController::class)->getPaymentStatus($otherRequest, $transactionId))
        ->toThrow(HttpResponseException::class);
});

it('fails closed when the payment callback verifier is missing or the signature is invalid', function () {
    [$actor, , $transactionId] = seedGatewayRefundFixture();
    DB::table('payment_transactions')->where('transaction_id', $transactionId)->update(['status' => 'pending']);
    $controller = app(PaymentController::class);
    $payload = ['transaction_id' => $transactionId, 'status' => 'success', 'payment_id' => 'callback-provider-1'];

    Config::set('booking.payment_callback_secret', '');
    $unconfiguredRequest = Request::create('/api/payments/callback', 'POST', $payload);
    $unconfiguredRequest->setUserResolver(fn () => $actor);
    expect($controller->paymentCallback($unconfiguredRequest)->getStatusCode())->toBe(503);

    $secret = str_repeat('callback-test-key-', 2);
    Config::set('booking.payment_callback_secret', $secret);
    $missingSignatureRequest = Request::create('/api/payments/callback', 'POST', $payload);
    $missingSignatureRequest->setUserResolver(fn () => $actor);
    expect($controller->paymentCallback($missingSignatureRequest)->getStatusCode())->toBe(422);

    $invalidPayload = [...$payload, 'signature' => str_repeat('0', 64)];
    $invalidRequest = Request::create('/api/payments/callback', 'POST', $invalidPayload);
    $invalidRequest->setUserResolver(fn () => $actor);
    expect($controller->paymentCallback($invalidRequest)->getStatusCode())->toBe(400)
        ->and(DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('status'))->toBe('pending');
});

it('applies an authenticated payment callback once and rejects a conflicting final outcome', function () {
    [$actor, $booking, $transactionId] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    DB::table('payment_transactions')->where('transaction_id', $transactionId)->update(['status' => 'pending']);
    $secret = str_repeat('callback-test-key-', 2);
    Config::set('booking.payment_callback_secret', $secret);
    $sms = Mockery::mock(SmsAutomationService::class);
    $sms->shouldReceive('queuePaymentConfirmation')->once();
    $this->app->instance(SmsAutomationService::class, $sms);
    $controller = app(PaymentController::class);
    $payload = ['transaction_id' => $transactionId, 'status' => 'success', 'payment_id' => 'callback-provider-2'];

    $first = $controller->paymentCallback(signedPaymentCallbackRequest($actor, $payload, $secret));
    $replay = $controller->paymentCallback(signedPaymentCallbackRequest($actor, $payload, $secret));
    expect($first->getStatusCode())->toBe(200)
        ->and($replay->getStatusCode())->toBe(200)
        ->and(DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('status'))->toBe('success')
        ->and(BookingPaymentReceipt::query()->where('booking_id', $booking->id)->where('provider_event_id', 'callback-provider-2')->count())->toBe(1);

    $conflict = null;
    try {
        $controller->paymentCallback(signedPaymentCallbackRequest($actor, [
            ...$payload,
            'status' => 'failed',
        ], $secret));
    } catch (HttpException $exception) {
        $conflict = $exception;
    }
    expect($conflict?->getStatusCode())->toBe(409)
        ->and(DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('status'))->toBe('success');
});
