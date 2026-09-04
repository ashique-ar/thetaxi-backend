<?php

namespace Tests\Unit;

use Tests\TestCase;

class DynamicAdvanceBookingClientContractTest extends TestCase
{
    public function test_dynamic_forms_receive_the_database_advance_window_and_configured_start_fields(): void
    {
        $form = file_get_contents(resource_path('views/components/dynamic-booking-form.blade.php'));

        $this->assertStringContainsString('data-advance-hours=', $form);
        $this->assertStringContainsString('data-site-now=', $form);
        $this->assertStringContainsString('data-start-date-field=', $form);
        $this->assertStringContainsString("field_mappings.dates", $form);
    }

    public function test_client_sets_initial_minimum_and_recalculates_time_when_date_changes(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString('function setupAdvanceBookingConstraints()', $script);
        $this->assertStringContainsString('advanceHours * 3600000', $script);
        $this->assertStringContainsString('value.getUTCMinutes() % 30', $script);
        $this->assertStringContainsString("30 - minuteRemainder", $script);
        $this->assertStringContainsString("timeInput.min = selectedDate === minimumDate ? minimumTime : '00:00'", $script);
        $this->assertStringContainsString("dateInput.addEventListener('change', () => applyConstraints(false))", $script);
        $this->assertStringContainsString("form.dataset.hasSearchContext === 'false'", $script);
    }

    public function test_client_blocks_a_booking_before_the_dynamic_minimum(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString('function validateAdvanceBookingSelection(form)', $script);
        $this->assertStringContainsString("selectedDateTime >= minimum", $script);
        $this->assertStringContainsString('const advanceBookingError = validateAdvanceBookingSelection(form);', $script);
        $this->assertStringContainsString('Earliest available time is', $script);
    }

    public function test_server_still_enforces_the_same_database_setting_and_site_timezone(): void
    {
        $request = file_get_contents(app_path('Http/Requests/BookingSearchRequest.php'));

        $this->assertStringContainsString("['booking_advance_hours']", $request);
        $this->assertStringContainsString("get('site_timezone'", $request);
        $this->assertStringContainsString('now($siteTimezone)->addHours($advanceHours)', $request);
    }
}
