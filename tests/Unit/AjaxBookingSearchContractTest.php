<?php

namespace Tests\Unit;

use Tests\TestCase;

class AjaxBookingSearchContractTest extends TestCase
{
    public function test_search_endpoint_returns_results_url_for_ajax_without_duplication(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/BookingController.php'));

        $this->assertStringContainsString('$request->expectsJson()', $controller);
        $this->assertStringContainsString("'results_url' => route('search')", $controller);
    }

    public function test_results_page_search_progressively_updates_results_and_handles_validation(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));

        $this->assertStringContainsString('async function submitResultsSearchAjax(form)', $script);
        $this->assertStringContainsString("'Accept': 'application/json'", $script);
        $this->assertStringContainsString('response.status === 422', $script);
        $this->assertStringContainsString("querySelector('#vehicleResultsSection')", $script);
        $this->assertStringContainsString('currentResults.replaceWith(nextResults)', $script);
        $this->assertStringContainsString("form.method.toUpperCase() === 'GET'", $script);
        $this->assertStringContainsString('new AbortController()', $script);
        $this->assertStringContainsString("showValidationMessage(message, 'error')", $script);
        $this->assertStringContainsString("type === 'success' ? '#198754'", $script);
        $this->assertStringNotContainsString('spinner-border spinner-border-sm', $script);
    }

    public function test_distance_failure_alert_is_replaceable_with_ajax_results(): void
    {
        $view = file_get_contents(resource_path('views/search.blade.php'));

        $this->assertStringContainsString('id="distanceCalculationAlert"', $view);
    }

    public function test_home_search_paints_loading_state_before_native_navigation(): void
    {
        $script = file_get_contents(public_path('assets/js/booking-form.js'));
        $styles = file_get_contents(public_path('assets/css/booking-form.css'));

        $this->assertStringContainsString('setSearchLoading(form, true);', $script);
        $this->assertStringContainsString('window.requestAnimationFrame(() =>', $script);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(form);', $script);
        $this->assertStringContainsString("window.addEventListener('pageshow'", $script);
        $this->assertStringContainsString('@keyframes booking-search-spin', $styles);
        $this->assertStringContainsString('.booking-search-submit.ajax-search-loading > span::before', $styles);
    }
}
