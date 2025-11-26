<?php

namespace App\Http\ViewComposers;

use App\Services\WebsiteSettingsService;
use Illuminate\View\View;

class SettingsViewComposer
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Bind data to the view.
     * Provides a global $settings variable to all views with ALL categorized settings
     */
    public function compose(View $view): void
    {
        // Get all categorized settings and flatten them into a single array
        // This allows views to access settings via $settings['key'] for any category
        $allSettings = $this->settingsService->getAllCategorizedSettings();
        
        // Flatten the categorized settings into a single array for easy access
        $settings = [];
        foreach ($allSettings as $category => $categorySettings) {
            if (is_array($categorySettings)) {
                $settings = array_merge($settings, $categorySettings);
            }
        }
        
        // Always provide the settings to all views
        $view->with('settings', $settings);
    }
}
