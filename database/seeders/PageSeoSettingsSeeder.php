<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Website\WebsiteSetting;
use Illuminate\Support\Facades\Cache;

class PageSeoSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $seoData = [
            // Home
            'seo_home_title' => 'Taxi Sri Lanka | Book a Car with Driver | Company',
            'seo_home_description' => 'Book a Taxi in Sri Lanka at Company for a reliable travel. Get on-time pickups, fair rates and friendly local drivers. Book a car with a Driver Now.',
            'seo_home_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, Company',

            // Cart (Address: https://Company/cart)
            'seo_cart_title' => 'Book Online Taxi Booking in Sri Lanka | Company',
            'seo_cart_description' => 'Book your Sri Lanka taxi online with Company. Enjoy quick confirmation, secure checkout and professional drivers.',
            'seo_cart_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, Company',

            // About (Address: https://Company/about)
            'seo_about_title' => 'Taxi Hire Sri Lanka | Car Hire Company | About Company',
            'seo_about_description' => 'Reserve taxi to about on Company for reliable Sri Lanka transport. Get on-time pickups, fair rates and friendly local drivers.',
            'seo_about_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, Company',

            // Taxi (Address: https://Company/taxi)
            'seo_taxi_title' => 'Taxi Hire Sri Lanka | Book a Taxi | Company',
            'seo_taxi_description' => 'Plan your trip with Company. Pre-book taxi in Sri Lanka for smooth pickups, safe rides and 24/7 customer support. Book Online Now.',
            'seo_taxi_keywords' => 'taxi, taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka',

            // Contact (Address: https://Company/contact)
            'seo_contact_title' => 'Address, Phone and Email | Book Online | Contact Company',
            'seo_contact_description' => 'Contact to reserve a taxi at Company via Address, Phone or Email. Book Online now to get on-time pickups, fair rates and friendly local drivers.',
            'seo_contact_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, Company',

            // Checkout (Address: https://Company/checkout)
            'seo_checkout_title' => 'Book Online | Taxi Booking in Sri Lanka | Company',
            'seo_checkout_description' => 'Book your Sri Lanka taxi online with Company. Enjoy quick confirmation, secure checkout and professional drivers. Book Taxi Hire Online Now.',
            'seo_checkout_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, Company',
        ];

        foreach ($seoData as $key => $value) {
            WebsiteSetting::firstOrCreate(
                ['type' => $key],
                ['value' => $value]
            );
        }

        // Clear cache
        Cache::forget('global_settings_flattened');
        foreach ($seoData as $key => $value) {
            Cache::forget('website_settings_' . $key);
        }
        
        $this->command->info('Page-specific SEO settings seeded successfully!');
    }
}
