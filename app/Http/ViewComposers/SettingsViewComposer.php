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
     * Provides a global $settings variable to all views
     */
    public function compose(View $view): void
    {
        // If settings are not already provided by the controller, get defaults
        if (!$view->offsetExists('settings')) {
            // Try to get homepage settings as a fallback
            $settings = $this->settingsService->getHomepageSettings();
            $view->with('settings', $settings);
        }
    }
}
