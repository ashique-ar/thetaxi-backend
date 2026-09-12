<?php

namespace Tests\Unit;

use App\Models\Booking\BookingItem;
use App\Services\BookingFlowService;
use App\Http\Resources\Booking\BookingFlowResource;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class BookingItemPriceOverrideTest extends TestCase
{
    private function resolve(array $payload, float $calculated, ?BookingItem $existing = null): array
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'resolveItemPriceOverride');

        return $method->invoke($service, $payload, $calculated, $existing);
    }

    public function test_each_trip_can_increase_or_decrease_with_a_reason(): void
    {
        $increase = $this->resolve(['final_price' => 1250, 'price_adjustment_reason' => 'Peak demand'], 1000);
        $decrease = $this->resolve(['final_price' => 800, 'price_adjustment_reason' => 'Customer recovery'], 1000);

        self::assertSame(1250.0, $increase['effective_price']);
        self::assertSame(800.0, $decrease['effective_price']);
        self::assertSame('Peak demand', $increase['columns']['price_override_reason']);
        self::assertSame('Customer recovery', $decrease['columns']['price_override_reason']);
    }

    public function test_changed_trip_price_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve(['final_price' => 900], 1000);
    }

    public function test_invalid_trip_price_is_rejected_instead_of_becoming_zero(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve(['final_price' => 'not-a-price', 'price_adjustment_reason' => 'Invalid'], 1000);
    }

    public function test_omitted_price_preserves_an_existing_agreed_price(): void
    {
        $existing = new BookingItem([
            'price_override_amount' => 850,
            'price_override_reason' => 'Agreed quote',
        ]);

        $result = $this->resolve([], 1100, $existing);

        self::assertSame(850.0, $result['effective_price']);
        self::assertSame('Agreed quote', $result['reason']);
    }

    public function test_an_existing_override_can_be_changed_again(): void
    {
        $existing = new BookingItem([
            'total_price' => 850,
            'price_override_amount' => 850,
            'price_override_reason' => 'First agreement',
        ]);

        $result = $this->resolve([
            'final_price' => 925.75,
            'price_adjustment_reason' => 'Revised agreement',
        ], 1000, $existing);

        self::assertSame(925.75, $result['effective_price']);
        self::assertTrue($result['changed']);
        self::assertSame('Revised agreement', $result['reason']);
    }

    public function test_normal_booking_resources_remove_original_price_values(): void
    {
        $resource = (new ReflectionClass(BookingFlowResource::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($resource, 'withoutOriginalPrices');

        $result = $method->invoke($resource, [
            'total_price' => 850,
            'original_price' => 1000,
            'nested' => ['price_before_customizations' => 1000, 'final_price' => 850],
        ]);

        self::assertSame(['total_price' => 850, 'nested' => []], $result);
    }

    public function test_completion_preserves_the_agreed_item_price(): void
    {
        $source = file_get_contents(app_path('Services/BookingLifecycleService.php'));

        self::assertStringContainsString("if (\$bookingItem->price_override_amount !== null)", $source);
        self::assertStringContainsString("\$finalBase = (float) \$bookingItem->price_override_amount", $source);
    }
}
