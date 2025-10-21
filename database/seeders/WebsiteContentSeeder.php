<?php

namespace Database\Seeders;

use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WebsiteContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create CMS Content Types
        $contentTypes = [
            [
                'title' => 'Featured Services',
                'slug' => 'featured-services',
                'description' => 'Featured taxi services displayed on the homepage'
            ],
            [
                'title' => 'Testimonials',
                'slug' => 'testimonials',
                'description' => 'Customer testimonials and reviews'
            ],
            [
                'title' => 'Service Areas',
                'slug' => 'service-areas',
                'description' => 'Areas where TheTaxi provides services'
            ],
            [
                'title' => 'News & Updates',
                'slug' => 'news-updates',
                'description' => 'Company news and service updates'
            ]
        ];

        foreach ($contentTypes as $type) {
            CmsContentType::updateOrCreate(
                ['slug' => $type['slug']],
                array_merge($type, ['id' => Str::uuid()])
            );
        }

        // Get content type IDs
        $featuredServicesType = CmsContentType::where('slug', 'featured-services')->first();
        $testimonialsType = CmsContentType::where('slug', 'testimonials')->first();
        $serviceAreasType = CmsContentType::where('slug', 'service-areas')->first();

        // Create Featured Services Content
        $featuredServices = [
            [
                'title' => '24/7 Airport Transfer',
                'slug' => '24-7-airport-transfer',
                'author' => 'Airport Service Team',
                'thumbnail' => '/assets/img/services/airport-transfer.jpg',
                'body' => 'Professional airport pickup and drop-off service available round the clock. Meet and greet service with flight monitoring.',
                'meta_title' => '24/7 Airport Transfer Service - TheTaxi',
                'meta_description' => 'Reliable airport transfer service in Sri Lanka. Professional drivers, flight monitoring, meet and greet service.',
                'is_active' => true,
                'display_order' => 1,
                'cms_content_type_id' => $featuredServicesType->id
            ],
            [
                'title' => 'City Taxi Service',
                'slug' => 'city-taxi-service',
                'author' => 'City Service Team',
                'thumbnail' => '/assets/img/services/city-rides.jpg',
                'body' => 'Quick and convenient taxi rides within Colombo and major cities. Affordable rates with professional drivers.',
                'meta_title' => 'City Taxi Service - TheTaxi',
                'meta_description' => 'Fast and reliable city taxi service in Sri Lanka. Book now for quick pickups.',
                'is_active' => true,
                'display_order' => 2,
                'cms_content_type_id' => $featuredServicesType->id
            ],
            [
                'title' => 'Outstation Tours',
                'slug' => 'outstation-tours',
                'author' => 'Tour Service Team',
                'thumbnail' => '/assets/img/services/outstation.jpg',
                'body' => 'Comfortable long-distance travel to Kandy, Galle, Nuwara Eliya and other destinations. Fixed package rates.',
                'meta_title' => 'Outstation Tour Service - TheTaxi',
                'meta_description' => 'Long-distance taxi service to popular destinations in Sri Lanka. Fixed rates, experienced drivers.',
                'is_active' => true,
                'display_order' => 3,
                'cms_content_type_id' => $featuredServicesType->id
            ]
        ];

        foreach ($featuredServices as $service) {
            CmsContent::updateOrCreate(
                ['slug' => $service['slug']],
                array_merge($service, ['id' => Str::uuid()])
            );
        }

        // Create Testimonials Content
        $testimonials = [
            [
                'title' => 'Excellent Airport Service',
                'slug' => 'testimonial-vignesh',
                'author' => 'Vignesh R',
                'thumbnail' => '/assets/img/testimonials/customer1.jpg',
                'body' => 'Very fast and affordable. One of the best and friendly services provided by TheTaxi. I left few items in the cab and they constantly kept in touch with me and sent the items back to me. Such a good gesture for taxi service to make them more reliable and trustworthy. Cabs were clean and in very good condition.',
                'is_active' => true,
                'display_order' => 1,
                'cms_content_type_id' => $testimonialsType->id
            ],
            [
                'title' => 'Professional Service',
                'slug' => 'testimonial-sarah',
                'author' => 'Sarah De Silva',
                'thumbnail' => '/assets/img/testimonials/customer2.jpg',
                'body' => 'TheTaxi provided excellent service for our family trip to Kandy. The driver was professional, the vehicle was comfortable, and they were punctual. Highly recommended for outstation trips.',
                'is_active' => true,
                'display_order' => 2,
                'cms_content_type_id' => $testimonialsType->id
            ],
            [
                'title' => 'Reliable City Service',
                'slug' => 'testimonial-john',
                'author' => 'John Fernando',
                'thumbnail' => '/assets/img/testimonials/customer3.jpg',
                'body' => 'I use TheTaxi regularly for my daily commute in Colombo. Always on time, fair prices, and courteous drivers. The booking process is simple and efficient.',
                'is_active' => true,
                'display_order' => 3,
                'cms_content_type_id' => $testimonialsType->id
            ]
        ];

        foreach ($testimonials as $testimonial) {
            CmsContent::updateOrCreate(
                ['slug' => $testimonial['slug']],
                array_merge($testimonial, ['id' => Str::uuid()])
            );
        }

        // Create Website Settings
        $settings = [
            ['type' => 'company_name', 'value' => 'TheTaxi'],
            ['type' => 'company_tagline', 'value' => 'Your Reliable Taxi Service'],
            ['type' => 'contact_phone', 'value' => '+94 711 615 615'],
            ['type' => 'contact_email', 'value' => 'info@thetaxi.com'],
            ['type' => 'contact_whatsapp', 'value' => '+94 711 615 615'],
            ['type' => 'company_address', 'value' => 'Colombo, Sri Lanka'],
            ['type' => 'operating_hours', 'value' => '24/7 Service Available'],
            ['type' => 'facebook_url', 'value' => 'https://www.facebook.com/TheTaxiSriLanka'],
            ['type' => 'instagram_url', 'value' => 'https://www.instagram.com/thetaxi_lk/'],
            ['type' => 'twitter_url', 'value' => 'https://www.twitter.com/thetaxi_lk']
        ];

        foreach ($settings as $setting) {
            WebsiteSetting::updateOrCreate(
                ['type' => $setting['type']],
                array_merge($setting, ['id' => Str::uuid()])
            );
        }
    }
}
