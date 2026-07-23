<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Website\WebsiteSetting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add branding settings to website_settings table
        $brandingSettings = [
            // Company Branding
            ['type' => 'brand_name', 'value' => 'Company'],
            ['type' => 'brand_tagline', 'value' => 'Your Trusted Car Rental Partner'],
            ['type' => 'brand_short_name', 'value' => 'Company'],
            
            // Logos
            ['type' => 'brand_logo_primary', 'value' => '/images/logo/logo.png'],
            ['type' => 'brand_logo_secondary', 'value' => '/images/logo/logo-white.png'],
            ['type' => 'brand_logo_icon', 'value' => '/images/logo/icon.png'],
            ['type' => 'brand_favicon', 'value' => '/favicon.ico'],
            
            // Colors - Primary
            ['type' => 'brand_color_primary', 'value' => '#1E40AF'],
            ['type' => 'brand_color_primary_light', 'value' => '#3B82F6'],
            ['type' => 'brand_color_primary_dark', 'value' => '#1E3A8A'],
            
            // Colors - Secondary
            ['type' => 'brand_color_secondary', 'value' => '#F59E0B'],
            ['type' => 'brand_color_secondary_light', 'value' => '#FBBF24'],
            ['type' => 'brand_color_secondary_dark', 'value' => '#D97706'],
            
            // Colors - Accent
            ['type' => 'brand_color_accent', 'value' => '#10B981'],
            ['type' => 'brand_color_accent_light', 'value' => '#34D399'],
            ['type' => 'brand_color_accent_dark', 'value' => '#059669'],
            
            // Portal Specific
            ['type' => 'portal_title', 'value' => 'Company | Portal'],
            ['type' => 'portal_logo', 'value' => '/images/logo/logo.png'],
            ['type' => 'portal_theme', 'value' => 'theme-brand'],
            ['type' => 'portal_scheme', 'value' => 'light'],
            ['type' => 'portal_sidebar_appearance', 'value' => 'default'],
            ['type' => 'portal_sidebar_style', 'value' => 'dark'],
            
        ];

        WebsiteSetting::withoutEvents(function () use ($brandingSettings): void {
            foreach ($brandingSettings as $setting) {
                WebsiteSetting::firstOrCreate(
                    ['type' => $setting['type']],
                    ['value' => $setting['value']]
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $types = [
            'brand_name',
            'brand_tagline',
            'brand_short_name',
            'brand_logo_primary',
            'brand_logo_secondary',
            'brand_logo_icon',
            'brand_favicon',
            'brand_color_primary',
            'brand_color_primary_light',
            'brand_color_primary_dark',
            'brand_color_secondary',
            'brand_color_secondary_light',
            'brand_color_secondary_dark',
            'brand_color_accent',
            'brand_color_accent_light',
            'brand_color_accent_dark',
            'portal_title',
            'portal_logo',
            'portal_theme',
            'portal_scheme',
            'portal_sidebar_appearance',
            'portal_sidebar_style',
        ];

        WebsiteSetting::withoutEvents(
            fn () => WebsiteSetting::whereIn('type', $types)->delete()
        );
    }
};
