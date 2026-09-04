<?php

namespace Tests\Unit;

use Tests\TestCase;

class BookingSearchRetryStateContractTest extends TestCase
{
    public function test_failed_client_validation_preserves_location_identity_and_removes_only_generated_aliases(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $cleanupStart = strpos($script, 'function cleanupFailedSubmissionAliases(form)');
        $cleanupEnd = strpos($script, '/**', $cleanupStart + 1);
        $cleanup = substr($script, $cleanupStart, $cleanupEnd - $cleanupStart);

        $this->assertStringNotContainsString("latInput.value = ''", $cleanup);
        $this->assertStringNotContainsString("lngInput.value = ''", $cleanup);
        $this->assertStringContainsString("input[data-canonical-generated=\"true\"]", $script);
        $this->assertStringContainsString("input.dataset.canonicalGenerated = 'true'", $script);
        $this->assertStringContainsString('cleanupFailedSubmissionAliases(form);', $script);
    }

    public function test_focusing_a_restored_location_does_not_clear_it(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));
        $focusStart = strpos($script, "input.addEventListener('focus', function ()");
        $focusEnd = strpos($script, "input.addEventListener('click'", $focusStart);
        $focusHandler = substr($script, $focusStart, $focusEnd - $focusStart);

        $this->assertStringNotContainsString("this.value = ''", $focusHandler);
        $this->assertStringNotContainsString("data-place-selected', 'false", $focusHandler);
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
