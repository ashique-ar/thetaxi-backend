<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TourFaqSeeder extends Seeder
{
    private const FAQS = [
        [
            'How do route suggestions work?',
            'After you choose places and activities, we may suggest a route with less driving between them. Compare it with your chosen order and select the route you prefer. Visit times and overnight locations can affect which routes work best.',
        ],
        [
            'Are attraction tickets included in the transport price?',
            'No. The price covers your selected vehicle and driver. Attraction entry tickets, safari jeeps, train tickets, and other activity costs are separate. Activity ticket booking is planned for a future release.',
        ],
        [
            'Can I choose which day to visit each place?',
            'Yes. When you add a place or activity, choose the day you plan to visit it. The available days take your overnight locations into account.',
        ],
        [
            'Can I keep my original route instead of the suggested route?',
            'Yes. You can compare your selected order with the suggested route and choose the one you prefer before continuing.',
        ],
        [
            'What if my route or price needs a review?',
            'Choose “Ask our team to review this Tour” and our team will check your plan. Requesting a review does not charge you.',
        ],
        [
            'How do I choose the right vehicle?',
            'Choose a vehicle that has enough seats for everyone travelling and enough luggage space for your bags. The available vehicle types show their passenger and luggage limits.',
        ],
        [
            'When do I confirm and pay for my booking?',
            'After choosing a route and reviewing its price and booking terms, continue to checkout to confirm your booking and follow the available payment steps.',
        ],
        [
            'Can I add a flight number or a special request?',
            'Yes. Add your flight number or any request in the optional notes field when you enter your contact details.',
        ],
        [
            'Can I change my Tour after submitting it?',
            'Contact our booking team with your booking details. They will let you know what changes can be made to your route or arrangements.',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $category = DB::table('faq_categories')->where('slug', 'tours')->first();

            if (!$category) {
                $categoryId = (string) Str::uuid();
                DB::table('faq_categories')->insert([
                    'id' => $categoryId,
                    'name' => 'Tours',
                    'slug' => 'tours',
                    'description' => 'Common questions about planning and booking private Tours.',
                    'sort_order' => 100,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $categoryId = $category->id;
            }

            foreach (self::FAQS as $sortOrder => [$question, $answer]) {
                $exists = DB::table('faqs')
                    ->where('faq_category_id', $categoryId)
                    ->where('question', $question)
                    ->exists();

                if (!$exists) {
                    DB::table('faqs')->insert([
                        'id' => (string) Str::uuid(),
                        'faq_category_id' => $categoryId,
                        'category' => 'Tours',
                        'question' => $question,
                        'answer' => $answer,
                        'sort_order' => $sortOrder + 1,
                        'is_featured' => false,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
