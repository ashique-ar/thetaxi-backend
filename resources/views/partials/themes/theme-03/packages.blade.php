<section class="t3-itinerary" id="things-to-do-section" aria-labelledby="t3-itinerary-title">
    <div class="container">
        <header class="t3-itinerary__heading">
            <div>
                <span class="t3-kicker">Things to do</span>
                <h2 id="t3-itinerary-title">{{ $settings['packages_section_title'] ?? 'Things to Do' }}</h2>
            </div>
            <p>{{ $settings['packages_section_description'] ?? 'Discover exciting activities and experiences Sri Lanka has to offer' }}</p>
        </header>

        <div class="t3-itinerary__list">
            @foreach ($packages->take(6) as $index => $item)
                <x-cms-card
                    :item="$item"
                    :delayMs="($index + 1) * 200"
                    :itemIndex="$index + 1"
                    type="things-to-do"
                    :showPrice="true"
                    :showDuration="true"
                    :showRating="true"
                    template="theme-03-itinerary" />
            @endforeach
        </div>

        <footer class="t3-itinerary__all">
            <a href="{{ route('cms.index', ['contentType' => 'things-to-do']) }}" class="primary-btn1 transparent">
                View All Activities
            </a>
        </footer>
    </div>
</section>
