<?php

use App\Helpers\BookingLinkHelper;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Service\ServiceType;
use App\Models\Website\PendingPaymentLink;
use App\Services\PendingPaymentManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

it('uses the public website for the quotation acceptance link', function () {
    config(['app.url' => 'https://public.example/']);
    URL::forceRootUrl('https://staff-api.example');

    $quote = new PendingPaymentLink(['token' => 'offer-token']);

    expect(BookingLinkHelper::getQuotationCheckoutLink($quote))
        ->toBe('https://public.example/checkout/quotation-convert/offer-token');
});

it('includes only general and selected service terms for a quotation', function () {
    Schema::create('terms_and_conditions', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('service_type_id')->nullable();
        $table->string('service_type')->nullable();
        $table->string('payment_type')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('display_order')->default(0);
        $table->softDeletes();
    });

    $rows = [
        ['General', null, 'general', null],
        ['Selected by ID', 'selected', null, 'quotation'],
        ['Selected by legacy code', null, 'rental', null],
        ['Another legacy service', null, 'transfer', null],
        ['Another ID', 'other', 'rental', null],
        ['Payment-only terms', 'selected', null, 'full'],
    ];
    foreach ($rows as $index => [$title, $id, $code, $payment]) {
        DB::table('terms_and_conditions')->insert([
            'title' => $title, 'service_type_id' => $id, 'service_type' => $code,
            'payment_type' => $payment, 'display_order' => $index,
        ]);
    }

    $item = new BookingItem(['service_type_id' => 'selected']);
    $item->setRelation('serviceType', new ServiceType(['code' => 'rental']));
    $booking = (new Booking)->setRelation('bookingItems', new \Illuminate\Database\Eloquent\Collection([$item]));

    expect(array_column(PendingPaymentManager::quotationTermsSnapshot($booking), 'title'))
        ->toBe(['General', 'Selected by ID', 'Selected by legacy code']);
});

it('renders terms as readable text with line breaks and decoded entities', function () {
    expect(PendingPaymentManager::quotationTermText(
        '<p>Deposit &amp; balance</p>&lt;p&gt;Bring ID&lt;br&gt;before collection&lt;/p&gt;<script>ignored</script>'
    ))->toBe("Deposit & balance\nBring ID\nbefore collection");
});

it('keeps ordered and nested list structure in quotation terms', function () {
    expect(PendingPaymentManager::quotationTermText(
        '<p>Payment terms</p><ol start="2" type="A"><li>Pay the rental fee<ul><li>Before pickup</li><li>Keep the receipt</li></ul></li><li>Bring ID</li></ol>'
    ))->toBe("Payment terms\nB. Pay the rental fee\n  • Before pickup\n  • Keep the receipt\nC. Bring ID");
});

it('keeps list numbering and indentation in quotation terms', function () {
    expect(PendingPaymentManager::quotationTermText(
        '<p>Payment terms</p><ol start="2" type="A"><li>Pay the rental fee<ul><li>Before pickup</li><li>Keep the receipt</li></ul></li><li>Bring ID</li></ol>'
    ))->toBe("Payment terms\nB. Pay the rental fee\n  • Before pickup\n  • Keep the receipt\nC. Bring ID");
});

it('binds the saved refundable deposit amount to the quotation snapshot', function () {
    $item = new BookingItem(['metadata' => ['refundable_deposit_lkr' => 2500.50]]);
    $booking = new class extends Booking {
        protected $table = 'bookings';

        public function primaryItem(): ?BookingItem
        {
            return $this->bookingItems->first();
        }
    };
    $booking->setRelation('bookingItems', collect([$item]));

    expect(BookingLinkHelper::quotationSnapshot($booking)['items'][0]['refundable_deposit_lkr'])
        ->toBe('2500.50');

    $hash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode(BookingLinkHelper::quotationSnapshot($booking)));
    expect(BookingLinkHelper::matchesQuotationSnapshot($booking, $hash))->toBeTrue();

    $item->metadata = [];
    expect(BookingLinkHelper::quotationSnapshot($booking)['items'][0])
        ->not->toHaveKey('refundable_deposit_lkr');
    expect(BookingLinkHelper::matchesQuotationSnapshot($booking, $hash))->toBeFalse();

    $legacyHash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode(BookingLinkHelper::quotationSnapshot($booking)));
    $item->metadata = ['refundable_deposit_lkr' => 2500.50];
    expect(BookingLinkHelper::matchesQuotationSnapshot($booking, $legacyHash))->toBeTrue();
});

it('formats extra km rate labels in quotation snapshots, option groups, emails, and acceptance views', function () {
    expect(BookingLinkHelper::quotationExtraKmRateLabel(['extra_km_price' => 150], 'LKR'))
        ->toBe('150 LKR/km');
    expect(BookingLinkHelper::quotationExtraKmRateLabel([], 'LKR'))
        ->toBeNull();

    $serviceType = new ServiceType(['name' => 'Day Rental', 'code' => 'day_rental', 'pricing_mode' => 'day']);
    $item = new BookingItem([
        'vehicle_group_id' => 'vg-1',
        'service_type_id' => 'st-1',
        'duration_days' => 2,
        'unit_price' => 25000,
        'total_price' => 25000,
        'metadata' => [
            'distance_details' => [
                'free_km_per_day' => 100,
                'allowed_total_km' => 200,
                'extra_km_price' => 150,
            ],
        ],
    ]);
    $item->id = 'item-1';
    $item->setRelation('serviceType', $serviceType);
    $item->setRelation('vehicleGroup', new \App\Models\Vehicle\VehicleGroup(['name' => 'Sedan']));

    $booking = new class extends Booking {
        protected $table = 'bookings';

        public function primaryItem(): ?BookingItem
        {
            return $this->bookingItems->first();
        }
    };
    $booking->id = 'booking-1';
    $booking->booking_number = 'QT20260001';
    $booking->currency = 'LKR';
    $booking->total_estimated = 25000;
    $booking->setRelation('bookingItems', new \Illuminate\Database\Eloquent\Collection([$item]));

    $snapshot = BookingLinkHelper::quotationDisplaySnapshot($booking);
    expect($snapshot['items'][0]['extra_km_rate_label'])->toBe('150 LKR/km');

    $acceptanceSource = file_get_contents(resource_path('views/checkout/quotation-acceptance.blade.php'));
    $staffEmailSource = file_get_contents(resource_path('views/emails/quotation-request.blade.php'));
    $confirmationSource = file_get_contents(resource_path('views/emails/quotation-request-confirmation.blade.php'));
    $notificationSource = file_get_contents(resource_path('views/emails/quotation-request-notification.blade.php'));
    $paymentResumeSource = file_get_contents(resource_path('views/checkout/payment-resume.blade.php'));

    expect($acceptanceSource)->toContain('Extra KM Rate:')
        ->and($acceptanceSource)->toContain('extra_km_rate_label')
        ->and($staffEmailSource)->toContain('Extra KM Rate:')
        ->and($confirmationSource)->toContain('Extra KM Rate')
        ->and($notificationSource)->toContain('Extra KM Rate')
        ->and($paymentResumeSource)->toContain('Extra KM Rate');

    \Illuminate\Support\Facades\Blade::compileString($acceptanceSource);
    \Illuminate\Support\Facades\Blade::compileString($staffEmailSource);
    \Illuminate\Support\Facades\Blade::compileString($confirmationSource);
    \Illuminate\Support\Facades\Blade::compileString($notificationSource);
    \Illuminate\Support\Facades\Blade::compileString($paymentResumeSource);
});
