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
        return array_keys(array_filter(
            config('website_themes.themes', []),
            static fn (array $theme): bool => (bool) ($theme['released'] ?? false)
        ));
    }
}

if (!function_exists('normalize_theme_identifier')) {
    /**
     * Normalize a stored theme identifier against the released theme manifest.
     */
    function normalize_theme_identifier(?string $theme): string
    {
        $defaultTheme = (string) config('website_themes.default', 'default');

        return in_array($theme, get_allowed_themes(), true) ? $theme : $defaultTheme;
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
            return normalize_theme_identifier(is_string($theme) ? $theme : null);
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

if (!function_exists('theme_partial_for')) {
    /**
     * Resolve a partial for a specific theme.
     *
     * Mandatory partials never fall back to default markup. This keeps an
     * incomplete theme from accidentally presenting another theme's shell.
     */
    function theme_partial_for(string $theme, string $partial): string
    {
        if ($theme === 'default') {
            return "partials.{$partial}";
        }

        $themePartialPath = "partials.themes.{$theme}.{$partial}";
        if (view()->exists($themePartialPath)) {
            return $themePartialPath;
        }

        $requiredPartials = config("website_themes.themes.{$theme}.required_partials", []);
        if (in_array($partial, is_array($requiredPartials) ? $requiredPartials : [], true)) {
            throw new LogicException("Required {$theme} partial [{$themePartialPath}] is missing.");
        }

        return "partials.{$partial}";
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
        return theme_partial_for(get_active_theme(), $partial);
    }
}

if (!function_exists('theme_asset')) {
    /**
     * Get a manifest-backed asset path for the active theme.
     */
    function theme_asset(string $key): ?string
    {
        $asset = config('website_themes.themes.' . get_active_theme() . '.' . $key);

        return is_string($asset) && $asset !== '' ? $asset : null;
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
