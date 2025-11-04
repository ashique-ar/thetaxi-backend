<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AboutController extends Controller
{
    public function index()
    {
        $data = Cache::remember('about_page_data', 3600, function () {
            return [
                // Main about content
                'aboutData' => $this->getAboutData(),
                
                // Service items for "We're Providing Best Service Ever!" section
                'services' => $this->getAboutServices(),
                
                // Company journey timeline data
                'journeyTimeline' => $this->getJourneyTimeline(),
                
                // Why choose us features
                'whyChooseFeatures' => $this->getWhyChooseFeatures(),
                
                // Counter statistics
                'counters' => $this->getCounters(),
                
                // Testimonials for additional social proof
                'testimonials' => $this->getTestimonials(),
                
                // Partners data (reused from home)
                'partners' => $this->getPartners(),
            ];
        });

        return view('about', $data);
    }

    private function getAboutData()
    {
        $aboutType = CmsContentType::where('name', 'about-page')->first();
        if (!$aboutType) {
            return $this->getDefaultAboutData();
        }

        $content = CmsContent::where('cms_content_type_id', $aboutType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $aboutData = [];
        foreach ($content as $item) {
            $customFields = $item->custom_fields ?? [];
            
            switch ($item->slug ?? $item->title) {
                case 'breadcrumb':
                    $aboutData['breadcrumb'] = [
                        'title' => $item->title,
                        'subtitle' => $item->content,
                        'background_image' => $customFields['background_image'] ?? 'assets/img/innerpages/breadcrumb-bg2.jpg',
                        'home_link' => $customFields['home_link'] ?? 'Home',
                        'current_page' => $customFields['current_page'] ?? 'About TheTaxi'
                    ];
                    break;
                    
                case 'main-about':
                    $aboutData['main'] = [
                        'title' => $item->title,
                        'subtitle' => $customFields['subtitle'] ?? '',
                        'content' => $item->content,
                        'description' => $customFields['description'] ?? '',
                        'about_image' => $customFields['about_image'] ?? 'assets/img/home3/about-img.png',
                        'founder_signature' => $customFields['founder_signature'] ?? 'assets/img/innerpages/about-page-founder-signature.png',
                        'founder_name' => $customFields['founder_name'] ?? 'Robert Harringson',
                        'founder_title' => $customFields['founder_title'] ?? 'Founder at TheTaxi'
                    ];
                    break;
                    
                case 'offer-banner':
                    $aboutData['offer'] = [
                        'text' => $item->title,
                        'link_text' => $customFields['link_text'] ?? 'Check Offer',
                        'link_url' => $customFields['link_url'] ?? 'travel-package-01.html'
                    ];
                    break;
            }
        }

        return $aboutData ?: $this->getDefaultAboutData();
    }

    private function getAboutServices()
    {
        $servicesType = CmsContentType::where('name', 'services')->first();
        if (!$servicesType) {
            return $this->getDefaultServices();
        }

        $services = CmsContent::where('cms_content_type_id', $servicesType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(3)
            ->get();

        $servicesList = [];
        foreach ($services as $service) {
            $customFields = $service->custom_fields ?? [];
            $servicesList[] = [
                'title' => $service->title,
                'description' => $service->content,
                'icon_svg' => $customFields['icon_svg'] ?? $this->getDefaultServiceIcon(),
            ];
        }

        return $servicesList ?: $this->getDefaultServices();
    }

    private function getJourneyTimeline()
    {
        $timelineType = CmsContentType::where('name', 'about-page')->first();
        if (!$timelineType) {
            return $this->getDefaultTimeline();
        }

        $timelineItems = CmsContent::where('cms_content_type_id', $timelineType->id)
            ->where('is_active', true)
            ->where('slug', 'LIKE', 'timeline-%')
            ->orderBy('sort_order')
            ->get();

        $timeline = [];
        foreach ($timelineItems as $item) {
            $customFields = $item->custom_fields ?? [];
            $timeline[] = [
                'year' => $customFields['year'] ?? '2023',
                'title' => $item->title,
                'description' => $item->content,
                'image' => $customFields['image'] ?? 'assets/img/innerpages/about-page-journey-img1.jpg',
                'tab_id' => $customFields['tab_id'] ?? 'pills-one'
            ];
        }

        return $timeline ?: $this->getDefaultTimeline();
    }

    private function getWhyChooseFeatures()
    {
        $featuresType = CmsContentType::where('name', 'about-page')->first();
        if (!$featuresType) {
            return $this->getDefaultFeatures();
        }

        $features = CmsContent::where('cms_content_type_id', $featuresType->id)
            ->where('is_active', true)
            ->where('slug', 'LIKE', 'feature-%')
            ->orderBy('sort_order')
            ->limit(4)
            ->get();

        $featuresList = [];
        foreach ($features as $feature) {
            $customFields = $feature->custom_fields ?? [];
            $featuresList[] = [
                'title' => $feature->title,
                'description' => $feature->content,
                'icon_svg' => $customFields['icon_svg'] ?? $this->getDefaultFeatureIcon(),
                'css_class' => $customFields['css_class'] ?? ''
            ];
        }

        return $featuresList ?: $this->getDefaultFeatures();
    }

    private function getCounters()
    {
        $countersType = CmsContentType::where('name', 'about-page')->first();
        if (!$countersType) {
            return $this->getDefaultCounters();
        }

        $counters = CmsContent::where('cms_content_type_id', $countersType->id)
            ->where('is_active', true)
            ->where('slug', 'LIKE', 'counter-%')
            ->orderBy('sort_order')
            ->limit(4)
            ->get();

        $countersList = [];
        foreach ($counters as $counter) {
            $customFields = $counter->custom_fields ?? [];
            $countersList[] = [
                'number' => $customFields['number'] ?? '100',
                'suffix' => $customFields['suffix'] ?? '+',
                'title' => $counter->title,
                'icon_svg' => $customFields['icon_svg'] ?? $this->getDefaultCounterIcon(),
                'css_class' => $customFields['css_class'] ?? ''
            ];
        }

        return $countersList ?: $this->getDefaultCounters();
    }

    private function getTestimonials()
    {
        $testimonialsType = CmsContentType::where('name', 'testimonials')->first();
        if (!$testimonialsType) {
            return [];
        }

        return CmsContent::where('cms_content_type_id', $testimonialsType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(6)
            ->get()
            ->map(function ($testimonial) {
                $customFields = $testimonial->custom_fields ?? [];
                return [
                    'name' => $customFields['author_name'] ?? $testimonial->title,
                    'content' => $testimonial->content,
                    'rating' => $customFields['rating'] ?? 5,
                    'location' => $customFields['location'] ?? '',
                    'image' => $customFields['author_image'] ?? 'assets/img/testimonial/author.jpg'
                ];
            });
    }

    private function getPartners()
    {
        $partnersType = CmsContentType::where('name', 'partners')->first();
        if (!$partnersType) {
            return [];
        }

        return CmsContent::where('cms_content_type_id', $partnersType->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($partner) {
                $customFields = $partner->custom_fields ?? [];
                return [
                    'name' => $partner->title,
                    'logo' => $customFields['partner_logo'] ?? 'assets/img/home1/partner-01.png',
                    'url' => $customFields['partner_url'] ?? '#',
                    'is_external' => $customFields['is_external'] ?? false
                ];
            });
    }

    // Default fallback data methods
    private function getDefaultAboutData()
    {
        return [
            'breadcrumb' => [
                'title' => 'About TheTaxi',
                'subtitle' => 'Professional Taxi Services',
                'background_image' => 'assets/img/innerpages/breadcrumb-bg2.jpg',
                'home_link' => 'Home',
                'current_page' => 'About TheTaxi'
            ],
            'main' => [
                'title' => "Why We're Best Agency",
                'subtitle' => 'Welcome to TheTaxi Travel Agency – Your Gateway to Unforgettable Journeys!',
                'content' => 'TheTaxi Travel Agency is a trusted name in the travel industry, offering seamless travel planning, personalized itineraries, and unforgettable adventures.',
                'description' => 'We believe that travel is more than just moving from one place to another—it\'s about discovering new cultures, creating unforgettable experiences, and making lifelong memories.',
                'about_image' => 'assets/img/home3/about-img.png',
                'founder_signature' => 'assets/img/innerpages/about-page-founder-signature.png',
                'founder_name' => 'Robert Harringson',
                'founder_title' => 'Founder at TheTaxi'
            ],
            'offer' => [
                'text' => 'Flat 30% Discounts All Packages',
                'link_text' => 'Check Offer',
                'link_url' => 'travel-package-01.html'
            ]
        ];
    }

    private function getDefaultServices()
    {
        return [
            [
                'title' => 'Local Guidance',
                'description' => 'Travel agencies have experienced professionals guidance.',
                'icon_svg' => $this->getDefaultServiceIcon()
            ],
            [
                'title' => 'Deals & Discounts',
                'description' => 'Agencies have special discounts on flights, hotels, & packages.',
                'icon_svg' => $this->getDefaultServiceIcon()
            ],
            [
                'title' => 'Saves Money',
                'description' => 'Avoids hidden fees & tourist traps, Multi-destination & budget-friendly options.',
                'icon_svg' => $this->getDefaultServiceIcon()
            ]
        ];
    }

    private function getDefaultTimeline()
    {
        return [
            [
                'year' => '1986',
                'title' => '1986 – The Birth of Travel Agencies',
                'description' => 'The first-ever travel agency was founded by Thomas Cook in England.',
                'image' => 'assets/img/innerpages/about-page-journey-img1.jpg',
                'tab_id' => 'pills-one'
            ],
            [
                'year' => '1996',
                'title' => '1996 – A New Era of Exploration',
                'description' => 'Expanded services internationally, arranging trips to Paris and beyond.',
                'image' => 'assets/img/innerpages/about-page-journey-img2.jpg',
                'tab_id' => 'pills-two'
            ]
        ];
    }

    private function getDefaultFeatures()
    {
        return [
            [
                'title' => 'Expertly Curated Tours.',
                'description' => 'Professional travel planning',
                'icon_svg' => $this->getDefaultFeatureIcon(),
                'css_class' => ''
            ],
            [
                'title' => 'Affordable & Flexible Packages.',
                'description' => 'Budget-friendly options',
                'icon_svg' => $this->getDefaultFeatureIcon(),
                'css_class' => 'two'
            ]
        ];
    }

    private function getDefaultCounters()
    {
        return [
            [
                'number' => '150',
                'suffix' => '+',
                'title' => 'Destinations',
                'icon_svg' => $this->getDefaultCounterIcon(),
                'css_class' => ''
            ],
            [
                'number' => '20',
                'suffix' => '+',
                'title' => 'Happy Traveler',
                'icon_svg' => $this->getDefaultCounterIcon(),
                'css_class' => 'divider'
            ]
        ];
    }

    private function getDefaultServiceIcon()
    {
        return '<svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M15 0C21.4662 0 26.7081 5.24194 26.7081 11.7081C26.7081 18.1743 21.4662 23.4163 15 23.4163C8.53375 23.4163 3.29187 18.1743 3.29187 11.7081C3.29187 5.24194 8.53375 0 15 0Z"/></svg>';
    }

    private function getDefaultFeatureIcon()
    {
        return '<svg width="50" height="50" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg"><path d="M35.4081 30.5529L32.5878 38.1096C32.1995 39.1501 31.5928 40.107 30.8484 39.3186C30.6269 39.084 30.4387 38.7694 30.4738 38.4237L31.0957 32.291C31.0973 32.2737 31.1035 32.2571 31.1138 32.2431C31.1241 32.229 31.138 32.2181 31.154 32.2114L35.2782 30.4294C35.2957 30.4216 35.3152 30.4192 35.334 30.4226C35.3529 30.4259 35.3703 30.4348 35.384 30.4482C35.4129 30.4757 35.422 30.5155 35.4081 30.5529Z"/></svg>';
    }

    private function getDefaultCounterIcon()
    {
        return '<svg width="45" height="45" viewBox="0 0 45 45" xmlns="http://www.w3.org/2000/svg"><path d="M38.0333 16.4395C38.3412 16.4394 38.6319 16.2974 38.8214 16.0547C39.0107 15.8121 39.0776 15.4959 39.003 15.1973L37.5909 9.54883C37.4796 9.10368 37.08 8.79105 36.6212 8.79102H8.37899C7.92011 8.79102 7.52055 9.10366 7.40926 9.54883L5.99715 15.1973C5.92256 15.4959 5.98936 15.812 6.17879 16.0547C6.3683 16.2974 6.65894 16.4395 6.96688 16.4395H38.0333Z"/></svg>';
    }
}