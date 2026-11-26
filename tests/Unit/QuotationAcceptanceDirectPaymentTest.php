<?php

use App\Helpers\BookingLinkHelper;
use App\Models\Booking\Booking;
use App\Models\Website\PendingPaymentLink;
use App\Services\MailDispatchService;
use App\Services\WebXPayService;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function setupQuotationDirectPaymentSchema(): void
{
    Schema::create('users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('customers', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('service_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->string('code')->nullable();
        $table->boolean('requires_refundable_deposit')->default(false);
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('vehicle_groups', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->decimal('refundable_deposit', 12, 2)->default(0);
        $table->json('images')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('reference_number_registry', function (Blueprint $table): void {
        $table->id();
        $table->string('reference_code')->unique();
        $table->string('reference_type')->nullable();
        $table->uuid('corporate_id')->nullable();
        $table->timestamps();
    });

    Schema::create('booking_history_events', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id')->nullable();
        $table->uuid('booking_item_id')->nullable();
        $table->string('entity_type')->nullable();
        $table->uuid('entity_id')->nullable();
        $table->string('event_type')->nullable();
        $table->string('actor_type')->nullable();
        $table->uuid('actor_user_id')->nullable();
        $table->string('actor_display_snapshot')->nullable();
        $table->string('source')->nullable();
        $table->string('reason')->nullable();
        $table->json('changes')->nullable();
        $table->json('context')->nullable();
        $table->string('correlation_id')->nullable();
        $table->timestamp('occurred_at')->nullable();
        $table->string('provenance')->nullable();
        $table->timestamps();
    });

    Schema::create('bookings', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('customer_id')->nullable();
        $table->string('booking_number')->nullable();
        $table->string('status')->nullable();
        $table->string('payment_status')->nullable();
        $table->string('payment_type')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('payment_gateway_order_id')->nullable();
        $table->string('currency')->default('LKR');
        $table->decimal('base_amount', 12, 2)->default(0);
        $table->decimal('addons_cost', 12, 2)->default(0);
        $table->decimal('service_fee', 12, 2)->default(0);
        $table->decimal('tax_amount', 12, 2)->default(0);
        $table->decimal('vat_amount', 12, 2)->default(0);
        $table->decimal('discount_amount', 12, 2)->default(0);
        $table->decimal('total_estimated', 12, 2)->default(0);
        $table->decimal('total_actual', 12, 2)->default(0);
        $table->decimal('amount_to_pay', 12, 2)->default(0);
        $table->decimal('amount_paid', 12, 2)->default(0);
        $table->json('pricing_snapshot')->nullable();
        $table->json('workflow_data')->nullable();
        $table->text('notes')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('booking_items', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('service_type_id')->nullable();
        $table->uuid('vehicle_group_id')->nullable();
        $table->string('from_date')->nullable();
        $table->string('to_date')->nullable();
        $table->string('from_time')->nullable();
        $table->string('to_time')->nullable();
        $table->integer('duration_days')->default(1);
        $table->integer('quantity')->default(1);
        $table->decimal('unit_price', 12, 2)->default(0);
        $table->decimal('total_price', 12, 2)->default(0);
        $table->json('pickup_location')->nullable();
        $table->json('dropoff_location')->nullable();
        $table->json('metadata')->nullable();
        $table->json('pricing_breakdown')->nullable();
        $table->json('workflow_config_snapshot')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('booking_addons', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id')->nullable();
        $table->uuid('booking_item_id')->nullable();
        $table->string('addon_name')->nullable();
        $table->integer('quantity')->default(1);
        $table->decimal('unit_price', 12, 2)->default(0);
        $table->decimal('total_price', 12, 2)->default(0);
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('pending_payment_links', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('token')->unique();
        $table->string('type');
        $table->json('booking_context')->nullable();
        $table->decimal('amount_due', 12, 2)->default(0);
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('accessed_at')->nullable();
        $table->integer('access_count')->default(0);
        $table->timestamp('invalidated_at')->nullable();
        $table->timestamp('accepted_at')->nullable();
        $table->unsignedInteger('revision_number')->default(1);
        $table->json('acceptance_evidence')->nullable();
        $table->json('revision_item_ids')->nullable();
        $table->decimal('revision_total', 12, 2)->nullable();
        $table->decimal('revision_amount_due', 12, 2)->nullable();
        $table->json('revision_history')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->uuid('notifiable_id');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    Schema::create('business_settings', function (Blueprint $table): void {
        $table->id();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('website_settings', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->string('data_type')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('terms_and_conditions', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('version')->nullable();
        $table->text('content')->nullable();
        $table->string('service_type_id')->nullable();
        $table->string('service_type')->nullable();
        $table->string('payment_type')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('display_order')->default(0);
        $table->softDeletes();
    });
}

it('redirects directly from quotation acceptance to WebXPay and supports cancel retry', function () {
    setupQuotationDirectPaymentSchema();
    activity()->disableLogging();

    $serviceTypeId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::table('service_types')->insert([
        'id' => $serviceTypeId,
        'name' => 'Day Rental',
        'code' => 'day_rental',
        'requires_refundable_deposit' => false,
    ]);

    $vehicleGroupId = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::table('vehicle_groups')->insert([
        'id' => $vehicleGroupId,
        'name' => 'Sedan',
        'refundable_deposit' => 0,
    ]);

    $booking = Booking::query()->create([
        'id' => (string) Str::uuid(),
        'booking_number' => 'QT20260099',
        'status' => config('booking.status.quotation_requested'),
        'payment_status' => 'pending',
        'currency' => 'LKR',
        'base_amount' => 15000,
        'total_estimated' => 15000,
        'total_actual' => 15000,
        'amount_to_pay' => 15000,
        'amount_paid' => 0,
    ]);

    \App\Models\Booking\BookingItem::query()->create([
        'id' => (string) Str::uuid(),
        'booking_id' => $booking->id,
        'service_type_id' => $serviceTypeId,
        'vehicle_group_id' => $vehicleGroupId,
        'from_date' => '2026-12-01',
        'to_date' => '2026-12-02',
        'from_time' => '09:00',
        'to_time' => '18:00',
        'duration_days' => 1,
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
    ]);

    $snapshot = BookingLinkHelper::quotationSnapshot($booking);
    $snapshotHash = hash('sha256', CanonicalJson::encode($snapshot));
    $terms = [];
    $termsHash = hash('sha256', CanonicalJson::encode($terms));

    $quote = PendingPaymentLink::query()->create([
        'id' => (string) Str::uuid(),
        'booking_id' => $booking->id,
        'token' => 'quote-token-direct-pay',
        'type' => 'quotation_conversion',
        'booking_context' => [
            'quotation' => [
                'snapshot_hash' => $snapshotHash,
                'snapshot' => $snapshot,
                'terms' => $terms,
                'terms_hash' => $termsHash,
            ],
        ],
        'amount_due' => 15000,
        'expires_at' => now()->addDays(7),
        'revision_number' => 1,
    ]);

    $webxPayMock = Mockery::mock(WebXPayService::class);
    $webxPayMock->shouldReceive('isEnabled')->andReturn(true);
    $webxPayMock->shouldReceive('createPayment')->once()->andReturn([
        'success' => true,
        'method' => 'rsa_redirect',
        'payment_url' => 'https://webxpay.example/pay',
        'order_id' => 'QT20260099-1234567890',
        'amount' => 15000.0,
        'currency' => 'LKR',
        'booking_amount' => 15000.0,
        'booking_currency' => 'LKR',
        'encrypted_payment' => 'enc',
        'secret_key' => 'sec',
        'custom_fields' => 'cf',
        'enc_method' => 'rsa',
        'customer_data' => [],
    ]);
    app()->instance(WebXPayService::class, $webxPayMock);

    $mailMock = Mockery::mock(MailDispatchService::class);
    $mailMock->shouldIgnoreMissing();
    app()->instance(MailDispatchService::class, $mailMock);

    $response = $this->withoutMiddleware()->post(route('checkout.quotation-accept', ['token' => $quote->token]), [
        'accept_terms' => '1',
        'terms_hash' => $termsHash,
    ]);

    expect(session('error'))->toBeNull();
    $response->assertRedirect(route('checkout.webxpay.redirect'));
    expect(session('pending_booking_id'))->toBe($booking->id)
        ->and(session('payment_link_token'))->not->toBeEmpty()
        ->and(session('webxpay_payment_data.order_id'))->toBe('QT20260099-1234567890');

    $booking->refresh();
    expect($booking->status)->toBe(config('booking.status.payment_processing'));

    $paymentToken = session('payment_link_token');

    // Re-opening the quotation link while payment is pending redirects to payment-resume for recovery
    $this->get(route('checkout.quotation-convert', ['token' => $quote->token]))
        ->assertRedirect(route('checkout.payment-resume', ['token' => $paymentToken]));

    // Cancelling at WebXPay redirects to payment-resume to allow retry instead of empty checkout
    $this->get(route('checkout.webxpay.cancel'))
        ->assertRedirect(route('checkout.payment-resume', ['token' => $paymentToken]));
});
