<section class="t4-stories" id="travel-blog-section" aria-labelledby="t4-stories-title">
    <div class="container">
        <header class="t4-section-heading">
            <span class="t4-kicker">Travel journal</span>
            <h2 id="t4-stories-title">{{ $settings['blog_section_title'] ?? 'Travel Stories & Inspiration' }}</h2>
            <p>{{ $settings['blog_section_description'] ?? 'Discover inspiring travel stories, destination guides, and insider tips for your next adventure' }}</p>
        </header>
        <div class="t4-stories__grid">
            @foreach ($blogs->take(3) as $index => $item)
                <x-cms-card :item="$item" :delayMs="($index + 1) * 200" :itemIndex="$index + 1" type="blogs" :showPrice="false" :showDuration="false" :showRating="false" template="theme-04-media-card" />
            @endforeach
        </div>
        <div class="t4-section-action"><a href="{{ route('cms.index', ['contentType' => 'blogs']) }}" class="primary-btn1">View All Stories</a></div>
    </div>
</section>
