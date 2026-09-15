<?php

namespace Database\Seeders;

use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Database\Seeder;

class TheTaxiAboutCmsSeeder extends Seeder
{
    public function run(): void
    {
        $type = CmsContentType::firstOrCreate(
            ['slug' => 'about'],
            [
                'title' => 'About Us',
                'description' => 'TheTaxi company story, services and milestones.',
                'icon' => 'heroicons_outline:building-office-2',
                'url_prefix' => 'about',
                'is_active' => true,
                'display_order' => 6,
                'template_config' => [
                    'layout' => 'page',
                    'show_author' => false,
                    'show_date' => false,
                    'show_comments' => false,
                    'show_share' => false,
                ],
            ]
        );

        $content = CmsContent::firstOrNew(['slug' => 'about-thetaxi']);

        if (!$content->exists || !$content->updated_user_id) {
            $content->fill([
                'cms_content_type_id' => $type->id,
                'title' => 'About TheTaxi',
                'excerpt' => 'Discover TheTaxi’s journey from a single-car service to a nationwide transport and logistics provider.',
                'thumbnail' => '/resources/media/general/1767582118_A4CwbUd1_taxi-bg1.webp',
                'body' => $this->body(),
                'meta_title' => 'About TheTaxi | Sri Lanka Taxi Service',
                'meta_description' => 'Learn about TheTaxi’s history, nationwide fleet, professional chauffeurs, corporate transport and logistics services in Sri Lanka.',
                'status' => 'published',
                'published_at' => $content->published_at ?? now(),
                'is_active' => true,
                'allow_comments' => false,
                'display_order' => 1,
            ])->save();
        }
    }

    private function body(): string
    {
        return <<<'HTML'
<h2>Welcome to TheTaxi</h2>
<h3>Why We're Your Trusted Travel Partner</h3>
<p>TheTaxi is Sri Lanka’s premier taxi service, proudly serving travellers since 2011. Starting life as Casons Taxi, we built a reputation for reliability, safety and courteous service. We officially rebranded as TheTaxi, marking the beginning of an exciting new chapter. With over a decade of experience, our mission remains the same: to deliver safe, comfortable and memorable journeys for locals and international visitors. Our professional chauffeurs and well-maintained vehicles transport you to Sri Lanka’s most exquisite destinations and business hubs.</p>
<p>Our vision is rooted in accessibility and quality. We set out to provide budget-friendly point-to-point transfers, airport pickups and city travel without sacrificing service. Starting with just a single compact car and a small team, we have grown into a diverse fleet of sedans, premium vehicles and spacious vans to meet every traveller’s needs.</p>
<p><strong>Mr. Zufer Ahamed</strong><br>CEO of TheTaxi</p>

<h2>We're Providing the Best Taxi Service</h2>
<ul>
<li><strong>Local guidance:</strong> Professional chauffeurs with extensive knowledge of Sri Lanka’s roads and destinations.</li>
<li><strong>Competitive pricing:</strong> Budget-friendly transport options without compromising safety or service.</li>
<li><strong>Flexible travel:</strong> Point-to-point transfers, airport pickups, tours, corporate travel and logistics solutions.</li>
</ul>

<h2>Behind the Journey – Our Growth Story</h2>
<p>From a humble start to a nationwide fleet, our story is marked by continual improvement and customer trust.</p>
<h3>2011 – Launched</h3>
<p>Casons Taxi launched with a single compact car and a small team, providing budget taxis with exceptional service.</p>
<h3>2013 – Fleet expansion</h3>
<p>We grew beyond budget cars to include sedans and KDH vans, responding to multi-destination tour requests.</p>
<h3>2015 – Airport and tour expertise</h3>
<p>Leveraging our parent company and partners’ guidance, we opened a 24/7 airport counter and became experts in multi-destination transport, enabling quick pickups from airports, ports and train stations.</p>
<h3>2018 – 400-vehicle fleet</h3>
<p>Our fleet grew to over 400 vehicles ranging from budget cars to luxury SUVs and coaches, with a continued focus on comfort and affordability.</p>
<h3>2020 – Corporate portal</h3>
<p>We introduced a dedicated portal for multinational corporate clients with customised pricing and flexible services.</p>
<h3>2023 – Logistics and movers</h3>
<p>We expanded into house moving, truck and lorry hire, and logistics, offering cargo vans and trucks with professional loading and unloading services.</p>
<h3>2026 – TheTaxi rebrand</h3>
<p>Casons Taxi officially became TheTaxi on 10 January 2026 to reflect our growth and future vision.</p>

<h2>Why Travel with TheTaxi?</h2>
<p>We pride ourselves on professionalism. Our chauffeurs follow a strict dress code, and our vehicles are regularly inspected for safety and comfort. Whether you’re travelling for business or pleasure, TheTaxi ensures a seamless experience.</p>
<ul>
<li><strong>Expert drivers and local insight:</strong> Our chauffeurs are licensed by the Sri Lanka Tourism Development Authority and know every route.</li>
<li><strong>Personalised itineraries:</strong> From airport transfers to multi-day tours, each trip is planned around your schedule.</li>
<li><strong>24/7 customer support:</strong> Reservation agents are available day and night, and our vehicles and dispatchers operate around the clock.</li>
<li><strong>Corporate and group solutions:</strong> Limousines, shuttles and coaches for executives, conferences and events, supported by a corporate portal with tailored pricing.</li>
<li><strong>Diverse services:</strong> Beyond taxis, we provide van tours, moving services and logistics solutions with vehicles ranging from buddy lorries to jumbo trucks.</li>
</ul>
HTML;
    }
}
