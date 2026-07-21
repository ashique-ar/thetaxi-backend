<section class="t4-destinations" id="destinations-section" aria-labelledby="t4-destinations-title">
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Destinations</span>
            <h2 id="t4-destinations-title">{{ $settings['destinations_section_title'] ?? 'Top Destinations' }}</h2>
            <p>{{ $settings['destinations_section_description'] ?? 'Discover the most spectacular destinations Sri Lanka has to offer' }}</p>
        </header>
        <div class="t4-destinations__grid">
            @foreach ($destinations->take(6) as $index => $item)
                <x-cms-card :item="$item" :delayMs="($index + 1) * 200" :itemIndex="$index + 1" type="taxi" :showPrice="false" :showDuration="false" :showRating="true" template="theme-04-media-card" />
            @endforeach
        </div>
        <div class="t4-section-action"><a href="{{ route('cms.index', ['contentType' => 'taxi']) }}" class="primary-btn1">View All Destinations</a></div>
    </div>
</section>
