@php
    $theme04ServicesLinks = [];
    $theme04RoutesLinks = [];
    $theme04SupportLinks = [];

    foreach (['services' => 'theme04ServicesLinks', 'routes' => 'theme04RoutesLinks', 'support' => 'theme04SupportLinks'] as $group => $target) {
        for ($index = 1; $index <= 10; $index++) {
            if (!empty($settings["footer_{$group}_link_{$index}_text"]) && !empty($settings["footer_{$group}_link_{$index}_url"])) {
                ${$target}[] = [
                    'text' => $settings["footer_{$group}_link_{$index}_text"],
                    'url' => $settings["footer_{$group}_link_{$index}_url"],
                ];
            }
        }
    }

    $theme04Phone = $settings['company_phone'] ?? '';
    $theme04PhoneHref = preg_replace('/[^0-9+]/', '', $theme04Phone);
    $theme04Whatsapp = $settings['company_whatsapp'] ?? $theme04Phone;
    $theme04WhatsappHref = preg_replace('/[^0-9]/', '', $theme04Whatsapp);
    $theme04Email = $settings['company_email'] ?? '';
    $theme04Brand = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
    $theme04CtaEnabled = filter_var($settings['footer_cta_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $theme04NewsletterEnabled = filter_var($settings['footer_newsletter_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $theme04FooterImage = $settings['footer_cta_image'] ?? null;
    $theme04FooterImageUrl = is_string($theme04FooterImage) && str_starts_with($theme04FooterImage, 'media/')
        ? route('resources.assets', ['path' => $theme04FooterImage])
        : s3_asset($theme04FooterImage);
    $theme04QuickLinks = $theme04RoutesLinks ?: ($theme04SupportLinks ?: [
        ['text' => 'Home', 'url' => route('home')],
        ['text' => 'Our Fleet', 'url' => route('vehicles')],
        ['text' => 'About Us', 'url' => route('about')],
        ['text' => 'Contact', 'url' => route('contact')],
    ]);
    $theme04ServicesLinks = $theme04ServicesLinks ?: [['text' => 'Airport Transfers', 'url' => route('point-to-point')]];
@endphp

<footer class="t4-footer">
    @if ($theme04CtaEnabled)
        <section class="t4-footer__cta" @if ($theme04FooterImageUrl) style="--t4-footer-image: url('{{ $theme04FooterImageUrl }}')" @endif>
            <div class="t4-footer__cta-inner">
                <div>
                    <span class="t4-footer__eyebrow">{{ $settings['footer_cta_eyebrow'] ?? 'Explore Sri Lanka' }}</span>
                    <h2>{{ $settings['footer_cta_heading'] ?? 'Your Next Journey Starts Here' }}</h2>
                    <p>{{ $settings['footer_cta_description'] ?? 'Premium vehicles. Professional service. Unforgettable experiences.' }}</p>
                    <div class="t4-footer__cta-actions">
                        <a class="t4-footer__book" href="{{ $settings['footer_cta_book_url'] ?? route('home') }}">{{ $settings['footer_cta_book_label'] ?? 'Book Now' }} <span aria-hidden="true">→</span></a>
                        @if ($theme04WhatsappHref !== '')<a class="t4-footer__chat" href="https://wa.me/{{ $theme04WhatsappHref }}"><i class="bi bi-whatsapp" aria-hidden="true"></i> {{ $settings['footer_cta_chat_label'] ?? 'Chat on WhatsApp' }}</a>@endif
                    </div>
                </div>
                @if (!empty($settings['footer_cta_location']))<span class="t4-footer__location"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> {{ $settings['footer_cta_location'] }}</span>@endif
            </div>
        </section>
    @endif

    <div class="t4-footer__main">
        <div class="t4-footer__brand">
            <a href="{{ route('home') }}"><img src="{{ s3_asset($settings['logo_footer'] ?? $settings['logo_header'] ?? 'assets/img/header-logo.png') }}" alt="{{ $settings['logo_footer_alt'] ?? $theme04Brand }}"></a>
            @if (!empty($settings['footer_company_tagline']))<small>{{ $settings['footer_company_tagline'] }}</small>@endif
            <p>{{ ($settings['footer_description'] ?? null) ?: ($settings['site_tagline'] ?? 'Professional transport services') }}</p>
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
            [$settings['footer_quick_title'] ?? 'Quick Links', $theme04QuickLinks],
            [$settings['footer_services_title'] ?? 'Our Services', $theme04ServicesLinks],
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
            @if ($theme04PhoneHref !== '')<a href="tel:{{ $theme04PhoneHref }}"><i class="bi bi-telephone" aria-hidden="true"></i>{{ $theme04Phone }}</a>@endif
            @if ($theme04WhatsappHref !== '')<a href="https://wa.me/{{ $theme04WhatsappHref }}"><i class="bi bi-whatsapp" aria-hidden="true"></i>{{ $theme04Whatsapp }}</a>@endif
            @if ($theme04Email !== '')<a href="mailto:{{ $theme04Email }}"><i class="bi bi-envelope" aria-hidden="true"></i>{{ $theme04Email }}</a>@endif
            @if (!empty($settings['company_address']))<address><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $settings['company_address'] }}</address>@endif
        </div>
        @if ($theme04NewsletterEnabled)
            <section class="t4-footer__newsletter" aria-labelledby="t4-newsletter-title">
                <h3 id="t4-newsletter-title">{{ $settings['footer_newsletter_heading'] ?? 'Newsletter' }}</h3>
                <p>{{ $settings['footer_newsletter_subheading'] ?? 'Get travel tips, special offers and updates.' }}</p>
                @if (session('newsletter_success'))<p role="status">{{ session('newsletter_success') }}</p>@endif
                @error('email', 'newsletter')<p role="alert">{{ $message }}</p>@enderror
                <form action="{{ route('newsletter.subscribe') }}" method="POST">
                    @csrf
                    <label class="visually-hidden" for="t4-newsletter-email">Email address</label>
                    <input id="t4-newsletter-email" type="email" name="email" value="{{ old('email') }}" placeholder="{{ $settings['footer_newsletter_placeholder'] ?? 'Your email address' }}" required>
                    <button type="submit" aria-label="Subscribe to newsletter"><span aria-hidden="true">→</span></button>
                </form>
            </section>
        @endif
    </div>

    <div class="t4-footer__bottom">
        <p>© {{ date('Y') }} <a href="{{ route('home') }}">{{ $theme04Brand }}</a>. {{ ($settings['footer_copyright_text'] ?? null) ?: 'All rights reserved.' }}</p>
        <nav aria-label="Legal links"><a href="{{ $settings['footer_privacy_url'] ?? url('/privacy-policy') }}">Privacy Policy</a><a href="{{ $settings['footer_terms_url'] ?? url('/terms-and-conditions') }}">Terms &amp; Conditions</a><a href="{{ route('sitemap') }}">Sitemap</a></nav>
        <p>Designed with <span class="t4-footer__heart" aria-label="love">♥</span> in Sri Lanka</p>
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
