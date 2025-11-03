<?php

namespace Database\Seeders;

use App\Enums\CmsContentStatus;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Database\Seeder;

class CmsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create content types
        $blogType = CmsContentType::firstOrCreate(['slug' => 'blog'], [
            'title' => 'Blog',
            'description' => 'Blog articles and posts',
            'icon' => 'heroicons_outline:document-text',
            'template_config' => [
                'layout' => 'blog',
                'show_author' => true,
                'show_date' => true,
                'show_comments' => true,
                'show_share' => true,
            ],
            'url_prefix' => 'blog',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $newsType = CmsContentType::firstOrCreate(['slug' => 'news'], [
            'title' => 'News',
            'description' => 'Company news and announcements',
            'icon' => 'heroicons_outline:newspaper',
            'template_config' => [
                'layout' => 'news',
                'show_author' => false,
                'show_date' => true,
                'show_comments' => false,
                'show_share' => true,
            ],
            'url_prefix' => 'news',
            'is_active' => true,
            'display_order' => 2,
        ]);

        $servicesType = CmsContentType::firstOrCreate(['slug' => 'services'], [
            'title' => 'Services',
            'description' => 'Service descriptions and details',
            'icon' => 'heroicons_outline:cog-6-tooth',
            'template_config' => [
                'layout' => 'service',
                'show_author' => false,
                'show_date' => false,
                'show_comments' => false,
                'show_share' => false,
            ],
            'url_prefix' => 'services',
            'is_active' => true,
            'display_order' => 3,
        ]);

        // Create sample content for blog
        CmsContent::firstOrCreate(['slug' => 'welcome-to-thetaxi-blog'], [
            'cms_content_type_id' => $blogType->id,
            'title' => 'Welcome to TheTaxi Blog',
            'excerpt' => 'Learn about our car rental services and latest updates.',
            'body' => '<p>Welcome to the official TheTaxi blog! Here you\'ll find the latest updates about our car rental services, travel tips, and company news.</p><p>We\'re committed to providing the best car rental experience in Sri Lanka.</p>',
            'status' => CmsContentStatus::PUBLISHED,
            'published_at' => now(),
            'is_featured' => true,
            'allow_comments' => true,
            'meta_title' => 'Welcome to TheTaxi Blog - Car Rental Services',
            'meta_description' => 'Learn about TheTaxi car rental services and latest updates.',
            'custom_fields' => [
                'author_name' => 'TheTaxi Team',
                'read_time' => '2 min read',
            ],
            'views_count' => 0,
        ]);

        CmsContent::firstOrCreate(['slug' => 'top-10-destinations-sri-lanka'], [
            'cms_content_type_id' => $blogType->id,
            'title' => 'Top 10 Destinations in Sri Lanka',
            'excerpt' => 'Discover the most beautiful places to visit in Sri Lanka with our car rental service.',
            'body' => '<p>Sri Lanka is a beautiful island nation with stunning landscapes, rich culture, and amazing destinations.</p><h2>1. Sigiriya Rock Fortress</h2><p>An ancient rock fortress and UNESCO World Heritage site.</p><h2>2. Kandy</h2><p>The cultural capital of Sri Lanka with the famous Temple of the Tooth.</p>',
            'status' => CmsContentStatus::PUBLISHED,
            'published_at' => now()->subDays(2),
            'is_featured' => false,
            'allow_comments' => true,
            'meta_title' => 'Top 10 Destinations in Sri Lanka - Travel Guide',
            'meta_description' => 'Discover the most beautiful places to visit in Sri Lanka with our car rental service.',
            'custom_fields' => [
                'author_name' => 'Travel Expert',
                'read_time' => '5 min read',
                'featured_image_alt' => 'Beautiful Sri Lankan landscape',
            ],
            'views_count' => 156,
        ]);

        // Create sample news content
        CmsContent::firstOrCreate(['slug' => 'new-fleet-addition-luxury-suvs'], [
            'cms_content_type_id' => $newsType->id,
            'title' => 'New Fleet Addition - Luxury SUVs',
            'excerpt' => 'We have added premium luxury SUVs to our fleet for enhanced comfort.',
            'body' => '<p>We are excited to announce the addition of new luxury SUVs to our fleet. These vehicles offer premium comfort and advanced features for your travel needs.</p><p>Book now to experience luxury travel with TheTaxi!</p>',
            'status' => CmsContentStatus::PUBLISHED,
            'published_at' => now()->subDays(1),
            'is_featured' => true,
            'allow_comments' => false,
            'meta_title' => 'New Luxury SUVs Added to TheTaxi Fleet',
            'meta_description' => 'Experience premium comfort with our new luxury SUV fleet.',
            'custom_fields' => [
                'press_release' => true,
                'category' => 'Fleet Update',
            ],
            'views_count' => 89,
        ]);

        // Create sample service content
        CmsContent::firstOrCreate(['slug' => 'airport-transfer-service'], [
            'cms_content_type_id' => $servicesType->id,
            'title' => 'Airport Transfer Service',
            'excerpt' => 'Reliable and comfortable airport transfer service for all major airports.',
            'body' => '<p>Our airport transfer service provides reliable and comfortable transportation to and from all major airports in Sri Lanka.</p><h2>Features</h2><ul><li>24/7 availability</li><li>Professional drivers</li><li>Meet and greet service</li><li>Competitive pricing</li></ul>',
            'status' => CmsContentStatus::PUBLISHED,
            'published_at' => now()->subWeeks(1),
            'is_featured' => false,
            'allow_comments' => false,
            'meta_title' => 'Airport Transfer Service - TheTaxi',
            'meta_description' => 'Reliable airport transfer service with professional drivers and competitive pricing.',
            'custom_fields' => [
                'service_category' => 'Transportation',
                'price_range' => 'Competitive',
                'availability' => '24/7',
            ],
            'views_count' => 234,
        ]);

        $this->command->info('CMS content seeded successfully!');
    }
}
