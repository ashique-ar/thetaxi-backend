<?php

namespace App\Services;

use App\Models\Website\WebsiteSetting;
use App\Models\BusinessSetting;
use App\Models\Company;
use Illuminate\Support\Facades\Cache;

class WebsiteSettingsService
{
    private const CACHE_PREFIX = 'website_settings_';
    private const CACHE_DURATION = 3600; // 1 hour
    private const DEFAULT_BRAND_NAME = 'Company';
    private const DEFAULT_TAGLINE = 'Your Trusted Transport Partner';
    private const DEFAULT_LOGO = 'assets/img/header-logo.png';
    private const BUSINESS_OWNED_KEYS = [
        'site_name',
        'site_tagline',
        'company_name',
        'company_email',
        'company_phone',
        'company_whatsapp',
        'company_address',
        'company_website',
        'content_generation_url',
        'site_timezone',
        'default_currency',
        'portal_title',
        'portal_logo',
        'brand_color_primary',
        'brand_color_primary_light',
        'brand_color_primary_dark',
        'brand_color_secondary',
        'brand_color_secondary_light',
        'brand_color_secondary_dark',
        'brand_color_accent',
        'brand_color_accent_light',
        'brand_color_accent_dark',
        'portal_theme',
        'portal_scheme',
        'portal_sidebar_appearance',
        'portal_sidebar_style',
        'booking_base_currency',
        'booking_advance_hours',
        'booking_max_days',
        'cancellation_allowed',
        'cancellation_hours',
        'auto_dispatch_enabled',
        'include_garage_distance_in_pricing',
        'feature_corporate_management_enabled',
        'feature_vehicle_return_management_enabled',
        'assignment_enable_qc_stage',
        'assignment_enable_maintenance_stage',
        'driver_mobile_latest_version',
        'driver_mobile_mandatory_update',
        'driver_mobile_update_message',
        'ssl_force',
        'security_headers_enabled',
        'content_security_policy',
        'rate_limiting_enabled',
        'rate_limit_per_minute',
        'maintenance_mode',
        'maintenance_message',
        'mail_from_name',
        'mail_from_address',
        'booking_confirmation_enabled',
        'booking_reminder_enabled',
        'contact_form_notification',
        'email_footer_text',
    ];
    private const CATEGORY_METHODS = [
        'general' => 'getGeneralSettings',
        'homepage' => 'getHomepageSettings',
        'header' => 'getHeaderSettings',
        'footer' => 'getFooterSettings',
        'about' => 'getAboutPageSettings',
        'faq' => 'getFaqPageSettings',
        'corporate' => 'getCorporateSettings',
        'pointToPoint' => 'getPointToPointSettings',
        'point-to-point' => 'getPointToPointSettings',
        'seo' => 'getSeoSettings',
        'social_media' => 'getSocialMediaSettings',
        'social-media' => 'getSocialMediaSettings',
        'contact' => 'getContactPageSettings',
        'payment' => 'getPaymentSettings',
        'booking' => 'getBookingSettings',
        'driverMobile' => 'getDriverMobileSettings',
        'driver-mobile' => 'getDriverMobileSettings',
        'security' => 'getSecuritySettings',
        'email' => 'getEmailSettings',
        'sms' => 'getSmsSettings',
        'appearance' => 'getAppearanceSettings',
        'branding' => 'getBrandingSettings',
    ];

    /**
     * Get a setting value with caching
     */
    public function get(string $type, $default = null)
    {
        if (in_array($type, self::BUSINESS_OWNED_KEYS, true)) {
            $businessSetting = BusinessSetting::query()->where('type', $type)->first();
            if ($businessSetting) {
                return $businessSetting->value;
            }
        }

        $companyId = $this->resolveCurrentCompanyId();
        $cacheKey = self::CACHE_PREFIX . ($companyId ?: 'global') . '_' . $type;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($type, $default, $companyId) {
            return WebsiteSetting::getValue($type, $default, $companyId);
        });
    }

    /**
     * Set a setting value and clear cache
     */
    public function set(string $type, $value, ?string $companyId = null): void
    {
        $companyId ??= $this->resolveCurrentCompanyId();
        WebsiteSetting::setValue($type, $value, $companyId);
        $this->clearCache($type, $companyId);
    }

    /**
     * Get multiple settings with caching
     */
    public function getMultiple(array $types): array
    {
        $companyId = $this->resolveCurrentCompanyId();
        $result = [];
        $uncachedTypes = [];
        $cacheKeysByType = [];

        foreach ($types as $type) {
            $cacheKeysByType[$type] = self::CACHE_PREFIX . ($companyId ?: 'global') . '_' . $type;
        }

        $cachedValues = Cache::many(array_values($cacheKeysByType));

        foreach ($cacheKeysByType as $type => $cacheKey) {
            $cachedValue = $cachedValues[$cacheKey] ?? null;

            if ($cachedValue !== null) {
                $result[$type] = $cachedValue;
            } else {
                $uncachedTypes[] = $type;
            }
        }

        // Fetch uncached values from database
        if (!empty($uncachedTypes)) {
            $uncachedValues = WebsiteSetting::getValues($uncachedTypes, $companyId);
            $valuesToCache = [];

            foreach ($uncachedValues as $type => $value) {
                $cacheKey = $cacheKeysByType[$type];
                $valuesToCache[$cacheKey] = $value;
                $result[$type] = $value;
            }

            Cache::putMany($valuesToCache, self::CACHE_DURATION);
        }

        $businessValues = BusinessSetting::query()
            ->whereIn('type', array_values(array_intersect($types, self::BUSINESS_OWNED_KEYS)))
            ->pluck('value', 'type')
            ->all();

        foreach ($businessValues as $type => $value) {
            $result[$type] = $value;
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
            'banner_image',
            'theme_02_slider_images',
            'banner_background',
            'homepage_breadcrumb_image',

            // Partner Section
            'partner_section_title',
            'partner_logo_1',

            // Featured Vehicles
            'featured_vehicles_title',
            'featured_vehicles_description',
            'featured_vehicles_button_text',
            'vehicles_view_all_text',
            'features_section_title',

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
            'feature_card_vector',
            'feature_section_vector1',
            'feature_section_vector2',

            // About section settings (homepage)
            'about_section_heading',
            'about_section_title',
            'about_section_description',
            'about_section_image',
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

            // Offer section settings
            'offer_section_description',
            'offer_slider_img_1',
            'offer_slider_img_2',

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

            // Ratings & Reviews
            'tripadvisor_logo',
            'tripadvisor_stars',
            'tripadvisor_review',

            // Testimonials Section - Images & Vectors
            'testimonials_section_title',
            'testimonials_section_description',
            'testimonials_section_image',
            'testimonial_vector',
            'testimonial_author_img_1',
            'testimonial_author_img_2',
            'testimonial_author_img_3',
            'testimonial_author_img_4',
            'testimonial_author_img_5',

            // Destinations Section
            'destinations_section_title',
            'destinations_section_description',
            'fallback_destination_image',

            // Packages/Things to Do Section
            'packages_section_title',
            'packages_section_description',
            'features_section_image',
            'fallback_package_image',

            // Blog Section
            'blog_section_title',
            'blog_section_description',
            'destination_section_vector',
            'blog_section_vector',
            'blog_img_1',
            'blog_img_2',
            'blog_img_3',

            // Inspirations Section
            'inspirations_section_title',
            'inspirations_section_description',

            // FAQ section
            'faq_section_title',
            'faq_section_description',
            'faq_section_vector',

            // Counter labels
            'counter_travel_experience_label',
            'counter_happy_traveler_label',

            // Custom travel section
            'custom_travel_heading',
            'custom_tours_label',
            'tour_guide_label',

            // Commitment section
            'commitment_description',

            // Travel inspirations
            'travel_story_1_description',
            'travel_story_2_description',
            'travel_story_3_title',

            // Partner/Sponsor Logos
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
            'social_linkedin',
            'social_youtube',
            'social_tiktok',
            'company_name',
            'company_website',
            'video_section_help_text'
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
            'about_section_subtitle',
            'about_section_description_1',
            'about_section_description_2',
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
            // Keep older keys for backward compatibility
            'about_services_section_title',
            'about_journey_section_title',
            'about_journey_section_description',

            // Canonical keys used across templates and view composers
            'about_services_title',
            'about_journey_title',
            'about_journey_description',

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

            // Breadcrumb & Media
            'about_breadcrumb_image',
            'about_section_image',

            // Testimonials
            'about_testimonial_img_1',
            'about_testimonial_img_2',
            'about_testimonial_img_3',
            'about_testimonial_img_4',
            'about_testimonial_img_5',

            // Rating Logos
            'about_tripadvisor_logo',
            'about_trustpilot_logo',
            'about_why_travel_title',
            'about_why_travel_description',
            'about_partners_title',
            'partners_section_title',

            // New dynamic content arrays for About page
            'about_services',            // JSON array of service items {title,description,icon}
            'about_journey_items',       // JSON array of journey steps {title,description,image,order}
            'about_why_cards',           // JSON array of why-cards {title,description,icon}

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
            'contact_form_name_label',
            'contact_form_name_placeholder',
            'contact_form_email_label',
            'contact_form_email_placeholder',
            'contact_form_phone_label',
            'contact_form_phone_placeholder',
            'contact_form_destination_label',
            'contact_form_destination_placeholder',
            'contact_form_destination_option_1',
            'contact_form_destination_option_2',
            'contact_form_destination_option_3',
            'contact_form_destination_option_4',
            'contact_form_message_label',
            'contact_form_message_placeholder',
            'contact_form_privacy_text',
            'contact_form_submit_text',

            // Map & Location
            'contact_map_latitude',
            'contact_map_longitude',
            'contact_map_zoom',
            'contact_map_title',
            'contact_map_embed_url',
            'contact_hero_image',
            'contact_map_image',
            'emergency_contact',

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
     * Get corporate transfers page settings
     */
    public function getCorporateSettings(): array
    {
        $types = [
            'corporate_banner_image',
            'corporate_hero_heading',
            'corporate_hero_subheading',
            'corporate_form_company_placeholder',
            'corporate_form_contact_placeholder',
            'corporate_form_email_placeholder',
            'corporate_form_phone_placeholder',
            'corporate_form_requirements_placeholder',
            'corporate_form_submit_text',
            'corporate_feature_section_heading',
            'corporate_feature_section_description',
            'corporate_feature_1_title',
            'corporate_feature_1_description',
            'corporate_feature_1_icon',
            'corporate_feature_2_title',
            'corporate_feature_2_description',
            'corporate_feature_2_icon',
            'corporate_feature_3_title',
            'corporate_feature_3_description',
            'corporate_feature_3_icon',
            'corporate_feature_card_vector',
            'corporate_services_kicker',
            'corporate_services_heading',
            'corporate_services_description',
            'corporate_services_image',
            'corporate_services_feature_1',
            'corporate_services_feature_2',
            'corporate_services_feature_3',
            'corporate_services_feature_4',
            'corporate_services_feature_5',
            'corporate_services_feature_6',
            'corporate_benefits_kicker',
            'corporate_benefits_heading',
            'corporate_benefit_1_title',
            'corporate_benefit_1_description',
            'corporate_benefit_1_icon',
            'corporate_benefit_2_title',
            'corporate_benefit_2_description',
            'corporate_benefit_2_icon',
            'corporate_benefit_3_title',
            'corporate_benefit_3_description',
            'corporate_benefit_3_icon',
            'corporate_benefit_4_title',
            'corporate_benefit_4_description',
            'corporate_benefit_4_icon',
            'corporate_faq_kicker',
            'corporate_faq_heading',
            'corporate_faq_1_question',
            'corporate_faq_1_answer',
            'corporate_faq_2_question',
            'corporate_faq_2_answer',
            'corporate_faq_3_question',
            'corporate_faq_3_answer',
            'corporate_faq_4_question',
            'corporate_faq_4_answer',
            'corporate_faq_5_question',
            'corporate_faq_5_answer',
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get point-to-point page settings
     */
    public function getPointToPointSettings(): array
    {
        $types = [
            'point_to_point_banner_video',
            'point_to_point_banner_image',
            'point_to_point_hero_heading',
            'point_to_point_hero_subheading',
            'point_to_point_pickup_placeholder',
            'point_to_point_dropoff_placeholder',
            'point_to_point_date_placeholder',
            'point_to_point_time_placeholder',
            'point_to_point_return_toggle_label',
            'point_to_point_return_title',
            'point_to_point_return_date_placeholder',
            'point_to_point_return_time_placeholder',
            'point_to_point_passengers_label',
            'point_to_point_submit_text',
            'point_to_point_feature_section_heading',
            'point_to_point_feature_section_description',
            'point_to_point_feature_1_title',
            'point_to_point_feature_1_description',
            'point_to_point_feature_1_icon',
            'point_to_point_feature_2_title',
            'point_to_point_feature_2_description',
            'point_to_point_feature_2_icon',
            'point_to_point_feature_3_title',
            'point_to_point_feature_3_description',
            'point_to_point_feature_3_icon',
            'point_to_point_feature_card_vector',
            'point_to_point_services_kicker',
            'point_to_point_services_heading',
            'point_to_point_services_description',
            'point_to_point_services_image',
            'point_to_point_services_feature_1',
            'point_to_point_services_feature_2',
            'point_to_point_services_feature_3',
            'point_to_point_services_feature_4',
            'point_to_point_services_feature_5',
            'point_to_point_faq_kicker',
            'point_to_point_faq_heading',
            'point_to_point_faq_1_question',
            'point_to_point_faq_1_answer',
            'point_to_point_faq_2_question',
            'point_to_point_faq_2_answer',
            'point_to_point_faq_3_question',
            'point_to_point_faq_3_answer',
            'point_to_point_faq_4_question',
            'point_to_point_faq_4_answer',
        ];

        return $this->getMultiple($types);
    }

    /**
     * Clear cache for a specific type
     */
    public function clearCache(string $type, ?string $companyId = null): void
    {
        Cache::forget(self::CACHE_PREFIX . 'global_' . $type);
        if ($companyId) {
            Cache::forget(self::CACHE_PREFIX . $companyId . '_' . $type);
        }
        Cache::forget('global_settings_flattened');
        Cache::forget('global_settings_flattened_global');
        Cache::forget('active_theme_setting');
        if ($type === 'default_currency') {
            Cache::forget('display_currencies_for_views');
            Cache::forget('global_currency_data');
        }
        if ($companyId) {
            Cache::forget('global_settings_flattened_' . $companyId);
        }
    }

    /**
     * Clear all website settings cache
     */
    public function clearAllCache(): void
    {
        Cache::forget('global_settings_flattened');
        Cache::forget('global_settings_flattened_global');
        Cache::forget('active_theme_setting');

        if (method_exists(Cache::getStore(), 'getRedis')) {
            $pattern = self::CACHE_PREFIX . '*';
            $keys = Cache::getRedis()->keys($pattern);

            if (!empty($keys)) {
                Cache::getRedis()->del($keys);
            }
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
            'faq_section_title',
            'faq_section_description',
            'faq_page_subtitle',
            'faq_breadcrumb_image',
            'faq_page_banner_image',
            'faq_section_vector',
            'faq_hero_heading',
            'faq_hero_subheading',
            'faq_hero_background',

            // FAQ Section Content
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

    /**
     * Get all general site settings
     */
    public function getGeneralSettings(): array
    {
        $types = [
            'site_name',
            'brand_name',
            'brand_tagline',
            'brand_short_name',
            'site_tagline',
            'company_name',
            'company_phone',
            'company_whatsapp',
            'company_email',
            'company_address',
            'company_website',
            'content_generation_url',
            'site_timezone',
            'default_currency'
        ];

        return $this->withCanonicalBranding($this->getMultiple($types));
    }

    /**
     * Get all SEO settings
     */
    public function getSeoSettings(): array
    {
        $types = [
            'seo_title_template',
            'seo_meta_description',
            'seo_keywords',
            'seo_og_image',
            'seo_twitter_card',
            'google_analytics_id',
            'google_tag_manager_id',
            'google_ads_id',
            'google_ads_conversion_id',
            'google_ads_conversion_label',
            'facebook_pixel_id',
            'meta_verification_code',
            'google_site_verification',
            'sitemap_auto_generate',
            'sitemap_auto_ping',
            'sitemap_ping_urls',
            'sitemap_failure_notify',
            'sitemap_failure_notify_email'
        ];

        // Add page-specific SEO keys
        $pages = ['home', 'about', 'taxi', 'contact', 'things_to_do', 'services', 'corporate_transfers', 'cart', 'checkout'];
        foreach ($pages as $page) {
            $types[] = "seo_{$page}_title";
            $types[] = "seo_{$page}_description";
            $types[] = "seo_{$page}_keywords";
        }

        return $this->getMultiple($types);
    }

    /**
     * Get all social media settings
     */
    public function getSocialMediaSettings(): array
    {
        $types = [
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
     * Get all payment settings
     */
    public function getPaymentSettings(): array
    {
        $types = [
            'payment_methods_enabled',
            'payment_online_enabled',
            'payment_offline_enabled',
            'webxpay_enabled',
            'webxpay_merchant_secret',
            'webxpay_public_key',
            'webxpay_api_url',
            'webxpay_api_username',
            'webxpay_api_password',
            'webxpay_checkout_url',
            'webxpay_return_url',
            'webxpay_cancel_url',
            'webxpay_notify_url',
            'webxpay_currency',
            'advance_payment_enabled',
            'advance_payment_percentage',
            'advance_payment_min_amount',
            'service_fee_enabled',
            'service_fee_type',
            'service_fee_amount',
            'service_fee_min_amount',
            'service_fee_max_amount',
            'tax_enabled',
            'tax_rate',
            'tax_label',
            'tax_description',
            'vat_enabled',
            'vat_rate',
            'vat_label',
            'vat_description',
            'vat_applies_to_service_fee'
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all security settings
     */
    public function getSecuritySettings(): array
    {
        $types = [
            'ssl_force',
            'security_headers_enabled',
            'content_security_policy',
            'rate_limiting_enabled',
            'rate_limit_per_minute',
            'maintenance_mode',
            'maintenance_message'
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all email settings
     */
    public function getEmailSettings(): array
    {
        $types = [
            'mail_from_name',
            'mail_from_address',
            'booking_confirmation_enabled',
            'booking_reminder_enabled',
            'contact_form_notification',
            'email_footer_text'
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all SMS settings
     */
    public function getSmsSettings(): array
    {
        $types = [
            'sms_enabled',
            'sms_provider',
            'sms_default_sender_mask',
            'sms_allow_mask_override',
            'sms_queue_enabled',
            'sms_bulk_chunk_size',
            'sms_webhook_secret',
            'sms_booking_status_enabled',
            'sms_esms_base_url',
            'sms_esms_username',
            'sms_esms_password',
            'sms_esms_api_key',
            'sms_esms_delivery_callback_url',
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all booking settings
     */
    public function getBookingSettings(): array
    {
        $types = [
            'booking_advance_hours',
            'booking_notice_html',
            'booking_max_days',
            'cancellation_allowed',
            'cancellation_hours',
            'auto_dispatch_enabled',
            'guest_booking_enabled',
            'booking_base_currency',
            'booking_submit_inquiry_label',
            'booking_search_submit_label',
            'booking_return_trip_toggle_label',
            'booking_return_route_label',
            'booking_return_pickup_placeholder',
            'booking_return_dropoff_placeholder',
            'booking_return_date_label',
            'booking_return_date_placeholder',
            'booking_return_time_label',
            'booking_return_discount_label',
            'booking_return_discount_value',
            'booking_dropoff_label',
            'booking_dropoff_placeholder',
            'booking_date_placeholder',
            'booking_airport_select_placeholder',
            'enable_airport_transfers',
            'enable_ride_now',
            'enable_day_rental',
            'enable_selfdrive',
            'enable_with_drive',
            'enable_wedding',
            'enable_corporate',
            'show_return_trip_toggle',
            'include_garage_distance_in_pricing',
            'assignment_enable_qc_stage',
            'assignment_enable_maintenance_stage',
            'feature_corporate_management_enabled',
            'feature_vehicle_return_management_enabled',
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all driver mobile app settings.
     */
    public function getDriverMobileSettings(): array
    {
        $types = [
            'driver_mobile_latest_version',
            'driver_mobile_mandatory_update',
            'driver_mobile_update_message',
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get the non-sensitive feature flags needed to build and guard portal routes.
     */
    public function getBusinessFeatureFlags(): array
    {
        return $this->getMultiple([
            'feature_corporate_management_enabled',
            'feature_vehicle_return_management_enabled',
        ]);
    }

    /**
     * Get all appearance settings
     */
    public function getHeaderSettings(): array
    {
        $types = [
            // Canonical header labels used in blades
            'header_help_label',
            'header_cart_label',
            'header_quick_search_label',
            // Legacy keys (backward compatibility)
            'header_need_help_text',
            'header_cart_text',
            'header_search_placeholder',
            'header_quick_search_1',
            'header_quick_search_2',
            'header_quick_search_3',
            'header_quick_search_4',
            'header_logo_alt_text',
            'logo_header',
            'logo_header_alt',
            'logo_mobile',
            'logo_mobile_alt'
        ];

        return $this->getMultiple($types);
    }

    public function getFooterSettings(): array
    {
        $types = [
            // Canonical footer labels used in blades
            'footer_inquiry_heading',
            'footer_inquiry_subheading',
            'footer_email_label',
            'footer_phone_label',
            'footer_company_tagline',
            // Legacy keys (backward compatibility)
            'footer_inquiry_title',
            'footer_inquiry_subtitle',
            'footer_whatsapp_label',
            'footer_mail_label',
            'footer_call_label',
            'footer_whatsapp_number',
            'footer_services_title',
            'footer_routes_title',
            'footer_support_title',
            'footer_copyright_text',
            'footer_description',
            // Services Links
            'footer_services_link_1_text',
            'footer_services_link_1_url',
            'footer_services_link_2_text',
            'footer_services_link_2_url',
            'footer_services_link_3_text',
            'footer_services_link_3_url',
            'footer_services_link_4_text',
            'footer_services_link_4_url',
            'footer_services_link_5_text',
            'footer_services_link_5_url',
            'footer_services_link_6_text',
            'footer_services_link_6_url',
            'footer_services_link_7_text',
            'footer_services_link_7_url',
            'footer_services_link_8_text',
            'footer_services_link_8_url',
            'footer_services_link_9_text',
            'footer_services_link_9_url',
            'footer_services_link_10_text',
            'footer_services_link_10_url',
            // Routes Links
            'footer_routes_link_1_text',
            'footer_routes_link_1_url',
            'footer_routes_link_2_text',
            'footer_routes_link_2_url',
            'footer_routes_link_3_text',
            'footer_routes_link_3_url',
            'footer_routes_link_4_text',
            'footer_routes_link_4_url',
            'footer_routes_link_5_text',
            'footer_routes_link_5_url',
            'footer_routes_link_6_text',
            'footer_routes_link_6_url',
            'footer_routes_link_7_text',
            'footer_routes_link_7_url',
            'footer_routes_link_8_text',
            'footer_routes_link_8_url',
            'footer_routes_link_9_text',
            'footer_routes_link_9_url',
            'footer_routes_link_10_text',
            'footer_routes_link_10_url',
            // Support Links
            'footer_support_link_1_text',
            'footer_support_link_1_url',
            'footer_support_link_2_text',
            'footer_support_link_2_url',
            'footer_support_link_3_text',
            'footer_support_link_3_url',
            'footer_support_link_4_text',
            'footer_support_link_4_url',
            'footer_support_link_5_text',
            'footer_support_link_5_url',
            'footer_support_link_6_text',
            'footer_support_link_6_url',
            'footer_support_link_7_text',
            'footer_support_link_7_url',
            'footer_support_link_8_text',
            'footer_support_link_8_url',
            'footer_support_link_9_text',
            'footer_support_link_9_url',
            'footer_support_link_10_text',
            'footer_support_link_10_url',
            // Footer Media
            'logo_footer',
            'logo_footer_alt',
            'footer_breadcrumb_image'
        ];

        return $this->getMultiple($types);
    }

    public function getAppearanceSettings(): array
    {
        $types = [
            'active_theme',
            'primary_color',
            'secondary_color',
            'tertiary_color',
            'logo_header',
            'logo_header_alt',
            'logo_header_alt_text',
            'logo_footer',
            'logo_footer_alt',
            'logo_footer_alt_text',
            'logo_mobile',
            'logo_mobile_alt',
            'logo_mobile_alt_text',
            'favicon',
            'favicon_url',
            'cms_content_placeholder_image',
            'brand_image_1',
            'brand_image_2',
            'brand_image_3',
            'theme_02_slider_images'
        ];

        return $this->getMultiple($types);
    }

    /**
     * Get all settings for a specific category
     */
    public function getCategorySettings(string $category): array
    {
        $method = self::CATEGORY_METHODS[$category] ?? null;

        if ($method && method_exists($this, $method)) {
            return $this->$method();
        }

        throw new \InvalidArgumentException("Invalid settings category: {$category}");
    }

    public function getCategoryKeys(string $category): array
    {
        return array_keys($this->getCategorySettings($category));
    }

    /**
     * Update settings for a specific category
     */
    public function updateCategorySettings(string $category, array $settings): void
    {
        $validSettings = array_flip($this->getCategoryKeys($category));

        foreach ($settings as $type => $value) {
            if (!isset($validSettings[$type])) {
                continue;
            }

            $this->set($type, $value);
        }
    }

    public function resolveCurrentCompanyId(): ?string
    {
        if (!app()->bound('request')) {
            return null;
        }

        $request = request();
        $companyId = $request->header('X-Company-Id') ?: $request->query('company_id');
        if ($companyId) {
            return (string) $companyId;
        }

        $userCompanyId = optional($request->user())->company_id;
        if ($userCompanyId) {
            return (string) $userCompanyId;
        }

        $host = strtolower((string) $request->getHost());
        if ($host === '') {
            return null;
        }

        $company = Company::query()
            ->where('is_active', true)
            ->where(function ($query) use ($host) {
                $query->whereRaw('LOWER(domain) = ?', [$host])
                    ->orWhereRaw('LOWER(website) = ?', [$host])
                    ->orWhereRaw('LOWER(website) = ?', ['https://' . $host])
                    ->orWhereRaw('LOWER(website) = ?', ['http://' . $host]);
            })
            ->first(['id']);

        return $company?->id;
    }

    /**
     * Get all branding settings (public - no auth required)
     */
    public function getBrandingSettings(): array
    {
        $types = [
            // Canonical public/company identity
            'site_name',
            'site_tagline',
            'company_name',
            'company_phone',
            'company_whatsapp',
            'company_email',
            'company_address',
            'company_website',

            // Company Branding
            'brand_name',
            'brand_tagline',
            'brand_short_name',

            // Logos
            'brand_logo_primary',
            'brand_logo_secondary',
            'brand_logo_icon',
            'brand_favicon',

            // Colors - Primary
            'brand_color_primary',
            'brand_color_primary_light',
            'brand_color_primary_dark',

            // Colors - Secondary
            'brand_color_secondary',
            'brand_color_secondary_light',
            'brand_color_secondary_dark',

            // Colors - Accent
            'brand_color_accent',
            'brand_color_accent_light',
            'brand_color_accent_dark',

            // Portal Specific
            'portal_title',
            'portal_logo',
            'portal_theme',
            'portal_scheme',
            'portal_sidebar_appearance',
            'portal_sidebar_style',
            // Legacy/alternate key used in admin UI and helpers
            'active_theme',
            
            // Footer
            'footer_copyright_text',
            'footer_company_name',
        ];

        return $this->withCanonicalBranding($this->getMultiple($types));
    }

    /**
     * Normalize legacy/duplicate branding keys into one usable shape.
     *
     * Canonical identity keys are brand_name, company_name, company_* contact
     * fields, brand_logo_primary, brand_favicon, and portal_title. Legacy keys
     * are still populated so older Blade/Angular templates keep working.
     */
    public function withCanonicalBranding(array $settings): array
    {
        $brandName = $this->firstFilled($settings, ['brand_name', 'site_name', 'company_name'], self::DEFAULT_BRAND_NAME);
        $companyName = $this->firstFilled($settings, ['company_name', 'brand_name', 'site_name'], $brandName);
        $tagline = $this->firstFilled($settings, ['brand_tagline', 'site_tagline'], self::DEFAULT_TAGLINE);
        $shortName = $this->firstFilled($settings, ['brand_short_name', 'brand_name', 'site_name'], $brandName);
        $primaryLogo = $this->firstFilled($settings, ['brand_logo_primary', 'logo_header', 'portal_logo'], self::DEFAULT_LOGO);
        $favicon = $this->firstFilled($settings, ['brand_favicon', 'favicon', 'favicon_url'], 'favicon.ico');

        $settings['brand_name'] = $brandName;
        $settings['brand_short_name'] = $shortName;
        $settings['brand_tagline'] = $tagline;
        $settings['site_name'] = $this->filled($settings['site_name'] ?? null) ? $settings['site_name'] : $brandName;
        $settings['site_tagline'] = $this->filled($settings['site_tagline'] ?? null) ? $settings['site_tagline'] : $tagline;
        $settings['company_name'] = $companyName;
        $settings['company_whatsapp'] = $this->firstFilled($settings, ['company_whatsapp', 'footer_whatsapp_number'], $settings['company_phone'] ?? '');
        $settings['footer_company_name'] = $this->firstFilled($settings, ['footer_company_name'], $companyName);

        $settings['brand_logo_primary'] = $primaryLogo;
        $settings['brand_logo_secondary'] = $this->firstFilled($settings, ['brand_logo_secondary', 'logo_footer'], $primaryLogo);
        $settings['brand_logo_icon'] = $this->firstFilled($settings, ['brand_logo_icon', 'logo_mobile'], $primaryLogo);
        $settings['brand_favicon'] = $favicon;
        $settings['logo_header'] = $this->firstFilled($settings, ['logo_header'], $primaryLogo);
        $settings['logo_footer'] = $this->firstFilled($settings, ['logo_footer'], $settings['brand_logo_secondary']);
        $settings['logo_mobile'] = $this->firstFilled($settings, ['logo_mobile'], $settings['brand_logo_icon']);
        $settings['favicon'] = $favicon;
        $settings['portal_logo'] = $this->firstFilled($settings, ['portal_logo'], $primaryLogo);
        $settings['portal_title'] = $this->firstFilled($settings, ['portal_title'], $brandName . ' | Portal');
        $settings['portal_theme'] = $this->firstFilled($settings, ['portal_theme'], 'theme-brand');
        $settings['portal_scheme'] = $this->normalizeAllowed(
            $this->firstFilled($settings, ['portal_scheme'], 'light'),
            ['light', 'dark', 'auto'],
            'light'
        );
        $settings['portal_sidebar_appearance'] = $this->normalizeAllowed(
            $this->firstFilled($settings, ['portal_sidebar_appearance'], 'default'),
            ['default', 'dense', 'thin', 'compact'],
            'default'
        );
        $settings['portal_sidebar_style'] = $this->normalizeAllowed(
            $this->firstFilled($settings, ['portal_sidebar_style'], 'dark'),
            ['dark', 'light', 'brand'],
            'dark'
        );

        return $settings;
    }

    private function normalizeAllowed(?string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function firstFilled(array $settings, array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            if ($this->filled($settings[$key] ?? null)) {
                return trim((string) $settings[$key]);
            }
        }

        return $default;
    }

    private function filled($value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    public function resolveCompanyId(): ?string
    {
        return $this->resolveCurrentCompanyId();
    }

    /**
     * Get all settings organized by categories
     */
    public function getAllCategorizedSettings(?array $categories = null): array
    {
        $settings = [
            'general' => $this->getGeneralSettings(),
            'homepage' => $this->getHomepageSettings(),
            'header' => $this->getHeaderSettings(),
            'footer' => $this->getFooterSettings(),
            'about' => $this->getAboutPageSettings(),
            'faq' => $this->getFaqPageSettings(),
            'corporate' => $this->getCorporateSettings(),
            'pointToPoint' => $this->getPointToPointSettings(),
            'seo' => $this->getSeoSettings(),
            'social_media' => $this->getSocialMediaSettings(),
            'contact' => $this->getContactPageSettings(),
            'payment' => $this->getPaymentSettings(),
            'booking' => $this->getBookingSettings(),
            'driverMobile' => $this->getDriverMobileSettings(),
            'security' => $this->getSecuritySettings(),
            'email' => $this->getEmailSettings(),
            'sms' => $this->getSmsSettings(),
            'appearance' => $this->getAppearanceSettings(),
            'branding' => $this->getBrandingSettings(),
        ];

        if ($categories === null) {
            return $settings;
        }

        return array_intersect_key($settings, array_flip($categories));
    }
}
