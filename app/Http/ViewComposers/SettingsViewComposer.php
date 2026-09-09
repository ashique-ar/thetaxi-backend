<?php

namespace App\Http\ViewComposers;

use App\Services\WebsiteSettingsService;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;

class SettingsViewComposer
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    public function compose(View $view): void
    {
        // ULTRA-OPTIMIZED: Use aggressive caching to avoid repeated database hits
        $companyId = $this->settingsService->resolveCompanyId() ?: 'global';
        $settings = Cache::remember('global_settings_flattened_' . $companyId, 86400, function () {
            try {
                // Get ALL settings that templates might need (homepage + global + all pages)
                $allSettingsKeys = [
                    // Basic site info
                    'brand_name',
                    'brand_tagline',
                    'brand_short_name',
                    'site_name',
                    'company_name',
                    'company_phone',
                    'company_email',
                    'company_address',
                    'company_whatsapp',
                    'primary_color',
                    'secondary_color',
                    'tertiary_color',
                    'favicon',
                    'site_tagline',
                    'seo_title_template',
                    'seo_meta_description',
                    'seo_keywords',
                    'seo_og_image',
                    'seo_twitter_card',
                    'seo_robots',
                    'seo_canonical_enabled',
                    'seo_schema_enabled',
                    'seo_business_type',
                    'seo_service_area',
                    'seo_price_range',
                    'seo_default_locale',
                    'seo_ai_summary',
                    'seo_ai_topics',
                    'google_analytics_id',
                    'google_tag_manager_id',
                    'google_ads_id',
                    'google_ads_conversion_id',
                    'google_ads_conversion_label',
                    'facebook_pixel_id',
                    'meta_verification_code',
                    'google_site_verification',

                    // Logos
                    'brand_logo_primary',
                    'brand_logo_secondary',
                    'brand_logo_icon',
                    'brand_favicon',
                    'portal_logo',
                    'portal_title',
                    'logo_header',
                    'logo_header_alt',
                    'logo_mobile',
                    'logo_mobile_alt',
                    'logo_footer',
                    'logo_footer_alt',
                    'cms_content_placeholder_image',

                    // Social media
                    'social_facebook',
                    'social_twitter',
                    'social_instagram',
                    'social_linkedin',
                    'social_youtube',
                    'social_tiktok',

                    // Email essentials
                    'email_header_subtitle',
                    'email_footer_text',

                    // Header essentials
                    'header_help_label',
                    'header_cart_label',
                    'header_contact_label',
                    'header_menu_label',
                    'header_mobile_cart_label',
                    'header_search_placeholder',
                    'header_quick_search_label',
                    'header_quick_search_1',
                    'header_quick_search_2',
                    'header_quick_search_3',
                    'header_quick_search_4',

                    // Footer essentials
                    'footer_description',
                    'footer_copyright',
                    'footer_copyright_text',
                    'footer_company_tagline',
                    'footer_inquiry_heading',
                    'footer_inquiry_subheading',
                    'footer_whatsapp_number',
                    'footer_whatsapp_label',
                    'footer_email_label',
                    'footer_phone_label',
                    'footer_services_title',
                    'footer_routes_title',
                    'footer_support_title',

                    // Footer dynamic links (services, routes, support)
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

                    // Page Specific SEO Overrides
                    'seo_home_title', 'seo_home_description', 'seo_home_keywords',
                    'seo_about_title', 'seo_about_description', 'seo_about_keywords',
                    'seo_taxi_title', 'seo_taxi_description', 'seo_taxi_keywords',
                    'seo_contact_title', 'seo_contact_description', 'seo_contact_keywords',
                    'seo_faq_title', 'seo_faq_description', 'seo_faq_keywords',
                    'seo_point_to_point_title', 'seo_point_to_point_description', 'seo_point_to_point_keywords',
                    'seo_rate_chart_title', 'seo_rate_chart_description', 'seo_rate_chart_keywords',
                    'seo_cart_title', 'seo_cart_description', 'seo_cart_keywords',
                    'seo_checkout_title', 'seo_checkout_description', 'seo_checkout_keywords',

                    // Homepage sections
                    'banner_heading',
                    'banner_subheading',
                    'banner_video',
                    'banner_image',
                    'theme_02_slider_images',

                    // Partner section
                    'partner_section_title',
                    'partner_logo_1',
                    'partner_logo_2',
                    'partner_logo_3',
                    'partner_logo_4',
                    'partner_logo_5',
                    'partner_logo_6',

                    // Featured vehicles
                    'featured_vehicles_title',
                    'featured_vehicles_description',
                    'vehicles_view_all_text',

                    // Destinations section
                    'destinations_section_title',
                    'destinations_section_description',

                    // Packages section
                    'packages_section_title',
                    'packages_section_description',

                    // Offer section
                    'offer_slider_img_1',
                    'offer_slider_img_2',
                    'offer_section_description',

                    // Why section
                    'why_section_title',
                    'why_video_image',
                    'why_feature_1',
                    'why_feature_2',
                    'why_feature_3',
                    'why_feature_4',
                    'why_feature_icon_1',
                    'why_feature_icon_2',
                    'why_feature_icon_3',
                    'why_feature_icon_4',

                    // Testimonials section
                    'testimonials_section_title',
                    'testimonials_section_description',
                    'testimonial_author_img_1',
                    'testimonial_author_img_2',
                    'testimonial_author_img_3',
                    'testimonial_author_img_4',
                    'testimonial_author_img_5',
                    'testimonial_vector',

                    // TripAdvisor
                    'tripadvisor_logo',
                    'tripadvisor_stars',

                    // Inspirations/Services section
                    'inspirations_section_title',
                    'inspirations_section_description',

                    // Blog section
                    'blog_section_title',
                    'blog_section_description',

                    // FAQ section
                    'faq_section_title',
                    'faq_section_vector',

                    // About page
                    'about_page_title',
                    'about_breadcrumb_image',
                    'about_hero_heading',
                    'about_hero_subheading',
                    'about_section_title',
                    'about_section_subtitle',
                    'about_paragraph_1',
                    'about_paragraph_2',
                    'about_section_description_1',
                    'about_section_description_2',
                    'about_hero_image',
                    'founder_signature',
                    'founder_name',
                    'founder_title',
                    'about_services_title',
                    'about_services',
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
                    'about_journey_title',
                    'about_journey_items',
                    'about_journey_description',
                    'about_why_cards',
                    'about_why_travel_title',
                    'about_why_travel_description',
                    'partners_section_title',
                    'about_testimonial_img_1',
                    'about_testimonial_img_2',
                    'about_testimonial_img_3',
                    'about_testimonial_img_4',
                    'about_testimonial_img_5',
                    'about_tripadvisor_logo',
                    'about_trustpilot_logo',

                    // Contact page
                    'contact_page_title',
                    'contact_breadcrumb_image',
                    'contact_hero_heading',
                    'contact_hero_subheading',
                    'contact_address_1_title',
                    'contact_address_1_phone',
                    'contact_address_1_address',
                    'contact_address_2_title',
                    'contact_address_2_phone',
                    'contact_address_2_address',
                    'contact_address_3_title',
                    'contact_address_3_phone',
                    'contact_address_3_address',
                    'contact_form_title',
                    'contact_form_description',
                    'contact_form_name_label',
                    'contact_form_name_placeholder',
                    'contact_form_email_label',
                    'contact_form_email_placeholder',
                    'contact_form_phone_label',
                    'contact_form_phone_placeholder',
                    'contact_form_destination_label',
                    'contact_form_destination_option_1',
                    'contact_form_destination_option_2',
                    'contact_form_destination_option_3',
                    'contact_form_destination_option_4',
                    'contact_form_destination_placeholder',
                    'contact_form_message_label',
                    'contact_form_message_placeholder',
                    'contact_form_privacy_text',
                    'contact_form_submit_text',
                    'contact_map_embed_url',

                    // FAQ page
                    'faq_page_title',
                    'faq_breadcrumb_image',
                    'faq_hero_heading',
                    'faq_hero_subheading',
                    'faq_section_title',
                    'faq_section_description',
                    'faq_show_search',
                    'faq_search_placeholder',
                    'faq_show_categories',
                    'faq_all_categories_text',
                    'faq_page_banner_image',

                    // Corporate transfers page
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

                    // Point-to-point transfers page
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

                    // Booking form settings
                    'enable_airport_transfers',
                    'enable_ride_now',
                    'enable_day_rental',
                    'enable_selfdrive',
                    'enable_with_drive',
                    'enable_wedding',
                    'enable_corporate',
                    'show_return_trip_toggle',
                    'booking_advance_hours',
                    'booking_max_days',
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
                ];

                $essentialSettings = $this->settingsService->getMultiple($allSettingsKeys);

                // Add fallback defaults for missing values
                $defaults = [
                    'brand_name' => 'Company',
                    'brand_tagline' => 'Your Trusted Transport Partner',
                    'brand_short_name' => 'Company',
                    'site_name' => 'Company',
                    'company_name' => 'Company',
                    'company_phone' => '',
                    'primary_color' => '#BF2629',
                    'secondary_color' => '#717171',
                    'tertiary_color' => '#FFFFFF',
                    'email_header_subtitle' => 'Premium Taxi Service'
                ];

                $settings = $this->settingsService->withCanonicalBranding(array_merge($defaults, $essentialSettings));

                return $this->settingsService->withSeoDefaults($settings);
            } catch (\Exception $e) {
                // Fallback settings if database fails
                return $this->settingsService->withSeoDefaults([
                    'brand_name' => 'Company',
                    'brand_tagline' => 'Your Trusted Transport Partner',
                    'brand_short_name' => 'Company',
                    'site_name' => 'Company',
                    'company_name' => 'Company',
                    'company_phone' => '',
                    'primary_color' => '#BF2629',
                    'secondary_color' => '#717171',
                    'tertiary_color' => '#FFFFFF',
                    'email_header_subtitle' => 'Premium Taxi Service'
                ]);
            }
        });

        // Identify Current System Page for SEO Overrides
        $routeName = request()->route() ? request()->route()->getName() : null;
        $systemPage = null;

        $routeMap = [
            'home' => 'home',
            'about' => 'about',
            'vehicles' => 'taxi',
            'contact' => 'contact',
            'inquiry' => 'contact',
            'faq' => 'faq',
            'point-to-point' => 'point_to_point',
            'rate-chart' => 'rate_chart',
            'cart' => 'cart',
            'checkout' => 'checkout',
        ];

        if ($routeName && isset($routeMap[$routeName])) {
            $systemPage = $routeMap[$routeName];
        }

        // Inject Overrides if on a System Page
        if ($systemPage) {
            $pageTitle = $settings["seo_{$systemPage}_title"] ?? null;
            $pageDesc = $settings["seo_{$systemPage}_description"] ?? null;
            $pageKeywords = $settings["seo_{$systemPage}_keywords"] ?? null;

            if ($pageTitle) {
                $settings['seo_title_custom'] = $pageTitle;
            }
            if ($pageDesc) {
                $settings['seo_meta_description'] = $pageDesc;
            }
            if ($pageKeywords) {
                $settings['seo_keywords'] = $pageKeywords;
            }
        }

        $view->with('settings', $settings);
    }
}
