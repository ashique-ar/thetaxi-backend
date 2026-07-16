<?php

namespace Tests\Unit;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\InvoiceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class InvoiceFinalPricingLineItemTest extends TestCase
{
    public function test_extra_km_detail_does_not_increase_an_already_final_item_total(): void
    {
        $booking = new Booking(['currency' => 'LKR']);
        $item = new BookingItem([
            'quantity' => 1,
            'unit_price' => 1500,
            'total_price' => 1500,
            'customizations' => [[
                'type' => 'extra_km',
                'quantity' => 10,
                'rate_per_km' => 20,
                'total_cost' => 200,
            ]],
            'pricing_breakdown' => [
                'final_pricing' => [
                    'total_amount' => 1500,
                    'audit' => ['final_base' => 1500],
                ],
            ],
        ]);

        $booking->setRelation('bookingItems', new Collection([$item]));
        $booking->setRelation('bookingAddons', new Collection());

        $service = new InvoiceService(new ContractualDistanceSnapshotProjector());
        $method = new ReflectionMethod($service, 'buildLineItems');
        $lineItems = $method->invoke($service, $booking, 'LKR');

        self::assertSame(1500.0, (float) collect($lineItems)->sum('amount'));
    }

    public function test_cancelled_or_rejected_items_are_not_billed_on_the_aggregate_invoice(): void
    {
        $booking = new Booking(['currency' => 'LKR']);
        $completed = new BookingItem([
            'status' => 'completed',
            'quantity' => 1,
            'unit_price' => 1000,
            'total_price' => 1000,
        ]);
        $cancelled = new BookingItem([
            'status' => 'cancelled',
            'quantity' => 1,
            'unit_price' => 750,
            'total_price' => 750,
        ]);
        $rejected = new BookingItem([
            'status' => 'rejected',
            'quantity' => 1,
            'unit_price' => 500,
            'total_price' => 500,
        ]);

        $booking->setRelation(
            'bookingItems',
            new Collection([$completed, $cancelled, $rejected])
        );
        $booking->setRelation('bookingAddons', new Collection());

        $service = new InvoiceService(new ContractualDistanceSnapshotProjector());
        $method = new ReflectionMethod($service, 'buildLineItems');
        $lineItems = $method->invoke($service, $booking, 'LKR');

        self::assertCount(1, $lineItems);
        self::assertSame(1000.0, (float) collect($lineItems)->sum('amount'));
    }

    public function test_booking_aggregate_total_excludes_cancelled_and_rejected_items(): void
    {
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('driver_cost', 12, 2)->default(0);
            $table->decimal('distance_cost', 12, 2)->default(0);
            $table->decimal('addons_cost', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('gamify_discount_applied', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->string('status')->nullable();
            $table->decimal('total_price', 12, 2)->default(0);
            $table->json('addons')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        try {
            $bookingId = (string) Str::uuid();
            DB::table('bookings')->insert([
                'id' => $bookingId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([
                ['status' => 'completed', 'total_price' => 1000],
                ['status' => 'cancelled', 'total_price' => 750],
                ['status' => 'rejected', 'total_price' => 500],
            ] as $item) {
                DB::table('booking_items')->insert($item + [
                    'id' => (string) Str::uuid(),
                    'booking_id' => $bookingId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $booking = Booking::query()->with('bookingItems')->findOrFail($bookingId);

            self::assertSame(1000.0, $booking->calculateTotal());
        } finally {
            Schema::dropIfExists('booking_items');
            Schema::dropIfExists('bookings');
        }
    }
}
