<?php

namespace Database\Seeders;

use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Seeder;

class QuotationSmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        WebsiteSetting::firstOrCreate(
            ['type' => 'sms_quotation_requested_template'],
            ['value' => 'Thank you for requesting a quotation from {company_name}. Reference: {booking_number}. Our team will contact you shortly. Call {company_phone}.']
        );
    }
}
