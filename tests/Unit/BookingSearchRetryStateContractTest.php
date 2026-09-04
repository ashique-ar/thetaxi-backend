<?php

namespace Tests\Unit;

use Tests\TestCase;

class BookingSearchRetryStateContractTest extends TestCase
{
    public function test_failed_client_validation_removes_generated_aliases_and_default_coordinates(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString("if (latInput) latInput.value = ''", $script);
        $this->assertStringContainsString("if (lngInput) lngInput.value = ''", $script);
        $this->assertStringContainsString("input[data-canonical-generated=\"true\"]", $script);
        $this->assertStringContainsString("input.dataset.canonicalGenerated = 'true'", $script);
    }

    public function test_each_attempt_rebuilds_addresses_from_visible_controls_before_hidden_aliases(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString('removeGeneratedAliases();', $script);
        $this->assertStringContainsString("['pickup', 'from', 'pickup_location'", $script);
        $this->assertStringContainsString("['dropoff', 'to', 'dropoff_location'", $script);
        $this->assertStringContainsString('element.type !== \'hidden\' && element.offsetParent !== null', $script);
    }
}
