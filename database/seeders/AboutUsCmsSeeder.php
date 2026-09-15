<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\NavigationMenu;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AboutUsCmsSeeder extends Seeder
{
    public function run(): void
    {
        $type = CmsContentType::firstOrCreate(
            ['slug' => 'about'],
            [
                'title' => 'About Us',
                'description' => 'The Casons story, people, recognition, brand and careers.',
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

        $mediaUrls = $this->uploadMedia();

        foreach ($this->pages() as $page) {
            $page['thumbnail'] = strtr($page['thumbnail'] ?? '', $mediaUrls) ?: null;
            $page['body'] = strtr($page['body'], $mediaUrls);
            $content = CmsContent::firstOrNew(['slug' => $page['slug']]);

            if (!$content->exists || !$content->updated_user_id) {
                $content->fill($page + [
                        'cms_content_type_id' => $type->id,
                        'status' => 'published',
                        'published_at' => $content->published_at ?? now(),
                        'is_active' => true,
                        'allow_comments' => false,
                    ])->save();
            }
        }

        WebsiteSetting::setValue('about_page_slug', 'who-we-are');

        foreach (Company::pluck('id')->prepend(null) as $companyId) {
            $this->seedNavigation($companyId);
        }
    }

    private function uploadMedia(): array
    {
        foreach (['key', 'secret', 'region', 'bucket'] as $key) {
            if (!config("filesystems.disks.s3.{$key}")) {
                throw new RuntimeException("Missing common media S3 configuration: filesystems.disks.s3.{$key}");
            }
        }

        $disk = Storage::disk('s3');
        $urls = [];

        foreach ($this->media() as $source => $media) {
            $path = $media['path'];

            if (!$disk->exists($path)) {
                $response = Http::retry(3, 500)
                    ->timeout(60)
                    ->get($media['download_url'] ?? html_entity_decode($source))
                    ->throw();
                $contentType = $response->header('Content-Type') ?: 'application/octet-stream';

                if ($response->body() === '' || str_starts_with($contentType, 'text/html')) {
                    throw new RuntimeException("Media download did not return a file: {$source}");
                }

                $disk->put($path, $response->body(), [
                    'ContentType' => $contentType,
                ]);
            }

            $urls[$source] = '/resources/' . ltrim($path, '/');
        }

        return $urls;
    }

    private function media(): array
    {
        return [
            'https://www.casons.lk/assets/img/articals/Casons%20Logo%20-%20Red%203D%20200.jpg' => ['path' => 'cms/about/casons-logo-red-3d-200.jpg'],
            'https://www.casons.lk/assets/img/articals/Vision%20400.png' => ['path' => 'cms/about/vision-400.png'],
            'https://www.casons.lk/assets/img/articals/I%20Succeed%20600.png' => ['path' => 'cms/about/i-succeed-600.png'],
            'https://www.casons.lk/assets/img/articals/Casons%20Trademark%20-%20New.png' => ['path' => 'cms/about/casons-trademark-new.png'],
            'https://www.casons.lk/assets/img/articals/Mr%20Zakir%20Story%201.png' => ['path' => 'cms/about/mr-zakir-story-1.png'],
            'https://www.casons.lk/assets/img/articals/Mr%20Zakir%20Ahamed.jpg' => ['path' => 'cms/about/mr-zakir-ahamed.jpg'],
            'https://www.casons.lk/assets/img/zakir-ahmee-casons-lk.jpg' => ['path' => 'cms/about/zakir-ahmee-casons-lk.jpg'],
            'https://www.casons.lk/assets/audio/Casons-From-Rs-20-Car-Rental-Empire.wav' => ['path' => 'cms/about/casons-from-rs-20-car-rental-empire.wav'],
            'https://drive.google.com/file/d/0B_6Fji7-LxSVNFdXci1KM213MGM/view?usp=sharing&amp;resourcekey=0-OwblimUbsQ_dI0wtMMGdJg' => ['path' => 'cms/about/recommendations/british-high-commission', 'download_url' => 'https://drive.google.com/uc?export=download&id=0B_6Fji7-LxSVNFdXci1KM213MGM&resourcekey=0-OwblimUbsQ_dI0wtMMGdJg'],
            'https://drive.google.com/file/d/0B_6Fji7-LxSVYUxiLXVnczFvVU0/view?usp=sharing&amp;resourcekey=0-NXC7s2V2AV-oVe99NrV2PA' => ['path' => 'cms/about/recommendations/european-union-original', 'download_url' => 'https://drive.google.com/uc?export=download&id=0B_6Fji7-LxSVYUxiLXVnczFvVU0&resourcekey=0-NXC7s2V2AV-oVe99NrV2PA'],
            'https://drive.google.com/file/d/0B_6Fji7-LxSVVTdFdHB4NVpuZkk/view?usp=sharing&amp;resourcekey=0-5yC3pFQlJbYh_R2T4f7HZw' => ['path' => 'cms/about/recommendations/uk-bevarages', 'download_url' => 'https://drive.google.com/uc?export=download&id=0B_6Fji7-LxSVVTdFdHB4NVpuZkk&resourcekey=0-5yC3pFQlJbYh_R2T4f7HZw'],
            'https://drive.google.com/file/d/0B_6Fji7-LxSVNy1fT1psakQ1N1U/view?usp=sharing&amp;resourcekey=0-pA-BLknmPVZCCqh9eToWAw' => ['path' => 'cms/about/recommendations/usaid-sri-lanka', 'download_url' => 'https://drive.google.com/uc?export=download&id=0B_6Fji7-LxSVNy1fT1psakQ1N1U&resourcekey=0-pA-BLknmPVZCCqh9eToWAw'],
            'https://drive.google.com/file/d/0B_6Fji7-LxSVYVNsZVlzLXhRVzQ/view?usp=sharing&amp;resourcekey=0-iihbDvC9dNJMWvJ5EENMSA' => ['path' => 'cms/about/recommendations/virtusa', 'download_url' => 'https://drive.google.com/uc?export=download&id=0B_6Fji7-LxSVYVNsZVlzLXhRVzQ&resourcekey=0-iihbDvC9dNJMWvJ5EENMSA'],
            'https://drive.google.com/file/d/1BDnASBrSF-AgoGEAtRCvkvI9VbnZjI1S/view?usp=sharing' => ['path' => 'cms/about/recommendations/continuum-aviation', 'download_url' => 'https://drive.google.com/uc?export=download&id=1BDnASBrSF-AgoGEAtRCvkvI9VbnZjI1S'],
            'https://drive.google.com/file/d/1M123dGHQwglOz-HLN3J4HiQGWrhTmKau/view?usp=sharing' => ['path' => 'cms/about/recommendations/consulate-of-israel', 'download_url' => 'https://drive.google.com/uc?export=download&id=1M123dGHQwglOz-HLN3J4HiQGWrhTmKau'],
            'https://drive.google.com/file/d/1gWfs4xb_bAVawrrnh_Aq-OOFESNRh8EA/view?usp=sharing' => ['path' => 'cms/about/recommendations/ezy-corp', 'download_url' => 'https://drive.google.com/uc?export=download&id=1gWfs4xb_bAVawrrnh_Aq-OOFESNRh8EA'],
            'https://drive.google.com/file/d/1t0C5Np1aMHIPLx6txJjGWJYf56_8JhPW/view?usp=sharing' => ['path' => 'cms/about/recommendations/bmi-holdings', 'download_url' => 'https://drive.google.com/uc?export=download&id=1t0C5Np1aMHIPLx6txJjGWJYf56_8JhPW'],
            'https://drive.google.com/file/d/14Xn4dyZxt5SWyoK2iPY-7tRfzj6GGEob/view?usp=sharing' => ['path' => 'cms/about/recommendations/ana-marine-agencies-and-otv-veolia', 'download_url' => 'https://drive.google.com/uc?export=download&id=14Xn4dyZxt5SWyoK2iPY-7tRfzj6GGEob'],
            'https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EUi-HkYwBUFKqbtTHBB4QBIBzSAWOeiq7JqrutBrvx15oA?e=pk3cK7' => ['path' => 'cms/about/recommendations/sata-2017', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EUi-HkYwBUFKqbtTHBB4QBIBzSAWOeiq7JqrutBrvx15oA?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EVxSfnSfKPpJulogEfN_B5kBURUCYvRMg0670isTqV579A?e=4wmdqe' => ['path' => 'cms/about/recommendations/sata-2018', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EVxSfnSfKPpJulogEfN_B5kBURUCYvRMg0670isTqV579A?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQghxm5kooZAjAJZuePjvK4BKT1T3Ep8s1eBy9ftdNFzuQ?e=rlcr1y' => ['path' => 'cms/about/recommendations/sata-2019', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQghxm5kooZAjAJZuePjvK4BKT1T3Ep8s1eBy9ftdNFzuQ?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/ERXuEkc2755Pn9AxRE_kmd8BT42hShncLLng1NmAmb9R5w?e=zeqRUf' => ['path' => 'cms/about/recommendations/sata-2020', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/ERXuEkc2755Pn9AxRE_kmd8BT42hShncLLng1NmAmb9R5w?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EVqsdpWyBxNNnWveWAqj6DoBd6d37HtbrCOMh_CsEokbzQ?e=FAOkRi' => ['path' => 'cms/about/recommendations/sata-2023', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EVqsdpWyBxNNnWveWAqj6DoBd6d37HtbrCOMh_CsEokbzQ?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQtEfB7zu2lMlYOJkJmysfIB9U3gtVL-FSRx6yVa6NVRMQ?e=dhfWEP' => ['path' => 'cms/about/recommendations/european-union', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQtEfB7zu2lMlYOJkJmysfIB9U3gtVL-FSRx6yVa6NVRMQ?download=1'],
            'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/IQDjH88--IwdSZRhbe01xuG4ATeDe1loxV2_V1sk-i6moPU?e=EwiXh3' => ['path' => 'cms/about/recommendations/kiss-fm', 'download_url' => 'https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/IQDjH88--IwdSZRhbe01xuG4ATeDe1loxV2_V1sk-i6moPU?download=1'],
        ];
    }

    private function seedNavigation(?string $companyId): void
    {
        $about = NavigationMenu::firstOrCreate(
            ['company_id' => $companyId, 'parent_id' => null, 'title' => 'About'],
            [
                'url' => '/about',
                'target' => '_self',
                'icon' => 'info',
                'description' => 'Learn about Casons',
                'sort_order' => 5,
                'is_active' => true,
                'show_in_header' => true,
                'show_in_footer' => true,
            ]
        );

        foreach ([
            ['Who We Are', '/about/who-we-are'],
            ['Recommendations', '/about/recommendations'],
            ['Trademarks & Logos', '/about/trademarks'],
            ['Careers at Casons', '/about/careers'],
            ['Chairman Story', '/about/chairman-story'],
            ['News', '/news'],
        ] as $order => [$title, $url]) {
            NavigationMenu::firstOrCreate(
                ['company_id' => $companyId, 'parent_id' => $about->id, 'title' => $title],
                [
                    'url' => $url,
                    'target' => '_self',
                    'sort_order' => $order + 1,
                    'is_active' => true,
                    'show_in_header' => true,
                    'show_in_footer' => false,
                ]
            );
        }
    }

    private function pages(): array
    {
        return [
            [
                'title' => 'Who We Are',
                'slug' => 'who-we-are',
                'excerpt' => 'Discover the Casons story, our vision, our people, our clients and one of Sri Lanka\'s largest rental fleets.',
                'thumbnail' => 'https://www.casons.lk/assets/img/articals/Casons%20Logo%20-%20Red%203D%20200.jpg',
                'body' => <<<'HTML'
<h2>Our Company</h2>
<p>Founded in 1987 by Mr. M. C. Zakir Ahamed, Casons Rent a Car is a family-run business that has maintained its position as the leading car rental company in Sri Lanka. Offering a wide range of transportation services, including budget cars, luxury limousines, and coaches with chauffeur or self-driving options, Casons caters to local and foreign tourists, corporate groups, VIP movements, weddings, and celebrity events.</p>
<p>With the largest fleet of vehicles in the country, Casons is known for its high levels of customer service quality and satisfaction.</p>
<p>In 1991, Mr. M. C. Zufer Ahamed joined the company as CEO and Director, helping Casons quickly expand and establish itself as the leading car rental company in Sri Lanka. Inspired by their father, Mr. Mohamed Cassim, the company name, Casons, is a shortened version of “Cassim &amp; Sons.” Casons' team of dedicated and dynamic staff members is committed to upholding the company motto, “Excellence Unmatched,” by going above and beyond to improve the quality of service for every customer.</p>
<p>In 2017, Casons Rent a Car expanded its reach to the Bandaranayake International Airport (BIA) with the opening of a modern travel counter at the BIA Arrival Terminal. As the pioneers and specialists in the car rental industry in Sri Lanka, Casons is also the leading budget car rental company on the island.</p>
<p><img src="https://www.casons.lk/assets/img/articals/Casons%20Logo%20-%20Red%203D%20200.jpg" alt="Casons Rent a Car logo"></p>
<h2>Our Vision</h2>
<p>To lead our industry by defining service excellence and building unmatched customer loyalty.</p>
<p><img src="https://www.casons.lk/assets/img/articals/Vision%20400.png" alt="Casons vision"></p>
<h2>Our Strength</h2>
<p>At our company, we are proud to have a strong and dedicated team of 35 marketing and operational professionals, 15 skilled automobile technicians, and 150 experienced drivers. This diverse group hails from all parts of the island and is fluent in the main three languages spoken in our country. Their loyalty and expertise are essential to our daily operations, as they allow us to provide swift and efficient service in a constantly changing business landscape.</p>
<p>We believe that our dedicated staff is the key to our success and are grateful to have them on our team.</p>
<p><img src="https://www.casons.lk/assets/img/articals/I%20Succeed%20600.png" alt="Casons team strength"></p>
<h2>Our Clients</h2>
<p>We are grateful to serve a diverse and loyal customer base made up of individuals, companies, and groups who rely on our vehicle rental services for a variety of purposes. Our clients include local and foreign individuals visiting Sri Lanka for business or leisure, as well as government and non-governmental organizations, and corporate and diplomatic missions. We also work with hotels, airlines, travel agents, and tour operators.</p>
<p>We are proud to have received numerous accolades and endorsements from both organizations and individual customers for our ongoing commitment to improving the services we offer.</p>
<h2>Our Fleet</h2>
<p>We are proud to have the largest fleet of vehicles in the car rental service industry in Sri Lanka. Our fleet comprises over 500 vehicles, including cars, vans, double cabs, SUVs, coaches, and luxury vehicles such as Chrysler, Hummer, Benz, and BMW. We are also excited to offer the latest addition to our fleet, a 24-foot Chrysler limousine, which represents the height of luxury and class and is pioneering the limousine service in Sri Lanka.</p>
<p>Our extensive selection of vehicles for transportation needs includes over 50 4WD jeeps, 50 vans, 200 cars, double cabs, buses, and motorbikes, allowing us to meet a wide range of transportation needs.</p>
HTML,
                'meta_title' => 'Who We Are | Casons Rent-A-Car Sri Lanka',
                'meta_description' => 'Learn about Casons Rent-A-Car, our history since 1987, our people, clients, fleet and commitment to service excellence in Sri Lanka.',
                'display_order' => 1,
            ],
            [
                'title' => 'Recommendations',
                'slug' => 'recommendations',
                'excerpt' => 'Organisations, missions, partners and industry awards associated with Casons.',
                'body' => <<<'HTML'
<p>The following recommendations and recognition documents are available from the original Casons website.</p>
<ul>
<li><a href="https://drive.google.com/file/d/0B_6Fji7-LxSVNFdXci1KM213MGM/view?usp=sharing&amp;resourcekey=0-OwblimUbsQ_dI0wtMMGdJg" target="_blank" rel="noopener">British High Commission</a></li>
<li><a href="https://drive.google.com/file/d/0B_6Fji7-LxSVYUxiLXVnczFvVU0/view?usp=sharing&amp;resourcekey=0-NXC7s2V2AV-oVe99NrV2PA" target="_blank" rel="noopener">European Union</a></li>
<li><a href="https://drive.google.com/file/d/0B_6Fji7-LxSVVTdFdHB4NVpuZkk/view?usp=sharing&amp;resourcekey=0-5yC3pFQlJbYh_R2T4f7HZw" target="_blank" rel="noopener">UK Bevarages</a></li>
<li><a href="https://drive.google.com/file/d/0B_6Fji7-LxSVNy1fT1psakQ1N1U/view?usp=sharing&amp;resourcekey=0-pA-BLknmPVZCCqh9eToWAw" target="_blank" rel="noopener">USAID Sri Lanka</a></li>
<li><a href="https://drive.google.com/file/d/0B_6Fji7-LxSVYVNsZVlzLXhRVzQ/view?usp=sharing&amp;resourcekey=0-iihbDvC9dNJMWvJ5EENMSA" target="_blank" rel="noopener">Virtusa</a></li>
<li><a href="https://drive.google.com/file/d/1BDnASBrSF-AgoGEAtRCvkvI9VbnZjI1S/view?usp=sharing" target="_blank" rel="noopener">Continuum Aviation</a></li>
<li><a href="https://drive.google.com/file/d/1M123dGHQwglOz-HLN3J4HiQGWrhTmKau/view?usp=sharing" target="_blank" rel="noopener">Consulate of Israel</a></li>
<li><a href="https://drive.google.com/file/d/1gWfs4xb_bAVawrrnh_Aq-OOFESNRh8EA/view?usp=sharing" target="_blank" rel="noopener">EZY Corp</a></li>
<li><a href="https://drive.google.com/file/d/1t0C5Np1aMHIPLx6txJjGWJYf56_8JhPW/view?usp=sharing" target="_blank" rel="noopener">BMI Holdings</a></li>
<li><a href="https://drive.google.com/file/d/14Xn4dyZxt5SWyoK2iPY-7tRfzj6GGEob/view?usp=sharing" target="_blank" rel="noopener">A N A Marine Agencies</a></li>
<li><a href="https://drive.google.com/file/d/14Xn4dyZxt5SWyoK2iPY-7tRfzj6GGEob/view?usp=sharing" target="_blank" rel="noopener">OTV VEOLIA</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EUi-HkYwBUFKqbtTHBB4QBIBzSAWOeiq7JqrutBrvx15oA?e=pk3cK7" target="_blank" rel="noopener">SATA 2017</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EVxSfnSfKPpJulogEfN_B5kBURUCYvRMg0670isTqV579A?e=4wmdqe" target="_blank" rel="noopener">SATA 2018</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQghxm5kooZAjAJZuePjvK4BKT1T3Ep8s1eBy9ftdNFzuQ?e=rlcr1y" target="_blank" rel="noopener">SATA 2019</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/ERXuEkc2755Pn9AxRE_kmd8BT42hShncLLng1NmAmb9R5w?e=zeqRUf" target="_blank" rel="noopener">SATA 2020</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:i:/g/personal/it_casonsrentacar_com/EVqsdpWyBxNNnWveWAqj6DoBd6d37HtbrCOMh_CsEokbzQ?e=FAOkRi" target="_blank" rel="noopener">SATA 2023</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/EQtEfB7zu2lMlYOJkJmysfIB9U3gtVL-FSRx6yVa6NVRMQ?e=dhfWEP" target="_blank" rel="noopener">European Union</a></li>
<li><a href="https://casonsrentacarcom-my.sharepoint.com/:b:/g/personal/it_casonsrentacar_com/IQDjH88--IwdSZRhbe01xuG4ATeDe1loxV2_V1sk-i6moPU?e=EwiXh3" target="_blank" rel="noopener">Kiss FM</a></li>
</ul>
HTML,
                'meta_title' => 'Recommendations and Recognition | Casons Rent-A-Car',
                'meta_description' => 'View organisations served by Casons and our recognition at the South Asian Travel Awards.',
                'display_order' => 2,
            ],
            [
                'title' => 'Casons Trademarks & Logos',
                'slug' => 'trademarks',
                'excerpt' => 'Official guidance for using the Casons name, trademarks and brand assets.',
                'thumbnail' => 'https://www.casons.lk/assets/img/articals/Casons%20Trademark%20-%20New.png',
                'body' => '<p><img src="https://www.casons.lk/assets/img/articals/Casons%20Trademark%20-%20New.png" alt="Casons trademarks and logos"></p>',
                'meta_title' => 'Casons Trademarks and Logos | Brand Information',
                'meta_description' => 'Information about the Casons name, trademarks, logos and requests to use official brand assets.',
                'display_order' => 3,
            ],
            [
                'title' => 'Careers at Casons',
                'slug' => 'careers',
                'excerpt' => 'Build your career in transport, customer service and mobility with Casons.',
                'body' => <<<'HTML'
<p>A career at Casons can be a rewarding experience with excellent working environment under an Entrepreneur.</p>
<p>As a Casons employee you will be able to grasp opportunities for continuous career development as well as continuous learning and credentials.</p>
<p>You are expected to respect all our customers and you should have the passion and commitment to deliver service excellence.</p>
<p>If you feel that you have the right attitudes and values, please email your resume with 2 non-related referees to:</p>
<p><a href="mailto:hr@casonsrentacar.com">hr@casonsrentacar.com</a></p>
<p>or post your resume to:</p>
<address>Human Resources<br>Casons Rent A Car (Pvt) Ltd<br>181, Gothami Garden, Gothami Road,<br>Rajagiriya.</address>
<h2>Current Vacancies</h2>
HTML,
                'meta_title' => 'Careers at Casons | Join Our Team',
                'meta_description' => 'Explore careers at Casons Rent-A-Car and learn how to submit your CV to our Human Resources team.',
                'display_order' => 4,
            ],
            [
                'title' => 'Chairman Story',
                'slug' => 'chairman-story',
                'excerpt' => 'The story of Mr. M. C. Zakir Ahamed—from a determined young entrepreneur to the founder of Casons Rent-A-Car.',
                'thumbnail' => 'https://www.casons.lk/assets/img/articals/Mr%20Zakir%20Story%201.png',
                'body' => <<<'HTML'
<h2>From 20 rupees, 2 jeans and shirts, to one of Sri Lanka’s wealthiest Entrepreneurs</h2>
<p>As a young school boy in Matale with a knack for business and with the spirit of a fighter, Mr. Zakir Ahamed’s first interest in business began as early as primary school.</p>
<blockquote>“I remember when in school I used to buy chocolate and sell it to my friends, I bought for 20 rupees and sold at 25 rupees and make five rupees within twenty five minutes during the interval. This made me think if I had five hours I can make 25 rupees and how much I can make if I work five years. This is the first wonder I had into business,” said Mr. Zakir Ahamed.</blockquote>
<p>Born to a family of 4, to parents who were teachers by profession, Zakir was the eldest son. From an early age, Zakir had to learn to manage hardships and make sacrifices. As a school boy, he didn’t have electricity at home and had to study using kerosene lamps.</p>
<p><img src="https://www.casons.lk/assets/img/articals/Mr%20Zakir%20Story%201.png" alt="Mr Zakir Ahamed story"></p>
<blockquote>“I had a difficult childhood. As a kid, I had only one shirt to wear to school. I used to wash it every day and iron it with a charcoal iron to dry it in time for school the next day,” Mr. Ahamed recounted.</blockquote>
<p>But he didn’t let his struggles get the best of him. Energized and determined by hardships and the belief that he can achieve his dream, he enrolled at the Mahayiyawa Technical College, after his O/Ls to study automobile engineering.</p>
<blockquote>“I was crazy about cars from a very young age and my dream was to become an entrepreneur in the motorcar industry.”</blockquote>
<p>With just two jeans and two t-shirts in his backpack borrowed from his cousin and a million dreams in his heart, Mr. Zakir Ahamed strolled Colombo’s streets 30 years ago in search of an opportunity to pursue his dreams. It was a dream fuelled by struggles and hardships to become a successful entrepreneur in Sri Lanka.</p>
<blockquote>“I had only a little money with me and I thought one day I am gonna make more money with it,” Mr. Zakir Ahamed said.</blockquote>
<p>He started his career pumping petrol and checking meters at a local vehicle management company. His job was to check all the vehicles at the end of the day and take down the meter readings. But fate had other plans for him, and he had to leave this job unexpectedly.</p>
<blockquote>“At 21, I was working for a company which asked me to go home. When they told me to go home I had only 20 rupees with me. I thought I have a good youth and I was a 21 year old lad, I could hustle around and see how I could make more money with this 20 rupees,” he said.</blockquote>
<p>Getting sent home didn’t crush his spirit. With the help of a friend who lent him a Corolla station wagon, he started a rent-a-car business, but money was not easy to come.</p>
<blockquote>“I remember one instance I had to walk from Colpetty junction to BMICH just to collect 300 rupees from a client, because I couldn’t afford to come in a vehicle.”</blockquote>
<p>Mr. Ahamed said that there were many days when he had to decide between breakfast or lunch because he couldn’t afford both. He ate midway between breakfast and lunch so one meal satisfied both requirements. With only 5 rupees in his hands, he relied on the free curries that accompanied a dosai meal. Those vegetable and gram curries provided nutrition and kept him going for the rest of the day.</p>
<p>Soon, he was joined by his brother Zufer, who came to Colombo in search of a job.</p>
<blockquote>“My brother came to Colombo to get his passport made to go to UAE for a job. I told him I have a stable business and to join me so that together we can build a business,” Mr. Ahamed said.</blockquote>
<p>Starting their first rent-a-car office behind Temple Trees, the Ahamed brothers ran a two-man show. Mr. Ahamed was driver, owner, maintenance person, operations manager, cleaner and tea boy because initially it was just them in the business.</p>
<p>On his way to success he never forgot his family. From the early days of making a few rupees a month, he sent money to his sister who was studying at Jaffna University. Three hundred rupees may seem a small amount to many, but to Mr. Ahamed that was sometimes his entire earning for a month.</p>
<p>He overturned his fortunes by setting up Casons Rent a Car in 1987. He built a fleet ranging from standard sedans, SUVs, passenger vans and coaches to luxury limousines and cars such as Chrysler, Hummer, Benz and BMW, including a 24-foot Chrysler limousine.</p>
<blockquote>“I started my business with a lot of struggle. I had nothing, only a dream. When it came to money I had nothing, when it came to good clothing I had nothing, I had a big dream. With that dream I slowly built up this rent a car business,” Mr. Ahamed said.</blockquote>
<p>Supported by his brother Mr. Zufer Ahamed from the early days, Casons Rent a Car combines Mr. Ahamed’s fighting spirit with his brother’s innovative and creative business acumen.</p>
<p>Casons is a coined name for Cassim and Sons, the original company name created in honour of their father, Mr. Cassim. The company operated even during the time of terrorism, offering specialist tourist travel and promoting Sri Lanka as a popular tourist destination.</p>
<p>The company developed a dynamic and dedicated team including automobile technicians and trilingual drivers serving customers speedily and efficiently. Casons Rent a Car has supplied vehicles to major international events hosted in Sri Lanka. Its clientele includes United Nations agencies, diplomatic missions in Colombo, private-sector companies and government organisations.</p>
<p>Casons Rent a Car won Gold and Silver awards in 2017 and 2018 as a Leading Tourist Transport Provider in Sri Lanka and South Asia at the South Asian Travel Awards.</p>
<blockquote>“I am the biggest fighter. When it comes to business I don’t think anyone can fight like me. I will fight nonstop till I achieve it. This is the reason for my success,” he said.</blockquote>
<p>He believes that anyone who is determined can make it big if they are willing to work hard and never give up. A big dreamer and believer that one needs to first believe in themselves and their capabilities, Mr. Ahamed is an example of how determination, courage, self-belief and passion can change an individual’s fortunes.</p>
<blockquote>“Even at this age I dream. I dream that I want to be the best entrepreneur in Sri Lanka,” Mr. Ahamed said.</blockquote>
<p>He reminisces that his first customer, Mr. Prasad Wijesuriya, still continues to rely on him for transportation requirements. “I must have done something right for my first customer to stay with me for this long,” he said proudly.</p>
<p>The business expanded to the international tourist market, with customers booking vehicles online through the company’s booking engine. It also provides specialist vehicles, including four-wheel-drive vehicles for special terrain. Together, both brothers hope to take the transportation business to greater heights in Sri Lanka with new services for local and overseas travellers.</p>
<p>Ready to take on future challenges, Casons Rent a Car embraces problems as opportunities to learn and grow. With a business motto of providing excellent service to each customer and a philosophy of self-belief and never giving up, the company has more to offer in the coming years.</p>
<blockquote>“The best pages I have read is having problems in my life. I have numerous problems. Every morning I face hundreds of problems and make hundreds of mistakes. This is where I learn everything. I don’t regret problems; I enjoy them. I always overcome my problems. I have a resolution to never give up and to overcome my problems.”</blockquote>
<p>Looking back at his journey and forward to the future, Mr. Zakir Ahamed says:</p>
<blockquote>“I am very lucky that I have never done a job that I didn’t enjoy. Found out early in life what I liked doing and have put myself into a position to be able to do it. When you focus on possibilities you will have more opportunities. Don’t fear; it’s only a long-term mind killer. Face fear fast. It’s amazing! Do something you have never done and you will get what you have never got. Keep your good name, challenge yourself, help others and be the first to achieve.”</blockquote>
<p><img src="https://www.casons.lk/assets/img/articals/Mr%20Zakir%20Ahamed.jpg" alt="Mr Zakir Ahamed"></p>
<p><img src="https://www.casons.lk/assets/img/zakir-ahmee-casons-lk.jpg" alt="Mr Zakir Ahamed of Casons"></p>
<audio controls><source src="https://www.casons.lk/assets/audio/Casons-From-Rs-20-Car-Rental-Empire.wav" type="audio/wav">Your browser does not support the audio element.</audio>
HTML,
                'meta_title' => 'Chairman Story | Casons Founder M. C. Zakir Ahamed',
                'meta_description' => 'Read the story of Casons founder M. C. Zakir Ahamed and the determination behind a leading Sri Lankan transport business.',
                'display_order' => 5,
            ],
        ];
    }
}
