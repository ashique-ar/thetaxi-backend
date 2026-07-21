<section class="t3-services" id="services-section" aria-labelledby="t3-services-title">
    <div class="container">
        <header class="t3-services__heading">
            <div>
                <span class="t3-kicker">Services</span>
                <h2 id="t3-services-title">{{ $settings['inspirations_section_title'] ?? 'Our Services' }}</h2>
            </div>
            <p>{{ $settings['inspirations_section_description'] ?? 'Professional transportation and travel services designed to meet your unique needs' }}</p>
        </header>

        <div class="t3-services__list">
            @foreach ($inspirations->take(6) as $index => $item)
                <x-cms-card
                    :item="$item"
                    :delayMs="($index + 1) * 200"
                    :itemIndex="$index + 1"
                    type="services"
                    :showPrice="true"
                    :showDuration="false"
                    :showRating="false"
                    template="theme-03-editorial-service" />
            @endforeach
        </div>

        <footer class="t3-services__all">
            <span aria-hidden="true">{{ str_pad((string) min($inspirations->count(), 6), 2, '0', STR_PAD_LEFT) }}</span>
            <a href="{{ route('cms.index', ['contentType' => 'services']) }}" class="primary-btn1 transparent">
                View All Services
            </a>
        </footer>
    </div>
</section>
