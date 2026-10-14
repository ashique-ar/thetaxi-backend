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
        'payment_collection_method' => 'online',
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
    $companyId = DB::table('sales_booking_attributions')->where('booking_id', $bookingId)->value('company_id');
    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid(),
        'attempt_id' => (string) Str::uuid(),
        'booking_id' => $bookingId,
        'company_id' => $companyId,
        'amount' => 1000,
        'currency' => 'LKR',
        'requested_amount' => 1000,
        'requested_currency' => 'LKR',
        'gateway_payment_snapshot' => json_encode([
            'source_amount' => 1000, 'source_currency' => 'LKR',
            'source_evidence_valid' => true,
            'provider_amount' => 1000, 'provider_currency' => 'LKR',
            'order_id' => 'gateway-refund-fixture', 'fx_rate_to_lkr' => 1,
        ]),
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

function paymentInitiationRequest($actor, array $payload): Request
{
    $request = Request::create('/api/payments/initiate', 'POST', $payload);
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
        'attempt_id', 'gateway', 'booking_id', 'currency', 'payment_method', 'gateway_payment_snapshot',
        'transaction_id', 'gateway_transaction_id', 'payment_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('payment_refunds', [
            'transaction_id', 'amount', 'status', 'gateway_refund_id', 'idempotency_key',
            'request_payload_checksum', 'created_by',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('payment_transactions', ['requested_amount', 'requested_currency']))->toBeTrue();
});

it('does not advertise a fake payment method when no gateway is enabled', function () {
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('available')->once()->andReturn([]);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    $response = app(PaymentController::class)->getPaymentMethods()->getData(true);
    expect($response['data']['payment_methods'])->toBeEmpty()
        ->and($response['message'])->toBe('No online payment gateway is currently configured.');
});

it('rejects unsupported or disabled gateways before reserving booking balance', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('paypal')
        ->andThrow(new InvalidArgumentException('No payment gateway registered for method: paypal'));
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $unsupported = app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 100,
        'currency' => 'LKR',
        'payment_method' => 'paypal',
    ]));

    $disabledGateway = Mockery::mock(PaymentGatewayInterface::class);
    $disabledGateway->shouldReceive('isEnabled')->once()->andReturnFalse();
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('credit_card')->andReturn($disabledGateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $disabled = app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 100,
        'currency' => 'LKR',
        'payment_method' => 'credit_card',
    ]));

    expect($unsupported->getStatusCode())->toBe(422)
        ->and($disabled->getStatusCode())->toBe(503)
        ->and(DB::table('payment_transactions')->count())->toBe(1);
});

it('keeps an uncertain provider initiation visible as a reserved pending attempt', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->once()->andReturnTrue();
    $gateway->shouldReceive('initiatePayment')->once()->andReturn([
        'success' => false,
        'payment_url' => '',
        'message' => 'Provider response did not confirm session creation',
    ]);
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('webxpay')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    $response = app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 500,
        'currency' => 'LKR',
        'payment_method' => 'webxpay',
    ]));
    $attempt = DB::table('payment_transactions')->where('transaction_id', $response->getData(true)['data']['transaction_id'])->first();

    expect($response->getStatusCode())->toBe(502)
        ->and($attempt->status)->toBe('pending')
        ->and((float) $attempt->requested_amount)->toBe(500.0);
});

it('rejects payment initiation outside the booking company scope', function () {
    [, $booking] = seedGatewayRefundFixture();
    $otherCompany = Company::query()->create(['name' => 'Other payment initiation company', 'is_default' => false]);
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

    expect(fn () => app(PaymentController::class)->initiatePayment(paymentInitiationRequest($otherUser, [
        'booking_id' => $booking->id,
        'amount' => 100,
        'currency' => 'LKR',
        'payment_method' => 'credit_card',
    ])))->toThrow(HttpResponseException::class);
    expect(DB::table('payment_transactions')->count())->toBe(1);
});

it('rejects payment initiation with a mismatched currency or amount above canonical due', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 1500]);
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->twice()->andReturnTrue();
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->twice()->with('credit_card')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);
    $controller = app(PaymentController::class);

    expect(fn () => $controller->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 600,
        'currency' => 'LKR',
        'payment_method' => 'credit_card',
    ])))->toThrow(HttpException::class);
    expect(fn () => $controller->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 100,
        'currency' => 'USD',
        'payment_method' => 'credit_card',
    ])))->toThrow(HttpException::class);
    expect(DB::table('payment_transactions')->count())->toBe(1);
});

it('reserves pending initiation amounts against the remaining canonical due', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid(), 'attempt_id' => (string) Str::uuid(), 'booking_id' => $booking->id,
        'amount' => 700, 'currency' => 'LKR', 'requested_amount' => 700, 'requested_currency' => 'LKR',
        'payment_method' => 'credit_card', 'gateway' => 'credit_card', 'status' => 'pending',
        'transaction_id' => 'TXN-PENDING-RESERVATION', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->once()->andReturnTrue();
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('credit_card')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    expect(fn () => app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 400,
        'currency' => 'LKR',
        'payment_method' => 'credit_card',
    ])))->toThrow(HttpException::class);
    expect(DB::table('payment_transactions')->count())->toBe(2);
});

it('fails closed when an older pending initiation has no source-currency reservation evidence', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid(), 'attempt_id' => (string) Str::uuid(), 'booking_id' => $booking->id,
        'amount' => 700, 'currency' => 'LKR', 'payment_method' => 'credit_card', 'gateway' => 'credit_card',
        'status' => 'pending', 'transaction_id' => 'TXN-LEGACY-PENDING', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->once()->andReturnTrue();
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('credit_card')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    expect(fn () => app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 100,
        'currency' => 'LKR',
        'payment_method' => 'credit_card',
    ])))->toThrow(HttpException::class);
    expect(DB::table('payment_transactions')->count())->toBe(2);
});

it('persists successful payment initiation with a canonical due reservation', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    $bookingId = $booking->id;
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->once()->andReturnTrue();
    $gateway->shouldReceive('initiatePayment')->once()->withArgs(function ($receivedBooking, float $amount, string $currency, array $options) use ($bookingId): bool {
        return $receivedBooking->id === $bookingId
            && $amount === 500.0
            && $currency === 'LKR'
            && ($options['payment_type'] ?? null) === 'full';
    })->andReturn([
        'success' => true,
        'payment_url' => 'https://payments.example.test/checkout',
        'transaction_id' => 'provider-order-1',
        'gateway_data' => [
            'amount' => 510, 'currency' => 'LKR', 'booking_amount' => 500,
            'booking_currency' => 'LKR', 'order_id' => 'provider-order-1',
            'fx_rate_to_lkr' => 1, 'secret_key' => 'must-not-persist',
        ],
    ]);
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('webxpay')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    $response = app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id,
        'amount' => 500,
        'currency' => 'LKR',
        'payment_method' => 'webxpay',
    ]));
    $record = DB::table('payment_transactions')->where('id', $response->getData(true)['data']['record_id'])->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($record->status)->toBe('pending')
        ->and((float) $record->requested_amount)->toBe(500.0)
        ->and($record->requested_currency)->toBe('LKR')
        ->and((float) $record->amount)->toBe(510.0)
        ->and($record->currency)->toBe('LKR')
        ->and((float) json_decode($record->gateway_payment_snapshot, true)['source_amount'])->toBe(500.0)
        ->and(str_contains($record->gateway_payment_snapshot, 'must-not-persist'))->toBeFalse()
        ->and($record->gateway_transaction_id)->toBe('provider-order-1');
});

it('keeps a payment pending when provider source facts contradict the reservation', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $booking->update(['total_estimated' => 2000]);
    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('isEnabled')->once()->andReturnTrue();
    $gateway->shouldReceive('initiatePayment')->once()->andReturn([
        'success' => true,
        'payment_url' => 'https://payments.example.test/checkout',
        'transaction_id' => 'provider-order-mismatch',
        'gateway_data' => [
            'amount' => 510, 'currency' => 'LKR', 'booking_amount' => 501,
            'booking_currency' => 'LKR', 'order_id' => 'provider-order-mismatch',
        ],
    ]);
    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldReceive('resolve')->once()->with('webxpay')->andReturn($gateway);
    $this->app->instance(PaymentGatewayManager::class, $manager);

    $response = app(PaymentController::class)->initiatePayment(paymentInitiationRequest($actor, [
        'booking_id' => $booking->id, 'amount' => 500, 'currency' => 'LKR', 'payment_method' => 'webxpay',
    ]));
    $record = DB::table('payment_transactions')->where('id', $response->getData(true)['data']['record_id'])->first();
    $secret = str_repeat('callback-test-key-', 2);
    Config::set('booking.payment_callback_secret', $secret);
    try {
        app(PaymentController::class)->paymentCallback(signedPaymentCallbackRequest($actor, [
            'transaction_id' => $record->transaction_id, 'status' => 'success', 'payment_id' => 'mismatched-source',
        ], $secret));
        $callbackException = null;
    } catch (HttpException $exception) {
        $callbackException = $exception;
    }

    expect($response->getStatusCode())->toBe(502)
        ->and($record->status)->toBe('pending')
        ->and(json_decode($record->gateway_payment_snapshot, true)['source_evidence_valid'])->toBeFalse()
        ->and($response->getData(true)['data']['status'])->toBe('pending')
        ->and($callbackException?->getStatusCode())->toBe(409)
        ->and(DB::table('payment_transactions')->where('id', $record->id)->value('status'))->toBe('pending')
        ->and(BookingPaymentReceipt::query()->where('provider_event_id', 'mismatched-source')->exists())->toBeFalse();
});

it('lists pending payment initiations by reservation evidence within authorized companies', function () {
    [$actor, $booking] = seedGatewayRefundFixture();
    $reservedId = (string) Str::uuid();
    $unverifiedId = (string) Str::uuid();
    foreach ([
        [
            'id' => $reservedId, 'attempt_id' => (string) Str::uuid(), 'transaction_id' => 'TXN-RESERVED-QUEUE',
            'amount' => 500, 'currency' => 'LKR', 'requested_amount' => 450, 'requested_currency' => 'LKR',
        ],
        [
            'id' => $unverifiedId, 'attempt_id' => (string) Str::uuid(), 'transaction_id' => 'TXN-UNKNOWN-QUEUE',
            'amount' => 500, 'currency' => 'LKR', 'requested_amount' => null, 'requested_currency' => null,
        ],
    ] as $row) {
        DB::table('payment_transactions')->insert($row + [
            'booking_id' => $booking->id,
            'payment_method' => 'credit_card',
            'gateway' => 'credit_card',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $controller = app(PaymentController::class);
    $unverifiedRequest = Request::create('/api/payment-transactions/initiations/reconciliation', 'GET', ['reservation_state' => 'unverified']);
    $unverifiedRequest->setUserResolver(fn () => $actor);
    $unverified = $controller->getPaymentInitiationReconciliationQueue($unverifiedRequest)->getData(true)['data'];
    $reservedRequest = Request::create('/api/payment-transactions/initiations/reconciliation', 'GET', ['reservation_state' => 'reserved']);
    $reservedRequest->setUserResolver(fn () => $actor);
    $reserved = $controller->getPaymentInitiationReconciliationQueue($reservedRequest)->getData(true)['data'];

    expect($unverified['total'])->toBe(1)
        ->and($unverified['data'][0]['id'])->toBe($unverifiedId)
        ->and($unverified['data'][0]['reservation_state'])->toBe('unverified')
        ->and($reserved['total'])->toBe(1)
        ->and($reserved['data'][0]['id'])->toBe($reservedId)
        ->and($reserved['data'][0]['reservation_state'])->toBe('reserved');

    $otherCompany = Company::query()->create(['name' => 'Other pending payment company', 'is_default' => false]);
    $otherUser = User::factory()->create();
    $otherStaff = Staff::factory()->create(['user_id' => $otherUser->id, 'company_id' => $otherCompany->id]);
    UserContext::query()->create([
        'user_id' => $otherUser->id,
        'context_type' => 'staff',
        'context_id' => $otherStaff->id,
        'is_active' => true,
        'created_user_id' => $otherUser->id,
    ]);
    $foreignRequest = Request::create('/api/payment-transactions/initiations/reconciliation', 'GET');
    $foreignRequest->setUserResolver(fn () => $otherUser);
    expect($controller->getPaymentInitiationReconciliationQueue($foreignRequest)->getData(true)['data']['data'])->toBeEmpty();
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

it('does not refund more than the canonical receipt component can represent', function () {
    [$actor, $booking, $transactionId] = seedGatewayRefundFixture();
    $componentId = DB::table('booking_payment_receipt_components')
        ->join('booking_payment_receipts', 'booking_payment_receipt_components.receipt_id', '=', 'booking_payment_receipts.id')
        ->where('booking_payment_receipts.booking_id', $booking->id)
        ->value('booking_payment_receipt_components.id');
    DB::table('booking_payment_receipt_components')->where('id', $componentId)
        ->update(['adjusted_source_amount' => 800]);

    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldNotReceive('resolve');
    $this->app->instance(PaymentGatewayManager::class, $manager);

    expect(fn () => app(PaymentController::class)->refundPayment(paymentRefundRequest($actor, [
        'amount' => 300,
        'reason' => 'Exceeds canonical receipt balance',
        'idempotency_key' => 'refund-canonical-component-cap',
    ]), $transactionId))->toThrow(HttpException::class);
    expect(DB::table('payment_refunds')->count())->toBe(0);
});

it('requires matching transaction and canonical receipt currencies before refunding', function () {
    [$actor, , $transactionId] = seedGatewayRefundFixture();
    DB::table('payment_transactions')->where('transaction_id', $transactionId)->update(['currency' => 'USD']);

    $manager = Mockery::mock(PaymentGatewayManager::class);
    $manager->shouldNotReceive('resolve');
    $this->app->instance(PaymentGatewayManager::class, $manager);

    expect(fn () => app(PaymentController::class)->refundPayment(paymentRefundRequest($actor, [
        'amount' => 100,
        'reason' => 'Currency mismatch',
        'idempotency_key' => 'refund-currency-mismatch',
    ]), $transactionId))->toThrow(HttpException::class);
    expect(DB::table('payment_refunds')->count())->toBe(0);
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
    $settledReceipt = BookingPaymentReceipt::query()->where('booking_id', $booking->id)
        ->where('provider_event_id', 'callback-provider-2')->firstOrFail();
    expect($first->getStatusCode())->toBe(200)
        ->and($replay->getStatusCode())->toBe(200)
        ->and(DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('status'))->toBe('success')
        ->and(BookingPaymentReceipt::query()->where('booking_id', $booking->id)->where('provider_event_id', 'callback-provider-2')->count())->toBe(1)
        ->and((float) $settledReceipt->source_amount)->toBe(1000.0)
        ->and($settledReceipt->source_currency)->toBe('LKR')
        ->and((float) $settledReceipt->fx_rate_to_lkr)->toBe(1.0);

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

it('keeps a successful callback pending when settlement snapshot evidence is missing', function () {
    [$actor, , $transactionId] = seedGatewayRefundFixture();
    DB::table('payment_transactions')->where('transaction_id', $transactionId)->update([
        'status' => 'pending', 'gateway_payment_snapshot' => null,
    ]);
    $secret = str_repeat('callback-test-key-', 2);
    Config::set('booking.payment_callback_secret', $secret);
    $controller = app(PaymentController::class);

    try {
        $controller->paymentCallback(signedPaymentCallbackRequest($actor, [
            'transaction_id' => $transactionId, 'status' => 'success', 'payment_id' => 'missing-snapshot',
        ], $secret));
        $exception = null;
    } catch (HttpException $exception) {
        // The callback must remain pending until the provider charge evidence is reconciled.
    }

    expect($exception?->getStatusCode())->toBe(409)
        ->and(DB::table('payment_transactions')->where('transaction_id', $transactionId)->value('status'))->toBe('pending')
        ->and(BookingPaymentReceipt::query()->where('provider_event_id', 'missing-snapshot')->exists())->toBeFalse();
});
