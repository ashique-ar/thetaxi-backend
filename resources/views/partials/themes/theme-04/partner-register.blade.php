<section class="t4-partners partner-section" aria-labelledby="t4-partners-title">
    <div class="container">
        <div class="t4-partners__heading">
            <span class="t4-kicker">Trusted by our clients</span>
            <h2 id="t4-partners-title">{{ $settings['partner_section_title'] ?? 'Those Company You Can Easily Trust!' }}</h2>
        </div>
        <div class="t4-partners__rail marquee">
            <div class="t4-partners__items marquee__group">
                @foreach ($partners as $partner)
                    <a href="{{ $partner->link ?? '#' }}">
                        <img src="{{ $partner->image ? s3_asset($partner->image) : s3_asset($settings['partner_logo_1']) }}" alt="{{ $partner->title }}" loading="lazy">
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</section>
