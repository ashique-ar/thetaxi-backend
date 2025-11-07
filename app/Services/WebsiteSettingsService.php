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
            // Banner Section - Media & Text
            'banner_heading',
            'banner_subheading',
            'banner_award_text',
            'banner_video',
            
            // Partner Section
            'partner_section_title',
            
            // Feature Section - Text, Icons & Vectors
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
            'feature_cta_description',
            
            // About section settings
            'about_section_heading',
            
            // Offer section settings
            'offer_section_description',
            
            // Counter labels
            'counter_travel_experience_label',
            'counter_happy_traveler_label',
            
            // Custom travel section
            'custom_travel_heading',
            'custom_tours_label',
            'tour_guide_label',
            
            // Commitment section
            'commitment_description',
            
            // FAQ section
            'faq_section_title',
            'faq_section_description',
            
            // Travel inspirations
            'travel_story_1_description',
            'travel_story_2_description',
            'travel_story_3_title',
            'feature_card_vector',
            'feature_section_vector1',
            'feature_section_vector2',
            
            // Destinations Section
            'destinations_section_title',
            'destinations_section_description',
            
            // Packages/Things to Do Section
            'packages_section_title',
            'packages_section_description',
            
            // Blog Section
            'blog_section_title',
            'blog_section_description',
            
            // About Section - Text & Images
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
            'counter_people_img_1',
            'counter_people_img_2',
            'counter_people_img_3',
            'counter_people_img_4',
            
            // Partner/Sponsor Logos
            'partner_logo_1',
            'partner_logo_2',
            'partner_logo_3',
            'partner_logo_4',
            'partner_logo_5',
            'partner_logo_6',
            'partner_link_1',
            'partner_link_2',
            'partner_link_3',
            'partner_link_4',
            'partner_link_5',
            'partner_link_6',
            
            // Fallback Images
            'fallback_destination_image',
            'fallback_package_image',
            
            // Offer Slider Images
            'offer_slider_img_1',
            'offer_slider_img_2',
            
            // Packages Section
            'packages_section_title',
            'packages_section_description',
            
            // Why Section - Text & Icons
            'why_section_title',
            'why_section_description',
            'why_feature_1',
            'why_feature_2',
            'why_feature_3',
            'why_feature_4',
            'why_feature_icon_1',
            'why_feature_icon_2',
            'why_feature_icon_3',
            'why_feature_icon_4',
            'why_video_image',
            'tripadvisor_logo',
            'tripadvisor_stars',
            
            // Inspirations Section
            'inspirations_section_title',
            'inspirations_section_description',
            
            // Testimonials Section - Images & Vectors
            'testimonials_section_title',
            'testimonials_section_description',
            'testimonial_vector',
            'testimonial_author_img_1',
            'testimonial_author_img_2',
            'testimonial_author_img_3',
            'testimonial_author_img_4',
            'testimonial_author_img_5',
            
            // Vector Graphics & Decorative Images
            'destination_section_vector',
            'blog_section_vector',
            'faq_section_vector',
            
            // Blog Section Images
            'blog_img_1',
            'blog_img_2',
            'blog_img_3',
            
            // About Page Settings
            'about_page_title',
            'about_page_subtitle',
            'about_page_description',
            'about_hero_heading',
            'about_hero_subheading',
            'about_hero_image',
            'about_breadcrumb_image',
            'founder_name',
            'founder_title',
            'founder_signature',
            'founder_image',
            'services_section_title',
            'services_section_description',
            'service_1_title',
            'service_1_description',
            'service_1_icon',
            'service_2_title',
            'service_2_description',
            'service_2_icon',
            'service_3_title',
            'service_3_description',
            'service_3_icon',
            'service_4_title',
            'service_4_description',
            'service_4_icon',
            
            // Contact Page Settings
            'contact_page_title',
            'contact_page_subtitle',
            'contact_page_description',
            'contact_hero_heading',
            'contact_hero_subheading',
            'contact_address_1',
            'contact_address_1_title',
            'contact_address_1_phone',
            'contact_address_1_email',
            'contact_address_1_address',
            'contact_address_2',
            'contact_address_2_title',
            'contact_address_2_phone',
            'contact_address_2_email',
            'contact_address_2_address',
            'contact_address_3',
            'contact_address_3_title',
            'contact_address_3_phone',
            'contact_address_3_email',
            'contact_address_3_address',
            'contact_form_title',
            'contact_form_description',
            'contact_form_success_message',
            'contact_form_error_message',
            'contact_map_latitude',
            'contact_map_longitude',
            'contact_map_zoom',
            'contact_map_title',
            'contact_breadcrumb_image',
            'company_name',
            'company_website',
            'social_youtube',
            'social_tiktok',
            
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
     * Get all about page-related settings
     */
    public function getAboutPageSettings(): array
    {
        $types = [
            // About Page Content
            'about_page_title',
            'about_page_subtitle',
            'about_page_description',
            'about_hero_heading',
            'about_hero_subheading',
            'about_hero_image',
            
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
            
            // Founder Information
            'founder_name',
            'founder_title',
            'founder_signature',
            'founder_image',
            
            // Services Section
            'services_section_title',
            'services_section_description',
            'service_1_title',
            'service_1_description',
            'service_1_icon',
            'service_2_title',
            'service_2_description',
            'service_2_icon',
            'service_3_title',
            'service_3_description',
            'service_3_icon',
            'service_4_title',
            'service_4_description',
            'service_4_icon',
            
            // Breadcrumb
            'about_breadcrumb_image',
            
            // General company info
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
     * Get all contact page-related settings
     */
    public function getContactPageSettings(): array
    {
        $types = [
            // Contact Page Content
            'contact_page_title',
            'contact_page_subtitle',
            'contact_page_description',
            'contact_hero_heading',
            'contact_hero_subheading',
            
            // Contact Information
            'contact_address_1',
            'contact_address_1_title',
            'contact_address_1_phone',
            'contact_address_1_email',
            'contact_address_1_address',
            
            'contact_address_2',
            'contact_address_2_title',
            'contact_address_2_phone',
            'contact_address_2_email',
            'contact_address_2_address',
            
            'contact_address_3',
            'contact_address_3_title',
            'contact_address_3_phone',
            'contact_address_3_email',
            'contact_address_3_address',
            
            // Contact Form
            'contact_form_title',
            'contact_form_description',
            'contact_form_success_message',
            'contact_form_error_message',
            
            // Map & Location
            'contact_map_latitude',
            'contact_map_longitude',
            'contact_map_zoom',
            'contact_map_title',
            
            // Breadcrumb
            'contact_breadcrumb_image',
            
            // General company info
            'company_name',
            'company_phone',
            'company_email',
            'company_address',
            'company_website',
            'social_facebook',
            'social_twitter',
            'social_instagram',
            'social_linkedin',
            'social_youtube',
            'social_tiktok'
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

    /**
     * Get FAQ page settings
     */
    public function getFaqPageSettings(): array
    {
        $types = [
            // FAQ Page Content
            'faq_page_title',
            'faq_page_subtitle',
            'faq_breadcrumb_image',
            'faq_hero_heading',
            'faq_hero_subheading',
            'faq_hero_background',
            
            // FAQ Section Content
            'faq_section_title',
            'faq_section_subtitle',
            'faq_section_description',
            'faq_search_placeholder',
            'faq_all_categories_text',
            'faq_no_results_text',
            'faq_load_more_text',
            
            // Featured FAQ Section
            'faq_featured_title',
            'faq_featured_subtitle',
            'faq_featured_show_count',
            
            // FAQ Display Options
            'faq_show_categories',
            'faq_show_search',
            'faq_show_featured',
            'faq_items_per_page',
            'faq_enable_accordion',
            'faq_auto_expand',
        ];

        return $this->getMultiple($types);
    }
}