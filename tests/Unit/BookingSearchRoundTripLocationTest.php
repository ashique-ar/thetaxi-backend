<?php

namespace Tests\Unit;

use App\Http\Controllers\BookingController;
use ReflectionMethod;
use Tests\TestCase;

class BookingSearchRoundTripLocationTest extends TestCase
{
    public function test_unchanged_resubmission_reuses_the_previously_priced_route_identity(): void
    {
        $current = [
            'pickup_location' => [
                'address' => 'Bandaranaike International Airport (Airport)',
                'latitude' => null,
                'longitude' => null,
            ],
            'dropoff_location' => [
                'address' => 'Galle, Sri Lanka',
                'latitude' => 7.180756,
                'longitude' => 79.884117,
            ],
        ];
        $previous = [
            'pickup_location' => [
                'address' => 'Bandaranaike International Airport',
                'latitude' => 7.180756,
                'longitude' => 79.884117,
            ],
            'dropoff_location' => [
                'address' => 'Galle, Sri Lanka',
                'latitude' => 6.0328948,
                'longitude' => 80.2167912,
            ],
        ];

        $method = new ReflectionMethod(BookingController::class, 'preserveUnchangedSearchLocations');
        $result = $method->invoke(app(BookingController::class), $current, $previous);

        $this->assertSame($previous['pickup_location'], $result['pickup_location']);
        $this->assertSame($previous['dropoff_location'], $result['dropoff_location']);
    }

    public function test_changed_address_never_reuses_previous_coordinates(): void
    {
        $current = [
            'dropoff_location' => [
                'address' => 'Kandy, Sri Lanka',
                'latitude' => 7.290572,
                'longitude' => 80.633728,
            ],
        ];
        $previous = [
            'dropoff_location' => [
                'address' => 'Galle, Sri Lanka',
                'latitude' => 6.0328948,
                'longitude' => 80.2167912,
            ],
        ];

        $method = new ReflectionMethod(BookingController::class, 'preserveUnchangedSearchLocations');
        $result = $method->invoke(app(BookingController::class), $current, $previous);

        $this->assertSame($current['dropoff_location'], $result['dropoff_location']);
    }
}
