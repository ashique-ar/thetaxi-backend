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
        $settings = Cache::remember('global_settings_flattened', 86400, function() {
            try {
                // Get ALL settings that templates might need (homepage + global)
                $allSettingsKeys = [
                    // Basic site info
                    'site_name', 'company_name', 'company_phone', 'company_email', 'company_address',
                    'primary_color', 'secondary_color', 'tertiary_color', 
                    'favicon', 'logo', 'footer_logo', 'header_help_label',
                    
                    // Social media
                    'social_facebook', 'social_twitter', 'social_instagram', 'social_linkedin', 'social_youtube',
                    
                    // Footer essentials
                    'footer_description', 'footer_copyright',
                    
                    // Header essentials  
                    'header_cta_text', 'header_cta_link',
                    
                    // Homepage sections
                    'banner_heading', 'banner_subheading', 'banner_video', 'banner_image',
                    'destinations_section_title', 'destinations_section_description',
                    'packages_section_title', 'packages_section_description',
                    'inspirations_section_title', 'inspirations_section_description',
                    'blog_section_title', 'blog_section_description',
                    'testimonials_section_title', 'testimonials_section_description',
                    'faq_section_title', 'faq_section_description',
                    'partner_section_title',
                    
                    // Why section (THIS IS THE MISSING ONE!)
                    'why_section_title', 'why_section_description', 'why_video_image',
                    'why_feature_1', 'why_feature_2', 'why_feature_3', 'why_feature_4',
                    'why_feature_icon_1', 'why_feature_icon_2', 'why_feature_icon_3', 'why_feature_icon_4',
                    
                    // Featured vehicles
                    'featured_vehicles_title', 'featured_vehicles_description',
                    
                    // Offer section
                    'offer_slider_img_1', 'offer_slider_img_2',
                    
                    // Ratings
                    'tripadvisor_logo', 'tripadvisor_stars',
                    
                    // Partner logos
                    'partner_logo_1', 'partner_logo_2', 'partner_logo_3', 'partner_logo_4', 'partner_logo_5', 'partner_logo_6'
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
