@php
    $theme03ServicesLinks = [];
    $theme03RoutesLinks = [];
    $theme03SupportLinks = [];

    $theme03FooterGroups = [
        'services' => 'theme03ServicesLinks',
        'routes' => 'theme03RoutesLinks',
        'support' => 'theme03SupportLinks',
    ];

    foreach ($theme03FooterGroups as $group => $target) {
        $index = 1;
        while (!empty($settings["footer_{$group}_link_{$index}_text"])) {
            ${$target}[] = [
                'text' => $settings["footer_{$group}_link_{$index}_text"],
                'url' => $settings["footer_{$group}_link_{$index}_url"] ?? '#',
            ];
            $index++;
        }
    }

    $theme03Phone = $settings['company_phone'] ?? '';
    $theme03PhoneHref = preg_replace('/[^0-9+]/', '', $theme03Phone);
    $theme03Whatsapp = $settings['company_whatsapp'] ?? $theme03Phone;
    $theme03WhatsappHref = preg_replace('/[^0-9]/', '', $theme03Whatsapp);
    $theme03Email = $settings['company_email'] ?? '';
    $theme03Brand = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
@endphp

<footer class="t3-footer">
    @if ($settings['footer_newsletter_enabled'] ?? false)
        <section class="t3-footer__newsletter" aria-labelledby="t3-newsletter-title">
            <div>
                <span class="t3-kicker">Private dispatches</span>
                <h2 id="t3-newsletter-title">{{ $settings['footer_newsletter_heading'] ?? 'Subscribe to Our Newsletter' }}</h2>
                <p>{{ $settings['footer_newsletter_subheading'] ?? 'Get the latest updates and offers.' }}</p>
            </div>
            <form action="{{ $settings['footer_newsletter_action'] ?? '#' }}" method="POST">
                @csrf
                <label class="visually-hidden" for="t3-newsletter-email">Email address</label>
                <input
                    id="t3-newsletter-email"
                    type="email"
                    name="email"
                    placeholder="{{ $settings['footer_newsletter_placeholder'] ?? 'Enter your email' }}"
                    required>
                <button type="submit">{{ $settings['footer_newsletter_button'] ?? 'Subscribe' }}</button>
            </form>
        </section>
    @endif

    <div class="t3-footer__contact-band">
        <div>
            <span class="t3-kicker">{{ $settings['footer_inquiry_heading'] ?? 'Need help booking?' }}</span>
            <p>{{ $settings['footer_inquiry_subheading'] ?? 'Call or message us for quick assistance.' }}</p>
        </div>
        <ul>
            @if ($theme03PhoneHref !== '')
                <li><span>{{ $settings['footer_phone_label'] ?? 'Call' }}</span><a href="tel:{{ $theme03PhoneHref }}">{{ $theme03Phone }}</a></li>
            @endif
            @if ($theme03WhatsappHref !== '')
                <li><span>{{ $settings['footer_whatsapp_label'] ?? 'WhatsApp' }}</span><a href="https://wa.me/{{ $theme03WhatsappHref }}">{{ $theme03Whatsapp }}</a></li>
            @endif
            @if ($theme03Email !== '')
                <li><span>{{ $settings['footer_email_label'] ?? 'Email' }}</span><a href="mailto:{{ $theme03Email }}">{{ $theme03Email }}</a></li>
            @endif
        </ul>
    </div>

    <div class="t3-footer__main">
        <div class="t3-footer__brand">
            <a href="{{ route('home') }}" class="t3-footer__logo">
                <img
                    src="{{ s3_asset($settings['logo_footer'] ?? $settings['logo_header'] ?? 'assets/img/header-logo.png') }}"
                    alt="{{ $settings['logo_footer_alt'] ?? $theme03Brand }}">
            </a>
            <p>{{ $settings['footer_company_tagline'] ?? ($settings['site_tagline'] ?? $settings['brand_tagline'] ?? 'Professional transport services') }}</p>
            @if (!empty($settings['company_address']))
                <address>{{ $settings['company_address'] }}</address>
            @endif

            <ul class="t3-footer__social" aria-label="Social media">
                @foreach ([
                    'social_facebook' => ['Facebook', 'bxl-facebook'],
                    'social_linkedin' => ['LinkedIn', 'bxl-linkedin'],
                    'social_youtube' => ['YouTube', 'bxl-youtube'],
                    'social_instagram' => ['Instagram', 'bxl-instagram-alt'],
                    'social_twitter' => ['X / Twitter', 'bxl-twitter'],
                    'social_tiktok' => ['TikTok', 'bxl-tiktok'],
                ] as $settingKey => [$label, $icon])
                    @if (!empty($settings[$settingKey]))
                        <li><a href="{{ $settings[$settingKey] }}" aria-label="{{ $label }}"><i class="bx {{ $icon }}" aria-hidden="true"></i></a></li>
                    @endif
                @endforeach
            </ul>
        </div>

        @foreach ([
            [$settings['footer_services_title'] ?? 'Our Services', $theme03ServicesLinks],
            [$settings['footer_routes_title'] ?? 'Popular Routes', $theme03RoutesLinks],
            [$settings['footer_support_title'] ?? 'Support', $theme03SupportLinks],
        ] as [$title, $links])
            @if (!empty($links))
                <nav class="t3-footer__links" aria-label="{{ $title }}">
                    <h3>{{ $title }}</h3>
                    <ul>
                        @foreach ($links as $link)
                            <li><a href="{{ $link['url'] }}">{{ $link['text'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        @endforeach
    </div>

    <div class="t3-footer__bottom">
        <p>{{ $settings['footer_copyright_text'] ?? 'Copyright ' . date('Y') }} <a href="{{ route('home') }}">{{ $theme03Brand }}</a> | All Rights Reserved.</p>

        @if ($settings['footer_payment_methods_enabled'] ?? false)
            <div class="t3-footer__payments">
                <span>{{ $settings['footer_payment_methods_label'] ?? 'Accepted payment methods' }}</span>
                <ul>
                    @if ($settings['footer_payment_mastercard'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/mastar-card-icon.svg') }}" alt="Mastercard"></li>@endif
                    @if ($settings['footer_payment_visa'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/visa-icon.svg') }}" alt="Visa"></li>@endif
                    @if ($settings['footer_payment_paypal'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/paypal-icon.svg') }}" alt="PayPal"></li>@endif
                    @if ($settings['footer_payment_gpay'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/gpay-icon.svg') }}" alt="Google Pay"></li>@endif
                </ul>
            </div>
        @endif
    </div>
</footer>
