<section class="t4-activities" id="things-to-do-section" aria-labelledby="t4-activities-title">
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Things to do</span>
            <h2 id="t4-activities-title">{{ $settings['packages_section_title'] ?? 'Things to Do' }}</h2>
            <p>{{ $settings['packages_section_description'] ?? 'Discover exciting activities and experiences Sri Lanka has to offer' }}</p>
        </header>
        <div class="t4-activities__grid">
            @foreach ($packages->take(6) as $index => $item)
                <x-cms-card :item="$item" :delayMs="($index + 1) * 200" :itemIndex="$index + 1" type="things-to-do" :showPrice="true" :showDuration="true" :showRating="true" template="theme-04-media-card" />
            @endforeach
        </div>
        <div class="t4-section-action"><a href="{{ route('cms.index', ['contentType' => 'things-to-do']) }}" class="primary-btn1">View All Activities</a></div>
    </div>
</section>
