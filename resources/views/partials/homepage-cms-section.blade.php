<section class="home-cms-section home-cms-section--{{ $section['layout'] }}" aria-labelledby="home-cms-title-{{ $sectionIndex }}">
    <div class="container">
        @if ($section['layout'] !== 'banner')
            <div class="home-cms-section__head">
                <div>
                    @if ($section['eyebrow']) <span class="home-cms-section__eyebrow">{{ $section['eyebrow'] }}</span> @endif
                    <h2 id="home-cms-title-{{ $sectionIndex }}">{{ $section['title'] }}</h2>
                    @if ($section['description']) <p>{{ $section['description'] }}</p> @endif
                </div>
                <a href="{{ route('cms.index', ['contentType' => $section['type']->slug]) }}" class="home-cms-section__all">{{ $section['link_text'] }} <span aria-hidden="true">→</span></a>
            </div>
        @endif

        @if ($section['layout'] === 'features')
            <div class="home-cms-section__features">
                @foreach ($section['items'] as $item)
                    <a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}" class="home-cms-section__feature">
                        @if ($item->thumbnail) <img src="{{ s3_asset($item->thumbnail) }}" alt="" loading="lazy"> @endif
                        <span><strong>{{ $item->title }}</strong>@if ($item->excerpt)<small>{{ $item->excerpt }}</small>@endif</span>
                    </a>
                @endforeach
            </div>
        @elseif ($section['layout'] === 'banner')
            @php($lead = $section['items']->first())
            <div class="home-cms-section__banner" @if ($lead->featured_image || $lead->thumbnail) style="--cms-banner-image: url('{{ s3_asset($lead->featured_image ?: $lead->thumbnail) }}')" @endif>
                <div class="home-cms-section__banner-content">
                    @if ($section['eyebrow']) <span class="home-cms-section__eyebrow">{{ $section['eyebrow'] }}</span> @endif
                    <h2 id="home-cms-title-{{ $sectionIndex }}">{{ $section['title'] }}</h2>
                    <p>{{ $section['description'] ?: ($lead->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($lead->body ?? ''), 180)) }}</p>
                    <a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $lead->slug]) }}">{{ $section['link_text'] }} <span aria-hidden="true">→</span></a>
                </div>
                @if ($section['items']->count() > 1)
                    <ul class="home-cms-section__banner-points">
                        @foreach ($section['items']->skip(1) as $item)
                            <li>@if ($item->thumbnail)<img src="{{ s3_asset($item->thumbnail) }}" alt="" loading="lazy">@endif <a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">{{ $item->title }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @elseif ($section['layout'] === 'testimonials')
            <div class="home-cms-section__reviews">
                @foreach ($section['items'] as $item)
                    <article class="home-cms-section__review">
                        @if ($item->thumbnail)<img src="{{ s3_asset($item->thumbnail) }}" alt="" loading="lazy">@endif
                        <div>
                            <span class="home-cms-section__stars" aria-label="{{ min(5, max(0, (int) round($item->rating ?? 5))) }} out of 5 stars">@for ($star = 1; $star <= min(5, max(0, (int) round($item->rating ?? 5))); $star++)★@endfor</span>
                            <p>{{ $item->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($item->body ?? ''), 180) }}</p>
                            <strong>{{ $item->author ?: $item->title }}</strong>@if ($item->location)<small> | {{ $item->location }}</small>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        @elseif ($section['layout'] === 'logos')
            <div class="home-cms-section__logos">
                @foreach ($section['items'] as $item)
                    @if ($item->thumbnail || $item->featured_image)
                        <a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}"><img src="{{ s3_asset($item->thumbnail ?: $item->featured_image) }}" alt="{{ $item->title }}" loading="lazy"></a>
                    @endif
                @endforeach
            </div>
        @else
            <div class="home-cms-section__grid">
                @foreach ($section['items'] as $item)
                    <article class="home-cms-section__card">
                        <a class="home-cms-section__image" href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">
                            <img src="{{ $item->thumbnail ? s3_asset($item->thumbnail) : asset('assets/img/default-blog.jpg') }}" alt="{{ $item->title }}" loading="lazy">
                        </a>
                        <div class="home-cms-section__body">
                            <h3><a href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">{{ $item->title }}</a></h3>
                            @if ($item->excerpt || $item->body)<p>{{ \Illuminate\Support\Str::limit(trim(strip_tags(html_entity_decode($item->excerpt ?: $item->body, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 110) }}</p>@endif
                            <a class="home-cms-section__more" href="{{ route('cms.show', ['contentType' => $section['type']->slug, 'content' => $item->slug]) }}">Learn More <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
