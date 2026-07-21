<section class="t3-partner-register partner-section" aria-labelledby="t3-partner-title">
    <div class="container">
        <div class="t3-partner-register__heading">
            <span class="t3-kicker">Trusted connections</span>
            <h2 id="t3-partner-title">{{ $settings['partner_section_title'] ?? 'Those Company You Can Easily Trust!' }}</h2>
        </div>

        <div class="t3-partner-register__rail marquee">
            <div class="t3-partner-register__items marquee__group">
                @foreach ($partners as $partner)
                    <a href="{{ $partner->link ?? '#' }}" class="t3-partner-register__item">
                        <span aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <img
                            src="{{ $partner->image ? s3_asset($partner->image) : s3_asset($settings['partner_logo_1']) }}"
                            alt="{{ $partner->title }}"
                            loading="lazy">
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</section>
