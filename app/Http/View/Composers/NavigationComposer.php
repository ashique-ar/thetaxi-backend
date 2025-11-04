<?php

namespace App\Http\View\Composers;

use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\WebsiteSettingsService;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class NavigationComposer
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $navigationMenu = $this->getNavigationMenu();
        $headerSettings = $this->getHeaderSettings();
        $footerData = $this->getFooterData();
        
        $view->with([
            'navigationMenu' => $navigationMenu,
            'headerSettings' => $headerSettings,
            'footerData' => $footerData
        ]);
    }

    /**
     * Get navigation menu items for header
     */
    private function getNavigationMenu(): array
    {
        return Cache::remember('navigation_menu', 3600, function () {
            // Try to get from CMS first, fallback to static
            $navigationItems = $this->getCmsNavigationItems();
            
            if ($navigationItems->isEmpty()) {
                return $this->getStaticNavigationMenu();
            }

            return $navigationItems->map(function ($item) {
                $customFields = json_decode($item->custom_fields, true) ?? [];
                return [
                    'title' => $item->title,
                    'url' => $item->url,
                    'active' => $this->isActiveRoute($item->url),
                    'target' => $customFields['target'] ?? '_self',
                    'icon' => $customFields['icon'] ?? null,
                    'children' => $customFields['children'] ?? []
                ];
            })->toArray();
        });
    }

    /**
     * Get CMS navigation items
     */
    private function getCmsNavigationItems()
    {
        $contentType = CmsContentType::where('slug', 'navigation-menu')
            ->where('is_active', true)
            ->first();

        if (!$contentType) {
            return collect();
        }

        return CmsContent::where('cms_content_type_id', $contentType->id)
            ->where('status', 'published')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Get static navigation menu as fallback
     */
    private function getStaticNavigationMenu(): array
    {
        return [
            [
                'title' => 'Home',
                'url' => route('home'),
                'active' => request()->routeIs('home'),
                'target' => '_self',
                'children' => []
            ],
            [
                'title' => 'Services',
                'url' => '#services',
                'active' => false,
                'target' => '_self',
                'children' => [
                    ['title' => 'Airport Transfer', 'url' => route('home') . '#services'],
                    ['title' => 'City Rides', 'url' => route('home') . '#services'],
                    ['title' => 'Outstation', 'url' => route('home') . '#services'],
                    ['title' => 'Rental', 'url' => route('home') . '#services'],
                ]
            ],
            [
                'title' => 'Fleet',
                'url' => '#fleet',
                'active' => false,
                'target' => '_self',
                'children' => [
                    ['title' => 'Sedan', 'url' => route('home') . '#fleet'],
                    ['title' => 'SUV', 'url' => route('home') . '#fleet'],
                    ['title' => 'Hatchback', 'url' => route('home') . '#fleet'],
                    ['title' => 'Luxury', 'url' => route('home') . '#fleet'],
                ]
            ],
            [
                'title' => 'Pages',
                'url' => '#',
                'active' => request()->routeIs(['about', 'faq', 'cart', 'checkout']),
                'target' => '_self',
                'children' => [
                    ['title' => 'About TheTaxi', 'url' => route('about')],
                    ['title' => 'FAQ', 'url' => route('faq')],
                    ['title' => 'Cart', 'url' => route('cart')],
                    ['title' => 'Checkout', 'url' => route('checkout')],
                ]
            ],
            [
                'title' => 'Contact',
                'url' => route('contact'),
                'active' => request()->routeIs('contact'),
                'target' => '_self',
                'children' => []
            ]
        ];
    }

    /**
     * Get header-specific settings
     */
    private function getHeaderSettings(): array
    {
        return Cache::remember('header_settings', 3600, function () {
            // Get all homepage settings and filter the header ones we need
            $allSettings = $this->settingsService->getHomepageSettings();

            $headerSettings = [];
            $headerKeys = [
                'site_logo',
                'site_name', 
                'header_phone',
                'header_phone_display',
                'book_now_url',
                'search_placeholder',
                'top_offer_1',
                'top_offer_2',
                'top_offer_3'
            ];

            foreach ($headerKeys as $key) {
                if (isset($allSettings[$key])) {
                    $headerSettings[$key] = $allSettings[$key];
                }
            }

            return array_merge([
                'site_logo' => asset('assets/img/header-logo.png'),
                'site_name' => 'TheTaxi',
                'header_phone' => '+1 234 567 890',
                'header_phone_display' => '+1 234 567 890',
                'book_now_url' => '#',
                'search_placeholder' => 'Find Your Perfect Taxi Service',
                'top_offer_1' => 'One-Click Booking, Upto FLAT 30% Discount on Taxi Rides',
                'top_offer_2' => 'Book Your Taxi and Get Special Discounts Instantly',
                'top_offer_3' => 'Enjoy Safe & Comfortable Rides with Flexible Payment Options'
            ], $headerSettings);
        });
    }

    /**
     * Check if a URL is the active route
     */
    private function isActiveRoute(string $url): bool
    {
        if (str_starts_with($url, '#')) {
            return false;
        }

        $currentUrl = request()->url();
        $currentPath = parse_url($currentUrl, PHP_URL_PATH);
        $linkPath = parse_url($url, PHP_URL_PATH);

        return $currentPath === $linkPath;
    }

    /**
     * Get footer data including links and settings
     */
    private function getFooterData(): array
    {
        return Cache::remember('footer_data', 3600, function () {
            // Get all homepage settings for footer
            $allSettings = $this->settingsService->getHomepageSettings();

            // Get footer links from CMS
            $footerLinks = $this->getFooterLinks();

            // Footer settings with defaults
            $footerSettings = array_merge([
                'site_logo' => asset('assets/img/header-logo.png'),
                'site_name' => 'TheTaxi',
                'company_description' => 'TheTaxi Professional Services',
                'company_address' => '123 Transport Avenue, Suite 100<br>Your City, State 12345, Country',
                'footer_phone' => '+1 234 567 890',
                'footer_email' => 'info@thetaxi.com',
                'footer_whatsapp' => '+1 234 567 890',
                'facebook_url' => 'https://www.facebook.com/',
                'linkedin_url' => 'https://www.linkedin.com/',
                'youtube_url' => 'https://www.youtube.com/',
                'instagram_url' => 'https://www.instagram.com/',
                'twitter_url' => 'https://www.twitter.com/',
                'copyright_text' => 'All Rights Reserved.',
                'footer_inquiry_title' => 'To More Inquiry',
                'footer_inquiry_subtitle' => "Don't hesitate Call to TheTaxi."
            ], $this->filterSettingsByPrefix($allSettings, 'footer_'));

            return [
                'settings' => $footerSettings,
                'links' => $footerLinks
            ];
        });
    }

    /**
     * Get footer links from CMS
     */
    private function getFooterLinks(): array
    {
        $contentType = CmsContentType::where('slug', 'footer-links')
            ->where('is_active', true)
            ->first();

        if (!$contentType) {
            return $this->getStaticFooterLinks();
        }

        $links = CmsContent::where('cms_content_type_id', $contentType->id)
            ->where('status', 'published')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        if ($links->isEmpty()) {
            return $this->getStaticFooterLinks();
        }

        $organizedLinks = [];
        foreach ($links as $link) {
            $customFields = json_decode($link->custom_fields, true) ?? [];
            $category = $customFields['category'] ?? 'General';
            
            if (!isset($organizedLinks[$category])) {
                $organizedLinks[$category] = [];
            }

            $organizedLinks[$category][] = [
                'title' => $link->title,
                'url' => $link->url,
                'target' => $customFields['target'] ?? '_self'
            ];
        }

        return $organizedLinks;
    }

    /**
     * Get static footer links as fallback
     */
    private function getStaticFooterLinks(): array
    {
        return [
            'Our Services' => [
                ['title' => 'Airport Transfer', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Drop & Pickup', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Car Rental', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Corporate Services', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Luxury Cars', 'url' => route('home') . '#fleet', 'target' => '_self'],
                ['title' => 'Economy Cars', 'url' => route('home') . '#fleet', 'target' => '_self'],
                ['title' => 'SUVs & Vans', 'url' => route('home') . '#fleet', 'target' => '_self'],
                ['title' => '24/7 Service', 'url' => route('home') . '#services', 'target' => '_self'],
            ],
            'Popular Routes' => [
                ['title' => 'City to Airport', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Downtown Express', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Business District', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Hotel Transfers', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Tourist Attractions', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Shopping Centers', 'url' => route('home') . '#services', 'target' => '_self'],
                ['title' => 'Medical Centers', 'url' => route('home') . '#services', 'target' => '_self'],
            ],
            'Support' => [
                ['title' => 'About TheTaxi', 'url' => route('about'), 'target' => '_self'],
                ['title' => 'Contact Support', 'url' => route('contact'), 'target' => '_self'],
                ['title' => 'FAQ', 'url' => route('faq'), 'target' => '_self'],
                ['title' => 'Booking Help', 'url' => '#', 'target' => '_self'],
                ['title' => 'Payment Methods', 'url' => '#', 'target' => '_self'],
                ['title' => 'Cancellation Policy', 'url' => '#', 'target' => '_self'],
                ['title' => 'Privacy Policy', 'url' => '#', 'target' => '_self'],
                ['title' => 'Terms & Conditions', 'url' => '#', 'target' => '_self'],
            ]
        ];
    }

    /**
     * Filter settings by prefix
     */
    private function filterSettingsByPrefix(array $settings, string $prefix): array
    {
        $filtered = [];
        foreach ($settings as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }
}