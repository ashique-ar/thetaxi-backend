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
            'seo_home_title' => 'Taxi Sri Lanka | Book a Car with Driver | TheTaxi.lk',
            'seo_home_description' => 'Book a Taxi in Sri Lanka at TheTaxi.lk for a reliable travel. Get on-time pickups, fair rates and friendly local drivers. Book a car with a Driver Now.',
            'seo_home_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, TheTaxi.lk',

            // Cart (Address: https://thetaxi.lk/cart)
            'seo_cart_title' => 'Book Online Taxi Booking in Sri Lanka | TheTaxi.lk',
            'seo_cart_description' => 'Book your Sri Lanka taxi online with TheTaxi.lk. Enjoy quick confirmation, secure checkout and professional drivers.',
            'seo_cart_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, TheTaxi.lk',

            // About (Address: https://thetaxi.lk/about)
            'seo_about_title' => 'Taxi Hire Sri Lanka | Car Hire Company | About TheTaxi.lk',
            'seo_about_description' => 'Reserve taxi to about on TheTaxi.lk for reliable Sri Lanka transport. Get on-time pickups, fair rates and friendly local drivers.',
            'seo_about_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, TheTaxi.lk',

            // Taxi (Address: https://thetaxi.lk/taxi)
            'seo_taxi_title' => 'Taxi Hire Sri Lanka | Book a Taxi | TheTaxi.lk',
            'seo_taxi_description' => 'Plan your trip with TheTaxi.lk. Pre-book taxi in Sri Lanka for smooth pickups, safe rides and 24/7 customer support. Book Online Now.',
            'seo_taxi_keywords' => 'taxi, taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka',

            // Contact (Address: https://thetaxi.lk/contact)
            'seo_contact_title' => 'Address, Phone and Email | Book Online | Contact TheTaxi.lk',
            'seo_contact_description' => 'Contact to reserve a taxi at TheTaxi.lk via Address, Phone or Email. Book Online now to get on-time pickups, fair rates and friendly local drivers.',
            'seo_contact_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, TheTaxi.lk',

            // Things to do (Address: https://thetaxi.lk/things-to-do)
            'seo_things_to_do_title' => 'Book Taxi | Things to do in Sri Lanka | TheTaxi.lk',
            'seo_things_to_do_description' => 'Plan your trip with Pre-book taxi in Sri Lanka for smooth pickups, safe rides and 24/7 customer support. Enjoy things to do in Sri Lanka with TheTaxi.lk',
            'seo_things_to_do_keywords' => 'taxi to things do, taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka',

            // Services (Address: https://thetaxi.lk/services)
            'seo_services_title' => 'Taxi Services Sri Lanka | Book a Taxi Hire | TheTaxi.lk',
            'seo_services_description' => 'Book a Taxi Hire in Sri Lanka with TheTaxi.lk. Enjoy the best Taxi serivices in Sri Lanka. Get on-time pickups, fair rates and friendly local drivers.',
            'seo_services_keywords' => 'services, taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka',

            // Corporate Transfers (Address: https://thetaxi.lk/corporate-transfers)
            'seo_corporate_transfers_title' => 'Book Taxi to Corporate Transfers in Sri Lanka | TheTaxi.lk',
            'seo_corporate_transfers_description' => 'Plan your trip with TheTaxi.lk. Pre-book taxi for your corporate transfers in Sri Lanka for smooth pickups, safe rides and 24/7 customer support.',
            'seo_corporate_transfers_keywords' => 'corporate transfers, taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka',

            // Checkout (Address: https://thetaxi.lk/checkout)
            'seo_checkout_title' => 'Book Online | Taxi Booking in Sri Lanka | TheTaxi.lk',
            'seo_checkout_description' => 'Book your Sri Lanka taxi online with TheTaxi.lk. Enjoy quick confirmation, secure checkout and professional drivers. Book Taxi Hire Online Now.',
            'seo_checkout_keywords' => 'taxi Sri Lanka, Sri Lanka taxi service, airport taxi Sri Lanka, Colombo taxi, Bandaranaike airport transfer, airport pickup Sri Lanka, city taxi Colombo, private car hire Sri Lanka, chauffeur service Sri Lanka, tour taxi Sri Lanka, day tour Sri Lanka, Galle taxi, Kandy taxi, Ella taxi, Negombo taxi, cheap taxi Sri Lanka, online taxi booking, 24/7 taxi service, airport drop Sri Lanka, TheTaxi.lk',
        ];

        foreach ($seoData as $key => $value) {
            WebsiteSetting::updateOrCreate(
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
