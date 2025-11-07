<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Website\Faq;

class FaqsSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            [
                'question' => 'What Services Does Your Travel Agency Provide?',
                'answer' => 'A travel agency typically provides a wide range of services to ensure a smooth and enjoyable travel experience. As like- <span>Hotel booking, Flight Booking, Visa & Passport assistance, Car rentals, Tour packages, Travel insurance, Customized Travel Package etc.</span>',
                'category' => 'Services',
                'sort_order' => 1,
                'is_active' => true,
                'is_featured' => true
            ],
            [
                'question' => 'Can You Customize Travel Packages?',
                'answer' => 'Absolutely! We offer fully customized travel packages based on your interests, budget, and travel dates. Whether you\'re looking for <span>an adventure vacation, a romantic getaway, or a group tour</span>, our team will tailor every detail to create a personalized travel experience just for you.',
                'category' => 'Packages',
                'sort_order' => 2,
                'is_active' => true,
                'is_featured' => true
            ],
            [
                'question' => 'Can I Book Flights, Hotels, and Tours Separately?',
                'answer' => 'Yes, you can! We provide the flexibility to book <span>flights, hotels, and tours individually or as a complete package</span>. Whether you need only accommodation, or want to add a tour later — we\'re here to help you plan each component of your trip according to your preferences.',
                'category' => 'Booking',
                'sort_order' => 3,
                'is_active' => true,
                'is_featured' => true
            ],
            [
                'question' => 'What are Your Accepted Payment Methods?',
                'answer' => 'We accept a variety of <span>payment methods</span> to make your booking process convenient and secure. You can pay using major credit cards, debit cards, bank transfers, and select digital payment platforms.',
                'category' => 'Payment',
                'sort_order' => 4,
                'is_active' => true,
                'is_featured' => true
            ],
            [
                'question' => 'What Travel Documents are Required for International Travel?',
                'answer' => 'For international travel, you\'ll typically need several important <span>travel documents</span>. These include a valid passport, appropriate visa (if required), travel insurance, vaccination certificates (if applicable), and flight/accommodation confirmations.',
                'category' => 'Documents',
                'sort_order' => 5,
                'is_active' => true,
                'is_featured' => true
            ],
            [
                'question' => 'How Far in Advance Should I Book My Trip?',
                'answer' => 'We recommend booking <span>domestic trips 2-4 weeks in advance and international trips 6-8 weeks ahead</span>. However, last-minute bookings are also possible depending on availability. Early booking often provides better rates and more accommodation options.',
                'category' => 'Booking',
                'sort_order' => 6,
                'is_active' => true,
                'is_featured' => false
            ]
        ];

        foreach ($faqs as $faqData) {
            Faq::create($faqData);
        }
    }
}
