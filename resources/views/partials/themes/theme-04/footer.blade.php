@php
    $theme04ServicesLinks = [];
    $theme04RoutesLinks = [];
    $theme04SupportLinks = [];

    foreach (['services' => 'theme04ServicesLinks', 'routes' => 'theme04RoutesLinks', 'support' => 'theme04SupportLinks'] as $group => $target) {
        $index = 1;
        while (!empty($settings["footer_{$group}_link_{$index}_text"])) {
            ${$target}[] = [
                'text' => $settings["footer_{$group}_link_{$index}_text"],
                'url' => $settings["footer_{$group}_link_{$index}_url"] ?? '#',
            ];
            $index++;
        }
    }

    $theme04Phone = $settings['company_phone'] ?? '';
    $theme04PhoneHref = preg_replace('/[^0-9+]/', '', $theme04Phone);
    $theme04Whatsapp = $settings['company_whatsapp'] ?? $theme04Phone;
    $theme04WhatsappHref = preg_replace('/[^0-9]/', '', $theme04Whatsapp);
    $theme04Email = $settings['company_email'] ?? '';
    $theme04Brand = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
@endphp

<footer class="t4-footer">
    @if ($settings['footer_newsletter_enabled'] ?? false)
        <section class="t4-footer__newsletter" aria-labelledby="t4-newsletter-title">
            <div>
                <h2 id="t4-newsletter-title" class="t4-kicker">{{ $settings['footer_newsletter_heading'] ?? 'Subscribe to Our Newsletter' }}</h2>
                <p>{{ $settings['footer_newsletter_subheading'] ?? 'Get the latest updates and offers.' }}</p>
            </div>
            <form action="{{ $settings['footer_newsletter_action'] ?? '#' }}" method="POST">
                @csrf
                <label class="visually-hidden" for="t4-newsletter-email">Email address</label>
                <input id="t4-newsletter-email" type="email" name="email" placeholder="{{ $settings['footer_newsletter_placeholder'] ?? 'Enter your email' }}" required>
                <button type="submit">{{ $settings['footer_newsletter_button'] ?? 'Subscribe' }}</button>
            </form>
        </section>
    @endif

    <div class="t4-footer__main">
        <div class="t4-footer__brand">
            <a href="{{ route('home') }}"><img src="{{ s3_asset($settings['logo_footer'] ?? $settings['logo_header'] ?? 'assets/img/header-logo.png') }}" alt="{{ $settings['logo_footer_alt'] ?? $theme04Brand }}"></a>
            <p>{{ $settings['footer_company_tagline'] ?? ($settings['site_tagline'] ?? $settings['brand_tagline'] ?? 'Professional transport services') }}</p>
            <ul class="t4-footer__social" aria-label="Social media">
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
            [$settings['footer_services_title'] ?? 'Our Services', $theme04ServicesLinks],
            [$settings['footer_routes_title'] ?? 'Popular Routes', $theme04RoutesLinks],
            [$settings['footer_support_title'] ?? 'Support', $theme04SupportLinks],
        ] as [$title, $links])
            @if (!empty($links))
                <nav class="t4-footer__links" aria-label="{{ $title }}">
                    <h3>{{ $title }}</h3>
                    <ul>@foreach ($links as $link)<li><a href="{{ $link['url'] }}">{{ $link['text'] }}</a></li>@endforeach</ul>
                </nav>
            @endif
        @endforeach

        <div class="t4-footer__contact">
            <h3>{{ $settings['footer_inquiry_heading'] ?? 'Contact Us' }}</h3>
            @if (!empty($settings['company_address']))<address><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $settings['company_address'] }}</address>@endif
            @if ($theme04PhoneHref !== '')<a href="tel:{{ $theme04PhoneHref }}"><i class="bi bi-telephone" aria-hidden="true"></i>{{ $theme04Phone }}</a>@endif
            @if ($theme04WhatsappHref !== '')<a href="https://wa.me/{{ $theme04WhatsappHref }}"><i class="bi bi-whatsapp" aria-hidden="true"></i>{{ $theme04Whatsapp }}</a>@endif
            @if ($theme04Email !== '')<a href="mailto:{{ $theme04Email }}"><i class="bi bi-envelope" aria-hidden="true"></i>{{ $theme04Email }}</a>@endif
        </div>
    </div>

    <div class="t4-footer__bottom">
        <p>{{ $settings['footer_copyright_text'] ?? 'Copyright ' . date('Y') }} <a href="{{ route('home') }}">{{ $theme04Brand }}</a> | All Rights Reserved.</p>
        @if ($settings['footer_payment_methods_enabled'] ?? false)
            <ul aria-label="{{ $settings['footer_payment_methods_label'] ?? 'Accepted payment methods' }}">
                @if ($settings['footer_payment_mastercard'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/mastar-card-icon.svg') }}" alt="Mastercard"></li>@endif
                @if ($settings['footer_payment_visa'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/visa-icon.svg') }}" alt="Visa"></li>@endif
                @if ($settings['footer_payment_paypal'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/paypal-icon.svg') }}" alt="PayPal"></li>@endif
                @if ($settings['footer_payment_gpay'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/gpay-icon.svg') }}" alt="Google Pay"></li>@endif
            </ul>
        @endif
    </div>
</footer>
