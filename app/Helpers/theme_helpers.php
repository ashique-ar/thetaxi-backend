<?php

/**
 * Theme Helper Functions
 * 
 * Provides helper functions for theme management and dynamic theme loading.
 * Supports multiple themes with fallback to default theme.
 */

use App\Models\Website\WebsiteSetting;
use Illuminate\Support\Facades\Cache;

if (!function_exists('get_allowed_themes')) {
    /**
     * Get the list of allowed theme identifiers.
     *
     * @return array<string>
     */
    function get_allowed_themes(): array
    {
        return ['default', 'theme-02'];
    }
}

if (!function_exists('get_active_theme')) {
    /**
     * Get the currently active theme identifier.
     * 
     * Retrieves the active theme from site settings with caching.
     * Falls back to 'default' if the setting is missing or invalid.
     *
     * @return string The active theme identifier
     */
    function get_active_theme(): string
    {
        $cacheKey = 'active_theme_setting';
        
        return Cache::remember($cacheKey, 3600, function () {
            $theme = WebsiteSetting::getValue('active_theme', 'default');
            $allowedThemes = get_allowed_themes();
            
            // Validate against allowed themes, fallback to default if invalid
            if (!in_array($theme, $allowedThemes, true)) {
                return 'default';
            }
            
            return $theme;
        });
    }
}

if (!function_exists('is_theme')) {
    /**
     * Check if the specified theme is currently active.
     *
     * @param string $theme The theme identifier to check
     * @return bool True if the specified theme is active
     */
    function is_theme(string $theme): bool
    {
        return get_active_theme() === $theme;
    }
}

if (!function_exists('theme_partial')) {
    /**
     * Get the path to a theme-specific partial view.
     * 
     * Attempts to load a theme-specific partial from the themes directory.
     * Falls back to the default partial if the theme-specific one doesn't exist.
     *
     * @param string $partial The partial name (e.g., 'header', 'footer', 'hero')
     * @return string The view path to include
     */
    function theme_partial(string $partial): string
    {
        $activeTheme = get_active_theme();
        
        // If default theme, return the standard partial path
        if ($activeTheme === 'default') {
            return "partials.{$partial}";
        }
        
        // Build theme-specific partial path
        $themePartialPath = "partials.themes.{$activeTheme}.{$partial}";
        
        // Check if theme-specific partial exists, fallback to default if not
        if (view()->exists($themePartialPath)) {
            return $themePartialPath;
        }
        
        // Fallback to default partial
        return "partials.{$partial}";
    }
}

if (!function_exists('theme_class')) {
    /**
     * Generate a theme-specific CSS class modifier.
     * 
     * Returns a BEM-style modifier class for the active theme.
     * Returns empty string for default theme (no modifier needed).
     *
     * @param string $baseClass The base CSS class name
     * @return string The theme modifier class or empty string
     */
    function theme_class(string $baseClass): string
    {
        $activeTheme = get_active_theme();
        
        // No modifier needed for default theme
        if ($activeTheme === 'default') {
            return '';
        }
        
        // Return BEM-style modifier class
        return "{$baseClass}--{$activeTheme}";
    }
}
