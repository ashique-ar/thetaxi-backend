@php
    $theme03HeroHeading = $settings['banner_heading'] ?? 'All-in-one Travel Booking.';
    $theme03HeroSubheading = $settings['banner_subheading'] ?? 'Best travel agency in world-wide & achieve "World Travel Award"';
    $theme03HeroImage = $settings['banner_image'] ?? 'assets/img/home4/home4-banner-img.jpg';
    $theme03HeroBrand = $settings['brand_short_name'] ?? $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
    $theme03HeroCaption = $settings['site_tagline'] ?? $settings['brand_tagline'] ?? null;
@endphp

<section class="t3-hero" aria-labelledby="t3-hero-title">
    <div class="t3-hero__frame">
        <div class="t3-hero__copy">
            <span class="t3-kicker">{{ $theme03HeroBrand }}</span>
            <h1 id="t3-hero-title">{{ $theme03HeroHeading }}</h1>
            @if ($theme03HeroSubheading !== '')
                <p>{{ $theme03HeroSubheading }}</p>
            @endif

            <div class="t3-hero__folio" aria-hidden="true">
                <span>01</span>
                <i></i>
            </div>
        </div>

        <figure class="t3-hero__visual">
            <div class="t3-hero__image-frame">
                <img
                    src="{{ s3_asset($theme03HeroImage) }}"
                    alt="{{ $theme03HeroHeading }}"
                    width="1600"
                    height="1100"
                    loading="eager"
                    fetchpriority="high">
            </div>
            @if (!empty($theme03HeroCaption))
                <figcaption>{{ $theme03HeroCaption }}</figcaption>
            @endif
            <span class="t3-hero__index" aria-hidden="true">03</span>
        </figure>
    </div>
</section>
