<section class="t3-journal" id="travel-blog-section" aria-labelledby="t3-journal-title">
    <div class="container">
        <header class="t3-journal__heading">
            <div>
                <span class="t3-kicker">From the road</span>
                <h2 id="t3-journal-title">{{ $settings['blog_section_title'] ?? 'Travel Stories & Inspiration' }}</h2>
            </div>
            <p>{{ $settings['blog_section_description'] ?? 'Discover inspiring travel stories, destination guides, and insider tips for your next adventure' }}</p>
        </header>

        <div class="t3-journal__stories">
            @foreach ($blogs->take(3) as $index => $item)
                <x-cms-card
                    :item="$item"
                    :delayMs="($index + 1) * 200"
                    :itemIndex="$index + 1"
                    type="blogs"
                    :showPrice="false"
                    :showDuration="false"
                    :showRating="false"
                    template="theme-03-editorial-story" />
            @endforeach
        </div>

        <footer class="t3-journal__all">
            <span aria-hidden="true">{{ str_pad((string) min($blogs->count(), 3), 2, '0', STR_PAD_LEFT) }}</span>
            <a href="{{ route('cms.index', ['contentType' => 'blogs']) }}" class="primary-btn1 transparent">
                View All Stories
            </a>
        </footer>
    </div>
</section>
