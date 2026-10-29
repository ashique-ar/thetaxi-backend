@if (!empty($slide['eyebrow']))
    <span class="shared-hero-eyebrow">{{ $slide['eyebrow'] }}</span>
@endif
@if ($index === 0)
    <h1 class="shared-hero-heading">{{ $slide['heading'] }}</h1>
@else
    <h2 class="shared-hero-heading">{{ $slide['heading'] }}</h2>
@endif
@if ($slide['subheading'] !== '')
    <p class="shared-hero-description">{{ $slide['subheading'] }}</p>
@endif
@if ($slide['caption'] !== '')
    <small class="shared-hero-caption">{{ $slide['caption'] }}</small>
@endif
@if (($slide['ctaText'] ?? '') !== '')
    <a class="shared-hero-action" href="{{ $slide['ctaUrl'] }}">{{ $slide['ctaText'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
@endif
@if (!empty($slide['locationLabel']))
    <span class="shared-hero-location"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $slide['locationLabel'] }}</span>
@endif
