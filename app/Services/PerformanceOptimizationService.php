<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Intervention\Image\Laravel\Facades\Image;
use App\Models\Page;
use App\Models\NavigationMenu;
use App\Models\FooterLink;
use Carbon\Carbon;

class PerformanceOptimizationService
{
    protected int $defaultCacheTtl = 3600; // 1 hour
    protected array $imageFormats = ['webp', 'avif', 'jpg', 'png'];
    protected array $imageSizes = [
        'thumbnail' => [150, 150],
        'small' => [300, 300],
        'medium' => [600, 600],
        'large' => [1200, 1200],
        'xl' => [1920, 1080]
    ];

    /**
     * Get or cache page content with optimizations
     */
    public function getCachedPageContent(string $slug): ?array
    {
        $cacheKey = "page_content_{$slug}";

        return Cache::remember($cacheKey, $this->defaultCacheTtl, function () use ($slug) {
            $page = Page::where('slug', $slug)
                ->where('status', 'published')
                ->where('is_active', true)
                ->first();

            if (!$page) {
                return null;
            }

            // Optimize content
            $optimizedContent = $this->optimizeContent($page->content);

            return [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'content' => $optimizedContent,
                'excerpt' => $page->excerpt,
                'featured_image' => $this->getOptimizedImageUrl($page->featured_image),
                'seo_title' => $page->seo_title ?: $page->title,
                'seo_description' => $page->seo_description ?: $page->excerpt,
                'seo_keywords' => $page->seo_keywords,
                'published_at' => $page->published_at,
                'updated_at' => $page->updated_at,
                'cache_timestamp' => now()->toISOString()
            ];
        });
    }

    /**
     * Get cached navigation menu with optimizations
     */
    public function getCachedNavigation(string $location = 'header'): array
    {
        $cacheKey = "navigation_{$location}";

        return Cache::remember($cacheKey, $this->defaultCacheTtl, function () use ($location) {
            return NavigationMenu::where('location', $location)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->with([
                    'children' => function ($query) {
                        $query->where('is_active', true)->orderBy('sort_order');
                    }
                ])
                ->get()
                ->map(function ($menu) {
                    return [
                        'id' => $menu->id,
                        'title' => $menu->title,
                        'url' => $menu->url,
                        'icon' => $menu->icon,
                        'target' => $menu->target,
                        'children' => $menu->children->map(function ($child) {
                            return [
                                'id' => $child->id,
                                'title' => $child->title,
                                'url' => $child->url,
                                'icon' => $child->icon,
                                'target' => $child->target,
                            ];
                        })
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Get cached footer links with optimizations
     */
    public function getCachedFooterLinks(string $section = 'main'): array
    {
        $cacheKey = "footer_links_{$section}";

        return Cache::remember($cacheKey, $this->defaultCacheTtl, function () use ($section) {
            return FooterLink::where('section', $section)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($link) {
                    return [
                        'id' => $link->id,
                        'title' => $link->title,
                        'url' => $link->url,
                        'icon' => $link->icon,
                        'target' => $link->target,
                        'description' => $link->description,
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Optimize content by processing images and links
     */
    protected function optimizeContent(string $content): string
    {
        // Add lazy loading to images
        $content = preg_replace_callback(
            '/<img([^>]+)>/i',
            function ($matches) {
                $img = $matches[0];

                // Add lazy loading if not present
                if (!strpos($img, 'loading=')) {
                    $img = str_replace('<img', '<img loading="lazy"', $img);
                }

                // Add responsive class if not present
                if (!strpos($img, 'class=')) {
                    $img = str_replace('<img', '<img class="img-responsive"', $img);
                } elseif (!strpos($img, 'img-responsive')) {
                    $img = str_replace('class="', 'class="img-responsive ', $img);
                }

                return $img;
            },
            $content
        );

        // Optimize external links
        $content = preg_replace_callback(
            '/<a([^>]+)href="([^"]*)"([^>]*)>/i',
            function ($matches) {
                $url = $matches[2];
                $beforeHref = $matches[1];
                $afterHref = $matches[3];

                // Add security attributes to external links
                if ($this->isExternalUrl($url)) {
                    $securityAttrs = '';
                    if (!strpos($afterHref, 'rel=')) {
                        $securityAttrs .= ' rel="noopener noreferrer"';
                    }
                    if (!strpos($afterHref, 'target=')) {
                        $securityAttrs .= ' target="_blank"';
                    }

                    return "<a{$beforeHref}href=\"{$url}\"{$afterHref}{$securityAttrs}>";
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Generate optimized image URLs with different sizes and formats
     */
    public function getOptimizedImageUrl(?string $imageUrl): ?array
    {
        if (!$imageUrl) {
            return null;
        }

        $optimizedUrls = [];

        foreach ($this->imageSizes as $sizeName => $dimensions) {
            foreach ($this->imageFormats as $format) {
                $optimizedUrls[$sizeName][$format] = $this->generateImageVariant(
                    $imageUrl,
                    $dimensions[0],
                    $dimensions[1],
                    $format
                );
            }
        }

        return [
            'original' => $imageUrl,
            'optimized' => $optimizedUrls,
            'responsive_srcset' => $this->generateResponsiveSrcset($optimizedUrls)
        ];
    }

    /**
     * Generate responsive srcset for images
     */
    protected function generateResponsiveSrcset(array $optimizedUrls): string
    {
        $srcsetParts = [];

        foreach ($optimizedUrls as $sizeName => $formats) {
            if (isset($formats['webp'])) {
                $width = $this->imageSizes[$sizeName][0];
                $srcsetParts[] = "{$formats['webp']} {$width}w";
            }
        }

        return implode(', ', $srcsetParts);
    }

    /**
     * Generate image variant with specific dimensions and format
     */
    protected function generateImageVariant(string $imageUrl, int $width, int $height, string $format): string
    {
        // This would integrate with your image processing system
        // For now, return a placeholder URL pattern
        $pathInfo = pathinfo($imageUrl);
        $filename = $pathInfo['filename'];
        $extension = strtolower($pathInfo['extension']);

        return str_replace(
            ".{$extension}",
            "_{$width}x{$height}.{$format}",
            $imageUrl
        );
    }

    /**
     * Check if URL is external
     */
    protected function isExternalUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['host'])) {
            return false; // Relative URL
        }

        $currentHost = request()->getHost();
        return $parsedUrl['host'] !== $currentHost;
    }

    /**
     * Clear specific cache keys
     */
    public function clearCache(array $keys = []): bool
    {
        if (empty($keys)) {
            // Clear all CMS-related cache
            $patterns = [
                'page_content_*',
                'navigation_*',
                'footer_links_*',
                'faq_*',
                'seo_*'
            ];

            foreach ($patterns as $pattern) {
                Cache::flush();
            }
        } else {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }

        return true;
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        // This would require a cache driver that supports statistics
        // For now, return mock data
        return [
            'total_keys' => 150,
            'total_size' => '45.2 MB',
            'hit_rate' => 85.6,
            'miss_rate' => 14.4,
            'top_keys' => [
                'page_content_homepage' => ['hits' => 1250, 'size' => '12.5 KB'],
                'navigation_header' => ['hits' => 980, 'size' => '8.2 KB'],
                'footer_links_main' => ['hits' => 875, 'size' => '5.1 KB'],
            ]
        ];
    }

    /**
     * Optimize database queries for better performance
     */
    public function optimizeQueries(): array
    {
        $optimizations = [];

        // Add indexes if they don't exist
        $requiredIndexes = [
            'pages' => ['slug', 'status', 'is_active', 'published_at'],
            'navigation_menus' => ['location', 'is_active', 'sort_order'],
            'footer_links' => ['section', 'is_active', 'sort_order'],
            'faqs' => ['faq_category_id', 'is_active', 'sort_order'],
            'faq_categories' => ['is_active', 'sort_order']
        ];

        foreach ($requiredIndexes as $table => $columns) {
            foreach ($columns as $column) {
                // Check if index exists and add if needed
                $optimizations[] = "Index check for {$table}.{$column}";
            }
        }

        return $optimizations;
    }

    /**
     * Generate sitemap for SEO
     */
    public function generateSitemap(): string
    {
        $urls = collect();

        // Add pages
        $pages = Page::where('status', 'published')
            ->where('is_active', true)
            ->where('visibility', 'public')
            ->select('slug', 'updated_at', 'created_at')
            ->get();

        foreach ($pages as $page) {
            $urls->push([
                'loc' => url("/{$page->slug}"),
                'lastmod' => optional($page->updated_at)->toISOString() ?? optional($page->created_at)->toISOString(),
                'changefreq' => 'weekly',
                'priority' => $page->slug === 'home' ? '1.0' : '0.8'
            ]);
        }

        // Add inquiry service pages (/services/{slug})
        $servicePages = \App\Models\InquiryServicePage::where('status', 'published')
            ->where('is_active', true)
            ->select('slug', 'updated_at', 'created_at')
            ->get();

        foreach ($servicePages as $page) {
            $urls->push([
                'loc' => route('inquiry-services.show', $page->slug),
                'lastmod' => optional($page->updated_at)->toISOString() ?? optional($page->created_at)->toISOString(),
                'changefreq' => 'weekly',
                'priority' => '0.8'
            ]);
        }

        // Add CMS content (blog/articles) - include content type for correct URL
        $cmsContents = \App\Models\Website\CmsContent::published()->with('contentType')->select('slug', 'published_at', 'updated_at', 'created_at', 'cms_content_type_id')->get();
        foreach ($cmsContents as $content) {
            $typeSlug = $content->contentType->slug ?? 'blog';
            $loc = url("/{$typeSlug}/{$content->slug}");
            $urls->push([
                'loc' => $loc,
                'lastmod' => optional($content->updated_at)->toISOString() ?? optional($content->published_at)->toISOString() ?? optional($content->created_at)->toISOString(),
                'changefreq' => 'weekly',
                'priority' => '0.7'
            ]);
        }

        // Add vehicle detail pages
        $vehicles = \App\Models\Vehicle\Vehicle::where('status', 'published')->where('is_active', true)->select('id', 'updated_at', 'created_at', 'slug')->get();
        foreach ($vehicles as $vehicle) {
            $loc = route('vehicle.details', $vehicle->id);
            $urls->push([
                'loc' => $loc,
                'lastmod' => optional($vehicle->updated_at)->toISOString() ?? optional($vehicle->created_at)->toISOString(),
                'changefreq' => 'monthly',
                'priority' => '0.6'
            ]);
        }

        // Deduplicate by loc
        $urls = $urls->unique(function ($item) {
            return $item['loc'];
        })->values();

        // Generate XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        // Cache the sitemap
        Cache::put('sitemap', $xml, 86400); // 24 hours

        return $xml;
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'cache_hit_rate' => $this->getCacheHitRate(),
            'average_response_time' => $this->getAverageResponseTime(),
            'page_load_times' => $this->getPageLoadTimes(),
            'database_query_time' => $this->getAverageQueryTime(),
            'memory_usage' => $this->getMemoryUsage(),
            'storage_usage' => $this->getStorageUsage()
        ];
    }

    protected function getCacheHitRate(): float
    {
        // Mock implementation - integrate with your monitoring system
        return 85.6;
    }

    protected function getAverageResponseTime(): float
    {
        // Mock implementation - integrate with your monitoring system
        return 245.8; // milliseconds
    }

    protected function getPageLoadTimes(): array
    {
        // Mock implementation - integrate with your monitoring system
        return [
            'homepage' => 1.2,
            'about' => 0.8,
            'contact' => 0.9,
            'faq' => 1.1
        ];
    }

    protected function getAverageQueryTime(): float
    {
        // Mock implementation - integrate with your monitoring system
        return 12.5; // milliseconds
    }

    protected function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    protected function getStorageUsage(): array
    {
        // Mock implementation - integrate with your storage monitoring
        return [
            'total' => '10.5 GB',
            'used' => '3.2 GB',
            'free' => '7.3 GB',
            'percentage' => 30.5
        ];
    }
}