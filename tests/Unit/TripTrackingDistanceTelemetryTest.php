<?php

namespace Tests\Unit;

use App\Models\DriverAssignment;
use App\Services\Driver\TripTrackingService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class TripTrackingDistanceTelemetryTest extends TestCase
{
    public function test_fewer_than_two_route_points_is_missing_telemetry_not_zero_distance(): void
    {
        $service = (new ReflectionClass(TripTrackingService::class))->newInstanceWithoutConstructor();

        self::assertNull($service->calculateTripDistance($this->assignmentWithPoints([])));
        self::assertNull($service->calculateTripDistance($this->assignmentWithPoints([
            ['latitude' => 6.927079, 'longitude' => 79.861244],
        ])));
    }

    public function test_two_valid_identical_route_points_are_a_measured_zero_distance(): void
    {
        $service = (new ReflectionClass(TripTrackingService::class))->newInstanceWithoutConstructor();
        $assignment = $this->assignmentWithPoints([
            ['latitude' => 6.927079, 'longitude' => 79.861244],
            ['latitude' => 6.927079, 'longitude' => 79.861244],
        ]);

        self::assertSame(0.0, $service->calculateTripDistance($assignment));
    }

    /** @param array<int, array{latitude: float, longitude: float}> $points */
    private function assignmentWithPoints(array $points): DriverAssignment
    {
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('orderBy')
            ->once()
            ->with('recorded_at', 'asc')
            ->andReturnSelf();
        $relation->shouldReceive('get')
            ->once()
            ->with(['latitude', 'longitude'])
            ->andReturn(collect(array_map(
                fn (array $point): object => (object) $point,
                $points
            )));

        $assignment = Mockery::mock(DriverAssignment::class)->makePartial();
        $assignment->shouldReceive('routePoints')->once()->andReturn($relation);

        return $assignment;
    }
}
