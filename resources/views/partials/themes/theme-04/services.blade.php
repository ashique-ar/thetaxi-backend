<section class="t4-services" id="services-section" aria-labelledby="t4-services-title">
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Signature services</span>
            <h2 id="t4-services-title">{{ $settings['inspirations_section_title'] ?? 'Our Services' }}</h2>
            <p>{{ $settings['inspirations_section_description'] ?? 'Professional transportation and travel services designed to meet your unique needs' }}</p>
        </header>
        <div class="t4-services__grid">
            @foreach ($inspirations->take(6) as $index => $item)
                <x-cms-card :item="$item" :delayMs="($index + 1) * 200" :itemIndex="$index + 1" type="services" :showPrice="true" :showDuration="false" :showRating="false" template="theme-04-media-card" />
            @endforeach
        </div>
        <div class="t4-section-action"><a href="{{ route('cms.index', ['contentType' => 'services']) }}" class="primary-btn1">View All Services</a></div>
    </div>
</section>
