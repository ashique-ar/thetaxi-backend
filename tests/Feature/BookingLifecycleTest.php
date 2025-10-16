<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Booking\Booking;
use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Enums\BookingLifecycleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class BookingLifecycleTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private Vehicle $vehicle;
    private Driver $driver;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Create test vehicle
        $this->vehicle = Vehicle::factory()->create();

        // Create test driver
        $this->driver = Driver::factory()->create();

        // Create test booking
        $this->booking = Booking::factory()->create([
            'lifecycle_status' => BookingLifecycleStatus::BOOKING_CONFIRMED,
        ]);
    }

    /** @test */
    public function it_can_get_lifecycle_summary()
    {
        $this->actingAs($this->user);

        $response = $this->getJson("/api/booking-lifecycle/{$this->booking->id}/summary");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'status',
                     'data' => [
                         'currentStatus',
                         'stages',
                         'timeline',
                     ],
                     'message'
                 ]);
    }

    /** @test */
    public function it_can_get_available_inspectors()
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/booking-lifecycle/inspectors/available');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'status',
                     'data',
                     'message'
                 ]);
    }

    /** @test */
    public function it_requires_authentication_for_lifecycle_endpoints()
    {
        $response = $this->getJson("/api/booking-lifecycle/{$this->booking->id}/summary");
        $response->assertStatus(401);

        $response = $this->postJson('/api/booking-lifecycle/dispatch-vehicle', [
            'booking_id' => $this->booking->id,
        ]);
        $response->assertStatus(401);
    }

    /** @test */
    public function it_validates_required_fields_for_dispatch()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/booking-lifecycle/dispatch-vehicle', [
            'booking_id' => $this->booking->id,
            // Missing required fields
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors([
                     'vehicle_condition_notes',
                     'fuel_level',
                     'mileage',
                     'handover_time',
                     'handover_location'
                 ]);
    }

    /** @test */
    public function it_validates_required_fields_for_qc_inspection()
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/booking-lifecycle/complete-qc-inspection', [
            'booking_id' => $this->booking->id,
            // Missing required fields
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors([
                     'cleanliness_rating',
                     'fuel_level',
                     'mileage',
                     'interior_condition',
                     'exterior_condition',
                     'mechanical_condition',
                     'repair_required',
                 ]);
    }
}
