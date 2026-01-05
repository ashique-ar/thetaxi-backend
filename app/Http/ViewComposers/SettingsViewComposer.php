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

    /**
     * Bind data to the view.
     * CRITICAL FIX: Cache settings globally to avoid database calls on every page
     */
    public function compose(View $view): void
    {
        // ULTRA-OPTIMIZED: Use aggressive caching to avoid repeated database hits
        $settings = Cache::remember('global_settings_flattened', 86400, function () {
            try {
                // Get ALL settings that templates might need (homepage + global + all pages)
                $allSettingsKeys = [
                    // Basic site info
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

                    // Logos
                    'logo_header',
                    'logo_header_alt',
                    'logo_mobile',
                    'logo_mobile_alt',
                    'logo_footer',
                    'logo_footer_alt',

                    // Social media
                    'social_facebook',
                    'social_twitter',
                    'social_instagram',
                    'social_linkedin',
                    'social_youtube',
                    'social_tiktok',

                    // Header essentials
                    'header_help_label',
                    'header_cart_label',
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

                    // Homepage sections
                    'banner_heading',
                    'banner_subheading',
                    'banner_video',
                    'banner_image',

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
                ];

                $essentialSettings = $this->settingsService->getMultiple($allSettingsKeys);

                // Add fallback defaults for missing values
                $defaults = [
                    'site_name' => 'TheTaxi',
                    'company_name' => 'TheTaxi',
                    'company_phone' => '+1 234 567 890',
                    'primary_color' => '#BF2629',
                    'secondary_color' => '#717171',
                    'tertiary_color' => '#FFFFFF'
                ];

                return array_merge($defaults, $essentialSettings);
            } catch (\Exception $e) {
                // Fallback settings if database fails
                return [
                    'site_name' => 'TheTaxi',
                    'company_name' => 'TheTaxi',
                    'company_phone' => '+1 234 567 890',
                    'primary_color' => '#BF2629',
                    'secondary_color' => '#717171',
                    'tertiary_color' => '#FFFFFF'
                ];
            }
        });
        $view->with('settings', $settings);
    }
}
