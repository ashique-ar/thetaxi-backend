<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Support\Str;

class EnhancedCmsContentSeeder extends Seeder
{
    public function run(): void
    {
        // Get content types
        $destinations = CmsContentType::where('slug', 'destinations')->first();
        $thingsToDo = CmsContentType::where('slug', 'things-to-do')->first();
        $independentServices = CmsContentType::where('slug', 'independent-services')->first();
        $blogs = CmsContentType::where('slug', 'blogs')->first();

        // Sample destinations
        if ($destinations) {
            $this->createDestinations($destinations->id);
        }

        // Sample things to do
        if ($thingsToDo) {
            $this->createThingsToDo($thingsToDo->id);
        }

        // Sample independent services
        if ($independentServices) {
            $this->createIndependentServices($independentServices->id);
        }

        // Sample blogs
        if ($blogs) {
            $this->createBlogs($blogs->id);
        }
    }

    private function createDestinations($contentTypeId)
    {
        $destinations = [
            [
                'title' => 'Sigiriya Rock Fortress',
                'slug' => 'sigiriya-rock-fortress',
                'excerpt' => 'Climb the ancient rock fortress of Sigiriya, a UNESCO World Heritage site with stunning frescoes and panoramic views.',
                'location' => 'Sigiriya, Central Province',
                'price' => 45.00,
                'duration' => '3-4 hours',
                'rating' => 4.8,
                'reviews_count' => 1245,
                'category' => 'Historical Sites',
                'difficulty_level' => 'moderate',
                'tags' => ['UNESCO', 'History', 'Climbing', 'Architecture'],
                'special_offer' => true,
                'discount_percentage' => 15,
            ],
            [
                'title' => 'Galle Fort Colonial City',
                'slug' => 'galle-fort-colonial-city',
                'excerpt' => 'Explore the historic Galle Fort, a 16th-century Portuguese fortification with Dutch colonial architecture.',
                'location' => 'Galle, Southern Province',
                'price' => 25.00,
                'duration' => '2-3 hours',
                'rating' => 4.6,
                'reviews_count' => 892,
                'category' => 'Historical Sites',
                'difficulty_level' => 'easy',
                'tags' => ['Colonial', 'Fort', 'Walking Tour', 'Photography'],
                'special_offer' => false,
            ],
            [
                'title' => 'Kandy Cultural Triangle',
                'slug' => 'kandy-cultural-triangle',
                'excerpt' => 'Discover the cultural heart of Sri Lanka with temple visits, traditional dance shows, and the famous Temple of the Tooth.',
                'location' => 'Kandy, Central Province',
                'price' => 35.00,
                'duration' => 'Full Day',
                'rating' => 4.7,
                'reviews_count' => 1056,
                'category' => 'Cultural Tours',
                'difficulty_level' => 'easy',
                'tags' => ['Culture', 'Temples', 'Dance', 'Religion'],
                'special_offer' => true,
                'discount_percentage' => 10,
            ],
        ];

        foreach ($destinations as $data) {
            $this->createContent($contentTypeId, $data);
        }
    }

    private function createThingsToDo($contentTypeId)
    {
        $activities = [
            [
                'title' => 'Whale Watching in Mirissa',
                'slug' => 'whale-watching-mirissa',
                'excerpt' => 'Experience the thrill of spotting blue whales and dolphins in the waters off Mirissa Beach.',
                'location' => 'Mirissa, Southern Province',
                'price' => 65.00,
                'duration' => '3-4 hours',
                'rating' => 4.9,
                'reviews_count' => 567,
                'category' => 'Marine Adventures',
                'difficulty_level' => 'easy',
                'tags' => ['Wildlife', 'Ocean', 'Photography', 'Adventure'],
                'special_offer' => false,
            ],
            [
                'title' => 'Ella Rock Hiking Adventure',
                'slug' => 'ella-rock-hiking',
                'excerpt' => 'Challenge yourself with a hike to Ella Rock for breathtaking views of the hill country landscape.',
                'location' => 'Ella, Uva Province',
                'price' => 30.00,
                'duration' => '4-5 hours',
                'rating' => 4.5,
                'reviews_count' => 823,
                'category' => 'Hiking & Trekking',
                'difficulty_level' => 'challenging',
                'tags' => ['Hiking', 'Mountains', 'Views', 'Adventure'],
                'special_offer' => true,
                'discount_percentage' => 20,
            ],
            [
                'title' => 'Yala Safari Experience',
                'slug' => 'yala-safari-experience',
                'excerpt' => 'Embark on an exciting safari adventure in Yala National Park to spot leopards, elephants, and exotic birds.',
                'location' => 'Yala National Park',
                'price' => 85.00,
                'duration' => '6-8 hours',
                'rating' => 4.8,
                'reviews_count' => 1389,
                'category' => 'Wildlife Safari',
                'difficulty_level' => 'easy',
                'tags' => ['Safari', 'Wildlife', 'Photography', 'Nature'],
                'special_offer' => false,
            ],
        ];

        foreach ($activities as $data) {
            $this->createContent($contentTypeId, $data);
        }
    }

    private function createIndependentServices($contentTypeId)
    {
        $services = [
            [
                'title' => 'Premium Airport Transfer Service',
                'slug' => 'premium-airport-transfer-service',
                'excerpt' => 'Comfortable and reliable airport transfer service with professional drivers and modern vehicles.',
                'location' => 'Colombo International Airport',
                'price' => 45.00,
                'duration' => '1-2 hours',
                'rating' => 4.7,
                'reviews_count' => 2341,
                'category' => 'Transportation',
                'tags' => ['Airport', 'Transfer', 'Comfort', 'Reliable'],
                'special_offer' => true,
                'discount_percentage' => 10,
            ],
            [
                'title' => 'Authentic Ayurveda Spa Treatment',
                'slug' => 'authentic-ayurveda-spa-treatment',
                'excerpt' => 'Rejuvenate your body and mind with authentic Ayurvedic treatments by experienced therapists.',
                'location' => 'Negombo, Western Province',
                'price' => 120.00,
                'duration' => '2-3 hours',
                'rating' => 4.9,
                'reviews_count' => 445,
                'category' => 'Wellness & Spa',
                'tags' => ['Ayurveda', 'Spa', 'Wellness', 'Relaxation'],
                'special_offer' => false,
            ],
            [
                'title' => 'Professional Photography Tour',
                'slug' => 'professional-photography-tour',
                'excerpt' => 'Capture the beauty of Sri Lanka with a professional photographer guide to the most photogenic locations.',
                'location' => 'Multiple Locations',
                'price' => 150.00,
                'duration' => 'Full Day',
                'rating' => 4.8,
                'reviews_count' => 178,
                'category' => 'Photography',
                'tags' => ['Photography', 'Private', 'Professional', 'Custom'],
                'special_offer' => true,
                'discount_percentage' => 15,
            ],
        ];

        foreach ($services as $data) {
            $this->createContent($contentTypeId, $data);
        }
    }

    private function createBlogs($contentTypeId)
    {
        $blogs = [
            [
                'title' => 'Best Time to Visit Sri Lanka: A Seasonal Guide',
                'slug' => 'best-time-visit-sri-lanka',
                'excerpt' => 'Discover the perfect time to visit Sri Lanka based on weather patterns, cultural events, and tourist seasons.',
                'location' => 'Sri Lanka',
                'rating' => 4.6,
                'reviews_count' => 234,
                'category' => 'Travel Tips',
                'tags' => ['Travel Planning', 'Weather', 'Seasons', 'Tips'],
                'special_offer' => false,
            ],
            [
                'title' => 'Top 10 Beaches to Visit This Summer Season',
                'slug' => 'top-10-beaches-summer',
                'excerpt' => 'Sun, sand, and crystal-clear waters—summer is the perfect time to escape to the world\'s most beautiful beaches.',
                'location' => 'Coastal Regions',
                'rating' => 4.7,
                'reviews_count' => 567,
                'category' => 'Beach Destinations',
                'tags' => ['Beaches', 'Summer', 'Coast', 'Swimming'],
                'special_offer' => false,
            ],
            [
                'title' => 'Sri Lankan Cuisine: A Food Lover\'s Paradise',
                'slug' => 'sri-lankan-cuisine-guide',
                'excerpt' => 'Explore the rich flavors and diverse culinary traditions of Sri Lankan cuisine, from street food to fine dining.',
                'location' => 'Nationwide',
                'rating' => 4.8,
                'reviews_count' => 445,
                'category' => 'Food & Culture',
                'tags' => ['Food', 'Cuisine', 'Culture', 'Spices'],
                'special_offer' => false,
            ],
        ];

        foreach ($blogs as $data) {
            $this->createContent($contentTypeId, $data);
        }
    }

    private function createContent($contentTypeId, $data)
    {
        CmsContent::updateOrCreate(
            [
                'cms_content_type_id' => $contentTypeId,
                'slug' => $data['slug'],
            ],
            [
                'title' => $data['title'],
                'excerpt' => $data['excerpt'],
                'body' => $this->generateSampleBody($data['title'], $data['excerpt']),
                'location' => $data['location'],
                'price' => $data['price'] ?? null,
                'price_currency' => 'USD',
                'duration' => $data['duration'] ?? null,
                'rating' => $data['rating'] ?? 0,
                'reviews_count' => $data['reviews_count'] ?? 0,
                'category' => $data['category'] ?? null,
                'difficulty_level' => $data['difficulty_level'] ?? null,
                'tags' => $data['tags'] ?? [],
                'special_offer' => $data['special_offer'] ?? false,
                'discount_percentage' => $data['discount_percentage'] ?? null,
                'status' => 'published',
                'published_at' => now()->subDays(rand(1, 30)),
                'is_featured' => rand(0, 1),
                'is_active' => true,
                'availability_status' => 'available',
            ]
        );
    }

    private function generateSampleBody($title, $excerpt)
    {
        return "<p>{$excerpt}</p>
                <p>This is a detailed description of {$title}. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
                <p>Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.</p>
                <h3>What to Expect</h3>
                <ul>
                    <li>Professional guide</li>
                    <li>All necessary equipment</li>
                    <li>Transportation included</li>
                    <li>Refreshments provided</li>
                </ul>";
    }
}
