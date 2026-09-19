<section class="home-cms-section" aria-labelledby="home-cms-title-{{ $sectionIndex }}">
    <div class="container">
        <div class="home-cms-section__head">
            <div>
                @if ($section['eyebrow']) <span class="home-cms-section__eyebrow">{{ $section['eyebrow'] }}</span> @endif
                <h2 id="home-cms-title-{{ $sectionIndex }}">{{ $section['title'] }}</h2>
                @if ($section['description']) <p>{{ $section['description'] }}</p> @endif
            </div>
            <a href="{{ route('cms.index', ['contentType' => $section['type']->slug]) }}" class="home-cms-section__all">
                {{ $section['link_text'] }} <span aria-hidden="true">→</span>
            </a>
        </div>
        <div class="home-cms-section__grid">
            @foreach ($section['items'] as $item)
                <article class="home-cms-section__card">
                    <a class="home-cms-section__image" href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">
                        <img src="{{ $item->thumbnail ? s3_asset($item->thumbnail) : asset('assets/img/default-blog.jpg') }}" alt="{{ $item->title }}" loading="lazy">
                    </a>
                    <div class="home-cms-section__body">
                        <h3><a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">{{ $item->title }}</a></h3>
                        @if ($item->excerpt || $item->body)
                            <p>{{ \Illuminate\Support\Str::limit(trim(strip_tags(html_entity_decode($item->excerpt ?: $item->body, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 110) }}</p>
                        @endif
                        <a class="home-cms-section__more" href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">Learn More <span aria-hidden="true">→</span></a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
