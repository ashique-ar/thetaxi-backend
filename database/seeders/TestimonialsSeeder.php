<?php

namespace Database\Seeders;

use App\Models\Website\Testimonial;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestimonialsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $testimonials = [
            [
                'name' => 'James Bonde',
                'position' => 'Company Traveler',
                'company' => 'Adventure Seeker',
                'content' => 'This was the best trip of my life! Everything was perfectly planned, from airport pickup to guided tours. The accommodations were fantastic, and the itinerary was well-balanced. Highly recommended!',
                'rating' => 5,
                'location' => 'New York, USA',
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Sarah Wilson',
                'position' => 'Travel Blogger',
                'company' => 'Wanderlust Chronicles',
                'content' => 'Exceptional service from start to finish. The team went above and beyond to ensure our comfort and safety. The hidden gems they showed us were absolutely breathtaking!',
                'rating' => 5,
                'location' => 'London, UK',
                'is_featured' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Michael Chen',
                'position' => 'Business Executive',
                'company' => 'Tech Solutions Inc',
                'content' => 'Professional, reliable, and truly caring about their customers. They handled all the logistics seamlessly, allowing us to focus on enjoying our vacation. Will definitely book again!',
                'rating' => 5,
                'location' => 'Singapore',
                'is_featured' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Emma Rodriguez',
                'position' => 'Photographer',
                'company' => 'Creative Lens Studio',
                'content' => 'The attention to detail was incredible. Every moment was captured perfectly, and the local guides were knowledgeable and friendly. This exceeded all my expectations!',
                'rating' => 4,
                'location' => 'Barcelona, Spain',
                'is_featured' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'David Thompson',
                'position' => 'Retired Teacher',
                'company' => 'Education Veteran',
                'content' => 'At my age, comfort and reliability are paramount. Company delivered on both fronts. The pace was perfect, and every detail was thoughtfully planned.',
                'rating' => 5,
                'location' => 'Sydney, Australia',
                'is_featured' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($testimonials as $testimonial) {
            Testimonial::create(array_merge($testimonial, [
                'id' => Str::uuid(),
                'is_active' => true,
            ]));
        }
    }
}
