<?php

namespace Database\Seeders;

use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Seeder;

class WebsiteSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'type' => 'tax_percentage',
                'value' => '10',
            ],
            [
                'type' => 'service_fee_percentage',
                'value' => '5',
            ],
            [
                'type' => 'vat_percentage',
                'value' => '0',
            ],
            [
                'type' => 'discount_percentage',
                'value' => '0',
            ],
            [
                'type' => 'currency_symbol',
                'value' => 'LKR',
            ],
            [
                'type' => 'service_fee_type',
                'value' => 'fixed', // Can be 'fixed' or 'percentage'
            ],
            [
                'type' => 'company_name',
                'value' => 'The Taxi',
            ],
            [
                'type' => 'company_email',
                'value' => 'info@thetaxi.lk',
            ],
            [
                'type' => 'company_phone',
                'value' => '',
            ],
            [
                'type' => 'company_whatsapp',
                'value' => '',
            ],
            [
                'type' => 'advance_payment_percentage',
                'value' => '50',
            ],
            [
                'type' => 'payment_online_enabled',
                'value' => 'true',
            ],
            [
                'type' => 'payment_offline_enabled',
                'value' => 'true',
            ],
            [
                'type' => 'webxpay_enabled',
                'value' => 'false',
            ],
            [
                'type' => 'webxpay_merchant_secret',
                'value' => '',
            ],
            [
                'type' => 'webxpay_public_key',
                'value' => '',
            ],
            [
                'type' => 'webxpay_api_url',
                'value' => 'https://tokenize.webxpay.com/v1/api',
            ],
            [
                'type' => 'webxpay_api_username',
                'value' => '',
            ],
            [
                'type' => 'webxpay_api_password',
                'value' => '',
            ],
            [
                'type' => 'webxpay_checkout_url',
                'value' => 'https://webxpay.com/index.php?route=checkout/billing',
            ],
            [
                'type' => 'webxpay_return_url',
                'value' => '',
            ],
            [
                'type' => 'webxpay_cancel_url',
                'value' => '',
            ],
            [
                'type' => 'webxpay_notify_url',
                'value' => '',
            ],
            [
                'type' => 'webxpay_currency',
                'value' => 'LKR',
            ],
            [
                'type' => 'service_fee_enabled',
                'value' => 'false',
            ],
            [
                'type' => 'service_fee_amount',
                'value' => '0',
            ],
            [
                'type' => 'service_fee_min_amount',
                'value' => '0',
            ],
            [
                'type' => 'service_fee_max_amount',
                'value' => '',
            ],
            [
                'type' => 'tax_enabled',
                'value' => 'true',
            ],
            [
                'type' => 'tax_rate',
                'value' => '18',
            ],
            [
                'type' => 'tax_label',
                'value' => 'Government TAX',
            ],
            [
                'type' => 'tax_description',
                'value' => 'Government TAX',
            ],
            [
                'type' => 'vat_enabled',
                'value' => 'false',
            ],
            [
                'type' => 'vat_rate',
                'value' => '18',
            ],
            [
                'type' => 'vat_label',
                'value' => 'VAT',
            ],
            [
                'type' => 'vat_description',
                'value' => 'Value Added Tax',
            ],
            [
                'type' => 'vat_applies_to_service_fee',
                'value' => 'false',
            ],
            [
                'type' => 'advance_payment_enabled',
                'value' => 'true',
            ],
            [
                'type' => 'advance_payment_min_amount',
                'value' => '1000',
            ],
            [
                'type' => 'booking_base_currency',
                'value' => 'LKR',
            ],
            [
                'type' => 'assignment_enable_qc_stage',
                'value' => 'false',
            ],
            [
                'type' => 'assignment_enable_maintenance_stage',
                'value' => 'false',
            ],
            [
                'type' => 'driver_mobile_latest_version',
                'value' => '1.0.0',
            ],
            [
                'type' => 'driver_mobile_mandatory_update',
                'value' => 'false',
            ],
            [
                'type' => 'driver_mobile_update_message',
                'value' => 'A new driver app version is available. Please update to continue.',
            ],
            [
                'type' => 'site_name',
                'value' => 'The Taxi',
            ],
            [
                'type' => 'site_tagline',
                'value' => 'Your Trusted Transport Partner',
            ],
            [
                'type' => 'brand_name',
                'value' => 'The Taxi',
            ],
            [
                'type' => 'brand_tagline',
                'value' => 'Your Trusted Transport Partner',
            ],
            [
                'type' => 'brand_short_name',
                'value' => 'The Taxi',
            ],
            [
                'type' => 'brand_logo_primary',
                'value' => '/images/logo/logo.png',
            ],
            [
                'type' => 'brand_logo_secondary',
                'value' => '/images/logo/logo-text-on-dark.svg',
            ],
            [
                'type' => 'brand_logo_icon',
                'value' => '/images/logo/logo1.svg',
            ],
            [
                'type' => 'brand_favicon',
                'value' => '/favicon.ico',
            ],
            [
                'type' => 'portal_title',
                'value' => 'The Taxi | Portal',
            ],
            [
                'type' => 'portal_logo',
                'value' => '/images/logo/logo.png',
            ],
            [
                'type' => 'portal_theme',
                'value' => 'theme-brand',
            ],
            [
                'type' => 'brand_color_primary',
                'value' => '#BF2629',
            ],
            [
                'type' => 'brand_color_primary_light',
                'value' => '#F4E2E2',
            ],
            [
                'type' => 'brand_color_primary_dark',
                'value' => '#891318',
            ],
            [
                'type' => 'brand_color_secondary',
                'value' => '#717171',
            ],
            [
                'type' => 'brand_color_secondary_light',
                'value' => '#E5E5E5',
            ],
            [
                'type' => 'brand_color_secondary_dark',
                'value' => '#404040',
            ],
            [
                'type' => 'brand_color_accent',
                'value' => '#FFFFFF',
            ],
            [
                'type' => 'brand_color_accent_light',
                'value' => '#FFFFFF',
            ],
            [
                'type' => 'brand_color_accent_dark',
                'value' => '#F5F5F5',
            ],
            [
                'type' => 'footer_company_name',
                'value' => 'The Taxi',
            ],
            [
                'type' => 'footer_copyright_text',
                'value' => 'The Taxi. All rights reserved.',
            ],
        ];

        foreach ($settings as $setting) {
            WebsiteSetting::updateOrCreate(
                ['type' => $setting['type']],
                ['value' => $setting['value']]
            );
        }
    }
}
