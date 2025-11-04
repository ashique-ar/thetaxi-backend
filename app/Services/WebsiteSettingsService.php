<?php

namespace App\Services;

use App\Models\Website\WebsiteSetting;
use Illuminate\Support\Facades\Cache;

class WebsiteSettingsService
{
    private const CACHE_PREFIX = 'website_settings_';
    private const CACHE_DURATION = 3600; // 1 hour

    /**
     * Get a setting value with caching
     */
    public function get(string $type, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . $type;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($type, $default) {
            return WebsiteSetting::getValue($type, $default);
        });
    }

    /**
     * Set a setting value and clear cache
     */
    public function set(string $type, $value): void
    {
        WebsiteSetting::setValue($type, $value);
        $this->clearCache($type);
    }

    /**
     * Get multiple settings with caching
     */
    public function getMultiple(array $types): array
    {
        $result = [];
        $uncachedTypes = [];

        // Check cache for each type
        foreach ($types as $type) {
            $cacheKey = self::CACHE_PREFIX . $type;
            $cachedValue = Cache::get($cacheKey);
            
            if ($cachedValue !== null) {
                $result[$type] = $cachedValue;
            } else {
                $uncachedTypes[] = $type;
            }
        }

        // Fetch uncached values from database
        if (!empty($uncachedTypes)) {
            $uncachedValues = WebsiteSetting::getValues($uncachedTypes);
            
            foreach ($uncachedValues as $type => $value) {
                $cacheKey = self::CACHE_PREFIX . $type;
                Cache::put($cacheKey, $value, self::CACHE_DURATION);
                $result[$type] = $value;
            }
        }

        return $result;
    }

    /**
     * Get all homepage-related settings
     */
    public function getHomepageSettings(): array
    {
        $types = [
            // Banner Section
            'banner_heading',
            'banner_subheading',
            'banner_video',
            
            // Partner Section
            'partner_section_title',
            
            // Feature Section
            'feature_1_title',
            'feature_1_description',
            'feature_1_icon',
            'feature_2_title',
            'feature_2_description',
            'feature_2_icon',
            'feature_3_title',
            'feature_3_description',
            'feature_3_icon',
            'feature_cta_text',
            'feature_cta_link',
            'feature_card_vector',
            'feature_section_vector',
            'feature_section_vector2',
            
            // Destinations Section
            'destinations_section_title',
            'destination_section_vector',
            
            // About Section
            'about_section_title',
            'about_section_description',
            'about_feature_1',
            'about_feature_2',
            'about_feature_3',
            'about_years_experience',
            'about_years_label',
            'about_customers_count',
            'about_customers_label',
            'about_button_text',
            'about_tours_completed',
            'about_tours_label',
            'about_image_1',
            'about_image_2',
            'about_image_3',
            'about_customer_img_1',
            'about_customer_img_2',
            'about_customer_img_3',
            'about_customer_img_4',
            
            // Packages Section
            'packages_section_title',
            'packages_section_description',
            
            // Why Section
            'why_section_title',
            'why_section_description',
            'why_feature_1',
            'why_feature_1_icon',
            'why_feature_2',
            'why_feature_2_icon',
            'why_feature_3',
            'why_feature_3_icon',
            'why_feature_4',
            'why_feature_4_icon',
            'why_video_thumbnail',
            'why_video_url',
            
            // Inspirations Section
            'inspirations_section_title',
            'inspirations_section_description',
            
            // TripAdvisor Section
            'tripadvisor_logo',
            'tripadvisor_stars',
            'tripadvisor_rating',
            'tripadvisor_url',
            'tripadvisor_label',
            
            // Testimonial Section
            'testimonial_author_img_1',
            'testimonial_author_img_2',
            'testimonial_author_img_3',
            'testimonial_author_img_4',
            'testimonial_author_img_5',
            'testimonial_vector',
            
            // Package and Destination Fallbacks
            'package_fallback_image',
            'destination_fallback_image',
            
            // Offer Slider
            'offer_slider_img_1',
            'offer_slider_img_2',
            
            // Inspiration Fallbacks
            'inspiration_fallback_img_1',
            'inspiration_fallback_img_2',
            
            // General Settings
            'homepage_title',
            'homepage_subtitle',
            'homepage_description',
            'company_phone',
            'company_email',
            'company_address',
            'social_facebook',
            'social_twitter',
            'social_instagram',
            'social_linkedin'
        ];

        return $this->getMultiple($types);
    }

    /**
     * Clear cache for a specific type
     */
    public function clearCache(string $type): void
    {
        $cacheKey = self::CACHE_PREFIX . $type;
        Cache::forget($cacheKey);
    }

    /**
     * Clear all website settings cache
     */
    public function clearAllCache(): void
    {
        $pattern = self::CACHE_PREFIX . '*';
        $keys = Cache::getRedis()->keys($pattern);
        
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }
    }

    /**
     * Update multiple settings at once
     */
    public function updateMultiple(array $settings): void
    {
        foreach ($settings as $type => $value) {
            $this->set($type, $value);
        }
    }
}