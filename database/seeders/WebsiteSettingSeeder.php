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
                'value' => 'percentage', // Can be 'percentage' or 'flat'
            ],
            [
                'type' => 'company_name',
                'value' => 'TheTaxi',
            ],
            [
                'type' => 'company_email',
                'value' => 'info@thetaxi.lk',
            ],
            [
                'type' => 'company_phone',
                'value' => '+94771234567',
            ],
            [
                'type' => 'advance_payment_percentage',
                'value' => '50',
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
