<?php

namespace Tests\Unit;

use Tests\TestCase;

class BookingOldInputPrecedenceContractTest extends TestCase
{
    public function test_booking_form_restores_old_location_date_and_time_aliases_before_defaults(): void
    {
        $form = file_get_contents(resource_path('views/components/booking-form.blade.php'));

        $this->assertStringContainsString("['pickup_location', 'pickup', 'from', 'origin']", $form);
        $this->assertStringContainsString("['dropoff_location', 'dropoff', 'to', 'destination']", $form);
        $this->assertStringContainsString("['from_date', 'pickup_date', 'date']", $form);
        $this->assertStringContainsString("['from_time', 'pickup_time', 'time']", $form);
        $this->assertStringContainsString('return $oldValue;', $form);
    }
}
