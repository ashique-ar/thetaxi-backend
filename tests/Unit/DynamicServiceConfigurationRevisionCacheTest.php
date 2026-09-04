<?php

namespace Tests\Unit;

use Tests\TestCase;

class DynamicServiceConfigurationRevisionCacheTest extends TestCase
{
    public function test_public_form_cache_is_bound_to_the_authoritative_record_revision(): void
    {
        $service = file_get_contents(app_path('Services/DynamicServiceConfigurationService.php'));

        $this->assertStringContainsString('ServiceType::publicContext()', $service);
        $this->assertStringContainsString("\$serviceType->updated_at?->format('YmdHisv')", $service);
        $this->assertStringContainsString('service_form_config_public_v6_', $service);
        $this->assertStringContainsString('function () use ($serviceCode, $serviceType)', $service);
    }
}
