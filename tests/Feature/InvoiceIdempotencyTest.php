<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Invoice;
use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\InvoiceService;
use App\Services\MailDispatchService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    activity()->disableLogging();

    foreach ([
        'invoices',
        'booking_common_rate_pricings',
        'booking_addons',
        'booking_items',
        'bookings',
        'website_settings',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('customer_id')->nullable();
        $table->string('booking_number')->nullable();
        $table->string('invoice_number')->nullable();
        $table->string('currency')->nullable();
        $table->decimal('base_amount', 12, 2)->nullable();
        $table->decimal('total_estimated', 12, 2)->nullable();
        $table->decimal('discount_amount', 12, 2)->nullable();
        $table->json('pricing_snapshot')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('service_type_id')->nullable();
        $table->uuid('vehicle_id')->nullable();
        $table->uuid('driver_id')->nullable();
        $table->dateTime('from_date')->nullable();
        $table->dateTime('to_date')->nullable();
        $table->unsignedInteger('quantity')->default(1);
        $table->decimal('unit_price', 12, 2)->nullable();
        $table->decimal('total_price', 12, 2)->nullable();
        $table->json('pricing_breakdown')->nullable();
        $table->json('customizations')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_addons', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('addon_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_common_rate_pricings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('website_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->text('value')->nullable();
        $table->uuid('company_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('invoice_number')->unique();
        $table->uuid('booking_id');
        $table->uuid('customer_id')->nullable();
        $table->string('customer_name');
        $table->string('customer_email')->nullable();
        $table->string('customer_phone')->nullable();
        $table->text('customer_address')->nullable();
        $table->string('currency', 10)->default('LKR');
        $table->decimal('subtotal', 12, 2)->default(0);
        $table->decimal('discount_amount', 12, 2)->default(0);
        $table->decimal('tax_amount', 12, 2)->default(0);
        $table->decimal('total_amount', 12, 2)->default(0);
        $table->json('line_items');
        $table->string('status')->default('issued');
        $table->date('issue_date');
        $table->date('due_date')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->string('pdf_path')->nullable();
        $table->string('pdf_disk')->nullable()->default('local');
        $table->text('payment_terms')->nullable();
        $table->text('notes')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $migration = require database_path('migrations/2026_07_16_000010_harden_invoice_idempotency_and_delivery.php');
    $migration->up();
    $this->invoiceMigration = $migration;

    Storage::fake('local');
    Event::forget('composing: *');
    $this->invoiceService = new InvoiceService(new ContractualDistanceSnapshotProjector());
});

it('keeps one active invoice per booking across generation retries and reissues', function () {
    $booking = Booking::create([
        'currency' => 'LKR',
        'total_estimated' => 2500,
    ]);
    BookingItem::create([
        'booking_id' => $booking->id,
        'quantity' => 1,
        'unit_price' => 2500,
        'total_price' => 2500,
    ]);

    $first = $this->invoiceService->generateForBooking($booking);
    $retry = $this->invoiceService->generateForBooking($booking->fresh());

    expect($retry->id)->toBe($first->id)
        ->and(Invoice::where('booking_id', $booking->id)->count())->toBe(1)
        ->and($booking->fresh()->invoice_number)->toBe($first->invoice_number)
        ->and($first->fresh()->pdf_path)->not->toBeNull();
    Storage::disk($first->fresh()->pdf_disk ?? 'local')->assertExists($first->fresh()->pdf_path);

    expect(fn () => Invoice::create([
        'invoice_number' => 'INV-DUPLICATE-TEST',
        'booking_id' => $booking->id,
        'customer_name' => 'Duplicate',
        'currency' => 'LKR',
        'line_items' => [],
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
    ]))->toThrow(QueryException::class);

    $this->invoiceService->void($first, 'Reissue test');
    $reissued = $this->invoiceService->generateForBooking($booking->fresh());

    expect($reissued->id)->not->toBe($first->id)
        ->and(Invoice::where('booking_id', $booking->id)->where('status', '!=', 'void')->count())->toBe(1)
        ->and(Invoice::where('booking_id', $booking->id)->count())->toBe(2)
        ->and($booking->fresh()->invoice_number)->toBe($reissued->invoice_number);
});

it('reconciles legacy duplicates without voiding the paid invoice', function () {
    $this->invoiceMigration->down();

    $booking = Booking::create(['currency' => 'LKR']);
    $paid = Invoice::create([
        'invoice_number' => 'INV-PAID-CANONICAL',
        'booking_id' => $booking->id,
        'customer_name' => 'Paid invoice',
        'currency' => 'LKR',
        'line_items' => [],
        'status' => 'paid',
        'issue_date' => now()->toDateString(),
        'paid_at' => now(),
    ]);
    $issued = Invoice::create([
        'invoice_number' => 'INV-NEWER-ISSUED',
        'booking_id' => $booking->id,
        'customer_name' => 'Issued duplicate',
        'currency' => 'LKR',
        'line_items' => [],
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
    ]);
    $booking->update(['invoice_number' => $issued->invoice_number]);

    $this->invoiceMigration->up();

    expect($paid->fresh()->status)->toBe('paid')
        ->and($issued->fresh()->status)->toBe('void')
        ->and($booking->fresh()->invoice_number)->toBe($paid->invoice_number);
});

it('delivers only after commit and suppresses duplicate email retries', function () {
    $booking = Booking::create(['currency' => 'LKR']);
    $invoice = Invoice::create([
        'invoice_number' => 'INV-DELIVERY-TEST',
        'booking_id' => $booking->id,
        'customer_name' => 'Delivery Test',
        'customer_email' => 'customer@example.test',
        'currency' => 'LKR',
        'line_items' => [],
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
    ]);

    $deliveryCalls = 0;
    $dispatcher = Mockery::mock(MailDispatchService::class);
    $dispatcher->shouldReceive('sendToCustomer')
        ->once()
        ->andReturnUsing(function () use (&$deliveryCalls) {
            $deliveryCalls++;
        });
    app()->instance(MailDispatchService::class, $dispatcher);

    DB::beginTransaction();
    $this->invoiceService->sendToCustomer($invoice);
    expect($deliveryCalls)->toBe(0);
    DB::rollBack();
    expect($deliveryCalls)->toBe(0);

    DB::beginTransaction();
    $this->invoiceService->sendToCustomer($invoice->fresh());
    expect($deliveryCalls)->toBe(0);
    DB::commit();

    $delivered = $invoice->fresh();
    expect($deliveryCalls)->toBe(1)
        ->and($delivered->email_sent_at)->not->toBeNull()
        ->and($delivered->email_attempts)->toBe(1);

    $this->invoiceService->sendToCustomer($delivered);
    expect($deliveryCalls)->toBe(1)
        ->and($invoice->fresh()->email_attempts)->toBe(1);
});

it('does not create the invoice document until final pricing transaction commits', function () {
    $booking = Booking::create([
        'currency' => 'LKR',
        'total_estimated' => 900,
    ]);
    BookingItem::create([
        'booking_id' => $booking->id,
        'quantity' => 1,
        'unit_price' => 900,
        'total_price' => 900,
    ]);

    DB::beginTransaction();
    $this->invoiceService->generateAndSend($booking);
    expect(Invoice::where('booking_id', $booking->id)->exists())->toBeFalse();
    DB::rollBack();
    expect(Invoice::where('booking_id', $booking->id)->exists())->toBeFalse();

    DB::beginTransaction();
    $this->invoiceService->generateAndSend($booking->fresh());
    expect(Invoice::where('booking_id', $booking->id)->exists())->toBeFalse();
    DB::commit();

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});
