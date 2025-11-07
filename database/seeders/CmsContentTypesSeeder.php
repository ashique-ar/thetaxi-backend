<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Website\CmsContentType;

class CmsContentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $contentTypes = [
            [
                'title' => 'Destinations',
                'slug' => 'destinations',
                'description' => 'Travel destinations and places to visit',
                'icon' => 'map-pin',
                'url_prefix' => 'destinations',
                'is_active' => true,
                'display_order' => 1,
                'template_config' => [
                    'fields' => ['location', 'rating', 'category'],
                    'layout' => 'card',
                    'show_price' => false,
                    'show_duration' => false,
                    'show_rating' => true
                ]
            ],
            [
                'title' => 'Things to Do',
                'slug' => 'things-to-do',
                'description' => 'Activities, tours, and experiences',
                'icon' => 'activity',
                'url_prefix' => 'things-to-do',
                'is_active' => true,
                'display_order' => 2,
                'template_config' => [
                    'fields' => ['price', 'duration', 'location', 'rating', 'category'],
                    'layout' => 'card',
                    'show_price' => true,
                    'show_duration' => true,
                    'show_rating' => true
                ]
            ],
            [
                'title' => 'Independent Services',
                'slug' => 'independent-services',
                'description' => 'Standalone travel services and add-ons',
                'icon' => 'briefcase',
                'url_prefix' => 'services',
                'is_active' => true,
                'display_order' => 3,
                'template_config' => [
                    'fields' => ['price', 'duration', 'location', 'rating', 'category'],
                    'layout' => 'card',
                    'show_price' => true,
                    'show_duration' => true,
                    'show_rating' => true
                ]
            ],
            [
                'title' => 'Travel Blog',
                'slug' => 'blogs',
                'description' => 'Travel stories, tips, and inspiration',
                'icon' => 'edit',
                'url_prefix' => 'blog',
                'is_active' => true,
                'display_order' => 4,
                'template_config' => [
                    'fields' => ['location', 'category', 'published_at'],
                    'layout' => 'blog',
                    'show_price' => false,
                    'show_duration' => false,
                    'show_rating' => false
                ]
            ],
            [
                'title' => 'Partners',
                'slug' => 'partners',
                'description' => 'Travel partners and affiliates',
                'icon' => 'users',
                'url_prefix' => 'partners',
                'is_active' => true,
                'display_order' => 5,
                'template_config' => [
                    'fields' => ['category'],
                    'layout' => 'simple',
                    'show_price' => false,
                    'show_duration' => false,
                    'show_rating' => false
                ]
            ]
        ];

        foreach ($contentTypes as $typeData) {
            CmsContentType::updateOrCreate(
                ['slug' => $typeData['slug']],
                $typeData
            );
        }
    }
}
