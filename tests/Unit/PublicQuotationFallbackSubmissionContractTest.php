<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicQuotationFallbackSubmissionContractTest extends TestCase
{
    public function test_search_page_has_one_canonical_quotation_submit_owner(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $search = file_get_contents($projectRoot . '/resources/views/search.blade.php');
        $sharedScripts = file_get_contents(
            $projectRoot . '/resources/views/components/vehicle-card-scripts.blade.php'
        );

        $this->assertSame(
            0,
            substr_count($search, "$('#quotationRequestForm').on('submit'")
        );
        $this->assertSame(
            1,
            substr_count(
                $sharedScripts,
                "$(document).on('submit', '#quotationRequestForm'"
            )
        );
        $this->assertStringContainsString(
            'window.prepareQuotationRequestForm = function(form)',
            $search
        );
        $this->assertStringContainsString(
            "typeof window.prepareQuotationRequestForm === 'function'",
            $sharedScripts
        );
    }

    public function test_phone_context_is_normalized_before_the_shared_form_is_serialized(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $search = file_get_contents($projectRoot . '/resources/views/search.blade.php');
        $sharedScripts = file_get_contents(
            $projectRoot . '/resources/views/components/vehicle-card-scripts.blade.php'
        );

        $this->assertStringContainsString(
            "\$form.find('.quotation-phone-country-code').val(countryData.dialCode || '');",
            $search
        );
        $this->assertStringContainsString(
            "\$form.find('.quotation-phone-international').val(quotationPhoneIti.getNumber() || '');",
            $search
        );
        $this->assertLessThan(
            strpos($sharedScripts, 'data: $form.serialize()'),
            strpos($sharedScripts, 'window.prepareQuotationRequestForm(this)')
        );
    }

    public function test_repeat_submit_is_guarded_until_the_request_completes(): void
    {
        $sharedScripts = file_get_contents(
            dirname(__DIR__, 2)
                . '/resources/views/components/vehicle-card-scripts.blade.php'
        );

        $this->assertStringContainsString(
            "if (\$form.data('quotation-submitting'))",
            $sharedScripts
        );
        $this->assertStringContainsString(
            "\$form.data('quotation-submitting', true);",
            $sharedScripts
        );
        $this->assertStringContainsString(
            "\$form.data('quotation-submitting', false);",
            $sharedScripts
        );
    }

    public function test_portal_inquiry_flag_and_public_fallback_route_remain_connected(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $portalPricing = file_get_contents(
            dirname($projectRoot)
                . '/portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/pricing-table/pricing-table.component.html'
        );
        $vehicleCard = file_get_contents(
            $projectRoot . '/resources/views/components/vehicle-card.blade.php'
        );
        $routes = file_get_contents($projectRoot . '/routes/web.php');
        $bookingController = file_get_contents(
            $projectRoot . '/app/Http/Controllers/BookingController.php'
        );

        $this->assertStringContainsString(
            '[checked]="getServiceSettings(serviceWithSlabs.id).is_inquiry_only"',
            $portalPricing
        );
        $this->assertStringContainsString('@elseif($showQuotationButton)', $vehicleCard);
        $this->assertStringContainsString("->name('quotation.request')", $routes);
        $this->assertStringContainsString(
            "'inquiry_type' => 'quotation_request'",
            $bookingController
        );
    }
}
