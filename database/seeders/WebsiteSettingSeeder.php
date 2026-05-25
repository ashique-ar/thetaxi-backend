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
                'value' => 'Company',
            ],
            [
                'type' => 'company_email',
                'value' => 'info@Company',
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
        ];

        foreach ($settings as $setting) {
            WebsiteSetting::updateOrCreate(
                ['type' => $setting['type']],
                ['value' => $setting['value']]
            );
        }
    }
}
