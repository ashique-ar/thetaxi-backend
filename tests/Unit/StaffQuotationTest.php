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
