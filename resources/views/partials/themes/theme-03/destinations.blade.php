<section class="t3-destinations" id="destinations-section" aria-labelledby="t3-destinations-title">
    <div class="container">
        <header class="t3-destinations__heading">
            <span class="t3-kicker">Destinations</span>
            <h2 id="t3-destinations-title">{{ $settings['destinations_section_title'] ?? 'Top Destinations' }}</h2>
            <div>
                <p>{{ $settings['destinations_section_description'] ?? 'Discover the most spectacular destinations Sri Lanka has to offer' }}</p>
                <a href="{{ route('cms.index', ['contentType' => 'taxi']) }}" class="primary-btn1 transparent">
                    View All Destinations
                </a>
            </div>
        </header>

        <div class="t3-destinations__list">
            @foreach ($destinations->take(6) as $index => $item)
                <x-cms-card
                    :item="$item"
                    :delayMs="($index + 1) * 200"
                    :itemIndex="$index + 1"
                    type="taxi"
                    :showPrice="false"
                    :showDuration="false"
                    :showRating="true"
                    template="theme-03-destination-index" />
            @endforeach
        </div>
    </div>
</section>
