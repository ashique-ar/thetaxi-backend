<?php

namespace Tests\Unit;

use Tests\TestCase;

class DriverLocationSafeContractTest extends TestCase
{
    public function test_location_api_has_structured_acknowledgements_and_no_raw_exception_payloads(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/LocationController.php'));
        $service = file_get_contents(app_path('Services/Driver/LocationService.php'));

        self::assertStringContainsString("'accepted_count'", $controller);
        self::assertStringContainsString("'quarantined_count'", $controller);
        self::assertStringContainsString("'retryable_count'", $controller);
        self::assertStringContainsString("'correlation_id'", $controller);
        self::assertStringContainsString("'outcomes'", $controller);
        self::assertStringNotContainsString("'error' => \$e->getMessage()", $controller);
        self::assertStringContainsString('session_context_mismatch', $service);
        self::assertStringContainsString('assignment_context_mismatch', $service);
        self::assertStringContainsString('outside_assignment_window', $service);
    }
}
